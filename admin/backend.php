<?php
date_default_timezone_set('Asia/Kuala_Lumpur');
header('Content-Type: application/json');

if (session_id() == '') {
    session_start();
}

if (!isset($_SESSION['myusername'])) {
    echo json_encode(array('error' => 'Unauthorized'));
    exit;
}

$connect = 1;
include(__DIR__ . '/../../common/index_adv.php');

if (!isset($conn)) {
    echo json_encode(array('error' => 'Database connection error'));
    exit;
}

$username    = mysqli_real_escape_string($conn, $_SESSION['myusername']);
$auth_result = mysqli_query($conn, "SELECT atem, okr FROM staff WHERE username = '$username' AND recycle != 1");
if (!$auth_result || mysqli_num_rows($auth_result) === 0) {
    echo json_encode(array('error' => 'Unauthorized'));
    exit;
}
$auth_row         = mysqli_fetch_assoc($auth_result);
// SuperAdmin is the union of staff.atem and staff.okr.
$db_is_superadmin = ((int)$auth_row['atem'] === 1 || (int)$auth_row['okr'] === 1);

if (!$db_is_superadmin) {
    echo json_encode(array('error' => 'Unauthorized'));
    exit;
}

$action = isset($_GET['action']) ? $_GET['action'] : '';

if ($action === 'toggleStructWindow' && isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $new_value = (isset($_POST['value']) && (int)$_POST['value'] === 1) ? '1' : '0';
    mysqli_query($conn,
        "INSERT INTO atem_config (setting_key, setting_value) VALUES ('struct_window_override', '$new_value')
         ON DUPLICATE KEY UPDATE setting_value = '$new_value'"
    );
    echo json_encode(array('success' => true, 'value' => (int)$new_value));
    exit;
}

if ($action === 'toggleBackdate' && isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $new_value = (isset($_POST['value']) && (int)$_POST['value'] === 1) ? '1' : '0';
    mysqli_query($conn,
        "INSERT INTO atem_config (setting_key, setting_value) VALUES ('backdate_enabled', '$new_value')
         ON DUPLICATE KEY UPDATE setting_value = '$new_value'"
    );
    echo json_encode(array('success' => true, 'value' => (int)$new_value));
    exit;
}

// Writes okr_config.backdate_enabled - OKR has no admin page of its own, so
// its backdate toggle lives here alongside ATEM's, gated by the same
// combined SuperAdmin check above.
if ($action === 'toggleOkrBackdate' && isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $new_value = (isset($_POST['value']) && (int)$_POST['value'] === 1) ? '1' : '0';
    mysqli_query($conn,
        "INSERT INTO okr_config (setting_key, setting_value) VALUES ('backdate_enabled', '$new_value')
         ON DUPLICATE KEY UPDATE setting_value = '$new_value'"
    );
    echo json_encode(array('success' => true, 'value' => (int)$new_value));
    exit;
}

echo json_encode(array('error' => 'Unknown action'));
