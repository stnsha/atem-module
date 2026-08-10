<?php
$page_title = 'ATEM Dashboard';
// $page_title_actions must be set before including header.php (it echoes this
// during its own render), but the dev-override-aware $atem_permission/
// $_is_superadmin/$struct aren't resolved until header.php runs lock_adv.php -
// so this renders unconditionally with an id, and is hidden by inline JS just
// after the include below once those are actually available.
$page_title_actions = '<a href="okr/index.php" id="switch-okr-btn" class="btn btn-outline-primary btn-sm"><i class="bi bi-arrow-left-right"></i> Switch to OKR Dashboard</a>';
include('header.php');

$_okr_menu_visible = ((int)$atem_permission >= 3 || $_is_superadmin || in_array((int)$struct, array(4, 5), true));
?>
<?php if (!$_okr_menu_visible): ?>
<script>
(function() {
    var el = document.getElementById('switch-okr-btn');
    if (el) {
        el.remove();
    }
})();
</script>
<?php endif; ?>
<?php
$_dash_cur_year = max(2026, (int)date('Y'));
$dash_year_options = array();
for ($y = 2026; $y <= $_dash_cur_year; $y++) {
    $dash_year_options[] = $y;
}

// staff.department is comma-separated (e.g. "3,7"); parse all of the user's
// departments so dept-scoped grades only see their own departments here.
$dash_user_dept_ids = array();
if (isset($department) && $department !== '') {
    foreach (explode(',', (string)$department) as $_dpart) {
        $_dpart = (int)trim($_dpart);
        if ($_dpart > 0) { $dash_user_dept_ids[] = $_dpart; }
    }
}

// Grade 1 and 2 users only ever belong to one side (HQ or Outlet, per their
// own department), so showing both tabs is misleading noise - collapse to the
// single matching tab, like the pre-tab single-view page. Grade 3+ and
// SuperAdmin keep seeing both tabs as today (deferred to a future task).
$grade1_single_view = null;
if (((int)$atem_permission === 1 || (int)$atem_permission === 2) && !$_is_superadmin) {
    $grade1_single_view = in_array(1, $dash_user_dept_ids, true) ? 'outlet' : 'hq';
}

$dash_dept_options = array();
$_dept_res = mysqli_query($conn, "SELECT id, depart_name FROM staff_department ORDER BY depart_name");
if ($_dept_res) {
    while ($_drow = mysqli_fetch_assoc($_dept_res)) {
        if ((int)$atem_permission >= 4 || $_is_superadmin) {
            $dash_dept_options[] = array('id' => (int)$_drow['id'], 'name' => $_drow['depart_name']);
        } elseif (((int)$atem_permission === 2 || (int)$atem_permission === 3)
                  && in_array((int)$_drow['id'], $dash_user_dept_ids)) {
            $dash_dept_options[] = array('id' => (int)$_drow['id'], 'name' => $_drow['depart_name']);
        }
    }
}

// Staff filter dropdown, scoped the same way as the Issuer dropdown on view.php:
// grade 1 sees only self, grades 2-3 see staff in their own department(s), grade
// 4+/SuperAdmin see everyone. dept_ids lets the frontend narrow the list further
// when a specific department is also selected.
// status_rym lets the Outlet dashboard tab narrow its Staff dropdown to Area
// Managers (status_rym = 134); outlet_ids then lets the Outlet dropdown follow
// the selected staff member's own outlet(s).
$dash_staff_options = array();
if ((int)$atem_permission === 1 && !$_is_superadmin) {
    $_me_id = (int)$id_user;
    $_me_res = mysqli_query($conn, "SELECT id, nama_staff, department, outlet, status_rym FROM staff WHERE recycle != 1 AND id = " . $_me_id);
    if ($_me_res && ($_me_row = mysqli_fetch_assoc($_me_res))) {
        $_deptIds = array();
        foreach (explode(',', (string)$_me_row['department']) as $_p) {
            $_p = (int)trim($_p);
            if ($_p > 0) { $_deptIds[] = $_p; }
        }
        $_outletIds = array();
        foreach (explode(',', (string)$_me_row['outlet']) as $_o) {
            $_o = (int)trim($_o);
            if ($_o > 0) { $_outletIds[] = $_o; }
        }
        $dash_staff_options[] = array('id' => (int)$_me_row['id'], 'name' => $_me_row['nama_staff'], 'dept_ids' => $_deptIds, 'outlet_ids' => $_outletIds, 'status_rym' => (int)$_me_row['status_rym']);
    }
} else {
    $_staff_res = mysqli_query($conn, "SELECT id, nama_staff, department, outlet, status_rym FROM staff WHERE recycle != 1 ORDER BY nama_staff ASC");
    if ($_staff_res) {
        while ($_srow = mysqli_fetch_assoc($_staff_res)) {
            $_deptIds = array();
            foreach (explode(',', (string)$_srow['department']) as $_p) {
                $_p = (int)trim($_p);
                if ($_p > 0) { $_deptIds[] = $_p; }
            }
            if (((int)$atem_permission === 2 || (int)$atem_permission === 3) && !$_is_superadmin
                && !array_intersect($_deptIds, $dash_user_dept_ids)) {
                continue;
            }
            $_outletIds = array();
            foreach (explode(',', (string)$_srow['outlet']) as $_o) {
                $_o = (int)trim($_o);
                if ($_o > 0) { $_outletIds[] = $_o; }
            }
            $dash_staff_options[] = array('id' => (int)$_srow['id'], 'name' => $_srow['nama_staff'], 'dept_ids' => $_deptIds, 'outlet_ids' => $_outletIds, 'status_rym' => (int)$_srow['status_rym']);
        }
    }
}

// Regions (odb.outlet_regional) + outlets (odb DB, each carrying its
// regional_id) for the Outlet dashboard tab's filter bar. Chain:
// outlet_regional -> outlet.regional_id -> staff.status_rym = 134.
define('API_JWT_INCLUDED', true);
include(dirname(__FILE__) . '/api.php');
$dash_region_options = array();
$_region_res = mysqli_query($conn, "SELECT id, regional FROM outlet_regional ORDER BY regional ASC");
if ($_region_res) {
    while ($_rrow = mysqli_fetch_assoc($_region_res)) {
        $dash_region_options[] = array('id' => (int)$_rrow['id'], 'name' => $_rrow['regional']);
    }
}
$dash_outlet_options = array();
$_outlet_res = mysqli_query($conn, "SELECT id, code, regional_id FROM outlet ORDER BY code ASC");
if ($_outlet_res) {
    while ($_orow = mysqli_fetch_assoc($_outlet_res)) {
        $dash_outlet_options[] = array('id' => (int)$_orow['id'], 'code' => $_orow['code'], 'region_id' => (int)$_orow['regional_id']);
    }
}
?>

<script>
window.ATEM_DASH = <?php echo json_encode(array(
    'apiUrl'         => ATEM_BASE . 'api.php',
    'departments'    => $dash_dept_options,
    'staff'          => $dash_staff_options,
    'regions'        => $dash_region_options,
    'outlets'        => $dash_outlet_options,
    'tabSingleView'  => $grade1_single_view,
)); ?>;
</script>

<!-- ATEM Type Tabs -->
<?php if ($grade1_single_view === null): ?>
<ul class="nav nav-tabs atem-view-tabs mb-3" id="dash-view-tabs" role="tablist">
    <li class="nav-item" role="presentation">
        <button class="nav-link active atem-tab-color-hq" id="dash-tab-hq-btn" data-bs-toggle="tab"
            data-bs-target="#dash-tab-hq" type="button" role="tab" aria-controls="dash-tab-hq" aria-selected="true">
            <i class="bi bi-building"></i> HQ ATEM
        </button>
    </li>
    <li class="nav-item" role="presentation">
        <button class="nav-link atem-tab-color-outlet" id="dash-tab-outlet-btn" data-bs-toggle="tab"
            data-bs-target="#dash-tab-outlet" type="button" role="tab" aria-controls="dash-tab-outlet"
            aria-selected="false">
            <i class="bi bi-shop"></i> Outlet ATEM
        </button>
    </li>
</ul>
<?php endif; ?>
<div class="tab-content">
    <div class="tab-pane fade<?php echo ($grade1_single_view === null || $grade1_single_view === 'hq') ? ' show active' : ''; ?>"
        id="dash-tab-hq" role="tabpanel" aria-labelledby="dash-tab-hq-btn">

        <!-- Filter card -->
        <div class="atem-card atem-filter mb-3">
            <h6 class="atem-card-title"><i class="bi bi-funnel"></i> Filter</h6>
            <div class="row g-2 mt-1 align-items-end">
                <div class="col-md-2 col-sm-6">
                    <label class="form-label">Year</label>
                    <select id="dash-filter-year" class="form-select form-select-sm">
                        <option value="">All Years</option>
                        <?php foreach ($dash_year_options as $y): ?>
                        <option value="<?php echo $y; ?>" <?php echo ($y === 2026) ? ' selected' : ''; ?>>
                            <?php echo $y; ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2 col-sm-6">
                    <label class="form-label">Month</label>
                    <select id="dash-filter-month" class="form-select form-select-sm">
                        <option value="">All Months</option>
                        <option value="1">January</option>
                        <option value="2">February</option>
                        <option value="3">March</option>
                        <option value="4">April</option>
                        <option value="5">May</option>
                        <option value="6">June</option>
                        <option value="7">July</option>
                        <option value="8">August</option>
                        <option value="9">September</option>
                        <option value="10">October</option>
                        <option value="11">November</option>
                        <option value="12">December</option>
                    </select>
                </div>
                <div class="col-md-2 col-sm-6">
                    <label class="form-label">Quarter</label>
                    <div class="vf-issuer-wrap" id="dash-quarter-wrap">
                        <div class="vf-s2-selection" id="dash-quarter-btn" tabindex="0">All Quarters</div>
                        <div class="vf-s2-dropdown" id="dash-quarter-dropdown">
                            <ul class="vf-s2-list" style="padding:4px 0;">
                                <li class="vf-s2-list-item" style="cursor:default;">
                                    <label style="display:flex;align-items:center;gap:6px;width:100%;cursor:pointer;margin:0;">
                                        <input type="checkbox" class="dash-quarter-cb" value="1"> Q1 (Jan &ndash; Mar)
                                    </label>
                                </li>
                                <li class="vf-s2-list-item" style="cursor:default;">
                                    <label style="display:flex;align-items:center;gap:6px;width:100%;cursor:pointer;margin:0;">
                                        <input type="checkbox" class="dash-quarter-cb" value="2"> Q2 (Apr &ndash; Jun)
                                    </label>
                                </li>
                                <li class="vf-s2-list-item" style="cursor:default;">
                                    <label style="display:flex;align-items:center;gap:6px;width:100%;cursor:pointer;margin:0;">
                                        <input type="checkbox" class="dash-quarter-cb" value="3"> Q3 (Jul &ndash; Sep)
                                    </label>
                                </li>
                                <li class="vf-s2-list-item" style="cursor:default;">
                                    <label style="display:flex;align-items:center;gap:6px;width:100%;cursor:pointer;margin:0;">
                                        <input type="checkbox" class="dash-quarter-cb" value="4"> Q4 (Oct &ndash; Dec)
                                    </label>
                                </li>
                            </ul>
                        </div>
                    </div>
                </div>
                <div class="col-md-3 col-sm-6" id="dash-dept-col"
                    <?php if (empty($dash_dept_options)) { echo ' style="display:none;"'; } ?>>
                    <label class="form-label">Department</label>
                    <select id="dash-filter-dept" class="form-select form-select-sm">
                        <option value="">All Departments</option>
                    </select>
                </div>
                <div class="col-md-3 col-sm-6">
                    <label class="form-label">Staff</label>
                    <div class="vf-issuer-wrap" id="dash-staff-wrap">
                        <div class="vf-s2-selection" id="dash-staff-btn" tabindex="0">All staff</div>
                        <div class="vf-s2-dropdown" id="dash-staff-dropdown">
                            <div class="vf-s2-search-wrap">
                                <input class="vf-s2-search" id="dash-staff-search" type="search"
                                    placeholder="Search name...">
                            </div>
                            <ul class="vf-s2-list" id="dash-staff-list"></ul>
                        </div>
                        <input type="hidden" id="dash-staff-value" value="0">
                    </div>
                </div>
            </div>
            <div class="d-flex justify-content-end align-items-center gap-2 mt-2">
                <span class="text-muted" id="dash-filter-label" style="font-size:12px;"></span>
                <button class="btn btn-sm btn-outline-secondary" id="dash-reset-filter">Reset</button>
            </div>
        </div>

        <!-- Stat Cards -->
        <div class="row g-3 mb-4">
            <div class="col-12 col-sm-6 col-xl">
                <div class="atem-card atem-dash-stat h-100" style="cursor:pointer;">
                    <div class="atem-card-title mb-1">Total ATEM Cards</div>
                    <div class="atem-stat-value atem-stat-value--blue" id="dash-total">---</div>
                    <div class="atem-stat-label">created YTD</div>
                </div>
            </div>
            <div class="col-12 col-sm-6 col-xl">
                <div class="atem-card atem-dash-stat h-100" data-status="Active" style="cursor:pointer;">
                    <div class="atem-card-title mb-1">Active / Extended</div>
                    <div class="atem-stat-value atem-stat-value--blue" id="dash-active">---</div>
                    <div class="atem-stat-label" id="dash-extended-label" style="display:none;color:#fd7e14;">
                    </div>
                </div>
            </div>
            <div class="col-12 col-sm-6 col-xl">
                <div class="atem-card atem-dash-stat h-100" data-statuses="Completed,Completed with Excellence"
                    style="cursor:pointer;">
                    <div class="atem-card-title mb-1">Complete + Excellence</div>
                    <div class="atem-stat-value atem-stat-value--green" id="dash-closed">---</div>
                    <div class="atem-stat-label">closed successfully</div>
                </div>
            </div>
            <div class="col-12 col-sm-6 col-xl">
                <div class="atem-card atem-dash-stat h-100" data-status="Failed" style="cursor:pointer;">
                    <div class="atem-card-title mb-1">Failed ATEM</div>
                    <div class="atem-stat-value atem-stat-value--red" id="dash-failed">---</div>
                    <div class="atem-stat-label" id="dash-fail-rate">failure rate</div>
                </div>
            </div>
            <div class="col-12 col-sm-6 col-xl">
                <div class="atem-card atem-dash-stat h-100" data-overdue="1" data-statuses="Active,Extended"
                    style="cursor:pointer;">
                    <div class="atem-card-title mb-1">Overdue Cards</div>
                    <div class="atem-stat-value atem-stat-value--red" id="dash-overdue">---</div>
                    <div class="atem-stat-label">past due date</div>
                </div>
            </div>
            <div class="col-12 col-sm-6 col-xl">
                <div class="atem-card atem-dash-stat h-100" data-status="Suspended" style="cursor:pointer;">
                    <div class="atem-card-title mb-1">Suspended</div>
                    <div class="atem-stat-value atem-stat-value--red" id="dash-suspended">---</div>
                    <div class="atem-stat-label">temporarily on hold</div>
                </div>
            </div>
            <div class="col-12 col-sm-6 col-xl">
                <div class="atem-card atem-dash-stat h-100" data-incentive="1"
                    data-statuses="Active,Extended,Completed,Completed with Excellence" style="cursor:pointer;">
                    <div class="atem-card-title mb-1">Est. Incentive Forecast</div>
                    <div class="atem-stat-value atem-stat-value--orange" id="dash-incentive">---</div>
                    <div class="atem-stat-label">Level 2-4 payout</div>
                </div>
            </div>
        </div>

        <!-- My Involvement + ATEM Complexity Reward -->
        <div class="row g-3">
            <div class="col-lg-6">
                <div class="atem-card h-100">
                    <h6 class="atem-card-title mb-0" id="dash-myroles-title">My Involvement</h6>
                    <div class="text-muted mb-3" style="font-size:12px;padding-top:4px;" id="dash-myroles-subtitle">
                        Cards where you are the Issuer or an ARCI member, by role</div>
                    <div class="row g-2" id="dash-myroles-row">
                        <div class="col-6 col-md-4 col-xl-2">
                            <div class="atem-card atem-dash-stat h-100" style="cursor:pointer;padding:10px;"
                                data-issuer="me">
                                <div class="atem-card-title mb-1" style="font-size:11px;">Issuer</div>
                                <div class="atem-stat-value atem-stat-value--blue" id="dash-myrole-issuer"
                                    style="font-size:22px;">---</div>
                            </div>
                        </div>
                        <div class="col-6 col-md-4 col-xl-2">
                            <div class="atem-card atem-dash-stat h-100" style="cursor:pointer;padding:10px;"
                                data-myrole="A">
                                <div class="atem-card-title mb-1" style="font-size:11px;">Accountable (A)</div>
                                <div class="atem-stat-value" id="dash-myrole-a" style="font-size:22px;color:#6610f2;">
                                    ---</div>
                            </div>
                        </div>
                        <div class="col-6 col-md-4 col-xl-2">
                            <div class="atem-card atem-dash-stat h-100" style="cursor:pointer;padding:10px;"
                                data-myrole="R">
                                <div class="atem-card-title mb-1" style="font-size:11px;">Responsible (R)</div>
                                <div class="atem-stat-value" id="dash-myrole-r" style="font-size:22px;color:#0d6efd;">
                                    ---</div>
                            </div>
                        </div>
                        <div class="col-6 col-md-4 col-xl-2">
                            <div class="atem-card atem-dash-stat h-100" style="cursor:pointer;padding:10px;"
                                data-myrole="C">
                                <div class="atem-card-title mb-1" style="font-size:11px;">Consulted (C)</div>
                                <div class="atem-stat-value" id="dash-myrole-c" style="font-size:22px;color:#fd7e14;">
                                    ---</div>
                            </div>
                        </div>
                        <div class="col-6 col-md-4 col-xl-2">
                            <div class="atem-card atem-dash-stat h-100" style="cursor:pointer;padding:10px;"
                                data-myrole="I">
                                <div class="atem-card-title mb-1" style="font-size:11px;">Informed (I)</div>
                                <div class="atem-stat-value" id="dash-myrole-i" style="font-size:22px;color:#6c757d;">
                                    ---</div>
                            </div>
                        </div>
                        <div class="col-6 col-md-4 col-xl-2">
                            <div class="atem-card atem-dash-stat h-100" style="cursor:pointer;padding:10px;"
                                data-mine="1">
                                <div class="atem-card-title mb-1" style="font-size:11px;">Total Involved</div>
                                <div class="atem-stat-value atem-stat-value--green" id="dash-myrole-involved"
                                    style="font-size:22px;">---</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-lg-6">
                <div class="atem-card h-100">
                    <h6 class="atem-card-title mb-0">ATEM Complexity Reward</h6>
                    <div class="text-muted mb-3" style="font-size:12px;padding-top:4px;">Level 1 RM0; Level 2-4 follow
                        incentive
                        value</div>
                    <div class="table-responsive">
                        <table class="table table-sm align-middle mb-0">
                            <thead>
                                <tr>
                                    <th style="font-size:12px;text-align:left;">Level</th>
                                    <th style="font-size:12px;text-align:left;">Cards</th>
                                    <th style="font-size:12px;text-align:left;">Complete</th>
                                    <th style="font-size:12px;text-align:left;">Excellence</th>
                                    <th style="font-size:12px;text-align:left;">Fail</th>
                                    <th style="font-size:12px;text-align:left;">Forecast</th>
                                </tr>
                            </thead>
                            <tbody id="dash-level-body">
                                <tr>
                                    <td colspan="6" class="text-muted" style="font-size:12px;">Loading...</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <!-- Suspended & Force Terminated Breakdown + Closure & Failure Analysis -->
        <div class="row g-3 mt-0">
            <div class="col-lg-6">
                <div class="atem-card h-100">
                    <h6 class="atem-card-title mb-0">Suspended &amp; Force Terminated Breakdown</h6>
                    <div class="text-muted mb-3" style="font-size:12px;padding-top:4px;">By issuer - click a row to
                        view their cards</div>
                    <div class="table-responsive" style="max-height:280px;overflow-y:auto;">
                        <table class="table table-sm align-middle mb-0">
                            <thead>
                                <tr>
                                    <th style="font-size:12px;text-align:left;">Issuer</th>
                                    <th style="font-size:12px;text-align:left;">Department</th>
                                    <th style="font-size:12px;text-align:left;">Suspended</th>
                                    <th style="font-size:12px;text-align:left;">Force Terminated</th>
                                </tr>
                            </thead>
                            <tbody id="dash-sft-body">
                                <tr>
                                    <td colspan="4" class="text-muted" style="font-size:12px;">Loading...</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            <div class="col-lg-6">
                <div class="atem-card h-100">
                    <h6 class="atem-card-title mb-0">Closure &amp; Failure Analysis</h6>
                    <div class="text-muted mb-3" style="font-size:12px;padding-top:4px;">Issuer-only closure: Complete,
                        Excellence, Extend, Fail</div>
                    <div id="dash-bars">
                        <div class="atem-bar-row">
                            <div class="atem-bar-label">Excellence</div>
                            <div class="atem-bar-track">
                                <div class="atem-bar-fill" id="bar-excellence" style="width:0%;background:#198754;">
                                </div>
                            </div>
                            <div class="atem-bar-count" id="bar-excellence-n">-</div>
                        </div>
                        <div class="atem-bar-row">
                            <div class="atem-bar-label">Complete</div>
                            <div class="atem-bar-track">
                                <div class="atem-bar-fill" id="bar-complete" style="width:0%;background:#0d6efd;"></div>
                            </div>
                            <div class="atem-bar-count" id="bar-complete-n">-</div>
                        </div>
                        <div class="atem-bar-row">
                            <div class="atem-bar-label">Extended</div>
                            <div class="atem-bar-track">
                                <div class="atem-bar-fill" id="bar-extended" style="width:0%;background:#fd7e14;"></div>
                            </div>
                            <div class="atem-bar-count" id="bar-extended-n">-</div>
                        </div>
                        <div class="atem-bar-row">
                            <div class="atem-bar-label">Fail</div>
                            <div class="atem-bar-track">
                                <div class="atem-bar-fill" id="bar-failed" style="width:0%;background:#dc3545;"></div>
                            </div>
                            <div class="atem-bar-count" id="bar-failed-n">-</div>
                        </div>
                        <div class="atem-bar-row">
                            <div class="atem-bar-label">Suspended</div>
                            <div class="atem-bar-track">
                                <div class="atem-bar-fill" id="bar-suspended" style="width:0%;background:#e11d48;">
                                </div>
                            </div>
                            <div class="atem-bar-count" id="bar-suspended-n">-</div>
                        </div>
                        <div class="atem-bar-row">
                            <div class="atem-bar-label">Force Terminated</div>
                            <div class="atem-bar-track">
                                <div class="atem-bar-fill" id="bar-force-terminated"
                                    style="width:0%;background:#7c3aed;"></div>
                            </div>
                            <div class="atem-bar-count" id="bar-force-terminated-n">-</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Department Breakdown -->
        <div class="row g-3 mt-0">
            <div class="col-12">
                <div class="atem-card">
                    <h6 class="atem-card-title mb-0">Department Breakdown</h6>
                    <div class="text-muted mb-3" style="font-size:12px;padding-top:4px;">Cards, outcomes and incentive
                        forecast
                        by issuer department</div>
                    <div class="table-responsive">
                        <table class="table table-sm align-middle mb-0">
                            <thead>
                                <tr>
                                    <th style="font-size:12px;text-align:left;">Department</th>
                                    <th style="font-size:12px;text-align:left;">Cards</th>
                                    <th style="font-size:12px;text-align:left;">Complete</th>
                                    <th style="font-size:12px;text-align:left;">Excellence</th>
                                    <th style="font-size:12px;text-align:left;">Fail</th>
                                    <th style="font-size:12px;text-align:left;">Fail Rate</th>
                                    <th style="font-size:12px;text-align:left;">Forecast</th>
                                </tr>
                            </thead>
                            <tbody id="dash-dept-body">
                                <tr>
                                    <td colspan="7" class="text-muted" style="font-size:12px;">Loading...</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

    </div>
    <div class="tab-pane fade<?php echo ($grade1_single_view === 'outlet') ? ' show active' : ''; ?>"
        id="dash-tab-outlet" role="tabpanel" aria-labelledby="dash-tab-outlet-btn">

        <!-- Outlet Filter card -->
        <div class="atem-card atem-filter mb-3">
            <h6 class="atem-card-title"><i class="bi bi-funnel"></i> Filter</h6>
            <div class="row g-2 mt-1 align-items-end">
                <div class="col-md-2 col-sm-6">
                    <label class="form-label">Year</label>
                    <select id="dasho-filter-year" class="form-select form-select-sm">
                        <option value="">All Years</option>
                        <?php foreach ($dash_year_options as $y): ?>
                        <option value="<?php echo $y; ?>" <?php echo ($y === 2026) ? ' selected' : ''; ?>>
                            <?php echo $y; ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2 col-sm-6">
                    <label class="form-label">Month</label>
                    <select id="dasho-filter-month" class="form-select form-select-sm">
                        <option value="">All Months</option>
                        <option value="1">January</option>
                        <option value="2">February</option>
                        <option value="3">March</option>
                        <option value="4">April</option>
                        <option value="5">May</option>
                        <option value="6">June</option>
                        <option value="7">July</option>
                        <option value="8">August</option>
                        <option value="9">September</option>
                        <option value="10">October</option>
                        <option value="11">November</option>
                        <option value="12">December</option>
                    </select>
                </div>
                <div class="col-md-2 col-sm-6">
                    <label class="form-label">Quarter</label>
                    <div class="vf-issuer-wrap" id="dasho-quarter-wrap">
                        <div class="vf-s2-selection" id="dasho-quarter-btn" tabindex="0">All Quarters</div>
                        <div class="vf-s2-dropdown" id="dasho-quarter-dropdown">
                            <ul class="vf-s2-list" style="padding:4px 0;">
                                <li class="vf-s2-list-item" style="cursor:default;">
                                    <label style="display:flex;align-items:center;gap:6px;width:100%;cursor:pointer;margin:0;">
                                        <input type="checkbox" class="dasho-quarter-cb" value="1"> Q1 (Jan &ndash; Mar)
                                    </label>
                                </li>
                                <li class="vf-s2-list-item" style="cursor:default;">
                                    <label style="display:flex;align-items:center;gap:6px;width:100%;cursor:pointer;margin:0;">
                                        <input type="checkbox" class="dasho-quarter-cb" value="2"> Q2 (Apr &ndash; Jun)
                                    </label>
                                </li>
                                <li class="vf-s2-list-item" style="cursor:default;">
                                    <label style="display:flex;align-items:center;gap:6px;width:100%;cursor:pointer;margin:0;">
                                        <input type="checkbox" class="dasho-quarter-cb" value="3"> Q3 (Jul &ndash; Sep)
                                    </label>
                                </li>
                                <li class="vf-s2-list-item" style="cursor:default;">
                                    <label style="display:flex;align-items:center;gap:6px;width:100%;cursor:pointer;margin:0;">
                                        <input type="checkbox" class="dasho-quarter-cb" value="4"> Q4 (Oct &ndash; Dec)
                                    </label>
                                </li>
                            </ul>
                        </div>
                    </div>
                </div>
                <div class="col-md-2 col-sm-6">
                    <label class="form-label">Region</label>
                    <select id="dasho-filter-region" class="form-select form-select-sm">
                        <option value="">All Regions</option>
                    </select>
                </div>
                <div class="col-md-2 col-sm-6">
                    <label class="form-label">Outlet</label>
                    <div class="vf-issuer-wrap" id="dasho-outlet-wrap">
                        <div class="vf-s2-selection" id="dasho-outlet-btn" tabindex="0">All outlets</div>
                        <div class="vf-s2-dropdown" id="dasho-outlet-dropdown">
                            <div class="vf-s2-search-wrap">
                                <input class="vf-s2-search" id="dasho-outlet-search" type="search"
                                    placeholder="Search outlet...">
                            </div>
                            <ul class="vf-s2-list" id="dasho-outlet-list"></ul>
                        </div>
                        <input type="hidden" id="dasho-outlet-value" value="0">
                    </div>
                </div>
                <div class="col-md-2 col-sm-6">
                    <label class="form-label">Area Manager</label>
                    <div class="vf-issuer-wrap" id="dasho-staff-wrap">
                        <div class="vf-s2-selection" id="dasho-staff-btn" tabindex="0">All Area Managers</div>
                        <div class="vf-s2-dropdown" id="dasho-staff-dropdown">
                            <div class="vf-s2-search-wrap">
                                <input class="vf-s2-search" id="dasho-staff-search" type="search"
                                    placeholder="Search name...">
                            </div>
                            <ul class="vf-s2-list" id="dasho-staff-list"></ul>
                        </div>
                        <input type="hidden" id="dasho-staff-value" value="0">
                    </div>
                </div>
            </div>
            <div class="d-flex justify-content-end align-items-center gap-2 mt-2">
                <span class="text-muted" id="dasho-filter-label" style="font-size:12px;"></span>
                <button class="btn btn-sm btn-outline-secondary" id="dasho-reset-filter">Reset</button>
            </div>
        </div>

        <!-- Outlet Stat Cards -->
        <div class="row g-3 mb-4">
            <div class="col-12 col-sm-6 col-xl">
                <div class="atem-card atem-dash-stat-outlet h-100" style="cursor:pointer;">
                    <div class="atem-card-title mb-1">Total ATEM Cards</div>
                    <div class="atem-stat-value atem-stat-value--blue" id="dasho-total">---</div>
                    <div class="atem-stat-label">created YTD</div>
                </div>
            </div>
            <div class="col-12 col-sm-6 col-xl">
                <div class="atem-card atem-dash-stat-outlet h-100" data-status="Active" style="cursor:pointer;">
                    <div class="atem-card-title mb-1">Active / Extended</div>
                    <div class="atem-stat-value atem-stat-value--blue" id="dasho-active">---</div>
                    <div class="atem-stat-label" id="dasho-extended-label" style="display:none;color:#fd7e14;">
                    </div>
                </div>
            </div>
            <div class="col-12 col-sm-6 col-xl">
                <div class="atem-card atem-dash-stat-outlet h-100" data-statuses="Completed,Completed with Excellence"
                    style="cursor:pointer;">
                    <div class="atem-card-title mb-1">Complete + Excellence</div>
                    <div class="atem-stat-value atem-stat-value--green" id="dasho-closed">---</div>
                    <div class="atem-stat-label">closed successfully</div>
                </div>
            </div>
            <div class="col-12 col-sm-6 col-xl">
                <div class="atem-card atem-dash-stat-outlet h-100" data-status="Failed" style="cursor:pointer;">
                    <div class="atem-card-title mb-1">Failed ATEM</div>
                    <div class="atem-stat-value atem-stat-value--red" id="dasho-failed">---</div>
                    <div class="atem-stat-label" id="dasho-fail-rate">failure rate</div>
                </div>
            </div>
            <div class="col-12 col-sm-6 col-xl">
                <div class="atem-card atem-dash-stat-outlet h-100" data-overdue="1" data-statuses="Active,Extended"
                    style="cursor:pointer;">
                    <div class="atem-card-title mb-1">Overdue Cards</div>
                    <div class="atem-stat-value atem-stat-value--red" id="dasho-overdue">---</div>
                    <div class="atem-stat-label">past due date</div>
                </div>
            </div>
            <div class="col-12 col-sm-6 col-xl">
                <div class="atem-card atem-dash-stat-outlet h-100" data-status="Suspended" style="cursor:pointer;">
                    <div class="atem-card-title mb-1">Suspended</div>
                    <div class="atem-stat-value atem-stat-value--red" id="dasho-suspended">---</div>
                    <div class="atem-stat-label">temporarily on hold</div>
                </div>
            </div>
        </div>

        <!-- Outlet My Involvement + ATEM Pillar Reward -->
        <div class="row g-3">
            <div class="col-lg-6">
                <div class="atem-card h-100">
                    <h6 class="atem-card-title mb-0" id="dasho-myroles-title">My Involvement</h6>
                    <div class="text-muted mb-3" style="font-size:12px;padding-top:4px;" id="dasho-myroles-subtitle">
                        Cards where you are the Issuer or an ARCI member, by role</div>
                    <div class="row g-2" id="dasho-myroles-row">
                        <div class="col-6 col-md-4 col-xl-2">
                            <div class="atem-card atem-dash-stat-outlet h-100" style="cursor:pointer;padding:10px;"
                                data-issuer="me">
                                <div class="atem-card-title mb-1" style="font-size:11px;">Issuer</div>
                                <div class="atem-stat-value atem-stat-value--blue" id="dasho-myrole-issuer"
                                    style="font-size:22px;">---</div>
                            </div>
                        </div>
                        <div class="col-6 col-md-4 col-xl-2">
                            <div class="atem-card atem-dash-stat-outlet h-100" style="cursor:pointer;padding:10px;"
                                data-myrole="A">
                                <div class="atem-card-title mb-1" style="font-size:11px;">Accountable (A)</div>
                                <div class="atem-stat-value" id="dasho-myrole-a" style="font-size:22px;color:#6610f2;">
                                    ---</div>
                            </div>
                        </div>
                        <div class="col-6 col-md-4 col-xl-2">
                            <div class="atem-card atem-dash-stat-outlet h-100" style="cursor:pointer;padding:10px;"
                                data-myrole="R">
                                <div class="atem-card-title mb-1" style="font-size:11px;">Responsible (R)</div>
                                <div class="atem-stat-value" id="dasho-myrole-r" style="font-size:22px;color:#0d6efd;">
                                    ---</div>
                            </div>
                        </div>
                        <div class="col-6 col-md-4 col-xl-2">
                            <div class="atem-card atem-dash-stat-outlet h-100" style="cursor:pointer;padding:10px;"
                                data-myrole="C">
                                <div class="atem-card-title mb-1" style="font-size:11px;">Consulted (C)</div>
                                <div class="atem-stat-value" id="dasho-myrole-c" style="font-size:22px;color:#fd7e14;">
                                    ---</div>
                            </div>
                        </div>
                        <div class="col-6 col-md-4 col-xl-2">
                            <div class="atem-card atem-dash-stat-outlet h-100" style="cursor:pointer;padding:10px;"
                                data-myrole="I">
                                <div class="atem-card-title mb-1" style="font-size:11px;">Informed (I)</div>
                                <div class="atem-stat-value" id="dasho-myrole-i" style="font-size:22px;color:#6c757d;">
                                    ---</div>
                            </div>
                        </div>
                        <div class="col-6 col-md-4 col-xl-2">
                            <div class="atem-card atem-dash-stat-outlet h-100" style="cursor:pointer;padding:10px;"
                                data-mine="1">
                                <div class="atem-card-title mb-1" style="font-size:11px;">Total Involved</div>
                                <div class="atem-stat-value atem-stat-value--green" id="dasho-myrole-involved"
                                    style="font-size:22px;">---</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-lg-6">
                <div class="atem-card h-100">
                    <h6 class="atem-card-title mb-0">ATEM Pillar Reward</h6>
                    <div class="text-muted mb-3" style="font-size:12px;padding-top:4px;">Reward/deduction forecast by
                        pillar</div>
                    <div class="table-responsive">
                        <table class="table table-sm align-middle mb-0">
                            <thead>
                                <tr>
                                    <th style="font-size:12px;text-align:left;">Pillar</th>
                                    <th style="font-size:12px;text-align:left;">Cards</th>
                                    <th style="font-size:12px;text-align:left;">Complete</th>
                                    <th style="font-size:12px;text-align:left;">Excellence</th>
                                    <th style="font-size:12px;text-align:left;">Fail</th>
                                    <th style="font-size:12px;text-align:left;">Forecast</th>
                                </tr>
                            </thead>
                            <tbody id="dasho-pillar-body">
                                <tr>
                                    <td colspan="6" class="text-muted" style="font-size:12px;">Loading...</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <!-- Outlet Suspended & Force Terminated Breakdown + Closure & Failure Analysis -->
        <div class="row g-3 mt-0">
            <div class="col-lg-6">
                <div class="atem-card h-100">
                    <h6 class="atem-card-title mb-0">Suspended &amp; Force Terminated Breakdown</h6>
                    <div class="text-muted mb-3" style="font-size:12px;padding-top:4px;">By issuer - click a row to
                        view their cards</div>
                    <div class="table-responsive" style="max-height:280px;overflow-y:auto;">
                        <table class="table table-sm align-middle mb-0">
                            <thead>
                                <tr>
                                    <th style="font-size:12px;text-align:left;">Issuer</th>
                                    <th style="font-size:12px;text-align:left;">Department</th>
                                    <th style="font-size:12px;text-align:left;">Suspended</th>
                                    <th style="font-size:12px;text-align:left;">Force Terminated</th>
                                </tr>
                            </thead>
                            <tbody id="dasho-sft-body">
                                <tr>
                                    <td colspan="4" class="text-muted" style="font-size:12px;">Loading...</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            <div class="col-lg-6">
                <div class="atem-card h-100">
                    <h6 class="atem-card-title mb-0">Closure &amp; Failure Analysis</h6>
                    <div class="text-muted mb-3" style="font-size:12px;padding-top:4px;">Issuer-only closure: Complete,
                        Excellence, Extend, Fail</div>
                    <div id="dasho-bars">
                        <div class="atem-bar-row">
                            <div class="atem-bar-label">Excellence</div>
                            <div class="atem-bar-track">
                                <div class="atem-bar-fill" id="bar-o-excellence" style="width:0%;background:#198754;">
                                </div>
                            </div>
                            <div class="atem-bar-count" id="bar-o-excellence-n">-</div>
                        </div>
                        <div class="atem-bar-row">
                            <div class="atem-bar-label">Complete</div>
                            <div class="atem-bar-track">
                                <div class="atem-bar-fill" id="bar-o-complete" style="width:0%;background:#0d6efd;">
                                </div>
                            </div>
                            <div class="atem-bar-count" id="bar-o-complete-n">-</div>
                        </div>
                        <div class="atem-bar-row">
                            <div class="atem-bar-label">Extended</div>
                            <div class="atem-bar-track">
                                <div class="atem-bar-fill" id="bar-o-extended" style="width:0%;background:#fd7e14;">
                                </div>
                            </div>
                            <div class="atem-bar-count" id="bar-o-extended-n">-</div>
                        </div>
                        <div class="atem-bar-row">
                            <div class="atem-bar-label">Fail</div>
                            <div class="atem-bar-track">
                                <div class="atem-bar-fill" id="bar-o-failed" style="width:0%;background:#dc3545;"></div>
                            </div>
                            <div class="atem-bar-count" id="bar-o-failed-n">-</div>
                        </div>
                        <div class="atem-bar-row">
                            <div class="atem-bar-label">Suspended</div>
                            <div class="atem-bar-track">
                                <div class="atem-bar-fill" id="bar-o-suspended" style="width:0%;background:#e11d48;">
                                </div>
                            </div>
                            <div class="atem-bar-count" id="bar-o-suspended-n">-</div>
                        </div>
                        <div class="atem-bar-row">
                            <div class="atem-bar-label">Force Terminated</div>
                            <div class="atem-bar-track">
                                <div class="atem-bar-fill" id="bar-o-force-terminated"
                                    style="width:0%;background:#7c3aed;"></div>
                            </div>
                            <div class="atem-bar-count" id="bar-o-force-terminated-n">-</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Region & Outlet Breakdown -->
        <div class="row g-3 mt-0">
            <div class="col-lg-6">
                <div class="atem-card h-100">
                    <h6 class="atem-card-title mb-0">Region Breakdown</h6>
                    <div class="text-muted mb-3" style="font-size:12px;padding-top:4px;">Cards, outcomes and reward
                        forecast by outlet region</div>
                    <div class="table-responsive">
                        <table class="table table-sm align-middle mb-0">
                            <thead>
                                <tr>
                                    <th style="font-size:12px;text-align:left;">Region</th>
                                    <th style="font-size:12px;text-align:left;">Cards</th>
                                    <th style="font-size:12px;text-align:left;">Complete</th>
                                    <th style="font-size:12px;text-align:left;">Excellence</th>
                                    <th style="font-size:12px;text-align:left;">Fail</th>
                                    <th style="font-size:12px;text-align:left;">Fail Rate</th>
                                    <th style="font-size:12px;text-align:left;">Forecast</th>
                                </tr>
                            </thead>
                            <tbody id="dasho-region-body">
                                <tr>
                                    <td colspan="7" class="text-muted" style="font-size:12px;">Loading...</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            <div class="col-lg-6">
                <div class="atem-card h-100">
                    <h6 class="atem-card-title mb-0">Outlet Breakdown</h6>
                    <div class="text-muted mb-3" style="font-size:12px;padding-top:4px;">Cards, outcomes and reward
                        forecast by outlet</div>
                    <div class="table-responsive">
                        <table class="table table-sm align-middle mb-0">
                            <thead>
                                <tr>
                                    <th style="font-size:12px;text-align:left;">Outlet</th>
                                    <th style="font-size:12px;text-align:left;">Cards</th>
                                    <th style="font-size:12px;text-align:left;">Complete</th>
                                    <th style="font-size:12px;text-align:left;">Excellence</th>
                                    <th style="font-size:12px;text-align:left;">Fail</th>
                                    <th style="font-size:12px;text-align:left;">Fail Rate</th>
                                    <th style="font-size:12px;text-align:left;">Forecast</th>
                                </tr>
                            </thead>
                            <tbody id="dasho-outlet-breakdown-body">
                                <tr>
                                    <td colspan="7" class="text-muted" style="font-size:12px;">Loading...</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

    </div>
</div>

<?php
$page_js = ATEM_BASE . 'js/index.js';
include('footer.php');
?>