<?php
// ATEM mail utility. Hand-rolled SMTP over a raw SSL socket (same approach as
// odb/voucher/email_helper.php) - no PHPMailer/Composer vendor dependency, so
// there is nothing to `composer install` on deploy and no vendor/ to go stale.
// Sending is always best-effort: callers must not let a mail failure block
// the underlying action (e.g. a card suspend already committed to the DB).

/**
 * Daily mail-attempt log, independent of api.php's jwt_operations log, so
 * mail delivery is traceable from a file this app controls even when PHP's
 * own error_log() path isn't something ops actually checks on production.
 */
function logMailOperation($event, $message, $data = null, $level = 'INFO')
{
    $log_dir = __DIR__ . '/logs';
    $log_file = $log_dir . '/mail_operations-' . date('Y-m-d') . '.log';

    if (!is_dir($log_dir)) {
        if (!@mkdir($log_dir, 0755, true)) {
            @mkdir($log_dir, 0755);
        }
    }
    if (!is_dir($log_dir)) {
        return false;
    }
    if (!is_writable($log_dir)) {
        @chmod($log_dir, 0755);
    }

    $timestamp = date('Y-m-d H:i:s');
    $log_message = "[$timestamp] [$level] [$event] $message";
    if ($data !== null) {
        $log_message .= ' | ' . (is_array($data) ? json_encode($data) : $data);
    }
    $log_message .= "\n";

    $result = @file_put_contents($log_file, $log_message, FILE_APPEND);
    if ($result === false && !file_exists($log_file)) {
        @touch($log_file);
        @chmod($log_file, 0644);
        $result = @file_put_contents($log_file, $log_message, FILE_APPEND);
    }
    return $result !== false;
}

/**
 * SMTP credentials, sourced from .env (production mailbox) when present,
 * falling back to mail_config.local.php (e.g. a local Mailtrap sandbox).
 * Both files are gitignored - never commit real credentials.
 */
function getMailConfig()
{
    static $config = null;
    if ($config === null) {
        $envPath = __DIR__ . '/.env';
        $env = (is_file($envPath) && is_readable($envPath))
            ? parse_ini_file($envPath, false, INI_SCANNER_RAW)
            : false;

        if (!empty($env['MAIL_HOST'])) {
            $port = isset($env['MAIL_PORT']) ? (int)$env['MAIL_PORT'] : 465;
            $config = array(
                'host'       => $env['MAIL_HOST'],
                'port'       => $port,
                'username'   => isset($env['MAIL_USERNAME']) ? $env['MAIL_USERNAME'] : '',
                'password'   => isset($env['MAIL_PASSWORD']) ? $env['MAIL_PASSWORD'] : '',
                'secure'     => isset($env['MAIL_ENCRYPTION']) ? $env['MAIL_ENCRYPTION'] : (($port === 465) ? 'ssl' : 'tls'),
                'from_email' => !empty($env['MAIL_FROM_ADDRESS']) ? $env['MAIL_FROM_ADDRESS'] : (isset($env['MAIL_USERNAME']) ? $env['MAIL_USERNAME'] : 'noreply@atem.local'),
                'from_name'  => !empty($env['MAIL_FROM_NAME']) ? $env['MAIL_FROM_NAME'] : 'ATEM System',
            );
        } else {
            $path = __DIR__ . '/mail_config.local.php';
            $config = file_exists($path) ? include $path : array();
        }
    }
    return $config;
}

/**
 * Absolute link back to an ATEM card - email clients can't resolve the
 * host-relative ATEM_BASE, so scheme+host are added the same way
 * common/index_adv.php builds its own absolute URL.
 */
function buildAtemCardLink($atemId)
{
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    $scheme = $https ? 'https://' : 'http://';
    $host = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : 'localhost';
    return $scheme . $host . ATEM_BASE . 'edit.php?id=' . (int)$atemId;
}

/**
 * Shared HTML email shell: a colored header band, a light content area, and a
 * footer note. Callers supply the header color/title and the inner content.
 */
function atemEmailShell($headerColor, $headerTitle, $innerHtml)
{
    return "
    <div style=\"font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; color: #333;\">
        <div style=\"background-color: {$headerColor}; color: #fff; padding: 16px 20px;\">
            <h2 style=\"margin: 0; font-size: 18px;\">" . htmlspecialchars($headerTitle) . "</h2>
        </div>
        <div style=\"background-color: #f9f9f9; padding: 20px;\">
            {$innerHtml}
        </div>
        <div style=\"text-align: center; padding: 16px; font-size: 12px; color: #6c757d;\">
            This is an automated message from ATEM. Please do not reply to this email.
        </div>
    </div>
    ";
}

/**
 * Raw-socket SMTP send over implicit TLS (port 465), same protocol handling
 * as voucher/email_helper.php's _smtpSend(): connect, EHLO, AUTH LOGIN, MAIL
 * FROM/RCPT TO/DATA, then read the reply code after each step. Returns
 * array('ok' => bool, 'error' => string) - error is the last SMTP reply line
 * on failure, so dispatchAtemEmail() can log something actionable.
 */
function _atemSmtpSend($host, $port, $user, $pass, $fromName, $toEmail, $toName, $subject, $htmlBody, $useTLS = true)
{
    $ctx = stream_context_create(array('ssl' => array(
        'verify_peer'      => false,
        'verify_peer_name' => false,
        'allow_self_signed' => true,
    )));
    $target = ($useTLS ? 'ssl://' : '') . $host . ':' . $port;
    $sock = @stream_socket_client($target, $errno, $errstr, 15, STREAM_CLIENT_CONNECT, $ctx);
    if (!$sock) {
        return array('ok' => false, 'error' => "Could not connect to {$host}:{$port} - [{$errno}] {$errstr}");
    }
    stream_set_timeout($sock, 15);

    $read = function () use ($sock) {
        $r = '';
        while ($l = fgets($sock, 515)) {
            $r .= $l;
            if (isset($l[3]) && $l[3] === ' ') break;
        }
        return $r;
    };
    $cmd = function ($c) use ($sock, &$read) { fwrite($sock, $c . "\r\n"); return $read(); };

    $reply = $read(); // server greeting
    $reply = $cmd('EHLO localhost');
    $cmd('AUTH LOGIN');
    $cmd(base64_encode($user));
    $reply = $cmd(base64_encode($pass));
    if (strpos($reply, '235') === false) {
        fclose($sock);
        return array('ok' => false, 'error' => 'SMTP auth failed: ' . trim($reply));
    }

    $reply = $cmd('MAIL FROM:<' . $user . '>');
    if (strpos($reply, '250') === false) {
        fclose($sock);
        return array('ok' => false, 'error' => 'MAIL FROM rejected: ' . trim($reply));
    }
    $reply = $cmd('RCPT TO:<' . trim($toEmail) . '>');
    if (strpos($reply, '250') === false && strpos($reply, '251') === false) {
        fclose($sock);
        return array('ok' => false, 'error' => 'RCPT TO rejected: ' . trim($reply));
    }

    $reply = $cmd('DATA');
    if (strpos($reply, '354') === false) {
        fclose($sock);
        return array('ok' => false, 'error' => 'DATA rejected: ' . trim($reply));
    }

    $msg = 'Date: ' . date('r') . "\r\n"
        . 'From: =?UTF-8?B?' . base64_encode($fromName) . '?= <' . $user . '>' . "\r\n"
        . 'To: =?UTF-8?B?' . base64_encode($toName) . '?= <' . trim($toEmail) . '>' . "\r\n"
        . 'Subject: =?UTF-8?B?' . base64_encode($subject) . '?=' . "\r\n"
        . "MIME-Version: 1.0\r\n"
        . "Content-Type: text/html; charset=UTF-8\r\n"
        . "Content-Transfer-Encoding: base64\r\n"
        . "\r\n"
        . chunk_split(base64_encode($htmlBody))
        . "\r\n.\r\n";
    fwrite($sock, $msg);
    $reply = $read();
    $cmd('QUIT');
    fclose($sock);

    if (strpos($reply, '250') === false) {
        return array('ok' => false, 'error' => 'Message not accepted: ' . trim($reply));
    }
    return array('ok' => true, 'error' => '');
}

/**
 * Low-level sender shared by every ATEM notification email. Never throws -
 * returns array('success' => bool, 'message' => string) and logs failures.
 */
function dispatchAtemEmail($toEmail, $toName, $subject, $htmlBody, $altBody, $atemId)
{
    $cfg = getMailConfig();
    if (empty($cfg['host']) || empty($cfg['username']) || empty($cfg['password'])) {
        error_log('ATEM email skipped: mail is not configured (missing .env/mail_config.local.php values).');
        logMailOperation('dispatchAtemEmail', 'Skipped - mail not configured', array('atem_id' => (int)$atemId, 'reason' => 'no host/username/password in .env/mail_config.local.php', 'subject' => $subject), 'ERROR');
        return array('success' => false, 'message' => 'Mail is not configured.');
    }
    if (empty($toEmail)) {
        error_log('ATEM email skipped: recipient has no email on file (atem_id=' . (int)$atemId . ').');
        logMailOperation('dispatchAtemEmail', 'Skipped - recipient has no email on file', array('atem_id' => (int)$atemId, 'to_name' => $toName, 'subject' => $subject), 'WARNING');
        return array('success' => false, 'message' => 'Recipient has no email on file.');
    }

    $secure = isset($cfg['secure']) ? strtolower((string)$cfg['secure']) : '';
    $useTLS = !in_array($secure, array('', 'none', 'false', '0'), true);

    try {
        $result = _atemSmtpSend(
            $cfg['host'],
            (int)$cfg['port'],
            $cfg['username'],
            $cfg['password'],
            !empty($cfg['from_name']) ? $cfg['from_name'] : 'ATEM System',
            $toEmail,
            $toName,
            $subject,
            $htmlBody,
            $useTLS
        );
    } catch (Throwable $e) {
        // Catches anything unexpected in the socket/protocol handling so a
        // mail-step bug can never corrupt the JSON response of the action
        // that triggered it (e.g. suspend-atem).
        error_log('ATEM email failed (atem_id=' . (int)$atemId . '): ' . $e->getMessage());
        logMailOperation('dispatchAtemEmail', 'Failed - exception', array('atem_id' => (int)$atemId, 'to' => $toEmail, 'subject' => $subject, 'error' => $e->getMessage()), 'ERROR');
        return array('success' => false, 'message' => $e->getMessage());
    }

    if ($result['ok']) {
        logMailOperation('dispatchAtemEmail', 'Sent', array('atem_id' => (int)$atemId, 'to' => $toEmail, 'subject' => $subject), 'INFO');
        return array('success' => true);
    }

    error_log('ATEM email failed (atem_id=' . (int)$atemId . '): ' . $result['error']);
    logMailOperation('dispatchAtemEmail', 'Failed - SMTP error', array('atem_id' => (int)$atemId, 'to' => $toEmail, 'subject' => $subject, 'error' => $result['error']), 'ERROR');
    return array('success' => false, 'message' => $result['error']);
}

/**
 * Notify an ATEM card's Issuer that their card was suspended.
 */
function sendAtemSuspensionEmail($toEmail, $toName, $atemId, $atemTitle, $reason, $suspendedByName)
{
    $link = buildAtemCardLink($atemId);

    $inner = "<p>Hi " . htmlspecialchars($toName) . ",</p>"
        . "<p>Your ATEM card <strong>" . htmlspecialchars($atemTitle) . "</strong> (ID #" . (int)$atemId . ") has been suspended by " . htmlspecialchars($suspendedByName) . ".</p>"
        . "<p><strong>Reason for suspension:</strong><br>" . nl2br(htmlspecialchars($reason)) . "</p>"
        . "<p style=\"margin-top: 24px;\"><a href=\"" . htmlspecialchars($link) . "\" style=\"display: inline-block; padding: 10px 20px; background-color: #0d6efd; color: #fff; text-decoration: none; border-radius: 5px;\">View ATEM Card</a></p>";

    $altBody = "Your ATEM card \"{$atemTitle}\" (ID #{$atemId}) has been suspended by {$suspendedByName}.\n"
        . "Reason: {$reason}\n"
        . "View the card: {$link}";

    return dispatchAtemEmail(
        $toEmail,
        $toName,
        'ATEM Card Suspended: ' . $atemTitle,
        atemEmailShell('#dc3545', 'ATEM Card Suspended', $inner),
        $altBody,
        $atemId
    );
}

/**
 * Notify whoever suspended a card that the Issuer has appealed the suspension.
 */
function sendAtemAppealEmail($toEmail, $toName, $atemId, $atemTitle, $reason, $issuerName)
{
    $link = buildAtemCardLink($atemId);

    $inner = "<p>Hi " . htmlspecialchars($toName) . ",</p>"
        . "<p>" . htmlspecialchars($issuerName) . " has submitted an appeal for the suspension of ATEM card <strong>" . htmlspecialchars($atemTitle) . "</strong> (ID #" . (int)$atemId . ").</p>"
        . "<p><strong>Appeal reason:</strong><br>" . nl2br(htmlspecialchars($reason)) . "</p>"
        . "<p style=\"margin-top: 24px;\"><a href=\"" . htmlspecialchars($link) . "\" style=\"display: inline-block; padding: 10px 20px; background-color: #0d6efd; color: #fff; text-decoration: none; border-radius: 5px;\">View ATEM Card</a></p>";

    $altBody = "{$issuerName} has submitted an appeal for the suspension of ATEM card \"{$atemTitle}\" (ID #{$atemId}).\n"
        . "Appeal reason: {$reason}\n"
        . "View the card: {$link}";

    return dispatchAtemEmail(
        $toEmail,
        $toName,
        'Appeal Submitted for ATEM: ' . $atemTitle,
        atemEmailShell('#0d6efd', 'ATEM Suspension Appeal', $inner),
        $altBody,
        $atemId
    );
}

/**
 * Notify an ATEM card's Issuer that their suspended card was force-terminated
 * (either by the 30-day auto-scheduler or manually by a SuperAdmin).
 */
function sendAtemForceTerminateEmail($toEmail, $toName, $atemId, $atemTitle, $reason)
{
    $link = buildAtemCardLink($atemId);

    $inner = "<p>Hi " . htmlspecialchars($toName) . ",</p>"
        . "<p>Your ATEM card <strong>" . htmlspecialchars($atemTitle) . "</strong> (ID #" . (int)$atemId . ") has been force-terminated.</p>"
        . ($reason !== '' ? "<p><strong>Reason:</strong><br>" . nl2br(htmlspecialchars($reason)) . "</p>" : "")
        . "<p style=\"margin-top: 24px;\"><a href=\"" . htmlspecialchars($link) . "\" style=\"display: inline-block; padding: 10px 20px; background-color: #0d6efd; color: #fff; text-decoration: none; border-radius: 5px;\">View ATEM Card</a></p>";

    $altBody = "Your ATEM card \"{$atemTitle}\" (ID #{$atemId}) has been force-terminated.\n"
        . ($reason !== '' ? "Reason: {$reason}\n" : '')
        . "View the card: {$link}";

    return dispatchAtemEmail(
        $toEmail,
        $toName,
        'ATEM Card Force-Terminated: ' . $atemTitle,
        atemEmailShell('#dc3545', 'ATEM Card Force-Terminated', $inner),
        $altBody,
        $atemId
    );
}
