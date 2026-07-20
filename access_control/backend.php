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

$_be_serverName = isset($_SERVER['SERVER_NAME']) ? $_SERVER['SERVER_NAME'] : '';
$_be_httpHost   = isset($_SERVER['HTTP_HOST'])   ? $_SERVER['HTTP_HOST']   : '';
$_be_isLocal    = in_array($_be_serverName, array('localhost', '127.0.0.1'))
    || strpos($_be_serverName, 'localhost') !== false
    || strpos($_be_httpHost,   'localhost') !== false
    || strpos($_be_httpHost,   '127.0.0.1') !== false;

// Always query DB to get requester identity and department
$username    = mysqli_real_escape_string($conn, $_SESSION['myusername']);
$auth_result = mysqli_query($conn, "SELECT id, grade, atem, okr, department FROM staff WHERE username = '$username' AND recycle != 1");
if (!$auth_result || mysqli_num_rows($auth_result) === 0) {
    echo json_encode(array('error' => 'Unauthorized'));
    exit;
}
$auth_row          = mysqli_fetch_assoc($auth_result);
$requester_id      = (int)$auth_row['id'];
$requester_dept_ids = array();
foreach (explode(',', (string)$auth_row['department']) as $_d) {
    $_d = (int)trim($_d);
    if ($_d > 0) {
        $requester_dept_ids[] = $_d;
    }
}

// Always reflects the real DB SuperAdmin flags — used for administrative
// actions (library management, window toggle) that dev override should not
// suppress. SuperAdmin is the union of staff.atem and staff.okr.
$db_is_superadmin = ((int)$auth_row['atem'] === 1 || (int)$auth_row['okr'] === 1);

// Dev grade override applies to grade only; department always from DB.
// $requester_is_superadmin is suppressed when dev override is active so devs
// can simulate lower-grade struct-lock behaviour.
if ($_be_isLocal && isset($_SESSION['atem_dev_role_override'])) {
    $requester_grade         = (int)$_SESSION['atem_dev_role_override'];
    $requester_is_superadmin = false;
} else {
    $requester_is_superadmin = $db_is_superadmin;
    $requester_grade         = $requester_is_superadmin ? 6 : (int)$auth_row['grade'];
}

// Quarter window helpers
function getCurrentQuarter() {
    $month = (int)date('n');
    if ($month <= 3) return 1;
    if ($month <= 6) return 2;
    if ($month <= 9) return 3;
    return 4;
}
function isInStructWindow() {
    $day = (int)date('j');
    return ($day >= 1 && $day <= 10);
}

function attachOutletCodes($conn, $staff_list)
{
    $ids = [];
    foreach ($staff_list as $s) {
        foreach (explode(',', $s['outlet_raw']) as $oid) {
            $oid = (int)trim($oid);
            if ($oid > 0) {
                $ids[$oid] = true;
            }
        }
    }

    $code_map = [];
    if (!empty($ids)) {
        $ids_sql = implode(',', array_keys($ids));
        $r = mysqli_query($conn, "SELECT id, code FROM outlet WHERE id IN ($ids_sql)");
        if ($r) {
            while ($row = mysqli_fetch_assoc($r)) {
                $code_map[(int)$row['id']] = $row['code'];
            }
        }
    }

    foreach ($staff_list as &$s) {
        $codes = [];
        foreach (explode(',', $s['outlet_raw']) as $oid) {
            $oid = (int)trim($oid);
            if ($oid > 0 && isset($code_map[$oid])) {
                $codes[] = $code_map[$oid];
            }
        }
        $s['outlet_codes'] = $codes;
        unset($s['outlet_raw']);
    }
    unset($s);

    return $staff_list;
}
$current_year    = (int)date('Y');
$current_quarter = getCurrentQuarter();
$in_window       = isInStructWindow();

// SuperAdmin can globally override the struct update window
$cfg_r = mysqli_query($conn, "SELECT setting_value FROM atem_config WHERE setting_key = 'struct_window_override'");
if ($cfg_r && ($cfg_row = mysqli_fetch_assoc($cfg_r)) && $cfg_row['setting_value'] === '1') {
    $in_window = true;
}

if ($requester_grade < 1) {
    echo json_encode(array('error' => 'Unauthorized'));
    exit;
}

$action = isset($_GET['action']) ? $_GET['action'] : '';

if ($action === 'getActiveStaff' && isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'GET') {

    if ($requester_grade === 1) {
        $query  = "SELECT s.id, s.nama_staff, s.grade, s.status_semasa, s.struct,
                          s.department, d.depart_name, st.struct_name, s.outlet
                   FROM staff s
                   LEFT JOIN staff_department d  ON s.department = d.id
                   LEFT JOIN staff_struct     st ON s.struct      = st.id
                   WHERE s.recycle != 1 AND s.id = $requester_id";
        $result = mysqli_query($conn, $query);
        $staff  = array();
        if ($result) {
            while ($row = mysqli_fetch_assoc($result)) {
                $staff[] = array(
                    'id'              => (int)$row['id'],
                    'nama_staff'      => $row['nama_staff'],
                    'grade'           => (int)$row['grade'],
                    'status_semasa'   => $row['status_semasa']  ? $row['status_semasa']  : '-',
                    'department_name' => $row['depart_name']    ? $row['depart_name']    : '-',
                    'dept_raw'        => $row['department']     ? $row['department']     : '0',
                    'struct_id'       => ($row['struct'] !== null) ? (int)$row['struct'] : 0,
                    'struct_name'     => $row['struct_name']    ? $row['struct_name']    : '-',
                    'outlet_raw'      => $row['outlet']         ? $row['outlet']         : ''
                );
            }
        }
        $staff = attachOutletCodes($conn, $staff);
        echo json_encode(array(
            'success'     => true,
            'data'        => $staff,
            'total'       => count($staff),
            'page'        => 1,
            'per_page'    => count($staff),
            'total_pages' => 1
        ));
        exit;
    }

    $page    = isset($_GET['page'])     ? max(1, (int)$_GET['page'])               : 1;
    $perPage = isset($_GET['per_page']) ? max(5, min(100, (int)$_GET['per_page'])) : 30;
    $offset  = ($page - 1) * $perPage;

    $name_filter_raw = isset($_GET['name_filter']) ? trim($_GET['name_filter']) : '';
    $name_filter     = ($name_filter_raw !== '') ? mysqli_real_escape_string($conn, $name_filter_raw) : '';
    $dept_filter     = isset($_GET['dept_filter']) ? (int)$_GET['dept_filter'] : 0;

    $name_sql       = ($name_filter !== '') ? "AND s.nama_staff LIKE '%$name_filter%'" : '';
    $name_sql_count = ($name_filter !== '') ? "AND nama_staff LIKE '%$name_filter%'"   : '';

    // For grades 2-3, silently discard dept_filter that falls outside their allowed departments
    if ($dept_filter > 0 && $requester_grade <= 3 && !$requester_is_superadmin && !in_array($dept_filter, $requester_dept_ids)) {
        $dept_filter = 0;
    }
    $dept_filter_sql       = ($dept_filter > 0) ? "AND FIND_IN_SET($dept_filter, s.department)"   : '';
    $dept_filter_sql_count = ($dept_filter > 0) ? "AND FIND_IN_SET($dept_filter, department)"     : '';

    if ($requester_grade <= 3 && !$requester_is_superadmin) {
        $dept_conds       = array();
        $dept_conds_count = array();
        foreach ($requester_dept_ids as $_did) {
            $dept_conds[]       = "FIND_IN_SET($_did, s.department)";
            $dept_conds_count[] = "FIND_IN_SET($_did, department)";
        }
        $dept_sql           = count($dept_conds)       ? '(' . implode(' OR ', $dept_conds) . ')'       : '1=0';
        $dept_sql_count     = count($dept_conds_count) ? '(' . implode(' OR ', $dept_conds_count) . ')' : '1=0';
        $where_clause       = "s.recycle != 1 AND s.grade > 0 AND $dept_sql $name_sql $dept_filter_sql";
        $where_clause_count = "recycle != 1 AND grade > 0 AND $dept_sql_count $name_sql_count $dept_filter_sql_count";
    } else {
        $where_clause       = "s.recycle != 1 AND s.grade > 0 $name_sql $dept_filter_sql";
        $where_clause_count = "recycle != 1 AND grade > 0 $name_sql_count $dept_filter_sql_count";
    }

    $count_result = mysqli_query($conn, "SELECT COUNT(*) as total FROM staff WHERE $where_clause_count");
    $total = 0;
    if ($count_result) {
        $count_row = mysqli_fetch_assoc($count_result);
        $total     = (int)$count_row['total'];
    }

    $query = "SELECT s.id, s.nama_staff, s.grade, s.status_semasa, s.struct,
                     s.department, d.depart_name, st.struct_name, s.outlet
              FROM staff s
              LEFT JOIN staff_department d  ON s.department = d.id
              LEFT JOIN staff_struct     st ON s.struct      = st.id
              WHERE $where_clause
              ORDER BY s.grade ASC, s.nama_staff ASC
              LIMIT $perPage OFFSET $offset";
    $result = mysqli_query($conn, $query);

    $staff = array();
    if ($result) {
        while ($row = mysqli_fetch_assoc($result)) {
            $staff[] = array(
                'id'              => (int)$row['id'],
                'nama_staff'      => $row['nama_staff'],
                'grade'           => (int)$row['grade'],
                'status_semasa'   => $row['status_semasa']  ? $row['status_semasa']  : '-',
                'department_name' => $row['depart_name']    ? $row['depart_name']    : '-',
                'dept_raw'        => $row['department']     ? $row['department']     : '0',
                'struct_id'       => ($row['struct'] !== null) ? (int)$row['struct'] : 0,
                'struct_name'     => $row['struct_name']    ? $row['struct_name']    : '-',
                'outlet_raw'      => $row['outlet']         ? $row['outlet']         : ''
            );
        }
    }
    $staff = attachOutletCodes($conn, $staff);

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

if ($action === 'searchStaff' && isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($requester_grade === 1) {
        echo json_encode(array());
        exit;
    }

    $search_term = isset($_POST['search_term']) ? mysqli_real_escape_string($conn, trim($_POST['search_term'])) : '';
    if ($search_term === '') {
        echo json_encode(array());
        exit;
    }

    if ($requester_grade <= 3 && !$requester_is_superadmin) {
        $dept_conds = array();
        foreach ($requester_dept_ids as $_did) {
            $dept_conds[] = "FIND_IN_SET($_did, s.department)";
        }
        $dept_filter = count($dept_conds) ? 'AND (' . implode(' OR ', $dept_conds) . ')' : 'AND 1=0';
    } else {
        $dept_filter = '';
    }

    $query = "SELECT s.id, s.nama_staff, s.grade, s.status_semasa, s.struct,
                     s.department, d.depart_name, st.struct_name, s.outlet
              FROM staff s
              LEFT JOIN staff_department d  ON s.department = d.id
              LEFT JOIN staff_struct     st ON s.struct      = st.id
              WHERE s.nama_staff LIKE '%$search_term%'
              AND s.recycle != 1
              $dept_filter
              ORDER BY s.nama_staff ASC
              LIMIT 20";
    $result = mysqli_query($conn, $query);

    $staff = array();
    if ($result) {
        while ($row = mysqli_fetch_assoc($result)) {
            $staff[] = array(
                'id'              => (int)$row['id'],
                'nama_staff'      => $row['nama_staff'],
                'grade'           => (int)$row['grade'],
                'status_semasa'   => $row['status_semasa']  ? $row['status_semasa']  : '-',
                'department_name' => $row['depart_name']    ? $row['depart_name']    : '-',
                'dept_raw'        => $row['department']     ? $row['department']     : '0',
                'struct_id'       => ($row['struct'] !== null) ? (int)$row['struct'] : 0,
                'struct_name'     => $row['struct_name']    ? $row['struct_name']    : '-',
                'outlet_raw'      => $row['outlet']         ? $row['outlet']         : ''
            );
        }
    }
    $staff = attachOutletCodes($conn, $staff);

    echo json_encode($staff);
    exit;
}

if ($action === 'updateAccess' && isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($requester_grade < 2) {
        echo json_encode(array('success' => false, 'message' => 'Unauthorized.'));
        exit;
    }

    $target_id = isset($_POST['staff_id'])  ? (int)$_POST['staff_id']  : 0;
    $grade     = isset($_POST['grade'])     ? (int)$_POST['grade']     : -1;
    $struct_id = isset($_POST['struct_id']) ? (int)$_POST['struct_id'] : 0;

    $valid_grades = array(0, 1, 2, 3, 4, 5);

    if ($target_id <= 0 || !in_array($grade, $valid_grades)) {
        echo json_encode(array('success' => false, 'message' => 'Invalid input.'));
        exit;
    }

    if (!$requester_is_superadmin) {
        $dept_check = mysqli_query($conn, "SELECT department FROM staff WHERE id = $target_id AND recycle != 1");
        if (!$dept_check || mysqli_num_rows($dept_check) === 0) {
            echo json_encode(array('success' => false, 'message' => 'Staff not found.'));
            exit;
        }
        $dept_row        = mysqli_fetch_assoc($dept_check);
        $target_dept_ids = array();
        foreach (explode(',', (string)$dept_row['department']) as $_d) {
            $_d = (int)trim($_d);
            if ($_d > 0) {
                $target_dept_ids[] = $_d;
            }
        }
        if (empty(array_intersect($requester_dept_ids, $target_dept_ids))) {
            echo json_encode(array('success' => false, 'message' => 'You can only update staff in your own department.'));
            exit;
        }
    }

    $struct_id_safe = max(0, $struct_id);

    // Detect whether the struct value is actually changing
    $cur_result      = mysqli_query($conn, "SELECT struct FROM staff WHERE id = $target_id AND recycle != 1");
    $cur_row         = $cur_result ? mysqli_fetch_assoc($cur_result) : null;
    $struct_changing = ($cur_row !== null && (int)$cur_row['struct'] !== $struct_id_safe);

    // Check history existence once; reused for quota enforcement and insert decision
    $hist_exists = false;
    if ($struct_changing) {
        $hist_check  = mysqli_query($conn, "SELECT id FROM staff_struct_history WHERE staff_id = $target_id AND year = $current_year AND quarter = $current_quarter");
        $hist_exists = ($hist_check && mysqli_num_rows($hist_check) > 0);
    }

    // Struct changes require the window to be open for non-superadmin; re-updates within the window are allowed
    if ($struct_changing && !$requester_is_superadmin) {
        if (!$in_window) {
            echo json_encode(array('success' => false, 'message' => 'Evaluation structure can only be updated between the 1st and 10th of each quarter.'));
            exit;
        }
    }

    $update = "UPDATE staff SET grade = $grade, struct = $struct_id_safe WHERE id = $target_id AND recycle != 1";
    if (mysqli_query($conn, $update)) {
        if ($struct_changing) {
            if (!$hist_exists) {
                mysqli_query($conn, "INSERT INTO staff_struct_history (staff_id, struct, year, quarter) VALUES ($target_id, $struct_id_safe, $current_year, $current_quarter)");
            } else {
                mysqli_query($conn, "UPDATE staff_struct_history SET struct = $struct_id_safe WHERE staff_id = $target_id AND year = $current_year AND quarter = $current_quarter");
            }
        }
        echo json_encode(array('success' => true, 'message' => 'Updated successfully.'));
    } else {
        echo json_encode(array('success' => false, 'message' => 'Database error: ' . mysqli_error($conn)));
    }
    exit;
}

if ($action === 'getStructHistory' && isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $target_id = isset($_GET['staff_id']) ? (int)$_GET['staff_id'] : 0;
    if ($target_id <= 0) {
        echo json_encode(array('success' => false, 'message' => 'Invalid staff.'));
        exit;
    }

    $history = array();
    $hist_result = mysqli_query($conn,
        "SELECT h.year, h.quarter, h.struct, ss.struct_name
         FROM staff_struct_history h
         LEFT JOIN staff_struct ss ON h.struct = ss.id
         WHERE h.staff_id = $target_id
         ORDER BY h.year DESC, h.quarter DESC
         LIMIT 12"
    );
    if ($hist_result) {
        while ($row = mysqli_fetch_assoc($hist_result)) {
            $history[] = array(
                'year'        => (int)$row['year'],
                'quarter'     => (int)$row['quarter'],
                'struct_id'   => (int)$row['struct'],
                'struct_name' => $row['struct_name'] ? $row['struct_name'] : '-'
            );
        }
    }

    // Determine lock state for this requester + target
    $struct_locked = false;
    $lock_reason   = '';
    if (!$requester_is_superadmin) {
        if (!$in_window) {
            $struct_locked = true;
            $lock_reason   = 'Outside update window (1st-10th of each quarter)';
        }
    }

    echo json_encode(array(
        'success'      => true,
        'history'      => $history,
        'struct_locked' => $struct_locked,
        'lock_reason'  => $lock_reason
    ));
    exit;
}

if ($action === 'getLibrary' && isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $grades  = array();
    $structs = array();

    $r = mysqli_query($conn, "SELECT id, grade_name, is_active FROM staff_grade ORDER BY id ASC");
    if ($r) {
        while ($row = mysqli_fetch_assoc($r)) {
            $grades[] = array('id' => (int)$row['id'], 'label' => $row['grade_name'], 'is_active' => (int)$row['is_active']);
        }
    }

    $r = mysqli_query($conn, "SELECT id, struct_name, is_active FROM staff_struct ORDER BY id ASC");
    if ($r) {
        while ($row = mysqli_fetch_assoc($r)) {
            $structs[] = array('id' => (int)$row['id'], 'label' => $row['struct_name'], 'is_active' => (int)$row['is_active']);
        }
    }

    echo json_encode(array('success' => true, 'grades' => $grades, 'structs' => $structs));
    exit;
}

if ($action === 'updateLibrary' && isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$db_is_superadmin) {
        echo json_encode(array('success' => false, 'message' => 'Unauthorized.'));
        exit;
    }

    $type      = isset($_POST['type'])       ? $_POST['type']            : '';
    $id        = isset($_POST['id'])         ? (int)$_POST['id']         : -1;
    $has_label = isset($_POST['label']);
    $label     = $has_label ? trim($_POST['label']) : '';
    $has_active = isset($_POST['is_active']);
    $is_active  = $has_active ? ((int)$_POST['is_active'] === 1 ? 1 : 0) : 0;

    if (!in_array($type, array('grade', 'struct')) || $id < 0) {
        echo json_encode(array('success' => false, 'message' => 'Invalid input.'));
        exit;
    }
    if ($has_label && $label === '') {
        echo json_encode(array('success' => false, 'message' => 'Invalid input.'));
        exit;
    }
    if (!$has_label && !$has_active) {
        echo json_encode(array('success' => false, 'message' => 'Nothing to update.'));
        exit;
    }

    $table = ($type === 'grade') ? 'staff_grade' : 'staff_struct';
    $col   = ($type === 'grade') ? 'grade_name'  : 'struct_name';

    $set_parts = [];
    if ($has_label) {
        $label_escaped = mysqli_real_escape_string($conn, $label);
        $set_parts[]   = "`$col` = '$label_escaped'";
    }
    if ($has_active) {
        $set_parts[] = "`is_active` = " . $is_active;
    }

    $result = mysqli_query($conn, "UPDATE `$table` SET " . implode(', ', $set_parts) . " WHERE id = $id");
    if ($result && mysqli_affected_rows($conn) >= 0) {
        $message = $has_label ? 'Label updated successfully.' : 'Status updated successfully.';
        echo json_encode(array('success' => true, 'message' => $message));
    } else {
        echo json_encode(array('success' => false, 'message' => 'Database error: ' . mysqli_error($conn)));
    }
    exit;
}

if ($action === 'addLibrary' && isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$db_is_superadmin) {
        echo json_encode(array('success' => false, 'message' => 'Unauthorized.'));
        exit;
    }

    $type  = isset($_POST['type'])  ? $_POST['type']       : '';
    $label = isset($_POST['label']) ? trim($_POST['label']) : '';

    if (!in_array($type, array('grade', 'struct')) || $label === '') {
        echo json_encode(array('success' => false, 'message' => 'Invalid input.'));
        exit;
    }

    $table         = ($type === 'grade') ? 'staff_grade' : 'staff_struct';
    $col           = ($type === 'grade') ? 'grade_name'  : 'struct_name';
    $label_escaped = mysqli_real_escape_string($conn, $label);

    $max_result = mysqli_query($conn, "SELECT COALESCE(MAX(id), -1) + 1 AS next_id FROM `$table`");
    if (!$max_result) {
        echo json_encode(array('success' => false, 'message' => 'Database error: ' . mysqli_error($conn)));
        exit;
    }
    $max_row = mysqli_fetch_assoc($max_result);
    $next_id = $max_row ? (int)$max_row['next_id'] : 0;

    $insert = "INSERT INTO `$table` (id, `$col`) VALUES ($next_id, '$label_escaped')";

    if (mysqli_query($conn, $insert)) {
        echo json_encode(array('success' => true, 'message' => 'Entry added successfully.', 'id' => $next_id));
    } else {
        echo json_encode(array('success' => false, 'message' => 'Database error: ' . mysqli_error($conn)));
    }
    exit;
}

echo json_encode(array('error' => 'Unknown action'));
