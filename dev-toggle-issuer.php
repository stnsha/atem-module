<?php
/**
 * Development-only "act as issuer" toggle for ATEM testing.
 * Lets a real SuperAdmin (staff.atem = 1) on localhost temporarily assume a
 * specific ATEM card's issuer identity, so issuer-only edit/save/delete/chat
 * paths can be exercised without logging in as that staff member. Scope is
 * per atem id - api.php reads this override and swaps $staff_id (and the
 * fields derived from it) to the card's real issuer whenever that id is
 * being acted on.
 *
 * This file must NOT be deployed to production.
 */
date_default_timezone_set('Asia/Kuala_Lumpur');

if (session_id() == '') {
    session_start();
}

// Restrict to localhost only
$serverName = isset($_SERVER['SERVER_NAME']) ? $_SERVER['SERVER_NAME'] : '';
$httpHost   = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : '';
$isLocal    = in_array($serverName, array('localhost', '127.0.0.1'))
    || strpos($serverName, 'localhost') !== false
    || strpos($httpHost, 'localhost') !== false
    || strpos($httpHost, '127.0.0.1') !== false;

if (!$isLocal) {
    http_response_code(403);
    echo 'This endpoint is not available in production.';
    exit;
}

if (!isset($_SERVER['REQUEST_METHOD']) || $_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo 'Method not allowed.';
    exit;
}

// Restrict to a real SuperAdmin (staff.atem = 1), independent of any active
// grade dev-override - mirrors header.php's $_is_superadmin source flag.
$connect = 1;
include(dirname(__FILE__) . '/../common/index_adv.php');
$_dev_is_real_superadmin = false;
if (isset($conn) && isset($_SESSION['myusername'])) {
    $username = mysqli_real_escape_string($conn, $_SESSION['myusername']);
    $result = mysqli_query($conn, "SELECT atem FROM staff WHERE username = '$username' AND recycle != 1");
    if ($result && ($row = mysqli_fetch_assoc($result))) {
        $_dev_is_real_superadmin = ((int)$row['atem'] === 1);
    }
}

if (!$_dev_is_real_superadmin) {
    http_response_code(403);
    echo 'SuperAdmin only.';
    exit;
}

$atem_id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
$action  = isset($_POST['action']) ? $_POST['action'] : '';

if ($atem_id > 0) {
    if (!isset($_SESSION['atem_dev_issuer_override']) || !is_array($_SESSION['atem_dev_issuer_override'])) {
        $_SESSION['atem_dev_issuer_override'] = array();
    }
    if ($action === 'off') {
        unset($_SESSION['atem_dev_issuer_override'][$atem_id]);
    } else {
        $_SESSION['atem_dev_issuer_override'][$atem_id] = true;
    }
}

$_dev_atem_base = '/odb/' . basename(dirname(__FILE__)) . '/';

$redirect = isset($_POST['redirect']) && $_POST['redirect'] !== ''
    ? $_POST['redirect']
    : $_dev_atem_base . 'index.php';

// Only allow relative redirects to prevent open redirect
if (strpos($redirect, '://') !== false) {
    $redirect = $_dev_atem_base . 'index.php';
}

header('Location: ' . $redirect);
exit;
