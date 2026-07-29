<?php
/**
 * One-off email send test - sends a real email through the exact same
 * mailer.php pipeline used by suspend/appeal notifications, without needing
 * to create and suspend an ATEM card first.
 *
 * Restricted to logged-in SuperAdmins. Delete this file once mail delivery
 * is confirmed working - it is not meant to stay in production long-term.
 */
require_once(dirname(__FILE__) . '/../lock_adv.php');
$connect = 1;
include(dirname(__FILE__) . '/../common/index_adv.php');

$_dev_override_active = isset($_SESSION['atem_dev_role_override']);
$_is_superadmin = (!$_dev_override_active && isset($atem) && (int)$atem === 1);

if (!$_is_superadmin) {
    http_response_code(403);
    echo 'Forbidden. SuperAdmin only.';
    exit;
}

require_once(dirname(__FILE__) . '/mailer.php');

$result = null;
$toEmail = isset($_POST['to_email']) ? trim($_POST['to_email']) : 'anasuharosli@gmail.com';
$toName  = isset($_POST['to_name']) ? trim($_POST['to_name']) : 'Test Recipient';
$subject = isset($_POST['subject']) ? trim($_POST['subject']) : 'ATEM Test Email';
$message = isset($_POST['message']) ? trim($_POST['message']) : 'This is a test email sent directly from test-send-email.php.';

$cfg = getMailConfig();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $htmlBody = '<p>' . nl2br(htmlspecialchars($message)) . '</p>';
    $altBody  = $message;
    $result = dispatchAtemEmail($toEmail, $toName, $subject, $htmlBody, $altBody, 0);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>ATEM - Test Send Email</title>
    <style>
        body { font-family: Arial, sans-serif; max-width: 600px; margin: 40px auto; color: #333; }
        h1 { font-size: 18px; }
        label { display: block; margin-top: 12px; font-size: 13px; font-weight: bold; }
        input[type=text], input[type=email], textarea { width: 100%; padding: 8px; box-sizing: border-box; font-size: 13px; }
        textarea { height: 100px; }
        button { margin-top: 16px; padding: 8px 20px; background-color: #0d6efd; color: #fff; border: none; border-radius: 4px; cursor: pointer; font-size: 13px; }
        .result { margin-top: 20px; padding: 12px; border-radius: 4px; font-size: 13px; }
        .result.success { background-color: #d1e7dd; color: #0f5132; }
        .result.failure { background-color: #f8d7da; color: #842029; word-break: break-word; }
        .cfg { margin-top: 24px; padding: 12px; background-color: #f9f9f9; font-size: 12px; color: #555; }
        .cfg div { margin-bottom: 4px; }
    </style>
</head>
<body>
    <h1>ATEM - Test Send Email</h1>
    <p style="font-size:13px; color:#6c757d;">Sends through the same mailer.php pipeline as suspend/appeal notifications. SuperAdmin only.</p>

    <?php if ($result !== null): ?>
        <?php if (!empty($result['success'])): ?>
            <div class="result success">Sent successfully to <?php echo htmlspecialchars($toEmail); ?>.</div>
        <?php else: ?>
            <div class="result failure">Failed: <?php echo htmlspecialchars(isset($result['message']) ? $result['message'] : 'Unknown error'); ?></div>
        <?php endif; ?>
    <?php endif; ?>

    <form method="POST">
        <label for="to_email">To Email</label>
        <input type="email" id="to_email" name="to_email" value="<?php echo htmlspecialchars($toEmail); ?>" required>

        <label for="to_name">To Name</label>
        <input type="text" id="to_name" name="to_name" value="<?php echo htmlspecialchars($toName); ?>">

        <label for="subject">Subject</label>
        <input type="text" id="subject" name="subject" value="<?php echo htmlspecialchars($subject); ?>" required>

        <label for="message">Message</label>
        <textarea id="message" name="message"><?php echo htmlspecialchars($message); ?></textarea>

        <button type="submit">Send Test Email</button>
    </form>

    <div class="cfg">
        <strong>Current mail config (from .env):</strong>
        <div>Host: <?php echo htmlspecialchars(!empty($cfg['host']) ? $cfg['host'] : '(not set)'); ?></div>
        <div>Port: <?php echo htmlspecialchars(isset($cfg['port']) ? (string)$cfg['port'] : '(not set)'); ?></div>
        <div>From: <?php echo htmlspecialchars(!empty($cfg['from_email']) ? $cfg['from_email'] : '(not set)'); ?></div>
        <div>Check logs/mail_operations-<?php echo date('Y-m-d'); ?>.log for full send details.</div>
    </div>
</body>
</html>
