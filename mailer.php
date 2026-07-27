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
 * Notify an ATEM card's Issuer that their card was suspended. Never throws -
 * returns array('success' => bool, 'message' => string) and logs failures.
 */
function sendAtemSuspensionEmail($toEmail, $toName, $atemId, $atemTitle, $reason, $suspendedByName)
{
    $cfg = getMailConfig();
    if (empty($cfg['host']) || !class_exists(PHPMailer::class)) {
        error_log('ATEM suspension email skipped: mail is not configured (missing vendor/ or mail_config.local.php).');
        return array('success' => false, 'message' => 'Mail is not configured.');
    }
    if (empty($toEmail)) {
        error_log('ATEM suspension email skipped: issuer has no email on file (atem_id=' . (int)$atemId . ').');
        return array('success' => false, 'message' => 'Issuer has no email on file.');
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

        $link = buildAtemCardLink($atemId);

        $mail->isHTML(true);
        $mail->Subject = 'ATEM Card Suspended: ' . $atemTitle;
        $mail->Body = "
        <div style=\"font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; color: #333;\">
            <div style=\"background-color: #dc3545; color: #fff; padding: 16px 20px;\">
                <h2 style=\"margin: 0; font-size: 18px;\">ATEM Card Suspended</h2>
            </div>
            <div style=\"background-color: #f9f9f9; padding: 20px;\">
                <p>Hi " . htmlspecialchars($toName) . ",</p>
                <p>Your ATEM card <strong>" . htmlspecialchars($atemTitle) . "</strong> (ID #" . (int)$atemId . ") has been suspended by " . htmlspecialchars($suspendedByName) . ".</p>
                <p><strong>Reason for suspension:</strong><br>" . nl2br(htmlspecialchars($reason)) . "</p>
                <p style=\"margin-top: 24px;\">
                    <a href=\"" . htmlspecialchars($link) . "\" style=\"display: inline-block; padding: 10px 20px; background-color: #0d6efd; color: #fff; text-decoration: none; border-radius: 5px;\">View ATEM Card</a>
                </p>
            </div>
            <div style=\"text-align: center; padding: 16px; font-size: 12px; color: #6c757d;\">
                This is an automated message from ATEM. Please do not reply to this email.
            </div>
        </div>
        ";
        $mail->AltBody = "Your ATEM card \"{$atemTitle}\" (ID #{$atemId}) has been suspended by {$suspendedByName}.\n"
            . "Reason: {$reason}\n"
            . "View the card: {$link}";

        $mail->send();
        return array('success' => true);
    } catch (PHPMailerException $e) {
        error_log('ATEM suspension email failed (atem_id=' . (int)$atemId . '): ' . $mail->ErrorInfo);
        return array('success' => false, 'message' => $mail->ErrorInfo);
    } catch (Throwable $e) {
        // Catches anything beyond PHPMailer's own exception type (e.g. a bad
        // link/config error) so a mail-step bug can never corrupt the JSON
        // response of the action that triggered it (e.g. suspend-atem).
        error_log('ATEM suspension email failed (atem_id=' . (int)$atemId . '): ' . $e->getMessage());
        return array('success' => false, 'message' => $e->getMessage());
    }
}
