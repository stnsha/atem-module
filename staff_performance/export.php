<?php
if (session_id() == '') { session_start(); }

$connect = 1;
include(dirname(__FILE__) . '/../../common/index_adv.php');

if (!isset($conn)) { http_response_code(500); exit('Database connection error'); }

// Auth check — mirror api.php session pattern
$staff_id     = null;
$caller_grade = 0;
$caller_atem  = 0;
if (isset($_SESSION['myusername'])) {
    $un     = $_SESSION['myusername'];
    $un_esc = mysqli_real_escape_string($conn, $un);
    $res    = mysqli_query($conn, "SELECT id, grade, atem FROM staff WHERE username = '$un_esc' AND recycle != 1");
    if ($res && mysqli_num_rows($res) > 0) {
        $row          = mysqli_fetch_assoc($res);
        $staff_id     = (int)$row['id'];
        $caller_grade = (int)$row['grade'];
        $caller_atem  = (int)$row['atem'];
    }
}
if (!$staff_id) { http_response_code(403); exit('Unauthorized'); }
$effective_grade = ($caller_atem === 1) ? 6 : $caller_grade;
if ($effective_grade < 4) { http_response_code(403); exit('Forbidden'); }

// Include API functions
define('API_JWT_INCLUDED', true);
include(dirname(__FILE__) . '/../api.php');

// Parse request params
$type           = isset($_GET['type'])     ? $_GET['type']            : '';
$filter_month   = isset($_GET['month'])    ? (int)$_GET['month']      : 0;
$filter_year    = isset($_GET['year'])     ? (int)$_GET['year']       : (int)date('Y');
$filter_quarter = isset($_GET['quarter'])  ? (int)$_GET['quarter']    : 0;
$filter_dept    = isset($_GET['dept'])     ? (int)$_GET['dept']       : 0;
$filter_grade   = isset($_GET['grade'])    ? (int)$_GET['grade']      : 0;
$filter_struct  = isset($_GET['struct'])   ? (int)$_GET['struct']     : 0;
$ids_raw        = isset($_GET['ids'])      ? trim($_GET['ids'])        : '';
$target_staff   = isset($_GET['staff_id']) ? (int)$_GET['staff_id']   : 0;

if ($filter_quarter < 1 || $filter_quarter > 4) { $filter_quarter = 0; }
if ($filter_quarter > 0) { $filter_month = 0; }

$ids = array();
if ($ids_raw !== '') {
    foreach (explode(',', $ids_raw) as $_id) {
        $_id = (int)trim($_id);
        if ($_id > 0) { $ids[] = $_id; }
    }
}

// Download completion cookie for progress bar detection
$dl_token = (isset($_GET['dl_token']) && $_GET['dl_token'] !== '')
    ? preg_replace('/[^a-zA-Z0-9]/', '', $_GET['dl_token'])
    : '';
if ($dl_token !== '') {
    setcookie('export_done_' . $dl_token, '1', time() + 120, '/');
}

// Build ODB lookup maps
$staff_names   = array();
$dept_names    = array();
$grade_labels  = array();
$struct_labels = array();

$sr = mysqli_query($conn, "SELECT id, nama_staff FROM staff WHERE recycle != 1");
if ($sr) { while ($r = mysqli_fetch_assoc($sr)) { $staff_names[(int)$r['id']] = $r['nama_staff']; } }
$dr = mysqli_query($conn, "SELECT id, depart_name FROM staff_department");
if ($dr) { while ($r = mysqli_fetch_assoc($dr)) { $dept_names[(int)$r['id']] = $r['depart_name']; } }
$gr = mysqli_query($conn, "SELECT id, grade_name FROM staff_grade ORDER BY id ASC");
if ($gr) { while ($r = mysqli_fetch_assoc($gr)) { $grade_labels[(int)$r['id']] = $r['grade_name']; } }
$str_r = mysqli_query($conn, "SELECT id, struct_name FROM staff_struct ORDER BY id ASC");
if ($str_r) { while ($r = mysqli_fetch_assoc($str_r)) { $struct_labels[(int)$r['id']] = $r['struct_name']; } }

// ----------------------------------------
// Shared helpers
// ----------------------------------------
function fmt_ex_date($d) {
    if (!$d) { return ''; }
    $ts = strtotime(substr($d, 0, 10));
    return $ts ? date('j/n/Y', $ts) : '';
}

function ex_status_val($a) {
    $s = isset($a['status']) ? $a['status'] : null;
    return is_array($s)
        ? (isset($s['value']) ? $s['value'] : '')
        : (string)(isset($s) ? $s : '');
}

function ex_level_val($a) {
    return (!empty($a['level_structure']) && is_array($a['level_structure']))
        ? (isset($a['level_structure']['level']) ? $a['level_structure']['level'] : '')
        : (isset($a['level_label']) ? $a['level_label'] : '');
}

// Emit one or more rows for one staff member's involvement in one ATEM.
// One row per ARCI role; if issuer-only (no ARCI), one row with ARCI blank.
// Reward per role: A -> a_incentive_amount; R -> r_incentive_amount / R-count; C/I/issuer-only -> 0.
function emit_atem_rows($out, $sid, $name, $dept, $grade, $struct, $a) {
    $atem_id   = '#AT' . (int)(isset($a['id']) ? $a['id'] : 0);
    $title     = isset($a['title']) ? $a['title'] : '';
    $level     = ex_level_val($a);
    $start     = fmt_ex_date(isset($a['start_date']) ? $a['start_date'] : '');
    $end_raw   = (!empty($a['is_extended']) && !empty($a['extended_date_1']))
                    ? $a['extended_date_1']
                    : (isset($a['end_date']) ? $a['end_date'] : '');
    $end       = fmt_ex_date($end_raw);
    $status    = ex_status_val($a);
    $is_issuer = (isset($a['issuer_staff_id']) && (int)$a['issuer_staff_id'] === $sid);
    $issuer_yn = $is_issuer ? 'Yes' : 'No';

    $start_raw = isset($a['start_date']) ? $a['start_date'] : '';
    $ex_year   = ($start_raw && strlen($start_raw) >= 7) ? (int)substr($start_raw, 0, 4) : '';
    $ex_month  = ($start_raw && strlen($start_raw) >= 7) ? (int)substr($start_raw, 5, 2) : '';

    $a_amount = isset($a['a_incentive_amount']) ? (float)$a['a_incentive_amount'] : 0.0;
    $r_amount = isset($a['r_incentive_amount']) ? (float)$a['r_incentive_amount'] : 0.0;

    $r_count = 0;
    if (!empty($a['arci']) && is_array($a['arci'])) {
        foreach ($a['arci'] as $m) {
            if (isset($m['role']) && $m['role'] === 'R') { $r_count++; }
        }
    }

    $roles = array();
    if (!empty($a['arci']) && is_array($a['arci'])) {
        foreach ($a['arci'] as $m) {
            if (!empty($m['staff_id']) && (int)$m['staff_id'] === $sid && !empty($m['role'])) {
                $roles[] = $m['role'];
            }
        }
    }

    if (!empty($roles)) {
        foreach ($roles as $role) {
            if ($role === 'A') {
                $reward = number_format($a_amount, 2);
            } elseif ($role === 'R') {
                $reward = number_format($r_count > 0 ? $r_amount / $r_count : 0.0, 2);
            } else {
                $reward = number_format(0, 2);
            }
            fputcsv($out, array(
                $ex_year, $ex_month,
                $name, $dept, $grade, $struct,
                $atem_id, $title, $level, $start, $end,
                $issuer_yn, $role, $status, $reward
            ));
        }
    } else {
        fputcsv($out, array(
            $ex_year, $ex_month,
            $name, $dept, $grade, $struct,
            $atem_id, $title, $level, $start, $end,
            $issuer_yn, '', $status, number_format(0, 2)
        ));
    }
}

$csv_headers = array(
    'Year', 'Month',
    'Name', 'Department', 'Grade', 'Evaluation Structure',
    'ATEM ID', 'ATEM Title', 'Level / Complexity',
    'Start Date', 'End Date', 'Issuer', 'ARCI', 'ATEM Status', 'Est. Reward (RM)'
);

// ----------------------------------------
// Export: ATEM records for a specific staff
// ----------------------------------------
if ($type === 'staff-atem') {
    if (!$target_staff) { http_response_code(400); exit('Missing staff_id'); }

    // Resolve target staff's dept / grade / struct from ODB
    $t_dept_id  = 0;
    $t_grade_id = 0;
    $t_struct_id = 0;
    $ts_res = mysqli_query($conn, "SELECT department, grade, struct FROM staff WHERE id = " . $target_staff . " AND recycle != 1");
    if ($ts_res && $tr = mysqli_fetch_assoc($ts_res)) {
        $t_dept_id   = (int)$tr['department'];
        $t_grade_id  = (int)$tr['grade'];
        $t_struct_id = (int)$tr['struct'];
    }
    $t_name   = isset($staff_names[$target_staff])     ? $staff_names[$target_staff]       : ('Staff #' . $target_staff);
    $t_dept   = ($t_dept_id   && isset($dept_names[$t_dept_id]))    ? $dept_names[$t_dept_id]    : '-';
    $t_grade  = ($t_grade_id  && isset($grade_labels[$t_grade_id])) ? $grade_labels[$t_grade_id] : '-';
    $t_struct = ($t_struct_id && isset($struct_labels[$t_struct_id])) ? $struct_labels[$t_struct_id] : '-';

    $atem_result = getStaffAtemList($target_staff, $staff_id);
    $atem_rows   = (!empty($atem_result['success']) && isset($atem_result['data'])) ? $atem_result['data'] : array();

    $sname    = preg_replace('/[^A-Za-z0-9_\-]/', '_', $t_name);
    $filename = 'atem_' . $sname . '_' . date('Y-m-d') . '.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-cache, no-store, must-revalidate');
    $out = fopen('php://output', 'w');
    fputs($out, "\xEF\xBB\xBF");
    fputcsv($out, $csv_headers);

    foreach ($atem_rows as $a) {
        $iid = isset($a['issuer_staff_id']) ? (int)$a['issuer_staff_id'] : 0;
        $inv = ($iid === $target_staff);
        if (!$inv && !empty($a['arci']) && is_array($a['arci'])) {
            foreach ($a['arci'] as $m) {
                if (!empty($m['staff_id']) && (int)$m['staff_id'] === $target_staff) {
                    $inv = true;
                    break;
                }
            }
        }
        if (!$inv) { continue; }
        emit_atem_rows($out, $target_staff, $t_name, $t_dept, $t_grade, $t_struct, $a);
    }

    fclose($out);
    exit;
}

// ----------------------------------------
// Export: performance records (bulk)
// ----------------------------------------
if ($type === 'performance') {
    $quarter_months = array(1=>array(1,2,3), 2=>array(4,5,6), 3=>array(7,8,9), 4=>array(10,11,12));
    $records = array();

    if ($filter_quarter > 0) {
        foreach ($quarter_months[$filter_quarter] as $qm) {
            $res2 = getBonusEligibilityList($qm, $filter_year, null, $staff_id);
            if (!empty($res2['success']) && !empty($res2['data'])) {
                foreach ($res2['data'] as $r) { $records[] = $r; }
            }
        }
    } else {
        $res2    = getBonusEligibilityList($filter_month, $filter_year, null, $staff_id);
        $records = (!empty($res2['success']) && isset($res2['data'])) ? $res2['data'] : array();
    }

    // Apply filters
    $out_records = array();
    foreach ($records as $rec) {
        if (!empty($ids) && !in_array((int)(isset($rec['id']) ? $rec['id'] : 0), $ids)) { continue; }
        if ($filter_dept   > 0 && (int)(isset($rec['staff_dept_id']) ? $rec['staff_dept_id'] : 0) !== $filter_dept)   { continue; }
        if ($filter_grade  > 0 && (int)(isset($rec['staff_grade'])   ? $rec['staff_grade']   : 0) !== $filter_grade)  { continue; }
        if ($filter_struct > 0 && (int)(isset($rec['staff_struct'])  ? $rec['staff_struct']  : 0) !== $filter_struct) { continue; }
        $out_records[] = $rec;
    }

    // Fetch all ATEMs once
    $all_atems = array();
    $_atem_res = getApiDataWithJWT('atem', null, 'GET', $staff_id);
    if (isset($_atem_res['httpCode']) && $_atem_res['httpCode'] === 200) {
        $_atem_dec = json_decode($_atem_res['response'], true);
        $all_atems = (isset($_atem_dec['data']) && is_array($_atem_dec['data'])) ? $_atem_dec['data'] : array();
    }

    $filename = 'staff_performance_' . date('Y-m-d') . '.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-cache, no-store, must-revalidate');
    $out = fopen('php://output', 'w');
    fputs($out, "\xEF\xBB\xBF");
    fputcsv($out, $csv_headers);

    foreach ($out_records as $rec) {
        $sid       = isset($rec['staff_id'])      ? (int)$rec['staff_id']      : 0;
        $dept_id   = isset($rec['staff_dept_id']) ? (int)$rec['staff_dept_id'] : 0;
        $grade_id  = isset($rec['staff_grade'])   ? (int)$rec['staff_grade']   : 0;
        $struct_id = isset($rec['staff_struct'])  ? (int)$rec['staff_struct']  : 0;

        $p_name   = isset($staff_names[$sid])                                    ? $staff_names[$sid]         : ('Staff #' . $sid);
        $p_dept   = ($dept_id   && isset($dept_names[$dept_id]))                 ? $dept_names[$dept_id]      : '-';
        $p_grade  = ($grade_id  && isset($grade_labels[$grade_id]))              ? $grade_labels[$grade_id]   : '-';
        $p_struct = ($struct_id && isset($struct_labels[$struct_id]))            ? $struct_labels[$struct_id] : '-';

        foreach ($all_atems as $_a) {
            $iid = isset($_a['issuer_staff_id']) ? (int)$_a['issuer_staff_id'] : 0;
            $inv = ($iid === $sid);
            if (!$inv && !empty($_a['arci']) && is_array($_a['arci'])) {
                foreach ($_a['arci'] as $_m) {
                    if (!empty($_m['staff_id']) && (int)$_m['staff_id'] === $sid) {
                        $inv = true;
                        break;
                    }
                }
            }
            if (!$inv) { continue; }
            emit_atem_rows($out, $sid, $p_name, $p_dept, $p_grade, $p_struct, $_a);
        }
    }

    fclose($out);
    exit;
}

http_response_code(400);
exit('Invalid export type');
