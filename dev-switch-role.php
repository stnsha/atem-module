<?php
/**
 * Development-only role switcher for ATEM testing.
 * Sets a session override for the atem grade and clears the JWT cache
 * so the next API request fetches a fresh token with the chosen role.
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

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo 'Method not allowed.';
    exit;
}

$allowed_roles = array(0, 1, 2, 3, 4, 5, 6);
$role_input    = isset($_POST['role']) ? $_POST['role'] : null;

if ($role_input === 'clear') {
    unset($_SESSION['atem_dev_role_override']);
} elseif ($role_input !== null) {
    $role = (int)$role_input;
    if (in_array($role, $allowed_roles)) {
        $_SESSION['atem_dev_role_override'] = $role;
    }
}

// Clear cached JWT so the next request gets a fresh token with the new role
unset($_SESSION['jwt_token']);
unset($_SESSION['jwt_expires']);

$redirect = isset($_POST['redirect']) && $_POST['redirect'] !== ''
    ? $_POST['redirect']
    : '/odb/atem/index.php';

// Only allow relative redirects to prevent open redirect
if (strpos($redirect, '://') !== false) {
    $redirect = '/odb/atem/index.php';
}

header('Location: ' . $redirect);
exit;
