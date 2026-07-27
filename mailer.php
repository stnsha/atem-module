<?php
// ATEM mail utility. Uses PHPMailer via Composer (see composer.json/vendor).
// Sending is always best-effort: callers must not let a mail failure block
// the underlying action (e.g. a card suspend already committed to the DB).

if (file_exists(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
}

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as PHPMailerException;

function getMailConfig()
{
    static $config = null;
    if ($config === null) {
        $path = __DIR__ . '/mail_config.local.php';
        $config = file_exists($path) ? include $path : array();
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
 * Low-level sender shared by every ATEM notification email. Never throws -
 * returns array('success' => bool, 'message' => string) and logs failures.
 */
function dispatchAtemEmail($toEmail, $toName, $subject, $htmlBody, $altBody, $atemId)
{
    $cfg = getMailConfig();
    if (empty($cfg['host']) || !class_exists(PHPMailer::class)) {
        error_log('ATEM email skipped: mail is not configured (missing vendor/ or mail_config.local.php).');
        return array('success' => false, 'message' => 'Mail is not configured.');
    }
    if (empty($toEmail)) {
        error_log('ATEM email skipped: recipient has no email on file (atem_id=' . (int)$atemId . ').');
        return array('success' => false, 'message' => 'Recipient has no email on file.');
    }

    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host = $cfg['host'];
        $mail->SMTPAuth = true;
        $mail->Username = $cfg['username'];
        $mail->Password = $cfg['password'];
        $mail->Port = $cfg['port'];
        $mail->CharSet = 'UTF-8';

        $mail->setFrom(
            !empty($cfg['from_email']) ? $cfg['from_email'] : 'noreply@atem.local',
            !empty($cfg['from_name']) ? $cfg['from_name'] : 'ATEM System'
        );
        $mail->addAddress($toEmail, $toName);

        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body = $htmlBody;
        $mail->AltBody = $altBody;

        $mail->send();
        return array('success' => true);
    } catch (PHPMailerException $e) {
        error_log('ATEM email failed (atem_id=' . (int)$atemId . '): ' . $mail->ErrorInfo);
        return array('success' => false, 'message' => $mail->ErrorInfo);
    } catch (Throwable $e) {
        // Catches anything beyond PHPMailer's own exception type (e.g. a bad
        // link/config error) so a mail-step bug can never corrupt the JSON
        // response of the action that triggered it (e.g. suspend-atem).
        error_log('ATEM email failed (atem_id=' . (int)$atemId . '): ' . $e->getMessage());
        return array('success' => false, 'message' => $e->getMessage());
    }
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
