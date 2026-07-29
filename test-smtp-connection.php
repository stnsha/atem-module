<?php
/**
 * One-off SMTP connectivity test for wherever this file is deployed.
 * Opens a raw TCP socket to a mail host on a set of candidate ports, with no
 * PHPMailer/vendor dependency, so it isolates network-level reachability
 * from SMTP auth/protocol issues (WSAETIMEDOUT vs connection refused vs open).
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
    header('Content-Type: text/plain; charset=utf-8');
    echo "Forbidden. SuperAdmin only.\n";
    exit;
}

header('Content-Type: text/plain; charset=utf-8');

// Host under test defaults to whatever mailer.php would actually use
// (.env's outgoing_server), falling back to the known mail domain.
// Override with ?host=some.other.server to test an alternate target,
// e.g. this server's own local mail relay as a fallback option.
$cfgHost = null;
$envPath = dirname(__FILE__) . '/.env';
if (is_file($envPath) && is_readable($envPath)) {
    $env = parse_ini_file($envPath, false, INI_SCANNER_RAW);
    if (!empty($env['outgoing_server'])) {
        $cfgHost = $env['outgoing_server'];
    }
}
$host = isset($_GET['host']) && trim($_GET['host']) !== '' ? trim($_GET['host']) : ($cfgHost ? $cfgHost : 'mail.alpropharmacy.com.my');

// 465 is implicit TLS (SMTPS) - the server expects a TLS handshake
// immediately, so it needs the ssl:// wrapper to get a readable banner.
// 587/25 are plaintext until STARTTLS, so a plain socket is enough to see
// whether the connection opens at all.
$targets = array(
    465 => 'ssl://' . $host,
    587 => $host,
    25  => $host,
);
$timeoutSeconds = 8;

echo "SMTP connectivity test\n";
echo "Running on: " . (isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : 'unknown') . "\n";
echo "Target host: {$host}\n";
echo "Timeout per port: {$timeoutSeconds}s\n";
echo str_repeat('-', 60) . "\n";

foreach ($targets as $port => $address) {
    $start = microtime(true);
    $errno = 0;
    $errstr = '';
    $conn = @fsockopen($address, $port, $errno, $errstr, $timeoutSeconds);
    $elapsed = round(microtime(true) - $start, 2);

    if ($conn) {
        stream_set_timeout($conn, 3);
        $banner = fgets($conn, 512);
        fclose($conn);
        $bannerText = ($banner !== false && $banner !== null) ? trim($banner) : '(no banner read)';
        echo "Port {$port}: OPEN  ({$elapsed}s)  banner: {$bannerText}\n";
    } else {
        echo "Port {$port}: FAILED ({$elapsed}s)  errno {$errno}: {$errstr}\n";
    }
}

echo str_repeat('-', 60) . "\n";
echo "Reading this:\n";
echo "- OPEN + a banner starting with '220' means this port is reachable and\n";
echo "  the mail server answered - the block, if any, is elsewhere.\n";
echo "- FAILED after close to {$timeoutSeconds}s with no specific errno usually\n";
echo "  means the connection was silently dropped (firewall) rather than refused.\n";
echo "- FAILED almost instantly with 'Connection refused' means the host was\n";
echo "  reached but nothing is listening on that port - not a firewall block.\n";
echo "Done.\n";
