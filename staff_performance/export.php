<?php
if (session_id() == '') { session_start(); }

$connect = 1;
include(dirname(__FILE__) . '/../../common/index_adv.php');

if (!isset($conn)) { http_response_code(500); exit('Database connection error'); }

// Auth check — mirror api.php session pattern
$staff_id       = null;
$caller_grade   = 0;
$caller_atem    = 0;
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
// Access: grade 3+ or SuperAdmin (folded into $effective_grade = 6 above) —
// mirrors staff_performance/index.php's page guard and api.php's list/
// lock/unlock permission checks.
if ($effective_grade < 3) { http_response_code(403); exit('Forbidden'); }

// Include API functions
define('API_JWT_INCLUDED', true);
include(dirname(__FILE__) . '/../api.php');

// Parse request params
$type           = isset($_GET['type'])     ? $_GET['type']            : '';
$filter_month   = isset($_GET['month'])    ? (int)$_GET['month']      : 0;
$filter_year    = isset($_GET['year'])     ? (int)$_GET['year']       : (int)date('Y');
$filter_quarter = isset($_GET['quarter'])  ? atem_parse_quarters($_GET['quarter']) : array();
$filter_dept    = isset($_GET['dept'])     ? (int)$_GET['dept']       : 0;
$filter_grade   = isset($_GET['grade'])    ? (int)$_GET['grade']      : 0;
$filter_struct  = isset($_GET['struct'])   ? (int)$_GET['struct']     : 0;
$ids_raw        = isset($_GET['ids'])      ? trim($_GET['ids'])        : '';
$target_staff   = isset($_GET['staff_id']) ? (int)$_GET['staff_id']   : 0;
$statuses_raw   = isset($_GET['statuses']) ? trim($_GET['statuses'])   : '';
// Distinct from $target_staff (used only by the single-staff 'staff-atem' export
// type) — this is the Staff filter on the bulk 'performance' export/table.
$filter_staff_id = isset($_GET['staff_filter_id']) ? (int)$_GET['staff_filter_id'] : 0;

if (!empty($filter_quarter)) { $filter_month = 0; }

// Whitelist against the 6 selectable statuses (see atem_performance_status_options()
// in api.php); defaults to Completed + Completed with Excellence + Completed with
// Extension, matching the Staff Performance page's own default, if the caller
// didn't specify any.
$filter_statuses = array();
if ($statuses_raw !== '') {
    foreach (explode(',', $statuses_raw) as $_st) {
        $_st = trim($_st);
        if ($_st !== '') { $filter_statuses[] = $_st; }
    }
    $filter_statuses = array_values(array_intersect($filter_statuses, atem_performance_status_options()));
}
if (empty($filter_statuses)) {
    $filter_statuses = array('Completed', 'Completed with Excellence', 'Completed with Extension');
}

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
// Reward per role: A -> a_incentive_amount split among incentivised A members;
// R -> r_incentive_amount split among incentivised R members; a non-incentivised
// A/R member (or C/I/issuer-only) gets 0 - mirrors api.php's
// getStaffPerformanceLive() exactly, so this matches the on-screen table.
// $blank_reward is used for Outlet ATEM rows (atem_type=2) - Outlet ATEM no
// longer carries any incentive concept (Est. Reward on the on-screen table
// stays HQ-only), so the Est. Reward column is left blank instead of 0.00.
// 'Record Type' is always 'ATEM' here - shares the same column layout as
// emit_okr_rows() below so ATEM and OKR rows can sit in one flat CSV.
function emit_atem_rows($out, $sid, $name, $dept, $grade, $struct, $a, $blank_reward = false) {
    $atem_id   = '#AT' . (int)(isset($a['id']) ? $a['id'] : 0);
    $title     = isset($a['title']) ? $a['title'] : '';
    $level     = ex_level_val($a);
    $start     = fmt_ex_date(isset($a['start_date']) ? $a['start_date'] : '');
    $end_raw   = (!empty($a['is_extended']) && !empty($a['extended_date_1']))
                    ? $a['extended_date_1']
                    : (isset($a['end_date']) ? $a['end_date'] : '');
    $end       = fmt_ex_date($end_raw);
    $closure   = fmt_ex_date(isset($a['closure_date']) ? $a['closure_date'] : '');
    $status    = ex_status_val($a);
    $is_issuer = (isset($a['issuer_staff_id']) && (int)$a['issuer_staff_id'] === $sid);
    $issuer_yn = $is_issuer ? 'Yes' : 'No';
    $payout_yn = (isset($a['payout_status']) && $a['payout_status'] === 'Closed') ? 'Yes' : '';

    $start_raw = isset($a['start_date']) ? $a['start_date'] : '';
    $ex_year   = ($start_raw && strlen($start_raw) >= 7) ? (int)substr($start_raw, 0, 4) : '';
    $ex_month  = ($start_raw && strlen($start_raw) >= 7) ? (int)substr($start_raw, 5, 2) : '';

    $a_amount = isset($a['a_incentive_amount']) ? (float)$a['a_incentive_amount'] : 0.0;
    $r_amount = isset($a['r_incentive_amount']) ? (float)$a['r_incentive_amount'] : 0.0;

    // Reward pools are split only among ARCI members actually marked
    // is_incentivised - mirrors api.php's getStaffPerformanceLive() so the
    // export matches the on-screen table (a non-incentivised A/R member gets
    // RM 0, not an equal share of the pool).
    $inc_a_count = 0;
    $inc_r_count = 0;
    if (!empty($a['arci']) && is_array($a['arci'])) {
        foreach ($a['arci'] as $m) {
            if (empty($m['is_incentivised'])) { continue; }
            if (isset($m['role']) && $m['role'] === 'A') { $inc_a_count++; }
            if (isset($m['role']) && $m['role'] === 'R') { $inc_r_count++; }
        }
    }

    $roles = array();
    if (!empty($a['arci']) && is_array($a['arci'])) {
        foreach ($a['arci'] as $m) {
            if (!empty($m['staff_id']) && (int)$m['staff_id'] === $sid && !empty($m['role'])) {
                $roles[] = array('role' => $m['role'], 'is_incentivised' => !empty($m['is_incentivised']));
            }
        }
    }

    if (!empty($roles)) {
        foreach ($roles as $r) {
            $role = $r['role'];
            if ($blank_reward) {
                $reward = '';
            } elseif ($role === 'A' && $r['is_incentivised'] && $inc_a_count > 0) {
                $reward = number_format($a_amount / $inc_a_count, 2);
            } elseif ($role === 'R' && $r['is_incentivised'] && $inc_r_count > 0) {
                $reward = number_format($r_amount / $inc_r_count, 2);
            } else {
                $reward = number_format(0, 2);
            }
            fputcsv($out, array(
                'ATEM', $ex_year, $ex_month,
                $name, $dept, $grade, $struct,
                $atem_id, $title, $level, $start, $end, $closure,
                $issuer_yn, $role, $status, $reward, $payout_yn
            ));
        }
    } else {
        fputcsv($out, array(
            'ATEM', $ex_year, $ex_month,
            $name, $dept, $grade, $struct,
            $atem_id, $title, $level, $start, $end, $closure,
            $issuer_yn, '', $status, ($blank_reward ? '' : number_format(0, 2)), $payout_yn
        ));
    }
}

// Emit one row for one staff member's involvement in one OKR card - always
// Owner or Owner 2 (OKR row emission is only ever called for a card's actual
// owner(s), never the issuer - mirrors getOkrPerformanceLive()'s "my cards"
// scoping in api.php). OKR no longer carries any incentive/payout concept
// (see okr/CLAUDE.md's "Retired incentive columns"), so Est. Reward and
// Payout are always blank - unlike ATEM rows, there is no reward to compute.
// 'Level / Complexity' is likewise always blank - okr_cards.difficulty_level
// is a retired column, not read here.
function emit_okr_rows($out, $sid, $name, $dept, $grade, $struct, $o) {
    $okr_id  = '#OK' . (int)(isset($o['id']) ? $o['id'] : 0);
    $title   = isset($o['objective']) ? $o['objective'] : '';
    $start   = fmt_ex_date(isset($o['start_date']) ? $o['start_date'] : '');
    $end_raw = (!empty($o['extended']) && !empty($o['extended_date']))
                  ? $o['extended_date']
                  : (isset($o['end_date']) ? $o['end_date'] : '');
    $end       = fmt_ex_date($end_raw);
    $closure   = fmt_ex_date(isset($o['closed_at']) ? $o['closed_at'] : '');
    $status    = isset($o['status_value']) ? $o['status_value'] : '';
    $is_issuer = (isset($o['issuer_staff_id']) && (int)$o['issuer_staff_id'] === $sid);
    $issuer_yn = $is_issuer ? 'Yes' : 'No';

    $start_raw = isset($o['start_date']) ? $o['start_date'] : '';
    $ex_year   = ($start_raw && strlen($start_raw) >= 7) ? (int)substr($start_raw, 0, 4) : '';
    $ex_month  = ($start_raw && strlen($start_raw) >= 7) ? (int)substr($start_raw, 5, 2) : '';

    $owner_id = (int)(isset($o['owner_staff_id']) ? $o['owner_staff_id'] : 0);
    $role     = ($sid === $owner_id) ? 'Owner' : 'Owner 2';

    fputcsv($out, array(
        'OKR', $ex_year, $ex_month,
        $name, $dept, $grade, $struct,
        $okr_id, $title, '', $start, $end, $closure,
        $issuer_yn, $role, $status, '', ''
    ));
}

$csv_headers = array(
    'Record Type', 'Year', 'Month',
    'Name', 'Department', 'Grade', 'Evaluation Structure',
    'Record ID', 'Title / Objective', 'Level / Complexity',
    'Start Date', 'End Date', 'Closure Date', 'Issuer', 'Role', 'Status', 'Est. Reward (RM)', 'Payout'
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

    logAtemExport($conn, $target_staff, $staff_id, 'atem');

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
    // Mirrors api.php's get-performance-list: HQ ATEM, Outlet ATEM, and OKR
    // are fetched separately then merged on their union of staff ids, so
    // anyone visible on the on-screen table (which does the same union) is
    // exportable too. Est. Reward stays HQ ATEM only - Outlet ATEM and OKR
    // rows are emitted further below with a blank Est. Reward.
    $live = getStaffPerformanceLive($filter_month, $filter_year, $filter_quarter, $filter_statuses, $staff_id, 1, 0);
    $live_outlet = getStaffPerformanceLive($filter_month, $filter_year, $filter_quarter, $filter_statuses, $staff_id, 2, 0);
    if (empty($live['success']) || empty($live_outlet['success'])) {
        http_response_code(502);
        exit('Unable to reach the ATEM API. Please try again later.');
    }
    $okr_live = getOkrPerformanceLive($conn, $filter_month, $filter_year, $filter_quarter, $filter_statuses);

    // Resolve current grade/struct live from ODB (dept comes from each aggregate's
    // own ATEM-card context, same as the main performance list).
    $staff_grade      = array();
    $staff_struct     = array();
    $staff_dept_first = array();
    $_gs_res = mysqli_query($conn, "SELECT id, grade, struct, department FROM staff WHERE recycle != 1");
    if ($_gs_res) {
        while ($_gs_r = mysqli_fetch_assoc($_gs_res)) {
            $_gs_id = (int)$_gs_r['id'];
            $staff_grade[$_gs_id]  = ($_gs_r['grade']  !== null) ? (int)$_gs_r['grade']  : null;
            $staff_struct[$_gs_id] = ($_gs_r['struct'] !== null) ? (int)$_gs_r['struct'] : null;
            $staff_dept_first[$_gs_id] = 0;
            foreach (explode(',', (string)$_gs_r['department']) as $_gsd) {
                $_gsd = (int)trim($_gsd);
                if ($_gsd > 0) { $staff_dept_first[$_gs_id] = $_gsd; break; }
            }
        }
    }

    // Union of HQ-ATEM-involved, Outlet-ATEM-involved, and OKR-involved staff
    // ids - a staff member with only Outlet ATEM or OKR activity and no HQ
    // ATEM cards would otherwise never be exported.
    $union_sids = array_unique(array_merge(
        array_keys($live['data']),
        array_keys($live_outlet['data']),
        array_keys($okr_live['data'])
    ));

    // Apply filters
    $out_records = array();
    foreach ($union_sids as $sid) {
        $sid          = (int)$sid;
        $rec          = isset($live['data'][$sid])         ? $live['data'][$sid]         : null;
        $rec_outlet   = isset($live_outlet['data'][$sid])  ? $live_outlet['data'][$sid]  : null;
        $rec_okr      = isset($okr_live['data'][$sid])     ? $okr_live['data'][$sid]     : null;
        $dept_id      = ($rec && !empty($rec['dept_id']))
            ? (int)$rec['dept_id']
            : ((!empty($rec_outlet['dept_id']))
                ? (int)$rec_outlet['dept_id']
                : (isset($staff_dept_first[$sid]) ? $staff_dept_first[$sid] : 0));
        $grade_id  = isset($staff_grade[$sid])  ? $staff_grade[$sid]  : null;
        $struct_id = isset($staff_struct[$sid]) ? $staff_struct[$sid] : null;

        if (!empty($ids)        && !in_array($sid, $ids, true))    { continue; }
        if ($filter_dept     > 0 && $dept_id !== $filter_dept)     { continue; }
        if ($filter_grade    > 0 && $grade_id !== $filter_grade)   { continue; }
        if ($filter_struct   > 0 && $struct_id !== $filter_struct) { continue; }
        if ($filter_staff_id > 0 && $sid !== $filter_staff_id)     { continue; }

        $hq_total     = $rec        ? $rec['total_all']        : 0;
        $outlet_total = $rec_outlet ? $rec_outlet['total_all'] : 0;
        $okr_total    = $rec_okr    ? $rec_okr['total_all']    : 0;
        if ($hq_total <= 0 && $outlet_total <= 0 && $okr_total <= 0) { continue; }

        // Est. Reward used only for export sort order, matching the on-screen
        // table's default sort - HQ ATEM only, same as the on-screen Est.
        // Reward column.
        $total_reward = $rec ? (float)$rec['total_incentive'] : 0.0;

        $out_records[] = array(
            'staff_id' => $sid, 'dept_id' => $dept_id, 'grade_id' => $grade_id, 'struct_id' => $struct_id,
            'total_incentive' => $total_reward,
        );
    }

    // Default sort: highest Est. Reward first — matches the on-screen table,
    // so each staff's block of rows appears in the same order exported.
    usort($out_records, function ($a, $b) {
        return $b['total_incentive'] <=> $a['total_incentive'];
    });

    foreach ($out_records as $_rec) {
        logAtemExport($conn, $_rec['staff_id'], $staff_id, 'performance');
    }

    // Fetch all ATEMs once (both HQ and Outlet cards - unfiltered by type,
    // the loop below filters HQ vs Outlet itself)
    $all_atems = array();
    $_atem_res = getApiDataWithJWT('atem', null, 'GET', $staff_id);
    if (isset($_atem_res['httpCode']) && $_atem_res['httpCode'] === 200) {
        $_atem_dec = json_decode($_atem_res['response'], true);
        $all_atems = (isset($_atem_dec['data']) && is_array($_atem_dec['data'])) ? $_atem_dec['data'] : array();
    }
    // Fetch all OKR cards once (plain mysqli against the same database - OKR
    // has no separate API, see okr/CLAUDE.md).
    $all_okrs = array();
    $_okr_res = mysqli_query($conn, "SELECT c.id, c.objective, c.owner_staff_id, c.owner2_staff_id, c.issuer_staff_id,
                                             c.start_date, c.end_date, c.extended, c.extended_date, c.closed_at,
                                             os.value AS status_value
                                      FROM okr_cards c
                                      LEFT JOIN okr_statuses os ON c.result_status = os.id
                                      WHERE c.deleted_at IS NULL");
    if ($_okr_res) { while ($_or = mysqli_fetch_assoc($_okr_res)) { $all_okrs[] = $_or; } }

    // Outlet ATEM and OKR rows only ever include Completed-family statuses,
    // regardless of what else is checked in the Status filter - mirrors the
    // on-screen table's Outlet ATEM Completed/OKR Completed columns, which
    // likewise only ever count the 'complete' bucket. HQ ATEM rows below keep
    // respecting the full $filter_statuses, unchanged.
    $complete_family = array('Completed', 'Completed with Excellence', 'Completed with Extension');
    $outlet_okr_statuses = array_values(array_intersect($filter_statuses, $complete_family));

    $filename = 'staff_performance_' . date('Y-m-d') . '.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-cache, no-store, must-revalidate');
    $out = fopen('php://output', 'w');
    fputs($out, "\xEF\xBB\xBF");
    fputcsv($out, $csv_headers);

    $period_months = atem_period_months($filter_month, $filter_quarter);

    foreach ($out_records as $rec) {
        $sid       = $rec['staff_id'];
        $dept_id   = $rec['dept_id'];
        $grade_id  = $rec['grade_id'];
        $struct_id = $rec['struct_id'];

        $p_name   = isset($staff_names[$sid])   ? $staff_names[$sid]              : ('Staff #' . $sid);
        $p_dept   = ($dept_id   && isset($dept_names[$dept_id]))     ? $dept_names[$dept_id]      : '-';
        $p_grade  = ($grade_id  !== null && isset($grade_labels[$grade_id]))   ? $grade_labels[$grade_id]   : '-';
        $p_struct = ($struct_id !== null && isset($struct_labels[$struct_id])) ? $struct_labels[$struct_id] : '-';

        foreach ($all_atems as $_a) {
            // HQ ATEM rows only - $all_atems is unfiltered by type (fetched
            // once, reused for the Outlet ATEM loop below), so this loop must
            // exclude Outlet cards itself.
            $_atemType = isset($_a['atem_type']) ? (int)$_a['atem_type'] : 1;
            if ($_atemType !== 1) { continue; }

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

            $_status = ex_status_val($_a);
            if (!in_array($_status, $filter_statuses, true)) { continue; }

            $_dateField = atem_status_period_field($_status);
            $_dateStr   = isset($_a[$_dateField]) ? $_a[$_dateField] : null;
            if (!atem_date_in_period($_dateStr, $period_months, $filter_year)) { continue; }

            emit_atem_rows($out, $sid, $p_name, $p_dept, $p_grade, $p_struct, $_a);
        }

        if (!empty($outlet_okr_statuses)) {
            foreach ($all_atems as $_a) {
                $_atemType = isset($_a['atem_type']) ? (int)$_a['atem_type'] : 1;
                if ($_atemType !== 2) { continue; }

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

                $_status = ex_status_val($_a);
                if (!in_array($_status, $outlet_okr_statuses, true)) { continue; }

                $_dateField = atem_status_period_field($_status);
                $_dateStr   = isset($_a[$_dateField]) ? $_a[$_dateField] : null;
                if (!atem_date_in_period($_dateStr, $period_months, $filter_year)) { continue; }

                emit_atem_rows($out, $sid, $p_name, $p_dept, $p_grade, $p_struct, $_a, true);
            }

            foreach ($all_okrs as $_o) {
                $_o_owner  = isset($_o['owner_staff_id'])  ? (int)$_o['owner_staff_id']  : 0;
                $_o_owner2 = !empty($_o['owner2_staff_id']) ? (int)$_o['owner2_staff_id'] : 0;
                if ($sid !== $_o_owner && $sid !== $_o_owner2) { continue; }

                $_o_status = isset($_o['status_value']) ? $_o['status_value'] : '';
                if (!in_array($_o_status, $outlet_okr_statuses, true)) { continue; }

                $_o_date = isset($_o['closed_at']) ? $_o['closed_at'] : null;
                if (!atem_date_in_period($_o_date, $period_months, $filter_year)) { continue; }

                emit_okr_rows($out, $sid, $p_name, $p_dept, $p_grade, $p_struct, $_o);
            }
        }
    }

    fclose($out);
    exit;
}

http_response_code(400);
exit('Invalid export type');
