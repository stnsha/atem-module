<?php
ob_start();

$page_title = 'Staff Performance - Edit';
include('../header.php');

if ($atem_permission < 3 && !$_is_superadmin) {
    ob_end_clean();
    header('Location: ' . ATEM_BASE . 'index.php');
    exit;
}

ob_end_flush();

$rec_id       = isset($_GET['id'])    ? (int)$_GET['id']    : 0;
$target_sid   = isset($_GET['sid'])   ? (int)$_GET['sid']   : 0;
$filter_month   = isset($_GET['month'])   ? (int)$_GET['month']   : 0;
$filter_year    = isset($_GET['year'])    ? (int)$_GET['year']    : (int)date('Y');
// Quarter carries over as a comma-separated list from the Staff Performance
// summary page's own multi-select Quarter checkboxes (index.php) - parsed the
// same way api.php's atem_parse_quarters() does, so a link like "?quarter=2,3"
// pre-checks both boxes here instead of silently keeping only the first one.
$filter_quarter_raw = isset($_GET['quarter']) ? (string)$_GET['quarter'] : '';
$filter_quarters = array();
if ($filter_quarter_raw !== '') {
    foreach (explode(',', $filter_quarter_raw) as $_fq) {
        $_fq = (int)trim($_fq);
        if ($_fq >= 1 && $_fq <= 4) { $filter_quarters[] = $_fq; }
    }
    $filter_quarters = array_values(array_unique($filter_quarters));
}
if (!empty($filter_quarters)) { $filter_month = 0; }
// Carries over the Status checkboxes selected on the Staff Performance summary
// page, so the ATEM list here starts scoped to the same statuses instead of
// defaulting to "All statuses". Empty means no carry-over — all checked.
$filter_statuses_raw = isset($_GET['statuses']) ? trim($_GET['statuses']) : '';
$filter_statuses_init = array();
if ($filter_statuses_raw !== '') {
    foreach (explode(',', $filter_statuses_raw) as $_fs) {
        $_fs = trim($_fs);
        if ($_fs !== '') { $filter_statuses_init[] = $_fs; }
    }
}
// Carries over the Your Role checkboxes selected on the Staff Performance
// summary page, same pattern as Status above. Empty means no carry-over —
// falls back to the default A/R selection.
$filter_roles_raw = isset($_GET['roles']) ? trim($_GET['roles']) : '';
$filter_roles_init = array();
if ($filter_roles_raw !== '') {
    foreach (explode(',', $filter_roles_raw) as $_fr) {
        $_fr = trim($_fr);
        if ($_fr !== '') { $filter_roles_init[] = $_fr; }
    }
}
$_edit_cur_year = max(2026, (int)date('Y'));
$year_options   = array();
for ($y = 2026; $y <= $_edit_cur_year; $y++) {
    $year_options[] = $y;
}

if ($rec_id <= 0 || $target_sid <= 0) {
    header('Location: ' . ATEM_BASE . 'staff_performance/index.php');
    exit;
}

// Build ODB lookup maps
$staff_names   = array();
$dept_names    = array();
$grade_labels  = array();
$struct_labels = array();

$sr = mysqli_query($conn, "SELECT id, nama_staff FROM staff WHERE recycle != 1");
if ($sr) { while ($row = mysqli_fetch_assoc($sr)) { $staff_names[(int)$row['id']] = $row['nama_staff']; } }

$dr = mysqli_query($conn, "SELECT id, depart_name FROM staff_department");
if ($dr) { while ($row = mysqli_fetch_assoc($dr)) { $dept_names[(int)$row['id']] = $row['depart_name']; } }

$gr = mysqli_query($conn, "SELECT id, grade_name FROM staff_grade ORDER BY id ASC");
if ($gr) { while ($row = mysqli_fetch_assoc($gr)) { $grade_labels[(int)$row['id']] = $row['grade_name']; } }

$str_r = mysqli_query($conn, "SELECT id, struct_name FROM staff_struct ORDER BY id ASC");
if ($str_r) { while ($row = mysqli_fetch_assoc($str_r)) { $struct_labels[(int)$row['id']] = $row['struct_name']; } }

// Resolve target staff details from ODB
$t_name   = isset($staff_names[$target_sid]) ? $staff_names[$target_sid] : ('Staff #' . $target_sid);
$t_res    = mysqli_query($conn, "SELECT department, grade, struct FROM staff WHERE id = " . $target_sid . " AND recycle != 1");
$t_dept_id   = 0;
$t_grade_id  = null;
$t_struct_id = null;
if ($t_res && ($t_row = mysqli_fetch_assoc($t_res))) {
    $t_dept_id   = (int)$t_row['department'];
    $t_grade_id  = (int)$t_row['grade'];
    $t_struct_id = isset($t_row['struct']) ? (int)$t_row['struct'] : null;
}
$t_dept   = ($t_dept_id   && isset($dept_names[$t_dept_id]))                  ? $dept_names[$t_dept_id]            : '-';
$t_grade  = ($t_grade_id  !== null && isset($grade_labels[$t_grade_id]))       ? $grade_labels[$t_grade_id]         : '-';
$t_struct = ($t_struct_id !== null && isset($struct_labels[$t_struct_id]))     ? $struct_labels[$t_struct_id]       : '-';

// Fetch ATEM list for target staff
define('API_JWT_INCLUDED', true);
include(dirname(__FILE__) . '/../api.php');

$atem_result = getStaffAtemList($target_sid, $staff_id);
$atem_rows   = (!empty($atem_result['success']) && isset($atem_result['data'])) ? $atem_result['data'] : array();
$atem_unavailable = empty($atem_result['success']);

$edit_lookups = array('levels' => array(), 'statuses' => array());
$lr = getAtemLookups($staff_id);
if (!empty($lr['success']) && isset($lr['data'])) {
    $edit_lookups = $lr['data'];
}

$status_colors = array(
    'Draft'                     => '#6c757d',
    'Active'                    => '#0d6efd',
    'Completed'                 => '#198754',
    'Completed with Excellence' => '#0dcaf0',
    'Completed with Extension'  => '#495057',
    'Extended'                  => '#fd7e14',
    'Failed'                    => '#dc3545',
);

function fmt_date($d) {
    if (!$d) { return '-'; }
    $p = explode('-', $d);
    return (count($p) === 3) ? $p[2].'-'.$p[1].'-'.$p[0] : $d;
}

$month_names = array(
    1=>'January', 2=>'February', 3=>'March',    4=>'April',
    5=>'May',     6=>'June',     7=>'July',      8=>'August',
    9=>'September', 10=>'October', 11=>'November', 12=>'December'
);

// Combined activity log for this staff (ATEM payout locks + exports), built
// up across the ATEM fetch block below plus a dedicated export-log query,
// then sorted/rendered next to Staff Details further down.
$activity_logs = array();

// ATEM lock events - payout_closed_by/payout_closed_at are set by the
// Laravel atem-api's single-record payout-status endpoint (the only ATEM
// lock path that currently actually works - see bulk-lock-payout's own
// notes in api.php).
foreach ($atem_rows as $_a) {
    if (isset($_a['payout_status']) && $_a['payout_status'] === 'Closed'
        && !empty($_a['payout_closed_by']) && !empty($_a['payout_closed_at'])
    ) {
        $_lock_actor_id = (int)$_a['payout_closed_by'];
        $activity_logs[] = array(
            'type'  => 'ATEM Lock',
            'ref'   => '#AT' . (int)(isset($_a['id']) ? $_a['id'] : 0),
            'actor' => isset($staff_names[$_lock_actor_id]) ? $staff_names[$_lock_actor_id] : ('Staff #' . $_lock_actor_id),
            'when'  => $_a['payout_closed_at'],
        );
    }
}

// Export events - written by export.php's logAtemExport() every time this
// staff's data is included in an export (single-staff or bulk performance).
$_exp_res = mysqli_query($conn, "SELECT actor_staff_id, export_type, exported_at
                                  FROM atem_export_logs
                                  WHERE target_staff_id = " . $target_sid . "
                                  ORDER BY exported_at DESC
                                  LIMIT 15");
if ($_exp_res) {
    while ($_exp = mysqli_fetch_assoc($_exp_res)) {
        $_exp_actor_id = (int)$_exp['actor_staff_id'];
        $activity_logs[] = array(
            'type'  => 'Export',
            'ref'   => ucfirst($_exp['export_type']),
            'actor' => isset($staff_names[$_exp_actor_id]) ? $staff_names[$_exp_actor_id] : ('Staff #' . $_exp_actor_id),
            'when'  => $_exp['exported_at'],
        );
    }
}

// Newest first, capped so this doesn't grow unbounded on the page over time.
usort($activity_logs, function ($a, $b) {
    return strtotime($b['when']) <=> strtotime($a['when']);
});
$activity_logs = array_slice($activity_logs, 0, 15);

$back_url = ATEM_BASE . 'staff_performance/index.php';
if ($filter_month > 0 || $filter_year > 0 || !empty($filter_quarters)) {
    $back_url .= '?' . http_build_query(array('month'=>$filter_month,'year'=>$filter_year,'quarter'=>implode(',', $filter_quarters)));
}

$export_atem_url = ATEM_BASE . 'staff_performance/export.php?' . http_build_query(array('type'=>'staff-atem','staff_id'=>$target_sid));
?>

<div class="mb-3">
    <a href="<?php echo htmlspecialchars($back_url); ?>" class="btn btn-sm btn-outline-secondary">&larr; Back to Performance</a>
</div>

<!-- Row 1: Staff details -->
<div class="atem-card mb-3">
    <p class="mb-3 text-muted" style="font-size:11px;text-transform:uppercase;letter-spacing:.06em;font-weight:600;">
        Staff Details
    </p>
    <div class="row g-3">
        <div class="col-md-6">
            <table style="width:100%;font-size:13px;border-collapse:separate;border-spacing:0 8px;">
                <tr>
                    <td style="width:40%;color:#6c757d;font-size:11px;text-transform:uppercase;letter-spacing:.05em;font-weight:600;vertical-align:top;padding-right:12px;">Name</td>
                    <td style="font-size:13px;font-weight:500;"><?php echo htmlspecialchars($t_name); ?></td>
                </tr>
                <tr>
                    <td style="color:#6c757d;font-size:11px;text-transform:uppercase;letter-spacing:.05em;font-weight:600;vertical-align:top;padding-right:12px;">Department</td>
                    <td style="font-size:13px;"><?php echo htmlspecialchars($t_dept); ?></td>
                </tr>
                <tr>
                    <td style="color:#6c757d;font-size:11px;text-transform:uppercase;letter-spacing:.05em;font-weight:600;vertical-align:top;padding-right:12px;">Grade</td>
                    <td style="font-size:13px;"><?php echo htmlspecialchars($t_grade); ?></td>
                </tr>
                <tr>
                    <td style="color:#6c757d;font-size:11px;text-transform:uppercase;letter-spacing:.05em;font-weight:600;vertical-align:top;padding-right:12px;">Evaluation Structure</td>
                    <td style="font-size:13px;"><?php echo htmlspecialchars($t_struct); ?></td>
                </tr>
            </table>
        </div>
    </div>
</div>

<!-- Filter -->
<div class="atem-card atem-filter mb-3">
    <h6 class="atem-card-title"><i class="bi bi-funnel"></i> Filter</h6>
    <div class="row g-2 mt-1">
        <div class="col-md-3">
            <label class="form-label">Year</label>
            <select class="form-select form-select-sm" id="ef-year">
                <option value="">All Year</option>
                <?php foreach ($year_options as $y): ?>
                <option value="<?php echo $y; ?>"<?php echo ($y === $filter_year) ? ' selected' : ''; ?>><?php echo $y; ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-3">
            <label class="form-label">Closure Month</label>
            <select class="form-select form-select-sm" id="ef-month">
                <option value="0">All Month</option>
                <?php foreach ($month_names as $mnum => $mname): ?>
                <option value="<?php echo $mnum; ?>"<?php echo ($mnum === $filter_month) ? ' selected' : ''; ?>><?php echo $mname; ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-3">
            <label class="form-label">Quarter</label>
            <div class="vf-issuer-wrap" id="ef-quarter-wrap">
                <div class="vf-s2-selection" id="ef-quarter-btn" tabindex="0">All Quarter</div>
                <div class="vf-s2-dropdown" id="ef-quarter-dropdown">
                    <ul class="vf-s2-list" id="ef-quarter-list" style="padding:4px 0;">
                        <?php foreach (array(1 => 'Q1 (Jan-Mar)', 2 => 'Q2 (Apr-Jun)', 3 => 'Q3 (Jul-Sep)', 4 => 'Q4 (Oct-Dec)') as $_qn => $_ql): ?>
                        <li class="vf-s2-list-item" style="cursor:default;">
                            <label style="display:flex;align-items:center;gap:6px;width:100%;cursor:pointer;margin:0;">
                                <input type="checkbox" class="ef-quarter-cb" value="<?php echo $_qn; ?>"
                                    <?php echo in_array($_qn, $filter_quarters, true) ? ' checked' : ''; ?>>
                                <?php echo htmlspecialchars($_ql); ?>
                            </label>
                        </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <label class="form-label">Start Date</label>
            <input type="date" class="form-control form-control-sm" id="ef-from">
        </div>
        <div class="col-md-3">
            <label class="form-label">End Date</label>
            <input type="date" class="form-control form-control-sm" id="ef-to">
        </div>
        <div class="col-md-3">
            <label class="form-label">Level</label>
            <select class="form-select form-select-sm" id="ef-level">
                <option value="">All levels</option>
            </select>
        </div>
        <div class="col-md-3">
            <label class="form-label">Status</label>
            <div class="vf-issuer-wrap" id="ef-status-wrap">
                <div class="vf-s2-selection" id="ef-status-btn" tabindex="0">Loading...</div>
                <div class="vf-s2-dropdown" id="ef-status-dropdown">
                    <ul class="vf-s2-list" id="ef-status-list" style="padding:4px 0;"></ul>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <label class="form-label">Your Role with ARCI/Issuer</label>
            <div class="vf-issuer-wrap" id="ef-role-wrap">
                <div class="vf-s2-selection" id="ef-role-btn" tabindex="0">All roles</div>
                <div class="vf-s2-dropdown" id="ef-role-dropdown">
                    <ul class="vf-s2-list" id="ef-role-list" style="padding:4px 0;"></ul>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <label class="form-label">Search title</label>
            <input type="text" class="form-control form-control-sm" id="ef-search" placeholder="Type to search...">
        </div>
    </div>
    <div class="d-flex justify-content-end gap-2 mt-3">
        <button type="button" class="btn btn-outline-secondary btn-sm" id="ef-reset">Reset Filters</button>
        <a id="export-atem-btn" href="<?php echo htmlspecialchars($export_atem_url); ?>" class="btn btn-outline-success btn-sm">Export All ATEMs</a>
    </div>
    <div id="export-progress-wrap" style="display:none;margin-top:8px;max-width:400px;">
        <div style="margin-bottom:4px;">
            <span id="export-progress-msg">Preparing export...</span>
        </div>
        <div style="background:#e9ecef;border-radius:4px;height:8px;overflow:hidden;">
            <div class="atem-export-bar-anim"></div>
        </div>
    </div>
</div>

<!-- Row 2: ATEM table -->
<div class="atem-card">
    <div class="d-flex align-items-center mb-3 gap-2">
        <p class="mb-0 text-muted" style="font-size:11px;text-transform:uppercase;letter-spacing:.06em;font-weight:600;">
            All ATEM &mdash; <?php echo htmlspecialchars($t_name); ?>
        </p>
    </div>

    <?php if ($atem_unavailable): ?>
    <div class="alert alert-warning" style="font-size:13px;">Unable to reach the ATEM API. Please try again later.</div>
    <?php else: ?>
    <?php
    $level_colors = array(
        'Level 1' => '#6c757d',
        'Level 2' => '#0d6efd',
        'Level 3' => '#6610f2',
        'Level 4' => '#003B73',
    );
    $arci_colors = array(
        'A' => '#6610f2',
        'R' => '#0d6efd',
        'C' => '#fd7e14',
        'I' => '#6c757d',
    );
    $edit_atem_js_rows = array();
    foreach ($atem_rows as $a) {
        $a_status_raw = isset($a['status']) ? $a['status'] : null;
        $a_status   = is_array($a_status_raw)
            ? (isset($a_status_raw['value']) ? $a_status_raw['value'] : '')
            : (string)($a_status_raw ?: '');
        $a_level    = (!empty($a['level_structure']) && is_array($a['level_structure']))
            ? (isset($a['level_structure']['level']) ? $a['level_structure']['level'] : '')
            : (isset($a['level_label']) ? $a['level_label'] : '');
        $a_sys_name = (!empty($a['level_structure']) && is_array($a['level_structure']))
            ? (isset($a['level_structure']['system_name']) ? $a['level_structure']['system_name'] : '')
            : '';
        $a_color     = isset($status_colors[$a_status]) ? $status_colors[$a_status] : '#6c757d';
        $a_lvl_color = isset($level_colors[$a_level])   ? $level_colors[$a_level]   : '#6c757d';

        $issuer_id   = isset($a['issuer_staff_id']) ? (int)$a['issuer_staff_id'] : 0;
        $issuer_name = ($issuer_id && isset($staff_names[$issuer_id])) ? $staff_names[$issuer_id] : ('Staff #' . $issuer_id);
        $iss_dept_id = isset($a['staff_dept_id']) ? (int)$a['staff_dept_id'] : 0;
        $iss_dept    = ($iss_dept_id && isset($dept_names[$iss_dept_id])) ? $dept_names[$iss_dept_id] : '';

        $accountable_list = array();
        $target_roles     = array();
        if (!empty($a['arci']) && is_array($a['arci'])) {
            foreach ($a['arci'] as $_m) {
                if (isset($_m['role']) && $_m['role'] === 'A' && !empty($_m['staff_id'])) {
                    $_aid  = (int)$_m['staff_id'];
                    $_adid = isset($_m['staff_dept_id']) ? (int)$_m['staff_dept_id'] : 0;
                    $accountable_list[] = array(
                        'name' => isset($staff_names[$_aid]) ? $staff_names[$_aid] : ('Staff #' . $_aid),
                        'dept' => ($_adid && isset($dept_names[$_adid])) ? $dept_names[$_adid] : '',
                    );
                }
                if (!empty($_m['staff_id']) && (int)$_m['staff_id'] === $target_sid && isset($_m['role'])) {
                    $target_roles[] = $_m['role'];
                }
            }
        }
        if ($issuer_id === $target_sid) {
            array_unshift($target_roles, 'Issuer');
        }

        // Estimated Reward: the target staff member's own share of the incentive,
        // only for closing statuses. Stays null (rendered as "-") unless this
        // staff is themselves an incentivised A/R member on this card - an
        // issuer-only/C/I role, or a non-incentivised A/R, shows "-" since
        // they have no stake in the incentive at all. Once they ARE an
        // incentivised A/R participant, this becomes a real number (0.00 if
        // the payout isn't approved yet, final_incentive_amount stays 0 until
        // approved — a_incentive_amount/r_incentive_amount are always the raw
        // rule-based estimate regardless) rather than "-", since they're a
        // genuine (pending) participant, not a bystander.
        $closing_statuses = array('Completed', 'Completed with Excellence', 'Completed with Extension');
        $est_reward = null;
        if (in_array($a_status, $closing_statuses, true)
            && !empty($a['arci']) && is_array($a['arci'])
        ) {
            $incACount = 0;
            $incRCount = 0;
            foreach ($a['arci'] as $_m) {
                if (empty($_m['is_incentivised'])) { continue; }
                if (isset($_m['role']) && $_m['role'] === 'A') { $incACount++; }
                if (isset($_m['role']) && $_m['role'] === 'R') { $incRCount++; }
            }
            $is_approved = isset($a['final_incentive_amount']) && (float)$a['final_incentive_amount'] > 0;

            foreach ($a['arci'] as $_m) {
                if (empty($_m['staff_id']) || (int)$_m['staff_id'] !== $target_sid || empty($_m['is_incentivised'])) {
                    continue;
                }
                if ($_m['role'] === 'A' && $incACount > 0) {
                    $est_reward = ($est_reward === null ? 0.0 : $est_reward)
                        + ($is_approved ? (float)(isset($a['a_incentive_amount']) ? $a['a_incentive_amount'] : 0) / $incACount : 0.0);
                } elseif ($_m['role'] === 'R' && $incRCount > 0) {
                    $est_reward = ($est_reward === null ? 0.0 : $est_reward)
                        + ($is_approved ? (float)(isset($a['r_incentive_amount']) ? $a['r_incentive_amount'] : 0) / $incRCount : 0.0);
                }
            }
        }

        $edit_atem_js_rows[] = array(
            'id'          => (int)(isset($a['id']) ? $a['id'] : 0),
            'title'       => isset($a['title']) ? $a['title'] : '',
            'issuer_name' => $issuer_name,
            'issuer_dept' => $iss_dept,
            'accountable' => $accountable_list,
            'roles'       => $target_roles,
            'level'       => $a_level,
            'level_sys'   => $a_sys_name,
            'level_color' => $a_lvl_color,
            'start_date'  => isset($a['start_date']) ? $a['start_date'] : '',
            'end_date'    => isset($a['end_date'])   ? $a['end_date']   : '',
            'closure_date'=> isset($a['closure_date']) ? $a['closure_date'] : '',
            'ext_date'    => !empty($a['extended_date_1']) ? $a['extended_date_1'] : '',
            'is_extended' => !empty($a['is_extended']),
            'status'      => $a_status,
            'status_color'=> $a_color,
            'est_reward'  => $est_reward,
            'is_locked'   => (isset($a['payout_status']) && $a['payout_status'] === 'Closed'),
        );
    }
    ?>
    <div class="table-responsive">
        <table class="table table-hover align-middle atem-view-tbl" style="font-size:13px;">
            <thead>
                <tr>
                    <th>ATEM ID</th>
                    <th>Title</th>
                    <th>Issuer</th>
                    <th>Accountable</th>
                    <th>ARCI</th>
                    <th>Level</th>
                    <th>Start</th>
                    <th>End</th>
                    <th>Status</th>
                    <th class="text-end">Est. Reward</th>
                    <th style="width:60px;">Action</th>
                    <th style="width:70px;">Payout</th>
                </tr>
            </thead>
            <tbody id="edit-atem-tbody">
                <tr><td colspan="12" class="text-center text-muted py-4">Loading...</td></tr>
            </tbody>
        </table>
    </div>
    <div class="atem-pager" id="edit-atem-pager"></div>
    <?php endif; ?>
</div>

<div class="atem-card mt-3">
    <h6 class="atem-card-title"><i class="bi bi-clock-history"></i> Activity Log</h6>
    <?php if (empty($activity_logs)): ?>
    <p class="text-muted mb-0 mt-2" style="font-size:13px;">No lock or export activity recorded yet.</p>
    <?php else: ?>
    <div class="mt-2">
        <?php foreach ($activity_logs as $_log): ?>
        <div style="padding:8px 0;border-bottom:1px solid #f1f3f5;">
            <div style="font-size:13px;font-weight:500;"><?php echo htmlspecialchars($_log['type']); ?> <?php echo htmlspecialchars($_log['ref']); ?></div>
            <div class="text-muted" style="font-size:12px;">
                <?php echo htmlspecialchars($_log['actor']); ?>
                &middot; <?php echo htmlspecialchars(date('Y-m-d H:i:s', strtotime($_log['when']))); ?>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>

</div><!-- /.atem-container -->

<script>
var PERF_API_URL  = <?php echo json_encode(ATEM_BASE . 'api.php'); ?>;
var editTargetSid = <?php echo $target_sid; ?>;
<?php if (!$atem_unavailable): ?>
window.EDIT_ATEM_ROWS = <?php echo json_encode($edit_atem_js_rows); ?>;
<?php else: ?>
window.EDIT_ATEM_ROWS = [];
<?php endif; ?>
window.EDIT_LOOKUPS = <?php echo json_encode($edit_lookups); ?>;
window.EDIT_INIT_STATUSES = <?php echo json_encode($filter_statuses_init); ?>;
window.EDIT_INIT_ROLES = <?php echo json_encode($filter_roles_init); ?>;

var QUARTER_MONTHS = { 1: [1, 2, 3], 2: [4, 5, 6], 3: [7, 8, 9], 4: [10, 11, 12] };
var QUARTERS_LABEL = { 1: 'Q1 (Jan-Mar)', 2: 'Q2 (Apr-Jun)', 3: 'Q3 (Jul-Sep)', 4: 'Q4 (Oct-Dec)' };

// Shared period-match helper for the ATEM tab. quarters is an array (possibly
// empty) of quarter numbers - mirrors api.php's atem_period_months(), so a
// multi-quarter selection (e.g. Q2+Q3) matches the union of both quarters'
// months, same as the Staff Performance summary page.
function matchesPeriod(dateStr, year, month, quarters) {
    var hasQuarter = quarters && quarters.length > 0;
    if (!year && !month && !hasQuarter) { return true; }
    if (!dateStr) { return false; }
    var y = parseInt(dateStr.substring(0, 4), 10);
    var m = parseInt(dateStr.substring(5, 7), 10);
    if (year && y !== year) { return false; }
    if (hasQuarter) {
        var months = [];
        for (var qi = 0; qi < quarters.length; qi++) {
            months = months.concat(QUARTER_MONTHS[quarters[qi]] || []);
        }
        return months.indexOf(m) !== -1;
    }
    if (month) { return m === month; }
    return true;
}

var _editPage         = 1;
var _editPerPage      = 30;
var _editFilteredData = [];

function buildEditFilters() {
    var lookups = window.EDIT_LOOKUPS || {};

    var levelEl = document.getElementById('ef-level');
    if (levelEl) {
        var levels = lookups.levels || [];
        var lh = '<option value="">All levels</option>';
        for (var li = 0; li < levels.length; li++) {
            lh += '<option value="' + editEsc(levels[li].level) + '">' + editEsc(levels[li].level) + '</option>';
        }
        levelEl.innerHTML = lh;
    }

    var statusListEl = document.getElementById('ef-status-list');
    if (statusListEl) {
        var statuses = lookups.statuses || [];
        var initStatuses = window.EDIT_INIT_STATUSES || [];
        var sh = '';
        for (var si = 0; si < statuses.length; si++) {
            var sv = statuses[si].value;
            var checked = initStatuses.length ? (initStatuses.indexOf(sv) !== -1) : true;
            sh += '<li class="vf-s2-list-item" style="cursor:default;">' +
                '<label style="display:flex;align-items:center;gap:6px;width:100%;cursor:pointer;margin:0;">' +
                '<input type="checkbox" class="ef-status-cb" value="' + editEsc(sv) + '"' + (checked ? ' checked' : '') + '> ' + editEsc(sv) +
                '</label></li>';
        }
        statusListEl.innerHTML = sh;
        buildEditStatusDropdown();
    }

    var roleListEl = document.getElementById('ef-role-list');
    if (roleListEl) {
        var roleOrder = ['Issuer', 'A', 'R', 'C', 'I'];
        var initRoles = window.EDIT_INIT_ROLES || [];
        var defaultRoles = initRoles.length ? initRoles : ['A', 'R'];
        var rh = '';
        for (var roi = 0; roi < roleOrder.length; roi++) {
            var rv = roleOrder[roi];
            var rChecked = defaultRoles.indexOf(rv) !== -1;
            rh += '<li class="vf-s2-list-item" style="cursor:default;">' +
                '<label style="display:flex;align-items:center;gap:6px;width:100%;cursor:pointer;margin:0;">' +
                '<input type="checkbox" class="ef-role-cb" value="' + editEsc(rv) + '"' + (rChecked ? ' checked' : '') + '> ' + editEsc(rv) +
                '</label></li>';
        }
        roleListEl.innerHTML = rh;
        buildEditRoleDropdown();
    }
}

// ------------------------------------------------- status checkbox dropdown
function getSelectedEditStatuses() {
    var boxes = document.querySelectorAll('.ef-status-cb:checked');
    var out = [];
    for (var i = 0; i < boxes.length; i++) { out.push(boxes[i].value); }
    return out;
}

function allEditStatusCheckboxes() { return document.querySelectorAll('.ef-status-cb'); }

function updateEditStatusButtonLabel() {
    var btn = document.getElementById('ef-status-btn');
    if (!btn) { return; }
    var selected = getSelectedEditStatuses();
    var all = allEditStatusCheckboxes();
    if (selected.length === 0) {
        btn.textContent = 'No status selected';
    } else if (selected.length === all.length) {
        btn.textContent = 'All statuses';
    } else if (selected.length <= 2) {
        btn.textContent = selected.join(', ');
    } else {
        btn.textContent = selected.length + ' statuses selected';
    }
}

function resetEditStatusDropdown() {
    var boxes = allEditStatusCheckboxes();
    for (var i = 0; i < boxes.length; i++) { boxes[i].checked = true; }
    updateEditStatusButtonLabel();
    var dropEl = document.getElementById('ef-status-dropdown');
    if (dropEl) { dropEl.classList.remove('open'); }
}

var _efStatusDropdownBuilt = false;
function buildEditStatusDropdown() {
    var btnEl  = document.getElementById('ef-status-btn');
    var dropEl = document.getElementById('ef-status-dropdown');
    var wrapEl = document.getElementById('ef-status-wrap');
    if (!btnEl || !dropEl) { return; }

    // Sync dimensions, border and font-size to a form-select-sm exactly —
    // otherwise this custom div falls back to its own CSS box model and
    // renders taller/rounder than the selects next to it.
    var refEl = document.getElementById('ef-year');
    if (refEl) {
        var refStyle = window.getComputedStyle(refEl);
        btnEl.style.height       = refEl.offsetHeight + 'px';
        btnEl.style.border       = refStyle.border;
        btnEl.style.borderRadius = refStyle.borderRadius;
        btnEl.style.fontSize     = refStyle.fontSize;
        btnEl.style.color        = refStyle.color;
    }

    updateEditStatusButtonLabel();

    // buildEditFilters() (which calls this) can re-run if EDIT_LOOKUPS changes,
    // but the open/close/change listeners only need to be attached once.
    if (_efStatusDropdownBuilt) { return; }
    _efStatusDropdownBuilt = true;

    btnEl.addEventListener('click', function (e) {
        e.stopPropagation();
        dropEl.classList.toggle('open');
    });
    btnEl.addEventListener('keydown', function (e) {
        if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); dropEl.classList.toggle('open'); }
    });
    document.addEventListener('click', function (e) {
        if (wrapEl && !wrapEl.contains(e.target)) { dropEl.classList.remove('open'); }
    });
    document.addEventListener('change', function (e) {
        if (e.target && e.target.classList.contains('ef-status-cb')) {
            updateEditStatusButtonLabel();
            applyEditFilters();
        }
    });
}

// --------------------------------------------------- role checkbox dropdown
function getSelectedEditRoles() {
    var boxes = document.querySelectorAll('.ef-role-cb:checked');
    var out = [];
    for (var i = 0; i < boxes.length; i++) { out.push(boxes[i].value); }
    return out;
}

function allEditRoleCheckboxes() { return document.querySelectorAll('.ef-role-cb'); }

function updateEditRoleButtonLabel() {
    var btn = document.getElementById('ef-role-btn');
    if (!btn) { return; }
    var selected = getSelectedEditRoles();
    var all = allEditRoleCheckboxes();
    if (selected.length === 0) {
        btn.textContent = 'No role selected';
    } else if (selected.length === all.length) {
        btn.textContent = 'All roles';
    } else if (selected.length <= 2) {
        btn.textContent = selected.join(', ');
    } else {
        btn.textContent = selected.length + ' roles selected';
    }
}

function resetEditRoleDropdown() {
    var defaultRoles = ['A', 'R'];
    var boxes = allEditRoleCheckboxes();
    for (var i = 0; i < boxes.length; i++) { boxes[i].checked = (defaultRoles.indexOf(boxes[i].value) !== -1); }
    updateEditRoleButtonLabel();
    var dropEl = document.getElementById('ef-role-dropdown');
    if (dropEl) { dropEl.classList.remove('open'); }
}

var _efRoleDropdownBuilt = false;
function buildEditRoleDropdown() {
    var btnEl  = document.getElementById('ef-role-btn');
    var dropEl = document.getElementById('ef-role-dropdown');
    var wrapEl = document.getElementById('ef-role-wrap');
    if (!btnEl || !dropEl) { return; }

    var refEl = document.getElementById('ef-year');
    if (refEl) { syncS2ButtonSizeEdit(btnEl, refEl); }

    updateEditRoleButtonLabel();

    if (_efRoleDropdownBuilt) { return; }
    _efRoleDropdownBuilt = true;

    btnEl.addEventListener('click', function (e) {
        e.stopPropagation();
        dropEl.classList.toggle('open');
    });
    btnEl.addEventListener('keydown', function (e) {
        if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); dropEl.classList.toggle('open'); }
    });
    document.addEventListener('click', function (e) {
        if (wrapEl && !wrapEl.contains(e.target)) { dropEl.classList.remove('open'); }
    });
    document.addEventListener('change', function (e) {
        if (e.target && e.target.classList.contains('ef-role-cb')) {
            updateEditRoleButtonLabel();
            applyEditFilters();
        }
    });
}

// ------------------------------------------------ quarter checkbox dropdown
// Same checkbox-dropdown widget as Status above, but empty selection means
// "no quarter filter" (falls back to Month/unfiltered), not "match nothing" -
// mirrors index.php's Quarter widget exactly (including multi-select), so a
// link carrying over multiple quarters from the summary page pre-checks all
// of them here instead of only the first.
function getSelectedEditQuarters() {
    var boxes = document.querySelectorAll('.ef-quarter-cb:checked');
    var out = [];
    for (var i = 0; i < boxes.length; i++) { out.push(parseInt(boxes[i].value, 10)); }
    return out;
}

function allEditQuarterCheckboxes() { return document.querySelectorAll('.ef-quarter-cb'); }

function updateEditQuarterButtonLabel() {
    var btn = document.getElementById('ef-quarter-btn');
    if (!btn) { return; }
    var selected = getSelectedEditQuarters();
    var all = allEditQuarterCheckboxes();
    if (selected.length === 0 || selected.length === all.length) {
        btn.textContent = 'All Quarter';
    } else {
        var labels = [];
        for (var i = 0; i < selected.length; i++) { labels.push(QUARTERS_LABEL[selected[i]] || ('Q' + selected[i])); }
        btn.textContent = labels.join(', ');
    }
}

function resetEditQuarterDropdown() {
    var boxes = allEditQuarterCheckboxes();
    for (var i = 0; i < boxes.length; i++) { boxes[i].checked = false; }
    updateEditQuarterButtonLabel();
    var dropEl = document.getElementById('ef-quarter-dropdown');
    if (dropEl) { dropEl.classList.remove('open'); }
}

var _efQuarterDropdownBuilt = false;
function buildEditQuarterDropdown() {
    var btnEl  = document.getElementById('ef-quarter-btn');
    var dropEl = document.getElementById('ef-quarter-dropdown');
    var wrapEl = document.getElementById('ef-quarter-wrap');
    if (!btnEl || !dropEl) { return; }

    var refEl = document.getElementById('ef-year');
    if (refEl) { syncS2ButtonSizeEdit(btnEl, refEl); }

    updateEditQuarterButtonLabel();

    if (_efQuarterDropdownBuilt) { return; }
    _efQuarterDropdownBuilt = true;

    btnEl.addEventListener('click', function (e) {
        e.stopPropagation();
        dropEl.classList.toggle('open');
    });
    btnEl.addEventListener('keydown', function (e) {
        if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); dropEl.classList.toggle('open'); }
    });
    document.addEventListener('click', function (e) {
        if (wrapEl && !wrapEl.contains(e.target)) { dropEl.classList.remove('open'); }
    });
    document.addEventListener('change', function (e) {
        if (e.target && e.target.classList.contains('ef-quarter-cb')) {
            updateEditQuarterButtonLabel();
            // Month and quarter are mutually exclusive
            if (getSelectedEditQuarters().length) {
                var efMonth = document.getElementById('ef-month');
                if (efMonth) { efMonth.value = '0'; }
            }
            applyEditFilters();
        }
    });
}

// Mirrors the Closure Month rule used everywhere else in the app: closing statuses
// (Completed family, Extended-with-a-recorded-date, Failed) are matched by closure_date;
// Active is matched by start_date; anything else (Draft/Suspended/Deleted/pending-Extended)
// falls back to start_date so it still shows up somewhere rather than vanishing silently.
var EDIT_CLOSURE_STATUSES = ['Completed', 'Completed with Excellence', 'Completed with Extension', 'Extended', 'Failed'];

function editInPeriod(r, year, month, quarters) {
    var dateStr = (EDIT_CLOSURE_STATUSES.indexOf(r.status) !== -1) ? r.closure_date : r.start_date;
    return matchesPeriod(dateStr, year, month, quarters);
}

function applyEditFilters() {
    var data   = window.EDIT_ATEM_ROWS || [];
    var year   = document.getElementById('ef-year')   ? parseInt(document.getElementById('ef-year').value,   10) || 0 : 0;
    var month  = document.getElementById('ef-month')  ? parseInt(document.getElementById('ef-month').value,  10) || 0 : 0;
    var quarters = getSelectedEditQuarters();
    if (quarters.length) { month = 0; }
    var level  = document.getElementById('ef-level')  ? document.getElementById('ef-level').value  : '';
    var statuses = getSelectedEditStatuses();
    var allStatusCount = allEditStatusCheckboxes().length;
    var roles = getSelectedEditRoles();
    var allRoleCount = allEditRoleCheckboxes().length;
    var from   = document.getElementById('ef-from')   ? document.getElementById('ef-from').value   : '';
    var to     = document.getElementById('ef-to')     ? document.getElementById('ef-to').value     : '';
    var term   = document.getElementById('ef-search') ? document.getElementById('ef-search').value.toLowerCase().trim() : '';

    _editFilteredData = data.filter(function(r) {
        if (!editInPeriod(r, year, month, quarters)) { return false; }
        if (level  && r.level  !== level)  { return false; }
        if (statuses.length === 0) { return false; }
        if (statuses.length < allStatusCount && statuses.indexOf(r.status) === -1) { return false; }
        if (roles.length === 0) { return false; }
        if (roles.length < allRoleCount) {
            var roleMatch = false;
            if (r.roles) {
                for (var rri = 0; rri < roles.length; rri++) {
                    if (r.roles.indexOf(roles[rri]) >= 0) { roleMatch = true; break; }
                }
            }
            if (!roleMatch) { return false; }
        }
        if (from && (!r.start_date || r.start_date.substring(0, 10) < from)) { return false; }
        if (to   && (!r.start_date || r.start_date.substring(0, 10) > to))   { return false; }
        if (term && String(r.title).toLowerCase().indexOf(term) < 0) { return false; }
        return true;
    });
    _editPage = 1;
    renderEditAtemTable();
}

var EDIT_ARCI_COLORS = { 'Issuer': '#198754', 'A': '#6610f2', 'R': '#0d6efd', 'C': '#fd7e14', 'I': '#6c757d' };

function editEsc(s) {
    return String(s == null ? '' : s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

function editFmtDate(d) {
    if (!d) { return '-'; }
    var p = String(d).substring(0, 10).split('-');
    return (p.length === 3) ? (p[2] + '-' + p[1] + '-' + p[0]) : d;
}

function editFmtMoney(n) {
    var x = parseFloat(n) || 0;
    return x.toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
}

function editPageBtn(p) {
    return '<button type="button" class="atem-pager-btn' + (p === _editPage ? ' active' : '') + '" data-page="' + p + '">' + p + '</button>';
}

function renderEditAtemPager(total) {
    var pager = document.getElementById('edit-atem-pager');
    if (!pager) { return; }
    if (total === 0) { pager.innerHTML = ''; return; }
    var pages    = Math.max(1, Math.ceil(total / _editPerPage));
    var startIdx = (_editPage - 1) * _editPerPage;
    var shown    = Math.min(_editPerPage, total - startIdx);

    var opts = [10, 30, 50, 100];
    var selHtml = '<select class="atem-perpage-select">';
    for (var oi = 0; oi < opts.length; oi++) {
        selHtml += '<option value="' + opts[oi] + '"' + (_editPerPage === opts[oi] ? ' selected' : '') + '>' + opts[oi] + '</option>';
    }
    selHtml += '</select>';
    var leftHtml = '<div class="atem-pager-left">Show ' + selHtml + ' entries</div>';

    var info = '<span class="atem-pager-info">Showing ' + (startIdx + 1) + ' to ' + (startIdx + shown) + ' of ' + total + ' entries</span>';
    var btns = '<button type="button" class="atem-pager-btn" data-page="' + (_editPage - 1) + '"' + (_editPage <= 1 ? ' disabled' : '') + '>Previous</button>';

    var win = 2, pfrom = Math.max(1, _editPage - win), pto = Math.min(pages, _editPage + win);
    if (pfrom > 1) { btns += editPageBtn(1) + (pfrom > 2 ? '<span class="atem-pager-gap">...</span>' : ''); }
    for (var pp = pfrom; pp <= pto; pp++) { btns += editPageBtn(pp); }
    if (pto < pages) { btns += (pto < pages - 1 ? '<span class="atem-pager-gap">...</span>' : '') + editPageBtn(pages); }
    btns += '<button type="button" class="atem-pager-btn" data-page="' + (_editPage + 1) + '"' + (_editPage >= pages ? ' disabled' : '') + '>Next</button>';

    var rightHtml = '<div class="d-flex align-items-center gap-2">' + info + '<div class="atem-pager-bar">' + btns + '</div></div>';
    pager.innerHTML = leftHtml + rightHtml;
}

function renderEditAtemTable() {
    var data  = _editFilteredData;
    var tbody = document.getElementById('edit-atem-tbody');
    if (!tbody) { return; }

    if (!data.length) {
        var emptyMsg = (window.EDIT_ATEM_ROWS && window.EDIT_ATEM_ROWS.length > 0)
            ? 'No records match the current filters.'
            : 'No ATEM records found for this staff.';
        tbody.innerHTML = '<tr><td colspan="12" class="text-center text-muted py-4">' + emptyMsg + '</td></tr>';
        renderEditAtemPager(0);
        return;
    }

    var total    = data.length;
    var pages    = Math.max(1, Math.ceil(total / _editPerPage));
    if (_editPage > pages) { _editPage = pages; }
    var startIdx = (_editPage - 1) * _editPerPage;
    var pageData = data.slice(startIdx, startIdx + _editPerPage);

    var html = '';
    for (var i = 0; i < pageData.length; i++) {
        var a = pageData[i];

        var accountableHtml = '';
        if (a.accountable && a.accountable.length) {
            for (var ai = 0; ai < a.accountable.length; ai++) {
                if (ai > 0) { accountableHtml += '<div style="border-top:1px solid #dee2e6;margin:4px 0;"></div>'; }
                accountableHtml += '<div style="font-size:13px;">' + editEsc(a.accountable[ai].name) + '</div>'
                    + '<div style="font-size:11px;color:#6c757d;">' + editEsc(a.accountable[ai].dept) + '</div>';
            }
        } else {
            accountableHtml = '<span style="color:#adb5bd;font-size:12px;">&mdash;</span>';
        }

        var rolesHtml = '';
        if (a.roles && a.roles.length) {
            for (var ri = 0; ri < a.roles.length; ri++) {
                var rc = EDIT_ARCI_COLORS[a.roles[ri]] || '#198754';
                rolesHtml += '<span style="display:inline-block;background:' + rc + ';color:#fff;font-size:11px;font-weight:600;padding:2px 7px;border-radius:4px;margin:1px 2px;">' + editEsc(a.roles[ri]) + '</span>';
            }
        } else {
            rolesHtml = '<span style="color:#adb5bd;font-size:12px;">&mdash;</span>';
        }

        var levelHtml = a.level
            ? '<span class="atem-pill" style="background-color:' + editEsc(a.level_color) + '"'
              + (a.level_sys ? ' title="' + editEsc(a.level_sys) + '"' : '') + '>' + editEsc(a.level) + '</span>'
            : '-';

        var endHtml = editFmtDate(a.end_date);
        if (a.is_extended && a.ext_date) {
            endHtml += '<div style="margin-top:3px;font-size:11px;color:#fd7e14;font-weight:500;">'
                + '<i class="bi bi-arrow-right" style="font-size:10px;"></i> Extended to ' + editFmtDate(a.ext_date) + '</div>';
        }

        var rewardHtml = (a.est_reward === null || a.est_reward === undefined)
            ? '<span class="text-muted">-</span>'
            : 'RM ' + editFmtMoney(a.est_reward);

        var payoutHtml = a.is_locked
            ? '<i class="bi bi-lock-fill" style="color:#dc3545;font-size:16px;" title="Payout locked"></i>'
            : '<i class="bi bi-unlock-fill" style="color:#ffc107;font-size:16px;" title="Payout not yet locked"></i>';

        html += '<tr>'
            + '<td><span class="atem-id">#AT' + a.id + '</span></td>'
            + '<td>' + editEsc(a.title) + '</td>'
            + '<td>'
            + '<div style="font-size:13px;">' + editEsc(a.issuer_name) + '</div>'
            + (a.issuer_dept ? '<div style="font-size:11px;color:#6c757d;">' + editEsc(a.issuer_dept) + '</div>' : '')
            + '</td>'
            + '<td>' + accountableHtml + '</td>'
            + '<td>' + rolesHtml + '</td>'
            + '<td>' + levelHtml + '</td>'
            + '<td style="white-space:nowrap;">' + editFmtDate(a.start_date) + '</td>'
            + '<td style="white-space:nowrap;">' + endHtml + '</td>'
            + '<td><span class="atem-pill" style="background-color:' + editEsc(a.status_color) + '">' + editEsc(a.status || '-') + '</span></td>'
            + '<td class="text-end">' + rewardHtml + '</td>'
            + '<td><a class="btn btn-sm btn-outline-primary" href="' + window.ATEM_MODULE_BASE + 'edit.php?id=' + a.id + '&mode=read" title="View"><i class="bi bi-eye"></i></a></td>'
            + '<td class="text-center">' + payoutHtml + '</td>'
            + '</tr>';
    }
    tbody.innerHTML = html;
    renderEditAtemPager(total);
}

// Same status-dropdown box-model sync used by buildEditStatusDropdown().
function syncS2ButtonSizeEdit(btnEl, refEl) {
    if (!btnEl || !refEl) { return; }
    var refStyle = window.getComputedStyle(refEl);
    btnEl.style.height       = refEl.offsetHeight + 'px';
    btnEl.style.border       = refStyle.border;
    btnEl.style.borderRadius = refStyle.borderRadius;
    btnEl.style.fontSize     = refStyle.fontSize;
    btnEl.style.color        = refStyle.color;
}

document.addEventListener('DOMContentLoaded', function() {
    buildEditFilters();
    buildEditQuarterDropdown();
    applyEditFilters();

    // Filter events
    var _efIds = ['ef-year', 'ef-level', 'ef-from', 'ef-to'];
    for (var _efi = 0; _efi < _efIds.length; _efi++) {
        var _efEl = document.getElementById(_efIds[_efi]);
        if (_efEl) { _efEl.addEventListener('change', applyEditFilters); }
    }
    // Month / quarter mutual exclusion, then auto-apply - mirrors index.php's
    // perf-filter-month handling (the Quarter side of this exclusion is wired
    // inside buildEditQuarterDropdown() above).
    var efMonthEl = document.getElementById('ef-month');
    if (efMonthEl) {
        efMonthEl.addEventListener('change', function() {
            if (this.value && this.value !== '0') { resetEditQuarterDropdown(); }
            applyEditFilters();
        });
    }
    var efSearch = document.getElementById('ef-search');
    if (efSearch) { efSearch.addEventListener('keyup', applyEditFilters); }
    var efReset = document.getElementById('ef-reset');
    if (efReset) {
        efReset.addEventListener('click', function() {
            ['ef-year', 'ef-level', 'ef-from', 'ef-to', 'ef-search'].forEach(function(id) {
                var el = document.getElementById(id);
                if (el) { el.value = ''; }
            });
            var efMonth = document.getElementById('ef-month');
            if (efMonth) { efMonth.value = '0'; }
            resetEditQuarterDropdown();
            resetEditStatusDropdown();
            resetEditRoleDropdown();
            applyEditFilters();
        });
    }

    var editPager = document.getElementById('edit-atem-pager');
    if (editPager) {
        editPager.addEventListener('click', function(e) {
            var btn = e.target.closest ? e.target.closest('.atem-pager-btn') : null;
            if (btn && !btn.disabled) {
                var p = parseInt(btn.getAttribute('data-page'), 10);
                if (p >= 1) { _editPage = p; renderEditAtemTable(); }
            }
        });
        editPager.addEventListener('change', function(e) {
            if (e.target.classList.contains('atem-perpage-select')) {
                _editPerPage = parseInt(e.target.value, 10);
                _editPage = 1;
                renderEditAtemTable();
            }
        });
    }

    function triggerExport(url) {
        var token = Date.now().toString(36) + Math.random().toString(36).substr(2, 4);
        var sep   = url.indexOf('?') >= 0 ? '&' : '?';
        var wrap  = document.getElementById('export-progress-wrap');
        var msg   = document.getElementById('export-progress-msg');
        if (wrap) { wrap.style.display = 'block'; }
        if (msg)  { msg.textContent = 'Preparing export...'; }

        window.location.href = url + sep + 'dl_token=' + token;

        var cookieName = 'export_done_' + token;
        var pollTimer  = setInterval(function() {
            if (document.cookie.indexOf(cookieName) >= 0) {
                clearInterval(pollTimer);
                document.cookie = cookieName + '=;expires=Thu, 01 Jan 1970 00:00:00 GMT;path=/';
                if (msg) { msg.textContent = 'Done.'; }
                setTimeout(function() { if (wrap) { wrap.style.display = 'none'; } }, 1500);
            }
        }, 500);
        setTimeout(function() { clearInterval(pollTimer); if (wrap) { wrap.style.display = 'none'; } }, 60000);
    }

    var exportAtemBtn = document.getElementById('export-atem-btn');
    if (exportAtemBtn) {
        exportAtemBtn.addEventListener('click', function(e) {
            e.preventDefault();
            triggerExport(this.href);
        });
    }

});
</script>

<style>
#export-progress-wrap span { font-size: 12px; color: #6c757d; }
@keyframes atem-export-slide {
    0%   { transform: translateX(-150%); }
    100% { transform: translateX(400%); }
}
.atem-export-bar-anim {
    background: #198754;
    height: 100%;
    width: 35%;
    animation: atem-export-slide 1.2s ease-in-out infinite;
}
</style>

<?php include('../footer.php'); ?>
