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

// Lists staff for the SuperAdmin Access panel, with an optional name search.
// Not department-scoped like access_control's staff list - ATEM/OKR SuperAdmin
// is a site-wide flag, not a departmental permission.
if ($action === 'getSuperAdminStaff' && isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $page    = isset($_GET['page'])     ? max(1, (int)$_GET['page'])               : 1;
    $perPage = isset($_GET['per_page']) ? max(5, min(100, (int)$_GET['per_page'])) : 30;
    $offset  = ($page - 1) * $perPage;

    $name_filter_raw = isset($_GET['name_filter']) ? trim($_GET['name_filter']) : '';
    $name_filter      = ($name_filter_raw !== '') ? mysqli_real_escape_string($conn, $name_filter_raw) : '';
    $name_sql         = ($name_filter !== '') ? "AND s.nama_staff LIKE '%$name_filter%'" : '';
    $name_sql_count   = ($name_filter !== '') ? "AND nama_staff LIKE '%$name_filter%'"   : '';

    // With no name search, only show staff who are already an ATEM or OKR
    // admin (a roster of current SuperAdmins, not the full staff directory).
    // A name search still reaches every staff member regardless of admin
    // status, so a brand-new admin can still be found and promoted.
    $admin_only_sql       = ($name_filter === '') ? 'AND (atem != 0 OR okr != 0)' : '';
    $admin_only_sql_count = $admin_only_sql;

    $count_result = mysqli_query($conn, "SELECT COUNT(*) as total FROM staff WHERE recycle != 1 $name_sql_count $admin_only_sql_count");
    $total = 0;
    if ($count_result) {
        $count_row = mysqli_fetch_assoc($count_result);
        $total     = (int)$count_row['total'];
    }

    $query = "SELECT s.id, s.nama_staff, s.atem, s.okr, d.depart_name
              FROM staff s
              LEFT JOIN staff_department d ON s.department = d.id
              WHERE s.recycle != 1 $name_sql $admin_only_sql
              ORDER BY s.nama_staff ASC
              LIMIT $perPage OFFSET $offset";
    $result = mysqli_query($conn, $query);

    $staff = array();
    if ($result) {
        while ($row = mysqli_fetch_assoc($result)) {
            $staff[] = array(
                'id'              => (int)$row['id'],
                'nama_staff'      => $row['nama_staff'],
                'atem'            => (int)$row['atem'],
                'okr'             => (int)$row['okr'],
                'department_name' => $row['depart_name'] ? $row['depart_name'] : '-'
            );
        }
    }

    echo json_encode(array(
        'success'     => true,
        'data'        => $staff,
        'total'       => $total,
        'page'        => $page,
        'per_page'    => $perPage,
        'total_pages' => (int)ceil($total / $perPage)
    ));
    exit;
}

// Assigns/revokes the staff.atem and staff.okr SuperAdmin flags for one staff
// member. Both columns are written together since the panel edits them as a
// pair, but they remain independent flags everywhere else they're checked.
if ($action === 'updateSuperAdmin' && isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $target_id = isset($_POST['staff_id']) ? (int)$_POST['staff_id'] : 0;
    $atem_val  = (isset($_POST['atem']) && (int)$_POST['atem'] === 1) ? 1 : 0;
    $okr_val   = (isset($_POST['okr'])  && (int)$_POST['okr']  === 1) ? 1 : 0;

    if ($target_id <= 0) {
        echo json_encode(array('success' => false, 'message' => 'Invalid staff.'));
        exit;
    }

    $check = mysqli_query($conn, "SELECT id FROM staff WHERE id = $target_id AND recycle != 1");
    if (!$check || mysqli_num_rows($check) === 0) {
        echo json_encode(array('success' => false, 'message' => 'Staff not found.'));
        exit;
    }

    $update = "UPDATE staff SET atem = $atem_val, okr = $okr_val WHERE id = $target_id AND recycle != 1";
    if (mysqli_query($conn, $update)) {
        echo json_encode(array('success' => true, 'message' => 'Updated successfully.'));
    } else {
        echo json_encode(array('success' => false, 'message' => 'Database error: ' . mysqli_error($conn)));
    }
    exit;
}

echo json_encode(array('error' => 'Unknown action'));
