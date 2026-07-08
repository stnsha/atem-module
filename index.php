<?php
$page_title = 'Dashboard Overview';
include('header.php');

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
$dash_staff_options = array();
if ((int)$atem_permission === 1 && !$_is_superadmin) {
    $_me_id = (int)$staff_id;
    $_me_res = mysqli_query($conn, "SELECT id, nama_staff, department FROM staff WHERE recycle != 1 AND id = " . $_me_id);
    if ($_me_res && ($_me_row = mysqli_fetch_assoc($_me_res))) {
        $_deptIds = array();
        foreach (explode(',', (string)$_me_row['department']) as $_p) {
            $_p = (int)trim($_p);
            if ($_p > 0) { $_deptIds[] = $_p; }
        }
        $dash_staff_options[] = array('id' => (int)$_me_row['id'], 'name' => $_me_row['nama_staff'], 'dept_ids' => $_deptIds);
    }
} else {
    $_staff_res = mysqli_query($conn, "SELECT id, nama_staff, department FROM staff WHERE recycle != 1 ORDER BY nama_staff ASC");
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
            $dash_staff_options[] = array('id' => (int)$_srow['id'], 'name' => $_srow['nama_staff'], 'dept_ids' => $_deptIds);
        }
    }
}
?>

<script>
window.ATEM_DASH = <?php echo json_encode(array(
    'apiUrl'      => 'atem/api.php',
    'departments' => $dash_dept_options,
    'staff'       => $dash_staff_options,
)); ?>;
</script>

<!-- Filter card -->
<div class="atem-card atem-filter mb-3">
    <h6 class="atem-card-title"><i class="bi bi-funnel"></i> Filter</h6>
    <div class="row g-2 mt-1 align-items-end">
        <div class="col-md-2 col-sm-6">
            <label class="form-label">Year</label>
            <select id="dash-filter-year" class="form-select form-select-sm">
                <option value="">All Years</option>
                <?php foreach ($dash_year_options as $y): ?>
                <option value="<?php echo $y; ?>"<?php echo ($y === 2026) ? ' selected' : ''; ?>><?php echo $y; ?></option>
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
            <select id="dash-filter-quarter" class="form-select form-select-sm">
                <option value="">All Quarters</option>
                <option value="1">Q1 (Jan &ndash; Mar)</option>
                <option value="2">Q2 (Apr &ndash; Jun)</option>
                <option value="3">Q3 (Jul &ndash; Sep)</option>
                <option value="4">Q4 (Oct &ndash; Dec)</option>
            </select>
        </div>
        <div class="col-md-3 col-sm-6" id="dash-dept-col"<?php if (empty($dash_dept_options)) { echo ' style="display:none;"'; } ?>>
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
                        <input class="vf-s2-search" id="dash-staff-search" type="search" placeholder="Search name...">
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
            <div class="atem-card-title mb-1">Active / On Hand</div>
            <div class="atem-stat-value atem-stat-value--blue" id="dash-active">---</div>
            <div class="atem-stat-label">not yet closed</div>
            <div class="atem-stat-label" id="dash-extended-label" style="display:none;color:#fd7e14;margin-top:2px;"></div>
        </div>
    </div>
    <div class="col-12 col-sm-6 col-xl">
        <div class="atem-card atem-dash-stat h-100" data-preset="closed" style="cursor:pointer;">
            <div class="atem-card-title mb-1">Complete + Excellence</div>
            <div class="atem-stat-value atem-stat-value--green" id="dash-closed">---</div>
            <div class="atem-stat-label">eligible for completion count</div>
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
        <div class="atem-card atem-dash-stat h-100" data-overdue="1" style="cursor:pointer;">
            <div class="atem-card-title mb-1">Overdue Cards</div>
            <div class="atem-stat-value atem-stat-value--red" id="dash-overdue">---</div>
            <div class="atem-stat-label">active/extended past end date</div>
        </div>
    </div>
    <div class="col-12 col-sm-6 col-xl">
        <div class="atem-card atem-dash-stat h-100" data-incentive="1" style="cursor:pointer;">
            <div class="atem-card-title mb-1">Est. Incentive Forecast</div>
            <div class="atem-stat-value atem-stat-value--orange" id="dash-incentive">---</div>
            <div class="atem-stat-label">Level 2-4 payout</div>
        </div>
    </div>
</div>

<!-- My Role Breakdown -->
<div class="row g-3">
    <div class="col-12">
        <div class="atem-card">
            <h6 class="atem-card-title mb-0" id="dash-myroles-title">My Involvement</h6>
            <div class="text-muted mb-3" style="font-size:12px;padding-top:4px;" id="dash-myroles-subtitle">Cards where you are the Issuer or an ARCI member, by role</div>
            <div class="row g-2" id="dash-myroles-row">
                <div class="col-6 col-md-2">
                    <div class="atem-card atem-dash-stat h-100" style="cursor:pointer;padding:10px;" data-issuer="me">
                        <div class="atem-card-title mb-1" style="font-size:11px;">Issuer</div>
                        <div class="atem-stat-value atem-stat-value--blue" id="dash-myrole-issuer" style="font-size:22px;">---</div>
                    </div>
                </div>
                <div class="col-6 col-md-2">
                    <div class="atem-card atem-dash-stat h-100" style="cursor:pointer;padding:10px;" data-myrole="A">
                        <div class="atem-card-title mb-1" style="font-size:11px;">Accountable (A)</div>
                        <div class="atem-stat-value" id="dash-myrole-a" style="font-size:22px;color:#6610f2;">---</div>
                    </div>
                </div>
                <div class="col-6 col-md-2">
                    <div class="atem-card atem-dash-stat h-100" style="cursor:pointer;padding:10px;" data-myrole="R">
                        <div class="atem-card-title mb-1" style="font-size:11px;">Responsible (R)</div>
                        <div class="atem-stat-value" id="dash-myrole-r" style="font-size:22px;color:#0d6efd;">---</div>
                    </div>
                </div>
                <div class="col-6 col-md-2">
                    <div class="atem-card atem-dash-stat h-100" style="cursor:pointer;padding:10px;" data-myrole="C">
                        <div class="atem-card-title mb-1" style="font-size:11px;">Consulted (C)</div>
                        <div class="atem-stat-value" id="dash-myrole-c" style="font-size:22px;color:#fd7e14;">---</div>
                    </div>
                </div>
                <div class="col-6 col-md-2">
                    <div class="atem-card atem-dash-stat h-100" style="cursor:pointer;padding:10px;" data-myrole="I">
                        <div class="atem-card-title mb-1" style="font-size:11px;">Informed (I)</div>
                        <div class="atem-stat-value" id="dash-myrole-i" style="font-size:22px;color:#6c757d;">---</div>
                    </div>
                </div>
                <div class="col-6 col-md-2">
                    <div class="atem-card atem-dash-stat h-100" style="cursor:pointer;padding:10px;" data-mine="1">
                        <div class="atem-card-title mb-1" style="font-size:11px;">Total Involved</div>
                        <div class="atem-stat-value atem-stat-value--green" id="dash-myrole-involved" style="font-size:22px;">---</div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Level table + Bar chart -->
<div class="row g-3 mt-0">
    <div class="col-lg-6">
        <div class="atem-card h-100">
            <h6 class="atem-card-title mb-0">ATEM Complexity Reward</h6>
            <div class="text-muted mb-3" style="font-size:12px;padding-top:4px;">Level 1 RM0; Level 2-4 follow incentive
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
    <div class="col-lg-6">
        <div class="atem-card h-100">
            <h6 class="atem-card-title mb-0">Closure &amp; Failure Analysis</h6>
            <div class="text-muted mb-3" style="font-size:12px;padding-top:4px;">Issuer-only closure: Complete,
                Excellence, Extend, Fail</div>
            <div id="dash-bars">
                <div class="atem-bar-row">
                    <div class="atem-bar-label">Excellence</div>
                    <div class="atem-bar-track">
                        <div class="atem-bar-fill" id="bar-excellence" style="width:0%;background:#198754;"></div>
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
            </div>
            <div class="text-muted mt-3" style="font-size:11px;">Critical CEO use: identify failure rate by department,
                issuer, level and month.</div>
        </div>
    </div>
</div>

<!-- Department Breakdown -->
<div class="row g-3 mt-0">
    <div class="col-12">
        <div class="atem-card">
            <h6 class="atem-card-title mb-0">Department Breakdown</h6>
            <div class="text-muted mb-3" style="font-size:12px;padding-top:4px;">Cards, outcomes and incentive forecast by issuer department</div>
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
                        <tr><td colspan="7" class="text-muted" style="font-size:12px;">Loading...</td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<?php
$page_js = 'atem/js/index.js';
include('footer.php');
?>
