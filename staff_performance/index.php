<?php
ob_start();

$page_title = 'Staff Performance';
include('../header.php');

// Page access: grade 2+, People Management (dept 17), or SuperAdmin. Lock/
// Unlock payout is SuperAdmin-only (see the $_is_superadmin-gated buttons
// below and api.php's bulk-lock-payout/bulk-unlock-payout) - dept-17 and
// grade 2-5 staff can still view and export from this page, just not lock.
$_perf_dept_ids = array();
if (isset($department) && $department !== '') {
    foreach (explode(',', (string)$department) as $_perf_d) {
        $_perf_d = (int)trim($_perf_d);
        if ($_perf_d > 0) { $_perf_dept_ids[] = $_perf_d; }
    }
}

// staff.outlet is comma-separated too - used to narrow a grade-2 Outlet-
// department viewer down to their own specific outlet(s) in the Staff
// dropdown below, mirroring api.php's get-performance-list scoping.
$_perf_outlet_ids = array();
if (isset($outlet) && $outlet !== '') {
    foreach (explode(',', (string)$outlet) as $_perf_o) {
        $_perf_o = (int)trim($_perf_o);
        if ($_perf_o > 0) { $_perf_outlet_ids[] = $_perf_o; }
    }
}

// Grade 1 and 2 users only ever belong to one side (HQ or Outlet, per their
// own department), so showing both tabs is misleading noise - collapse to the
// single matching tab, like the pre-tab single-view page. Grade 3+ and
// SuperAdmin keep seeing both tabs as today (deferred to a future task).
// (Only a dept-17 grade-1/2 user ever reaches this page at all - see the
// access gate just below.)
$grade1_single_view = null;
if (((int)$atem_permission === 1 || (int)$atem_permission === 2) && !$_is_superadmin) {
    $grade1_single_view = in_array(1, $_perf_dept_ids, true) ? 'outlet' : 'hq';
}

if ($atem_permission < 2 && !$_is_superadmin && !in_array(17, $_perf_dept_ids, true)) {
    ob_end_clean();
    header('Location: ' . ATEM_BASE . 'index.php');
    exit;
}

ob_end_flush();

// Build ODB lookup maps for filter dropdowns
$dept_names    = array();
$grade_labels  = array();
$struct_labels = array();

$dr = mysqli_query($conn, "SELECT id, depart_name FROM staff_department");
if ($dr) { while ($row = mysqli_fetch_assoc($dr)) { $dept_names[(int)$row['id']] = $row['depart_name']; } }

$gr = mysqli_query($conn, "SELECT id, grade_name FROM staff_grade ORDER BY id ASC");
if ($gr) { while ($row = mysqli_fetch_assoc($gr)) { $grade_labels[(int)$row['id']] = $row['grade_name']; } }

$str_r = mysqli_query($conn, "SELECT id, struct_name FROM staff_struct ORDER BY id ASC");
if ($str_r) { while ($row = mysqli_fetch_assoc($str_r)) { $struct_labels[(int)$row['id']] = $row['struct_name']; } }

// Outlet filter options for the Outlet tab (replaces Department there).
$outlet_filter_options = array();
$outlet_r = mysqli_query($conn, "SELECT id, code FROM outlet ORDER BY code ASC");
if ($outlet_r) { while ($row = mysqli_fetch_assoc($outlet_r)) { $outlet_filter_options[] = array('id' => (int)$row['id'], 'name' => $row['code']); } }

// Build department options respecting grade — SuperAdmin always sees all departments
// regardless of their real grade (mirrors $_is_superadmin, never a bumped $atem_permission).
$dept_filter_options = array();
foreach ($dept_names as $did => $dname) {
    if ((int)$atem_permission >= 3 || $_is_superadmin) {
        $dept_filter_options[$did] = $dname;
    } elseif (isset($department) && (int)$department === $did) {
        $dept_filter_options[$did] = $dname;
    }
}

$init_month = (int)date('n');
$init_year  = max(2026, (int)date('Y'));
$year_options = array();
for ($y = 2026; $y <= $init_year; $y++) {
    $year_options[] = $y;
}

// Status filter — exact statuses only (mirrors atem_performance_status_options()
// in api.php). Defaults to Completed + Completed with Excellence only; every
// other status (including Completed with Extension) starts unchecked, so its
// column reads 0 until explicitly selected.
$perf_status_options = array('Completed', 'Completed with Excellence', 'Completed with Extension', 'Active', 'Extended', 'Failed');
$perf_default_statuses = array('Completed', 'Completed with Excellence');

// Staff filter dropdown (searchable, like the one on index.php). Grades 2-3
// (non-SA) are narrowed to their own department overlap here, mirroring
// api.php's get-performance-list mandatory scoping - a grade-2 Outlet-
// department viewer is narrowed further to their own specific outlet(s),
// since department=1 alone is shared by every outlet company-wide. Dept-17
// grade-1 users and grade 4+/SuperAdmin see every staff member, matching the
// same "no narrower carve-out" access model as the table itself.
// dept_ids lets the frontend narrow options further when a specific
// Department filter is also selected.
$_perf_is_grade2_outlet = ((int)$atem_permission === 2 && !$_is_superadmin && in_array(1, $_perf_dept_ids, true));
$_perf_is_scoped_grade  = (((int)$atem_permission === 2 || (int)$atem_permission === 3) && !$_is_superadmin);
$perf_staff_options = array();
$_pso_res = mysqli_query($conn, "SELECT id, nama_staff, department, outlet FROM staff WHERE recycle != 1 ORDER BY nama_staff ASC");
if ($_pso_res) {
    while ($_pso_row = mysqli_fetch_assoc($_pso_res)) {
        $_deptIds = array();
        foreach (explode(',', (string)$_pso_row['department']) as $_p) {
            $_p = (int)trim($_p);
            if ($_p > 0) { $_deptIds[] = $_p; }
        }
        if ($_perf_is_scoped_grade) {
            if ($_perf_is_grade2_outlet) {
                $_outletIds = array();
                foreach (explode(',', (string)$_pso_row['outlet']) as $_po) {
                    $_po = (int)trim($_po);
                    if ($_po > 0) { $_outletIds[] = $_po; }
                }
                if (!array_intersect($_perf_outlet_ids, $_outletIds)) { continue; }
            } elseif (!array_intersect($_deptIds, $_perf_dept_ids)) {
                continue;
            }
        }
        $perf_staff_options[] = array('id' => (int)$_pso_row['id'], 'name' => $_pso_row['nama_staff'], 'dept_ids' => $_deptIds);
    }
}
?>

<style>
.perf-count-link {
    color: #0d6efd;
    text-decoration: underline;
    text-underline-offset: 2px;
    font-weight: 500;
}

.perf-count-cell:hover .perf-count-link {
    color: #0a58ca;
}

.perf-role-cell {
    display: flex;
    flex-wrap: wrap;
    gap: 4px;
}

#atem-detail-tbody td:first-child,
#atem-detail-tbody td:nth-child(4),
#atem-detail-tbody td:nth-child(5) {
    white-space: nowrap;
}

#recalc-progress-wrap span {
    font-size: 12px;
}

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

<?php if ($grade1_single_view === null): ?>
<ul class="nav nav-tabs atem-view-tabs" id="perf-view-tabs" role="tablist">
    <li class="nav-item" role="presentation">
        <button class="nav-link active atem-tab-color-hq" id="perf-tab-hq-btn" data-bs-toggle="tab" data-bs-target="#perf-tab-hq"
            type="button" role="tab" aria-controls="perf-tab-hq" aria-selected="true">
            <i class="bi bi-building"></i> HQ ATEM
        </button>
    </li>
    <li class="nav-item" role="presentation">
        <button class="nav-link atem-tab-color-outlet" id="perf-tab-outlet-btn" data-bs-toggle="tab" data-bs-target="#perf-tab-outlet"
            type="button" role="tab" aria-controls="perf-tab-outlet" aria-selected="false">
            <i class="bi bi-shop"></i> Outlet ATEM
        </button>
    </li>
</ul>
<?php endif; ?>
<div class="tab-content pt-3">
<div class="tab-pane fade<?php echo ($grade1_single_view === null || $grade1_single_view === 'hq') ? ' show active' : ''; ?>"
    id="perf-tab-hq" role="tabpanel" aria-labelledby="perf-tab-hq-btn">

<!-- Filter Card -->
<div class="atem-card atem-filter mb-3">
    <h6 class="atem-card-title"><i class="bi bi-funnel"></i> Filter</h6>
    <div class="row g-2 mt-1 align-items-end">
        <div class="col-md-3 col-sm-6">
            <label class="form-label">Year</label>
            <select id="perf-filter-year" class="form-select form-select-sm">
                <?php foreach ($year_options as $y): ?>
                <option value="<?php echo $y; ?>" <?php echo ($y === $init_year) ? ' selected' : ''; ?>>
                    <?php echo $y; ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-3 col-sm-6">
            <label class="form-label">Closure Month</label>
            <select id="perf-filter-month" class="form-select form-select-sm">
                <option value="0">All Month</option>
                <?php foreach (array(1=>'January',2=>'February',3=>'March',4=>'April',5=>'May',6=>'June',7=>'July',8=>'August',9=>'September',10=>'October',11=>'November',12=>'December') as $mn => $ml): ?>
                <option value="<?php echo $mn; ?>">
                    <?php echo htmlspecialchars($ml); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-3 col-sm-6">
            <label class="form-label">Quarter</label>
            <select id="perf-filter-quarter" class="form-select form-select-sm">
                <option value="0">All Quarter</option>
                <option value="1">Q1 (Jan-Mar)</option>
                <option value="2">Q2 (Apr-Jun)</option>
                <option value="3">Q3 (Jul-Sep)</option>
                <option value="4">Q4 (Oct-Dec)</option>
            </select>
        </div>
        <?php if (!empty($dept_filter_options)): ?>
        <div class="col-md-3 col-sm-6">
            <label class="form-label">Department</label>
            <select id="perf-filter-dept" class="form-select form-select-sm">
                <option value="0">All Department</option>
                <?php foreach ($dept_filter_options as $did => $dname): ?>
                <option value="<?php echo $did; ?>"><?php echo htmlspecialchars($dname); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php endif; ?>
        <div class="col-md-3 col-sm-6">
            <label class="form-label">Grade</label>
            <select id="perf-filter-grade" class="form-select form-select-sm">
                <option value="0">All Grade</option>
                <?php foreach ($grade_labels as $gid => $gname): ?>
                <option value="<?php echo $gid; ?>"><?php echo htmlspecialchars($gname); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-3 col-sm-6">
            <label class="form-label">Evaluation Structure</label>
            <select id="perf-filter-struct" class="form-select form-select-sm">
                <option value="0">All Evaluation Structure</option>
                <?php foreach ($struct_labels as $sid2 => $sname): ?>
                <option value="<?php echo $sid2; ?>"><?php echo htmlspecialchars($sname); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-3 col-sm-6">
            <label class="form-label">Status</label>
            <div class="vf-issuer-wrap" id="perf-status-wrap">
                <div class="vf-s2-selection" id="perf-status-btn" tabindex="0">Loading...</div>
                <div class="vf-s2-dropdown" id="perf-status-dropdown">
                    <ul class="vf-s2-list" style="padding:4px 0;">
                        <?php foreach ($perf_status_options as $_pso): ?>
                        <li class="vf-s2-list-item" style="cursor:default;">
                            <label style="display:flex;align-items:center;gap:6px;width:100%;cursor:pointer;margin:0;">
                                <input type="checkbox" class="perf-status-cb" value="<?php echo htmlspecialchars($_pso); ?>"<?php echo in_array($_pso, $perf_default_statuses, true) ? ' checked' : ''; ?>>
                                <?php echo htmlspecialchars($_pso); ?>
                            </label>
                        </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            </div>
        </div>
        <div class="col-md-3 col-sm-6">
            <label class="form-label">Staff</label>
            <div class="vf-issuer-wrap" id="perf-staff-wrap">
                <div class="vf-s2-selection" id="perf-staff-btn" tabindex="0">All staff</div>
                <div class="vf-s2-dropdown" id="perf-staff-dropdown">
                    <div class="vf-s2-search-wrap">
                        <input class="vf-s2-search" id="perf-staff-search" type="search" placeholder="Search name...">
                    </div>
                    <ul class="vf-s2-list" id="perf-staff-list"></ul>
                </div>
                <input type="hidden" id="perf-staff-value" value="0">
            </div>
        </div>
        <div class="col-auto d-flex align-items-end gap-2 ms-auto">
            <button class="btn btn-sm btn-outline-secondary" id="perf-reset-filter">Reset</button>
            <a id="perf-export-all-btn" href="#" class="btn btn-outline-success btn-sm">Export</a>
            <?php if ($_is_superadmin): ?>
            <button class="btn btn-outline-danger btn-sm" id="perf-export-lock-btn">Export &amp; Lock</button>
            <button class="btn btn-danger btn-sm" id="perf-lock-btn">Lock Payout</button>
            <button class="btn btn-outline-warning btn-sm" id="perf-unlock-btn">Undo Lock Payout</button>
            <?php endif; ?>
        </div>
    </div>
    <div class="mt-2 text-end">
        <span class="text-muted" id="perf-filter-label" style="font-size:12px;"></span>
    </div>
</div>

<!-- Action Buttons -->
<div class="d-flex gap-2 mb-3 justify-content-end">
    <button class="btn btn-outline-success btn-sm" id="export-selected-btn" disabled>Export Selected</button>
    <?php if ($_is_superadmin): ?>
    <button class="btn btn-outline-danger btn-sm" id="perf-export-lock-selected-btn" disabled>Export &amp; Lock Selected</button>
    <button class="btn btn-danger btn-sm" id="perf-lock-selected-btn" disabled>Lock Selected</button>
    <button class="btn btn-outline-warning btn-sm" id="perf-unlock-selected-btn" disabled>Unlock Selected</button>
    <?php endif; ?>
</div>

<div id="export-progress-wrap" style="display:none;margin-bottom:12px;max-width:400px;">
    <div style="margin-bottom:4px;">
        <span id="export-progress-msg">Preparing export...</span>
    </div>
    <div style="background:#e9ecef;border-radius:4px;height:8px;overflow:hidden;">
        <div class="atem-export-bar-anim"></div>
    </div>
</div>

<!-- Table -->
<div class="atem-card">
    <p class="mb-3 text-muted" id="perf-period-label"
        style="font-size:11px;text-transform:uppercase;letter-spacing:.06em;font-weight:600;">
        Staff Performance
    </p>
    <div class="table-responsive">
        <table class="table table-hover align-middle atem-view-tbl">
            <thead>
                <tr>
                    <th style="width:36px;"><input type="checkbox" id="select-all" title="Select all"></th>
                    <th>Staff Details</th>
                    <th>Grade</th>
                    <th>Evaluation Structure</th>
                    <th class="text-center" title="Click to view details">ATEM</th>
                    <th class="text-center" title="Click to view details">Complete</th>
                    <th class="text-center" title="Click to view details">Active</th>
                    <th class="text-center" title="Click to view details">Extend</th>
                    <th class="text-center" title="Click to view details">Failed</th>
                    <th class="text-end">Est. Reward</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody id="perf-tbody">
                <tr>
                    <td colspan="11" class="text-center text-muted py-4">Loading...</td>
                </tr>
            </tbody>
        </table>
    </div>
    <div class="atem-pager" id="perf-pager"></div>
</div>

</div><!-- /#perf-tab-hq -->

<div class="tab-pane fade<?php echo ($grade1_single_view === 'outlet') ? ' show active' : ''; ?>"
    id="perf-tab-outlet" role="tabpanel" aria-labelledby="perf-tab-outlet-btn">

<!-- Filter Card -->
<div class="atem-card atem-filter mb-3">
    <h6 class="atem-card-title"><i class="bi bi-funnel"></i> Filter</h6>
    <div class="row g-2 mt-1 align-items-end">
        <div class="col-md-3 col-sm-6">
            <label class="form-label">Year</label>
            <select id="perfo-filter-year" class="form-select form-select-sm">
                <?php foreach ($year_options as $y): ?>
                <option value="<?php echo $y; ?>" <?php echo ($y === $init_year) ? ' selected' : ''; ?>>
                    <?php echo $y; ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-3 col-sm-6">
            <label class="form-label">Closure Month</label>
            <select id="perfo-filter-month" class="form-select form-select-sm">
                <option value="0">All Month</option>
                <?php foreach (array(1=>'January',2=>'February',3=>'March',4=>'April',5=>'May',6=>'June',7=>'July',8=>'August',9=>'September',10=>'October',11=>'November',12=>'December') as $mn => $ml): ?>
                <option value="<?php echo $mn; ?>">
                    <?php echo htmlspecialchars($ml); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-3 col-sm-6">
            <label class="form-label">Quarter</label>
            <select id="perfo-filter-quarter" class="form-select form-select-sm">
                <option value="0">All Quarter</option>
                <option value="1">Q1 (Jan-Mar)</option>
                <option value="2">Q2 (Apr-Jun)</option>
                <option value="3">Q3 (Jul-Sep)</option>
                <option value="4">Q4 (Oct-Dec)</option>
            </select>
        </div>
        <div class="col-md-3 col-sm-6">
            <label class="form-label">Outlet</label>
            <div class="vf-issuer-wrap" id="perfo-outlet-wrap">
                <div class="vf-s2-selection" id="perfo-outlet-btn" tabindex="0">All outlets</div>
                <div class="vf-s2-dropdown" id="perfo-outlet-dropdown">
                    <div class="vf-s2-search-wrap">
                        <input class="vf-s2-search" id="perfo-outlet-search" type="search" placeholder="Search outlet code...">
                    </div>
                    <ul class="vf-s2-list" id="perfo-outlet-list"></ul>
                </div>
                <input type="hidden" id="perfo-outlet-value" value="0">
            </div>
        </div>
        <div class="col-md-3 col-sm-6">
            <label class="form-label">Grade</label>
            <select id="perfo-filter-grade" class="form-select form-select-sm">
                <option value="0">All Grade</option>
                <?php foreach ($grade_labels as $gid => $gname): ?>
                <option value="<?php echo $gid; ?>"><?php echo htmlspecialchars($gname); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-3 col-sm-6">
            <label class="form-label">Evaluation Structure</label>
            <select id="perfo-filter-struct" class="form-select form-select-sm">
                <option value="0">All Evaluation Structure</option>
                <?php foreach ($struct_labels as $sid2 => $sname): ?>
                <option value="<?php echo $sid2; ?>"><?php echo htmlspecialchars($sname); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-3 col-sm-6">
            <label class="form-label">Status</label>
            <div class="vf-issuer-wrap" id="perfo-status-wrap">
                <div class="vf-s2-selection" id="perfo-status-btn" tabindex="0">Loading...</div>
                <div class="vf-s2-dropdown" id="perfo-status-dropdown">
                    <ul class="vf-s2-list" style="padding:4px 0;">
                        <?php foreach ($perf_status_options as $_pso): ?>
                        <li class="vf-s2-list-item" style="cursor:default;">
                            <label style="display:flex;align-items:center;gap:6px;width:100%;cursor:pointer;margin:0;">
                                <input type="checkbox" class="perfo-status-cb" value="<?php echo htmlspecialchars($_pso); ?>"<?php echo in_array($_pso, $perf_default_statuses, true) ? ' checked' : ''; ?>>
                                <?php echo htmlspecialchars($_pso); ?>
                            </label>
                        </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            </div>
        </div>
        <div class="col-md-3 col-sm-6">
            <label class="form-label">Staff</label>
            <div class="vf-issuer-wrap" id="perfo-staff-wrap">
                <div class="vf-s2-selection" id="perfo-staff-btn" tabindex="0">All staff</div>
                <div class="vf-s2-dropdown" id="perfo-staff-dropdown">
                    <div class="vf-s2-search-wrap">
                        <input class="vf-s2-search" id="perfo-staff-search" type="search" placeholder="Search name...">
                    </div>
                    <ul class="vf-s2-list" id="perfo-staff-list"></ul>
                </div>
                <input type="hidden" id="perfo-staff-value" value="0">
            </div>
        </div>
        <div class="col-auto d-flex align-items-end gap-2 ms-auto">
            <button class="btn btn-sm btn-outline-secondary" id="perfo-reset-filter">Reset</button>
            <a id="perfo-export-all-btn" href="#" class="btn btn-outline-success btn-sm">Export</a>
            <?php if ($_is_superadmin): ?>
            <button class="btn btn-outline-danger btn-sm" id="perfo-export-lock-btn">Export &amp; Lock</button>
            <button class="btn btn-danger btn-sm" id="perfo-lock-btn">Lock Payout</button>
            <button class="btn btn-outline-warning btn-sm" id="perfo-unlock-btn">Undo Lock Payout</button>
            <?php endif; ?>
        </div>
    </div>
    <div class="mt-2 text-end">
        <span class="text-muted" id="perfo-filter-label" style="font-size:12px;"></span>
    </div>
</div>

<!-- Action Buttons -->
<div class="d-flex gap-2 mb-3 justify-content-end">
    <button class="btn btn-outline-success btn-sm" id="perfo-export-selected-btn" disabled>Export Selected</button>
    <?php if ($_is_superadmin): ?>
    <button class="btn btn-outline-danger btn-sm" id="perfo-export-lock-selected-btn" disabled>Export &amp; Lock Selected</button>
    <button class="btn btn-danger btn-sm" id="perfo-lock-selected-btn" disabled>Lock Selected</button>
    <button class="btn btn-outline-warning btn-sm" id="perfo-unlock-selected-btn" disabled>Unlock Selected</button>
    <?php endif; ?>
</div>

<div id="perfo-export-progress-wrap" style="display:none;margin-bottom:12px;max-width:400px;">
    <div style="margin-bottom:4px;">
        <span id="perfo-export-progress-msg">Preparing export...</span>
    </div>
    <div style="background:#e9ecef;border-radius:4px;height:8px;overflow:hidden;">
        <div class="atem-export-bar-anim"></div>
    </div>
</div>

<!-- Table -->
<div class="atem-card">
    <p class="mb-3 text-muted" id="perfo-period-label"
        style="font-size:11px;text-transform:uppercase;letter-spacing:.06em;font-weight:600;">
        Staff Performance
    </p>
    <div class="table-responsive">
        <table class="table table-hover align-middle atem-view-tbl">
            <thead>
                <tr>
                    <th style="width:36px;"><input type="checkbox" id="perfo-select-all" title="Select all"></th>
                    <th>Staff Details</th>
                    <th>Grade</th>
                    <th>Evaluation Structure</th>
                    <th class="text-center" title="Click to view details">ATEM</th>
                    <th class="text-center" title="Click to view details">Complete</th>
                    <th class="text-center" title="Click to view details">Active</th>
                    <th class="text-center" title="Click to view details">Extend</th>
                    <th class="text-center" title="Click to view details">Failed</th>
                    <th class="text-end">Est. Reward</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody id="perfo-tbody">
                <tr>
                    <td colspan="11" class="text-center text-muted py-4">Loading...</td>
                </tr>
            </tbody>
        </table>
    </div>
    <div class="atem-pager" id="perfo-pager"></div>
</div>

</div><!-- /#perf-tab-outlet -->
</div><!-- /.tab-content -->

<!-- ATEM Detail Modal -->
<div class="modal fade" id="atemDetailModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <div class="modal-header py-2">
                <h6 class="modal-title mb-0" id="atem-detail-modal-title">ATEM Details</h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-0">
                <div id="atem-detail-loading" class="text-center py-4" style="display:none;">Loading...</div>
                <div id="atem-detail-error" class="text-danger px-3 py-2" style="display:none;"></div>
                <div class="table-responsive" id="atem-detail-table-wrap">
                    <table class="table table-hover align-middle atem-view-tbl mb-0">
                        <thead>
                            <tr>
                                <th>ATEM ID</th>
                                <th>Title</th>
                                <th>Level / Complexity</th>
                                <th>Start</th>
                                <th>End</th>
                                <th>Closure Date</th>
                                <th>Status</th>
                                <th>My Role</th>
                            </tr>
                        </thead>
                        <tbody id="atem-detail-tbody"></tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Lock Payout Modal (SuperAdmin only) -->
<div class="modal fade" id="payoutLockModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-lock-fill text-danger"></i> Lock Payout</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p id="payout-lock-modal-msg">This will close payout for the matched ATEM records. This cannot be undone by non-SuperAdmin users.</p>
                <label for="payout-lock-remark" class="form-label">Remark <span class="text-danger">*</span></label>
                <textarea id="payout-lock-remark" class="form-control" rows="3" placeholder="Reason for locking payout..."></textarea>
                <div class="atem-form-error" id="payout-lock-remark-error"></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-danger" id="payout-lock-confirm-btn">Lock Payout</button>
            </div>
        </div>
    </div>
</div>

<!-- Unlock Payout Modal (SuperAdmin only) -->
<div class="modal fade" id="payoutUnlockModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-unlock-fill text-warning"></i> Undo Lock Payout</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p id="payout-unlock-modal-msg" class="text-warning">
                    <i class="bi bi-exclamation-triangle-fill"></i>
                    This reopens payout for the matched records, reversing a closed/locked state. Only SuperAdmin can do this.
                </p>
                <label for="payout-unlock-remark" class="form-label">Remark <span class="text-danger">*</span></label>
                <textarea id="payout-unlock-remark" class="form-control" rows="3" placeholder="Reason for unlocking payout..."></textarea>
                <div class="atem-form-error" id="payout-unlock-remark-error"></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-warning" id="payout-unlock-confirm-btn">Undo Lock Payout</button>
            </div>
        </div>
    </div>
</div>

</div><!-- /.atem-container -->

<script>
var PERF_CFG = <?php echo json_encode(array(
    'apiUrl'          => ATEM_BASE . 'api.php',
    'permission'      => $atem_permission,
    'initMonth'       => $init_month,
    'initYear'        => $init_year,
    'defaultStatuses' => $perf_default_statuses,
    'staff'           => $perf_staff_options,
    'outlets'         => $outlet_filter_options,
    'isSuperAdmin'    => $_is_superadmin,
    'tabSingleView'   => $grade1_single_view,
)); ?>;

var PERF_API_URL = PERF_CFG.apiUrl;

var PERF_COL_LABELS = {
    'atem': 'All ATEM',
    'complete': 'Completed',
    'active': 'Active',
    'extend': 'Extended',
    'failed': 'Failed'
};
var STATUS_COLOR = {
    'Draft': '#6c757d',
    'Active': '#0d6efd',
    'Completed': '#198754',
    'Completed with Excellence': '#0dcaf0',
    'Completed with Extension': '#20c997',
    'Extended': '#fd7e14',
    'Failed': '#dc3545'
};
var MONTHS_LABEL = {
    1: 'January',
    2: 'February',
    3: 'March',
    4: 'April',
    5: 'May',
    6: 'June',
    7: 'July',
    8: 'August',
    9: 'September',
    10: 'October',
    11: 'November',
    12: 'December'
};
var QUARTERS_LABEL = {
    1: 'Q1 (Jan-Mar)',
    2: 'Q2 (Apr-Jun)',
    3: 'Q3 (Jul-Sep)',
    4: 'Q4 (Oct-Dec)'
};

var _currentPayload = {};
var _perfAllData = [];
var _perfPage = 1;
var _perfPerPage = 30;

var _currentPayloadOutlet = {};
var _perfoAllData = [];
var _perfoPage = 1;
var _perfoPerPage = 30;

function escHtml(s) {
    return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
}

// Syncs a custom dropdown button's box model to a form-select-sm reference
// element exactly. Must be re-run once a tab-pane leaves display:none (its
// offsetHeight reads 0 while hidden), see the shown.bs.tab listeners below.
function syncS2ButtonSize(btnEl, refEl) {
    if (!btnEl || !refEl) { return; }
    var refStyle = window.getComputedStyle(refEl);
    btnEl.style.height       = refEl.offsetHeight + 'px';
    btnEl.style.border       = refStyle.border;
    btnEl.style.borderRadius = refStyle.borderRadius;
    btnEl.style.fontSize     = refStyle.fontSize;
    btnEl.style.color        = refStyle.color;
}

function formatDate(s) {
    if (!s) {
        return '-';
    }
    var parts = s.split('-');
    if (parts.length === 3) {
        return parts[2] + '-' + parts[1] + '-' + parts[0];
    }
    return s;
}

function formatNumber(n) {
    var x = parseFloat(n) || 0;
    return x.toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
}

// payout_updated_at/payout_closed_at are datetimes (unlike the plain YYYY-MM-DD
// dates formatDate() handles) — parsed via Date() rather than string-split.
function formatDateTime(s) {
    if (!s) { return '-'; }
    var d = new Date(s);
    if (isNaN(d.getTime())) { return '-'; }
    function pad(n) { return (n < 10 ? '0' : '') + n; }
    return pad(d.getDate()) + '-' + pad(d.getMonth() + 1) + '-' + d.getFullYear() + ' ' + pad(d.getHours()) + ':' + pad(d.getMinutes());
}

// ----------------------------------------------------------- status filter
// prefix is 'perf' (HQ tab) or 'perfo' (Outlet tab) — each tab has its own
// independent set of status checkboxes/button, mirroring vf-/vfo- elsewhere.
function getSelectedStatuses(prefix) {
    var boxes = document.querySelectorAll('.' + prefix + '-status-cb:checked');
    var out = [];
    for (var i = 0; i < boxes.length; i++) { out.push(boxes[i].value); }
    return out;
}

function updateStatusButtonLabel(prefix) {
    var btn = document.getElementById(prefix + '-status-btn');
    if (!btn) { return; }
    var selected = getSelectedStatuses(prefix);
    var allBoxes = document.querySelectorAll('.' + prefix + '-status-cb');
    if (selected.length === 0) {
        btn.textContent = 'No status selected';
    } else if (selected.length === allBoxes.length) {
        btn.textContent = 'All Statuses';
    } else if (selected.length <= 2) {
        btn.textContent = selected.join(', ');
    } else {
        btn.textContent = selected.length + ' statuses selected';
    }
}

// ------------------------------------------------- staff searchable dropdown
// Mirrors the Staff dropdown on index.php (js/index.js buildStaffDropdown).
// Options narrow to the selected department, if any.
function buildStaffDropdown() {
    var listEl   = document.getElementById('perf-staff-list');
    var searchEl = document.getElementById('perf-staff-search');
    var btnEl    = document.getElementById('perf-staff-btn');
    var dropEl   = document.getElementById('perf-staff-dropdown');
    var valEl    = document.getElementById('perf-staff-value');
    if (!listEl || !btnEl || !dropEl) { return; }

    syncS2ButtonSize(btnEl, document.getElementById('perf-filter-year'));

    function currentDeptId() {
        var deptEl = document.getElementById('perf-filter-dept');
        return deptEl ? (parseInt(deptEl.value, 10) || 0) : 0;
    }

    function visibleStaff() {
        var all = PERF_CFG.staff || [];
        var deptId = currentDeptId();
        if (!deptId) { return all; }
        return all.filter(function(s) {
            return s.dept_ids && s.dept_ids.indexOf(deptId) !== -1;
        });
    }

    function renderList() {
        var staff = visibleStaff();
        var html = '<li class="vf-s2-list-item" data-id="0">All staff</li>';
        for (var i = 0; i < staff.length; i++) {
            html += '<li class="vf-s2-list-item" data-id="' + staff[i].id + '">' + escHtml(staff[i].name) + '</li>';
        }
        listEl.innerHTML = html;
    }

    function openDropdown() {
        renderList();
        dropEl.classList.add('open');
        if (searchEl) { searchEl.value = ''; filterList(''); searchEl.focus(); }
    }
    function closeDropdown() { dropEl.classList.remove('open'); }

    function filterList(term) {
        var items = listEl.querySelectorAll('li');
        var lower = term.toLowerCase();
        for (var j = 0; j < items.length; j++) {
            var text = items[j].textContent || '';
            items[j].classList.toggle('hidden', !(!lower || text.toLowerCase().indexOf(lower) >= 0));
        }
    }

    function selectStaff(id, name) {
        if (valEl) { valEl.value = id; }
        if (btnEl) { btnEl.textContent = name; }
        closeDropdown();
        loadPerformance(buildPayload());
    }

    btnEl.addEventListener('click', function(e) {
        e.stopPropagation();
        if (dropEl.classList.contains('open')) { closeDropdown(); } else { openDropdown(); }
    });
    btnEl.addEventListener('keydown', function(e) {
        if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); openDropdown(); }
    });
    if (searchEl) {
        searchEl.addEventListener('input', function() { filterList(this.value); });
        searchEl.addEventListener('click', function(e) { e.stopPropagation(); });
    }
    listEl.addEventListener('click', function(e) {
        var li = e.target.closest ? e.target.closest('li') : null;
        if (!li) { return; }
        selectStaff(parseInt(li.getAttribute('data-id'), 10) || 0, li.textContent);
    });
    document.addEventListener('click', function(e) {
        var wrap = document.getElementById('perf-staff-wrap');
        if (wrap && !wrap.contains(e.target)) { closeDropdown(); }
    });

    // If the selected staff member falls outside the newly chosen department,
    // clear the selection rather than keep a mismatched filter applied.
    var deptFilterEl = document.getElementById('perf-filter-dept');
    if (deptFilterEl) {
        deptFilterEl.addEventListener('change', function() {
            var deptId = currentDeptId();
            var selectedId = valEl ? (parseInt(valEl.value, 10) || 0) : 0;
            if (deptId && selectedId) {
                var match = (PERF_CFG.staff || []).some(function(s) {
                    return s.id === selectedId && s.dept_ids && s.dept_ids.indexOf(deptId) !== -1;
                });
                if (!match) { resetStaffDropdown(); }
            }
        });
    }
}

function resetStaffDropdown() {
    var valEl = document.getElementById('perf-staff-value');
    var btnEl = document.getElementById('perf-staff-btn');
    var dropEl = document.getElementById('perf-staff-dropdown');
    if (valEl)  { valEl.value = '0'; }
    if (btnEl)  { btnEl.textContent = 'All staff'; }
    if (dropEl) { dropEl.classList.remove('open'); }
}

// ---------------------------------------- Outlet tab: staff searchable dropdown
// Same widget as buildStaffDropdown, but the Outlet tab has no department to
// narrow by, so it always lists every staff member.
function buildStaffDropdownOutlet() {
    var listEl   = document.getElementById('perfo-staff-list');
    var searchEl = document.getElementById('perfo-staff-search');
    var btnEl    = document.getElementById('perfo-staff-btn');
    var dropEl   = document.getElementById('perfo-staff-dropdown');
    var valEl    = document.getElementById('perfo-staff-value');
    if (!listEl || !btnEl || !dropEl) { return; }

    syncS2ButtonSize(btnEl, document.getElementById('perfo-filter-year'));

    function renderList() {
        var staff = PERF_CFG.staff || [];
        var html = '<li class="vf-s2-list-item" data-id="0">All staff</li>';
        for (var i = 0; i < staff.length; i++) {
            html += '<li class="vf-s2-list-item" data-id="' + staff[i].id + '">' + escHtml(staff[i].name) + '</li>';
        }
        listEl.innerHTML = html;
    }

    function openDropdown() {
        renderList();
        dropEl.classList.add('open');
        if (searchEl) { searchEl.value = ''; filterList(''); searchEl.focus(); }
    }
    function closeDropdown() { dropEl.classList.remove('open'); }

    function filterList(term) {
        var items = listEl.querySelectorAll('li');
        var lower = term.toLowerCase();
        for (var j = 0; j < items.length; j++) {
            var text = items[j].textContent || '';
            items[j].classList.toggle('hidden', !(!lower || text.toLowerCase().indexOf(lower) >= 0));
        }
    }

    function selectStaff(id, name) {
        if (valEl) { valEl.value = id; }
        if (btnEl) { btnEl.textContent = name; }
        closeDropdown();
        loadPerformanceOutlet(buildPayloadOutlet());
    }

    btnEl.addEventListener('click', function(e) {
        e.stopPropagation();
        if (dropEl.classList.contains('open')) { closeDropdown(); } else { openDropdown(); }
    });
    btnEl.addEventListener('keydown', function(e) {
        if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); openDropdown(); }
    });
    if (searchEl) {
        searchEl.addEventListener('input', function() { filterList(this.value); });
        searchEl.addEventListener('click', function(e) { e.stopPropagation(); });
    }
    listEl.addEventListener('click', function(e) {
        var li = e.target.closest ? e.target.closest('li') : null;
        if (!li) { return; }
        selectStaff(parseInt(li.getAttribute('data-id'), 10) || 0, li.textContent);
    });
    document.addEventListener('click', function(e) {
        var wrap = document.getElementById('perfo-staff-wrap');
        if (wrap && !wrap.contains(e.target)) { closeDropdown(); }
    });
}

function resetStaffDropdownOutlet() {
    var valEl = document.getElementById('perfo-staff-value');
    var btnEl = document.getElementById('perfo-staff-btn');
    var dropEl = document.getElementById('perfo-staff-dropdown');
    if (valEl)  { valEl.value = '0'; }
    if (btnEl)  { btnEl.textContent = 'All staff'; }
    if (dropEl) { dropEl.classList.remove('open'); }
}

// ---------------------------------------------- Outlet tab: outlet dropdown
// Replaces the HQ tab's Department <select> — a searchable dropdown over
// PERF_CFG.outlets (id/code), same widget shape as the staff dropdown above.
function buildOutletDropdown() {
    var listEl   = document.getElementById('perfo-outlet-list');
    var searchEl = document.getElementById('perfo-outlet-search');
    var btnEl    = document.getElementById('perfo-outlet-btn');
    var dropEl   = document.getElementById('perfo-outlet-dropdown');
    var valEl    = document.getElementById('perfo-outlet-value');
    if (!listEl || !btnEl || !dropEl) { return; }

    syncS2ButtonSize(btnEl, document.getElementById('perfo-filter-year'));

    function renderList() {
        var outlets = PERF_CFG.outlets || [];
        var html = '<li class="vf-s2-list-item" data-id="0">All outlets</li>';
        for (var i = 0; i < outlets.length; i++) {
            html += '<li class="vf-s2-list-item" data-id="' + outlets[i].id + '">' + escHtml(outlets[i].name) + '</li>';
        }
        listEl.innerHTML = html;
    }

    function openDropdown() {
        renderList();
        dropEl.classList.add('open');
        if (searchEl) { searchEl.value = ''; filterList(''); searchEl.focus(); }
    }
    function closeDropdown() { dropEl.classList.remove('open'); }

    function filterList(term) {
        var items = listEl.querySelectorAll('li');
        var lower = term.toLowerCase();
        for (var j = 0; j < items.length; j++) {
            var text = items[j].textContent || '';
            items[j].classList.toggle('hidden', !(!lower || text.toLowerCase().indexOf(lower) >= 0));
        }
    }

    function selectOutlet(id, name) {
        if (valEl) { valEl.value = id; }
        if (btnEl) { btnEl.textContent = name; }
        closeDropdown();
        loadPerformanceOutlet(buildPayloadOutlet());
    }

    btnEl.addEventListener('click', function(e) {
        e.stopPropagation();
        if (dropEl.classList.contains('open')) { closeDropdown(); } else { openDropdown(); }
    });
    btnEl.addEventListener('keydown', function(e) {
        if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); openDropdown(); }
    });
    if (searchEl) {
        searchEl.addEventListener('input', function() { filterList(this.value); });
        searchEl.addEventListener('click', function(e) { e.stopPropagation(); });
    }
    listEl.addEventListener('click', function(e) {
        var li = e.target.closest ? e.target.closest('li') : null;
        if (!li) { return; }
        selectOutlet(parseInt(li.getAttribute('data-id'), 10) || 0, li.textContent);
    });
    document.addEventListener('click', function(e) {
        var wrap = document.getElementById('perfo-outlet-wrap');
        if (wrap && !wrap.contains(e.target)) { closeDropdown(); }
    });
}

function resetOutletDropdown() {
    var valEl = document.getElementById('perfo-outlet-value');
    var btnEl = document.getElementById('perfo-outlet-btn');
    var dropEl = document.getElementById('perfo-outlet-dropdown');
    if (valEl)  { valEl.value = '0'; }
    if (btnEl)  { btnEl.textContent = 'All outlets'; }
    if (dropEl) { dropEl.classList.remove('open'); }
}

function buildPayload() {
    var year = parseInt(document.getElementById('perf-filter-year').value, 10) || PERF_CFG.initYear;
    var month = parseInt(document.getElementById('perf-filter-month').value, 10) || 0;
    var quarter = parseInt(document.getElementById('perf-filter-quarter').value, 10) || 0;
    var deptEl = document.getElementById('perf-filter-dept');
    var gradeEl = document.getElementById('perf-filter-grade');
    var structEl = document.getElementById('perf-filter-struct');
    var staffEl = document.getElementById('perf-staff-value');
    var dept = deptEl ? (parseInt(deptEl.value, 10) || 0) : 0;
    var grade = gradeEl ? (parseInt(gradeEl.value, 10) || 0) : 0;
    var struct = structEl ? (parseInt(structEl.value, 10) || 0) : 0;
    var staffId = staffEl ? (parseInt(staffEl.value, 10) || 0) : 0;
    if (quarter > 0) {
        month = 0;
    }
    return {
        year: year,
        month: month,
        quarter: quarter,
        dept: dept,
        grade: grade,
        struct: struct,
        staff_id: staffId,
        statuses: getSelectedStatuses('perf'),
        filter_atem_type: 1
    };
}

function buildPayloadOutlet() {
    var year = parseInt(document.getElementById('perfo-filter-year').value, 10) || PERF_CFG.initYear;
    var month = parseInt(document.getElementById('perfo-filter-month').value, 10) || 0;
    var quarter = parseInt(document.getElementById('perfo-filter-quarter').value, 10) || 0;
    var outletEl = document.getElementById('perfo-outlet-value');
    var gradeEl = document.getElementById('perfo-filter-grade');
    var structEl = document.getElementById('perfo-filter-struct');
    var staffEl = document.getElementById('perfo-staff-value');
    var outletId = outletEl ? (parseInt(outletEl.value, 10) || 0) : 0;
    var grade = gradeEl ? (parseInt(gradeEl.value, 10) || 0) : 0;
    var struct = structEl ? (parseInt(structEl.value, 10) || 0) : 0;
    var staffId = staffEl ? (parseInt(staffEl.value, 10) || 0) : 0;
    if (quarter > 0) {
        month = 0;
    }
    return {
        year: year,
        month: month,
        quarter: quarter,
        grade: grade,
        struct: struct,
        staff_id: staffId,
        statuses: getSelectedStatuses('perfo'),
        filter_atem_type: 2,
        filter_outlet_id: outletId
    };
}

function buildLabel(payload) {
    if (payload.quarter > 0) {
        return (QUARTERS_LABEL[payload.quarter] || ('Q' + payload.quarter)) + ' ' + payload.year;
    }
    if (payload.month > 0) {
        return (MONTHS_LABEL[payload.month] || payload.month) + ' ' + payload.year;
    }
    return String(payload.year);
}

var _selectionButtonIds = {
    perf: ['export-selected-btn', 'perf-export-lock-selected-btn', 'perf-lock-selected-btn', 'perf-unlock-selected-btn'],
    perfo: ['perfo-export-selected-btn', 'perfo-export-lock-selected-btn', 'perfo-lock-selected-btn', 'perfo-unlock-selected-btn']
};

function updateExportSelected(prefix) {
    var checked = document.querySelectorAll('.' + prefix + '-row-cb:checked');
    var hasSelection = checked.length > 0;
    var btnIds = _selectionButtonIds[prefix] || [];
    for (var i = 0; i < btnIds.length; i++) {
        var btn = document.getElementById(btnIds[i]);
        if (btn) { btn.disabled = !hasSelection; }
    }
}

// rec.id on both tabs' row checkboxes is actually the STAFF id (get-performance-list
// returns one row per staff), so "selected rows" naturally means "selected staff" —
// exactly what the Lock/Unlock actions need to target.
function selectedStaffIds(prefix) {
    var checked = document.querySelectorAll('.' + prefix + '-row-cb:checked');
    var ids = [];
    for (var i = 0; i < checked.length; i++) { ids.push(parseInt(checked[i].value, 10)); }
    return ids;
}

// type=performance export URL, optionally scoped to specific staff ids ("Export
// Selected" / "Export & Lock Selected") — otherwise the full current filter.
function perfExportUrl(payload, ids) {
    var statuses = (payload.statuses || []).join(',');
    var qs = 'type=performance' +
        (ids && ids.length ? '&ids=' + ids.join(',') : '') +
        '&month=' + (payload.month || 0) + '&year=' + (payload.year || PERF_CFG.initYear) + '&quarter=' + (payload.quarter || 0) +
        '&dept=' + (payload.dept || 0) + '&grade=' + (payload.grade || 0) + '&struct=' + (payload.struct || 0) +
        '&staff_filter_id=' + (payload.staff_id || 0) +
        '&statuses=' + encodeURIComponent(statuses) + '&atem_type=1';
    return window.ATEM_MODULE_BASE + 'staff_performance/export.php?' + qs;
}

function perfoExportUrl(payload, ids) {
    var statuses = (payload.statuses || []).join(',');
    var qs = 'type=performance' +
        (ids && ids.length ? '&ids=' + ids.join(',') : '') +
        '&month=' + (payload.month || 0) + '&year=' + (payload.year || PERF_CFG.initYear) + '&quarter=' + (payload.quarter || 0) +
        '&grade=' + (payload.grade || 0) + '&struct=' + (payload.struct || 0) +
        '&staff_filter_id=' + (payload.staff_id || 0) +
        '&statuses=' + encodeURIComponent(statuses) + '&atem_type=2&outlet_id=' + (payload.filter_outlet_id || 0);
    return window.ATEM_MODULE_BASE + 'staff_performance/export.php?' + qs;
}

function updateActionUrls(payload) {
    var month = payload.month || 0;
    var year = payload.year || PERF_CFG.initYear;
    var quarter = payload.quarter || 0;
    var dept = payload.dept || 0;
    var grade = payload.grade || 0;
    var struct = payload.struct || 0;
    var staffId = payload.staff_id || 0;

    var exportBtn = document.getElementById('perf-export-all-btn');
    if (exportBtn) {
        var statuses = (payload.statuses || []).join(',');
        exportBtn.href = window.ATEM_MODULE_BASE + 'staff_performance/export.php?type=performance' +
            '&month=' + month + '&year=' + year + '&quarter=' + quarter +
            '&dept=' + dept + '&grade=' + grade + '&struct=' + struct +
            '&staff_filter_id=' + staffId +
            '&statuses=' + encodeURIComponent(statuses) +
            '&atem_type=1';
    }
}

function updateActionUrlsOutlet(payload) {
    var month = payload.month || 0;
    var year = payload.year || PERF_CFG.initYear;
    var quarter = payload.quarter || 0;
    var outletId = payload.filter_outlet_id || 0;
    var grade = payload.grade || 0;
    var struct = payload.struct || 0;
    var staffId = payload.staff_id || 0;

    var exportBtn = document.getElementById('perfo-export-all-btn');
    if (exportBtn) {
        var statuses = (payload.statuses || []).join(',');
        exportBtn.href = window.ATEM_MODULE_BASE + 'staff_performance/export.php?type=performance' +
            '&month=' + month + '&year=' + year + '&quarter=' + quarter +
            '&grade=' + grade + '&struct=' + struct +
            '&staff_filter_id=' + staffId +
            '&statuses=' + encodeURIComponent(statuses) +
            '&atem_type=2&outlet_id=' + outletId;
    }
}

function perfPageBtn(p) {
    return '<button type="button" class="atem-pager-btn' + (p === _perfPage ? ' active' : '') + '" data-page="' + p +
        '">' + p + '</button>';
}

function perfoPageBtn(p) {
    return '<button type="button" class="atem-pager-btn' + (p === _perfoPage ? ' active' : '') + '" data-page="' + p +
        '">' + p + '</button>';
}

function renderPerfPager(total) {
    var pager = document.getElementById('perf-pager');
    if (!pager) {
        return;
    }
    if (total === 0) {
        pager.innerHTML = '';
        return;
    }
    var pages = Math.max(1, Math.ceil(total / _perfPerPage));
    var startIdx = (_perfPage - 1) * _perfPerPage;
    var shown = Math.min(_perfPerPage, total - startIdx);

    var opts = [10, 30, 50, 100];
    var selHtml = '<select class="atem-perpage-select">';
    for (var oi = 0; oi < opts.length; oi++) {
        selHtml += '<option value="' + opts[oi] + '"' + (_perfPerPage === opts[oi] ? ' selected' : '') + '>' + opts[
            oi] + '</option>';
    }
    selHtml += '</select>';
    var leftHtml = '<div class="atem-pager-left">Show ' + selHtml + ' entries</div>';

    var info = '<span class="atem-pager-info">Showing ' + (startIdx + 1) + ' to ' + (startIdx + shown) + ' of ' +
        total + ' entries</span>';
    var btns = '<button type="button" class="atem-pager-btn" data-page="' + (_perfPage - 1) + '"' + (_perfPage <= 1 ?
        ' disabled' : '') + '>Previous</button>';

    var win = 2,
        pfrom = Math.max(1, _perfPage - win),
        pto = Math.min(pages, _perfPage + win);
    if (pfrom > 1) {
        btns += perfPageBtn(1) + (pfrom > 2 ? '<span class="atem-pager-gap">...</span>' : '');
    }
    for (var pp = pfrom; pp <= pto; pp++) {
        btns += perfPageBtn(pp);
    }
    if (pto < pages) {
        btns += (pto < pages - 1 ? '<span class="atem-pager-gap">...</span>' : '') + perfPageBtn(pages);
    }
    btns += '<button type="button" class="atem-pager-btn" data-page="' + (_perfPage + 1) + '"' + (_perfPage >= pages ?
        ' disabled' : '') + '>Next</button>';

    var rightHtml = '<div class="d-flex align-items-center gap-2">' + info + '<div class="atem-pager-bar">' + btns +
        '</div></div>';
    pager.innerHTML = leftHtml + rightHtml;
}

function renderPerfPagerOutlet(total) {
    var pager = document.getElementById('perfo-pager');
    if (!pager) {
        return;
    }
    if (total === 0) {
        pager.innerHTML = '';
        return;
    }
    var pages = Math.max(1, Math.ceil(total / _perfoPerPage));
    var startIdx = (_perfoPage - 1) * _perfoPerPage;
    var shown = Math.min(_perfoPerPage, total - startIdx);

    var opts = [10, 30, 50, 100];
    var selHtml = '<select class="atem-perpage-select">';
    for (var oi = 0; oi < opts.length; oi++) {
        selHtml += '<option value="' + opts[oi] + '"' + (_perfoPerPage === opts[oi] ? ' selected' : '') + '>' + opts[
            oi] + '</option>';
    }
    selHtml += '</select>';
    var leftHtml = '<div class="atem-pager-left">Show ' + selHtml + ' entries</div>';

    var info = '<span class="atem-pager-info">Showing ' + (startIdx + 1) + ' to ' + (startIdx + shown) + ' of ' +
        total + ' entries</span>';
    var btns = '<button type="button" class="atem-pager-btn" data-page="' + (_perfoPage - 1) + '"' + (_perfoPage <= 1 ?
        ' disabled' : '') + '>Previous</button>';

    var win = 2,
        pfrom = Math.max(1, _perfoPage - win),
        pto = Math.min(pages, _perfoPage + win);
    if (pfrom > 1) {
        btns += perfoPageBtn(1) + (pfrom > 2 ? '<span class="atem-pager-gap">...</span>' : '');
    }
    for (var pp = pfrom; pp <= pto; pp++) {
        btns += perfoPageBtn(pp);
    }
    if (pto < pages) {
        btns += (pto < pages - 1 ? '<span class="atem-pager-gap">...</span>' : '') + perfoPageBtn(pages);
    }
    btns += '<button type="button" class="atem-pager-btn" data-page="' + (_perfoPage + 1) + '"' + (_perfoPage >= pages ?
        ' disabled' : '') + '>Next</button>';

    var rightHtml = '<div class="d-flex align-items-center gap-2">' + info + '<div class="atem-pager-bar">' + btns +
        '</div></div>';
    pager.innerHTML = leftHtml + rightHtml;
}

function renderTable(data, payload) {
    var tbody = document.getElementById('perf-tbody');
    var selectAll = document.getElementById('select-all');
    if (selectAll) {
        selectAll.checked = false;
    }
    updateExportSelected('perf');

    if (!data || !data.length) {
        tbody.innerHTML =
            '<tr><td colspan="11" class="text-center text-muted py-4">No records found for this period.</td></tr>';
        renderPerfPager(0);
        return;
    }

    var total = data.length;
    var pages = Math.max(1, Math.ceil(total / _perfPerPage));
    if (_perfPage > pages) {
        _perfPage = pages;
    }
    var startIdx = (_perfPage - 1) * _perfPerPage;
    var pageData = data.slice(startIdx, startIdx + _perfPerPage);

    var month = (payload && payload.month) ? payload.month : 0;
    var year = (payload && payload.year) ? payload.year : PERF_CFG.initYear;

    function countCell(n) {
        return n > 0 ?
            '<span class="perf-count-link">' + n + '</span>' :
            '<span class="text-muted">0</span>';
    }

    var quarter = (payload && payload.quarter) ? payload.quarter : 0;
    var dept = (payload && payload.dept) ? payload.dept : 0;
    var grade = (payload && payload.grade) ? payload.grade : 0;
    var struct = (payload && payload.struct) ? payload.struct : 0;
    var statuses = (payload && payload.statuses) ? payload.statuses : [];
    var statusesQs = encodeURIComponent(statuses.join(','));

    var html = '';
    for (var i = 0; i < pageData.length; i++) {
        var rec = pageData[i];

        var editUrl = window.ATEM_MODULE_BASE + 'staff_performance/edit.php?id=' + rec.id + '&sid=' + rec.staff_id +
            '&month=' + month + '&year=' + year + '&statuses=' + statusesQs;
        var exportUrl = window.ATEM_MODULE_BASE + 'staff_performance/export.php?type=performance&ids=' + rec.id +
            '&month=' + month + '&year=' + year + '&quarter=' + quarter +
            '&dept=' + dept + '&grade=' + grade + '&struct=' + struct +
            '&statuses=' + statusesQs;

        html += '<tr>' +
            '<td><input type="checkbox" class="perf-row-cb" value="' + rec.id + '"></td>' +
            '<td>' +
            '<div style="font-weight:500;">' + escHtml(rec.staff_name) + '</div>' +
            '<div class="text-muted" style="font-size:11px;">' + escHtml(rec.dept_name) + '</div>' +
            '</td>' +
            '<td>' + escHtml(rec.grade_label) + '</td>' +
            '<td>' + escHtml(rec.struct_label) + '</td>' +
            '<td class="text-center perf-count-cell" data-staff-id="' + rec.staff_id + '" data-col="atem" style="cursor:pointer;">' + countCell(rec.total_atem) + '</td>' +
            '<td class="text-center perf-count-cell" data-staff-id="' + rec.staff_id + '" data-col="complete" style="cursor:pointer;">' + countCell(rec.complete_count) + '</td>' +
            '<td class="text-center perf-count-cell" data-staff-id="' + rec.staff_id + '" data-col="active" style="cursor:pointer;">' + countCell(rec.active_count) + '</td>' +
            '<td class="text-center perf-count-cell" data-staff-id="' + rec.staff_id + '" data-col="extend" style="cursor:pointer;">' + countCell(rec.extend_count) + '</td>' +
            '<td class="text-center perf-count-cell" data-staff-id="' + rec.staff_id + '" data-col="failed" style="cursor:pointer;">' + countCell(rec.failed_count) + '</td>' +
            '<td class="text-end">RM ' + formatNumber(rec.total_incentive) + '</td>' +
            '<td style="white-space:nowrap;">' +
            '<a class="btn btn-sm btn-outline-secondary me-1" href="' + escHtml(editUrl) + '">View</a>' +
            '<a class="btn btn-sm btn-outline-success me-1 perf-row-export" href="' + escHtml(exportUrl) + '">Export</a>' +
            (PERF_CFG.isSuperAdmin && rec.has_unlocked ? '<button type="button" class="btn btn-sm btn-outline-danger me-1 perf-row-lock" data-staff-id="' + rec.staff_id + '" title="Lock Payout"><i class="bi bi-lock-fill"></i></button>' : '') +
            (PERF_CFG.isSuperAdmin && rec.has_locked ? '<button type="button" class="btn btn-sm btn-outline-warning perf-row-unlock" data-staff-id="' + rec.staff_id + '" title="Undo Lock Payout"><i class="bi bi-unlock-fill"></i></button>' : '') +
            '</td>' +
            '</tr>';
    }
    tbody.innerHTML = html;
    renderPerfPager(total);
}

function renderTableOutlet(data, payload) {
    var tbody = document.getElementById('perfo-tbody');
    var selectAll = document.getElementById('perfo-select-all');
    if (selectAll) {
        selectAll.checked = false;
    }
    updateExportSelected('perfo');

    if (!data || !data.length) {
        tbody.innerHTML =
            '<tr><td colspan="11" class="text-center text-muted py-4">No records found for this period.</td></tr>';
        renderPerfPagerOutlet(0);
        return;
    }

    var total = data.length;
    var pages = Math.max(1, Math.ceil(total / _perfoPerPage));
    if (_perfoPage > pages) {
        _perfoPage = pages;
    }
    var startIdx = (_perfoPage - 1) * _perfoPerPage;
    var pageData = data.slice(startIdx, startIdx + _perfoPerPage);

    var month = (payload && payload.month) ? payload.month : 0;
    var year = (payload && payload.year) ? payload.year : PERF_CFG.initYear;

    function countCell(n) {
        return n > 0 ?
            '<span class="perf-count-link">' + n + '</span>' :
            '<span class="text-muted">0</span>';
    }

    var quarter = (payload && payload.quarter) ? payload.quarter : 0;
    var outletId = (payload && payload.filter_outlet_id) ? payload.filter_outlet_id : 0;
    var grade = (payload && payload.grade) ? payload.grade : 0;
    var struct = (payload && payload.struct) ? payload.struct : 0;
    var statuses = (payload && payload.statuses) ? payload.statuses : [];
    var statusesQs = encodeURIComponent(statuses.join(','));

    var html = '';
    for (var i = 0; i < pageData.length; i++) {
        var rec = pageData[i];

        var editUrl = window.ATEM_MODULE_BASE + 'staff_performance/edit.php?id=' + rec.id + '&sid=' + rec.staff_id +
            '&month=' + month + '&year=' + year + '&statuses=' + statusesQs;
        var exportUrl = window.ATEM_MODULE_BASE + 'staff_performance/export.php?type=performance&ids=' + rec.id +
            '&month=' + month + '&year=' + year + '&quarter=' + quarter +
            '&grade=' + grade + '&struct=' + struct +
            '&statuses=' + statusesQs + '&atem_type=2&outlet_id=' + outletId;

        html += '<tr>' +
            '<td><input type="checkbox" class="perfo-row-cb" value="' + rec.id + '"></td>' +
            '<td>' +
            '<div style="font-weight:500;">' + escHtml(rec.staff_name) + '</div>' +
            '<div class="text-muted" style="font-size:11px;">' + escHtml(rec.position_label) + '</div>' +
            '</td>' +
            '<td>' + escHtml(rec.grade_label) + '</td>' +
            '<td>' + escHtml(rec.struct_label) + '</td>' +
            '<td class="text-center perfo-count-cell" data-staff-id="' + rec.staff_id + '" data-col="atem" style="cursor:pointer;">' + countCell(rec.total_atem) + '</td>' +
            '<td class="text-center perfo-count-cell" data-staff-id="' + rec.staff_id + '" data-col="complete" style="cursor:pointer;">' + countCell(rec.complete_count) + '</td>' +
            '<td class="text-center perfo-count-cell" data-staff-id="' + rec.staff_id + '" data-col="active" style="cursor:pointer;">' + countCell(rec.active_count) + '</td>' +
            '<td class="text-center perfo-count-cell" data-staff-id="' + rec.staff_id + '" data-col="extend" style="cursor:pointer;">' + countCell(rec.extend_count) + '</td>' +
            '<td class="text-center perfo-count-cell" data-staff-id="' + rec.staff_id + '" data-col="failed" style="cursor:pointer;">' + countCell(rec.failed_count) + '</td>' +
            '<td class="text-end">RM ' + formatNumber(rec.total_incentive) + '</td>' +
            '<td style="white-space:nowrap;">' +
            '<a class="btn btn-sm btn-outline-secondary me-1" href="' + escHtml(editUrl) + '">View</a>' +
            '<a class="btn btn-sm btn-outline-success me-1 perfo-row-export" href="' + escHtml(exportUrl) + '">Export</a>' +
            (PERF_CFG.isSuperAdmin && rec.has_unlocked ? '<button type="button" class="btn btn-sm btn-outline-danger me-1 perfo-row-lock" data-staff-id="' + rec.staff_id + '" title="Lock Payout"><i class="bi bi-lock-fill"></i></button>' : '') +
            (PERF_CFG.isSuperAdmin && rec.has_locked ? '<button type="button" class="btn btn-sm btn-outline-warning perfo-row-unlock" data-staff-id="' + rec.staff_id + '" title="Undo Lock Payout"><i class="bi bi-unlock-fill"></i></button>' : '') +
            '</td>' +
            '</tr>';
    }
    tbody.innerHTML = html;
    renderPerfPagerOutlet(total);
}

function loadPerformance(payload) {
    _currentPayload = payload;

    var tbody = document.getElementById('perf-tbody');
    var labelEl = document.getElementById('perf-filter-label');
    var periodEl = document.getElementById('perf-period-label');
    var label = buildLabel(payload);

    tbody.innerHTML = '<tr><td colspan="11" class="text-center text-muted py-4">Loading...</td></tr>';
    if (labelEl) {
        labelEl.textContent = label;
    }
    if (periodEl) {
        periodEl.textContent = label + ' — Staff Performance';
    }

    updateActionUrls(payload);

    var body = {
        action: 'get-performance-list'
    };
    for (var k in payload) {
        if (payload.hasOwnProperty(k)) {
            body[k] = payload[k];
        }
    }

    fetch(PERF_API_URL, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify(body)
        })
        .then(function(r) {
            return r.json();
        })
        .then(function(res) {
            if (!res.success) {
                tbody.innerHTML = '<tr><td colspan="11" class="text-center text-danger py-3">' +
                    escHtml(res.message || 'Failed to load records.') + '</td></tr>';
                return;
            }
            _perfAllData = res.data || [];
            _perfPage = 1;
            renderTable(_perfAllData, payload);
        })
        .catch(function() {
            tbody.innerHTML =
                '<tr><td colspan="11" class="text-center text-danger py-3">Request failed. Please try again.</td></tr>';
        });
}

function loadPerformanceOutlet(payload) {
    _currentPayloadOutlet = payload;

    var tbody = document.getElementById('perfo-tbody');
    var labelEl = document.getElementById('perfo-filter-label');
    var periodEl = document.getElementById('perfo-period-label');
    var label = buildLabel(payload);

    tbody.innerHTML = '<tr><td colspan="11" class="text-center text-muted py-4">Loading...</td></tr>';
    if (labelEl) {
        labelEl.textContent = label;
    }
    if (periodEl) {
        periodEl.textContent = label + ' — Staff Performance';
    }

    updateActionUrlsOutlet(payload);

    var body = {
        action: 'get-performance-list'
    };
    for (var k in payload) {
        if (payload.hasOwnProperty(k)) {
            body[k] = payload[k];
        }
    }

    fetch(PERF_API_URL, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify(body)
        })
        .then(function(r) {
            return r.json();
        })
        .then(function(res) {
            if (!res.success) {
                tbody.innerHTML = '<tr><td colspan="11" class="text-center text-danger py-3">' +
                    escHtml(res.message || 'Failed to load records.') + '</td></tr>';
                return;
            }
            _perfoAllData = res.data || [];
            _perfoPage = 1;
            renderTableOutlet(_perfoAllData, payload);
        })
        .catch(function() {
            tbody.innerHTML =
                '<tr><td colspan="11" class="text-center text-danger py-3">Request failed. Please try again.</td></tr>';
        });
}

document.addEventListener('DOMContentLoaded', function() {

    buildStaffDropdown();
    buildStaffDropdownOutlet();
    buildOutletDropdown();

    // Status filter dropdown (checkbox list, styled like the Issuer searchable dropdown)
    var statusBtn = document.getElementById('perf-status-btn');
    var statusDropdown = document.getElementById('perf-status-dropdown');
    var statusWrap = document.getElementById('perf-status-wrap');
    updateStatusButtonLabel('perf');
    syncS2ButtonSize(statusBtn, document.getElementById('perf-filter-year'));

    var statusBtnO = document.getElementById('perfo-status-btn');
    var statusDropdownO = document.getElementById('perfo-status-dropdown');
    var statusWrapO = document.getElementById('perfo-status-wrap');
    updateStatusButtonLabel('perfo');
    syncS2ButtonSize(statusBtnO, document.getElementById('perfo-filter-year'));

    if (statusBtn && statusDropdown) {
        statusBtn.addEventListener('click', function(e) {
            e.stopPropagation();
            statusDropdown.classList.toggle('open');
        });
        statusBtn.addEventListener('keydown', function(e) {
            if (e.key === 'Enter' || e.key === ' ') {
                e.preventDefault();
                statusDropdown.classList.toggle('open');
            }
        });
        document.addEventListener('click', function(e) {
            if (statusWrap && !statusWrap.contains(e.target)) {
                statusDropdown.classList.remove('open');
            }
        });
    }
    if (statusBtnO && statusDropdownO) {
        statusBtnO.addEventListener('click', function(e) {
            e.stopPropagation();
            statusDropdownO.classList.toggle('open');
        });
        statusBtnO.addEventListener('keydown', function(e) {
            if (e.key === 'Enter' || e.key === ' ') {
                e.preventDefault();
                statusDropdownO.classList.toggle('open');
            }
        });
        document.addEventListener('click', function(e) {
            if (statusWrapO && !statusWrapO.contains(e.target)) {
                statusDropdownO.classList.remove('open');
            }
        });
    }
    document.addEventListener('change', function(e) {
        if (e.target && e.target.classList.contains('perf-status-cb')) {
            updateStatusButtonLabel('perf');
            loadPerformance(buildPayload());
        } else if (e.target && e.target.classList.contains('perfo-status-cb')) {
            updateStatusButtonLabel('perfo');
            loadPerformanceOutlet(buildPayloadOutlet());
        }
    });

    // Re-sync Outlet tab dropdown sizing once the pane leaves display:none —
    // offsetHeight reads 0 while hidden, so the initial sync above is a no-op
    // for this tab until it's actually shown (same fix pattern as index.js's
    // Outlet dashboard tab and view.js's vfo-* controls).
    var perfTabOutletBtn = document.getElementById('perf-tab-outlet-btn');
    if (perfTabOutletBtn) {
        perfTabOutletBtn.addEventListener('shown.bs.tab', function() {
            syncS2ButtonSize(document.getElementById('perfo-staff-btn'), document.getElementById('perfo-filter-year'));
            syncS2ButtonSize(document.getElementById('perfo-outlet-btn'), document.getElementById('perfo-filter-year'));
            syncS2ButtonSize(statusBtnO, document.getElementById('perfo-filter-year'));
        });
    }

    // Initial load
    if (PERF_CFG.tabSingleView !== 'outlet') { loadPerformance(buildPayload()); }
    if (PERF_CFG.tabSingleView !== 'hq') { loadPerformanceOutlet(buildPayloadOutlet()); }

    // Reset filter
    document.getElementById('perf-reset-filter').addEventListener('click', function() {
        document.getElementById('perf-filter-year').value = PERF_CFG.initYear;
        document.getElementById('perf-filter-month').value = '0';
        document.getElementById('perf-filter-quarter').value = '0';
        var deptEl = document.getElementById('perf-filter-dept');
        var gradeEl = document.getElementById('perf-filter-grade');
        var structEl = document.getElementById('perf-filter-struct');
        if (deptEl) {
            deptEl.value = '0';
        }
        if (gradeEl) {
            gradeEl.value = '0';
        }
        if (structEl) {
            structEl.value = '0';
        }
        var statusBoxes = document.querySelectorAll('.perf-status-cb');
        for (var _si = 0; _si < statusBoxes.length; _si++) {
            statusBoxes[_si].checked = (PERF_CFG.defaultStatuses || []).indexOf(statusBoxes[_si].value) !== -1;
        }
        updateStatusButtonLabel('perf');
        resetStaffDropdown();
        loadPerformance(buildPayload());
    });

    // Reset filter (Outlet)
    document.getElementById('perfo-reset-filter').addEventListener('click', function() {
        document.getElementById('perfo-filter-year').value = PERF_CFG.initYear;
        document.getElementById('perfo-filter-month').value = '0';
        document.getElementById('perfo-filter-quarter').value = '0';
        var gradeElO = document.getElementById('perfo-filter-grade');
        var structElO = document.getElementById('perfo-filter-struct');
        if (gradeElO) {
            gradeElO.value = '0';
        }
        if (structElO) {
            structElO.value = '0';
        }
        var statusBoxesO = document.querySelectorAll('.perfo-status-cb');
        for (var _sio = 0; _sio < statusBoxesO.length; _sio++) {
            statusBoxesO[_sio].checked = (PERF_CFG.defaultStatuses || []).indexOf(statusBoxesO[_sio].value) !== -1;
        }
        updateStatusButtonLabel('perfo');
        resetOutletDropdown();
        resetStaffDropdownOutlet();
        loadPerformanceOutlet(buildPayloadOutlet());
    });

    // Month / quarter mutual exclusion, then auto-apply
    document.getElementById('perf-filter-month').addEventListener('change', function() {
        if (this.value && this.value !== '0') {
            document.getElementById('perf-filter-quarter').value = '0';
        }
        loadPerformance(buildPayload());
    });
    document.getElementById('perf-filter-quarter').addEventListener('change', function() {
        if (this.value && this.value !== '0') {
            document.getElementById('perf-filter-month').value = '0';
        }
        loadPerformance(buildPayload());
    });
    document.getElementById('perfo-filter-month').addEventListener('change', function() {
        if (this.value && this.value !== '0') {
            document.getElementById('perfo-filter-quarter').value = '0';
        }
        loadPerformanceOutlet(buildPayloadOutlet());
    });
    document.getElementById('perfo-filter-quarter').addEventListener('change', function() {
        if (this.value && this.value !== '0') {
            document.getElementById('perfo-filter-month').value = '0';
        }
        loadPerformanceOutlet(buildPayloadOutlet());
    });

    // Auto-apply on every other filter change
    var _perfAutoFilterIds = ['perf-filter-year', 'perf-filter-dept', 'perf-filter-grade', 'perf-filter-struct'];
    for (var _pfi = 0; _pfi < _perfAutoFilterIds.length; _pfi++) {
        var _pfEl = document.getElementById(_perfAutoFilterIds[_pfi]);
        if (_pfEl) {
            _pfEl.addEventListener('change', function() {
                loadPerformance(buildPayload());
            });
        }
    }
    var _perfoAutoFilterIds = ['perfo-filter-year', 'perfo-filter-grade', 'perfo-filter-struct'];
    for (var _pfoi = 0; _pfoi < _perfoAutoFilterIds.length; _pfoi++) {
        var _pfoEl = document.getElementById(_perfoAutoFilterIds[_pfoi]);
        if (_pfoEl) {
            _pfoEl.addEventListener('change', function() {
                loadPerformanceOutlet(buildPayloadOutlet());
            });
        }
    }

    // Select-all checkbox
    var selectAll = document.getElementById('select-all');
    if (selectAll) {
        selectAll.addEventListener('change', function() {
            var cbs = document.querySelectorAll('.perf-row-cb');
            for (var i = 0; i < cbs.length; i++) {
                cbs[i].checked = selectAll.checked;
            }
            updateExportSelected('perf');
        });
    }
    var selectAllO = document.getElementById('perfo-select-all');
    if (selectAllO) {
        selectAllO.addEventListener('change', function() {
            var cbs = document.querySelectorAll('.perfo-row-cb');
            for (var i = 0; i < cbs.length; i++) {
                cbs[i].checked = selectAllO.checked;
            }
            updateExportSelected('perfo');
        });
    }
    // Row checkbox change (event delegation)
    document.addEventListener('change', function(e) {
        if (e.target && e.target.classList.contains('perf-row-cb')) {
            updateExportSelected('perf');
            var cbs = document.querySelectorAll('.perf-row-cb');
            var allChecked = cbs.length > 0;
            for (var i = 0; i < cbs.length; i++) {
                if (!cbs[i].checked) {
                    allChecked = false;
                    break;
                }
            }
            if (selectAll) {
                selectAll.checked = allChecked;
            }
        } else if (e.target && e.target.classList.contains('perfo-row-cb')) {
            updateExportSelected('perfo');
            var cbsO = document.querySelectorAll('.perfo-row-cb');
            var allCheckedO = cbsO.length > 0;
            for (var io = 0; io < cbsO.length; io++) {
                if (!cbsO[io].checked) {
                    allCheckedO = false;
                    break;
                }
            }
            if (selectAllO) {
                selectAllO.checked = allCheckedO;
            }
        }
    });

    // Export selected
    var exportSelectedBtn = document.getElementById('export-selected-btn');
    if (exportSelectedBtn) {
        exportSelectedBtn.addEventListener('click', function() {
            var ids = selectedStaffIds('perf');
            if (!ids.length) { return; }
            triggerExport(perfExportUrl(_currentPayload, ids));
        });
    }
    var exportSelectedBtnO = document.getElementById('perfo-export-selected-btn');
    if (exportSelectedBtnO) {
        exportSelectedBtnO.addEventListener('click', function() {
            var ids = selectedStaffIds('perfo');
            if (!ids.length) { return; }
            triggerExport(perfoExportUrl(_currentPayloadOutlet, ids), 'perfo-export-progress-wrap', 'perfo-export-progress-msg');
        });
    }

    // Count cell click -> ATEM detail modal (shared by both tabs; atemType
    // scopes the drill-down to the tab that was actually clicked, so an
    // Outlet-tab row never pulls in a staff member's HQ cards and vice versa).
    var detailModal = document.getElementById('atemDetailModal');
    var bsDetailModal = new bootstrap.Modal(detailModal);

    function openAtemDetailModal(targetSid, col, payload, atemType) {
        if (!targetSid) {
            return;
        }
        var targetMonth = payload.month || 0;
        var targetYear = payload.year || PERF_CFG.initYear;
        var targetQuarter = payload.quarter || 0;
        var targetStatuses = payload.statuses || [];

        var modalTitle = document.getElementById('atem-detail-modal-title');
        var loading = document.getElementById('atem-detail-loading');
        var errorEl = document.getElementById('atem-detail-error');
        var tableWrap = document.getElementById('atem-detail-table-wrap');
        var tbody = document.getElementById('atem-detail-tbody');

        modalTitle.textContent = PERF_COL_LABELS[col] || 'ATEM Details';
        loading.style.display = 'block';
        tableWrap.style.display = 'none';
        errorEl.style.display = 'none';
        tbody.innerHTML = '';
        bsDetailModal.show();

        fetch(PERF_API_URL, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({
                    action: 'get-staff-atem-list',
                    target_staff_id: targetSid,
                    month: targetMonth,
                    year: targetYear,
                    quarter: targetQuarter,
                    statuses: targetStatuses,
                    filter_atem_type: atemType,
                    col: col
                })
            })
            .then(function(r) {
                return r.json();
            })
            .then(function(res) {
                loading.style.display = 'none';
                if (!res.success) {
                    errorEl.textContent = res.message || 'Failed to load ATEM data.';
                    errorEl.style.display = 'block';
                    return;
                }
                var data = res.data || [];
                var ROLE_COLOR = {
                    'Issuer': '#198754',
                    'Area Manager': '#20c997',
                    'A': '#6610f2',
                    'R': '#0d6efd',
                    'C': '#fd7e14',
                    'I': '#6c757d'
                };
                if (!data.length) {
                    tbody.innerHTML =
                        '<tr><td colspan="8" class="text-center text-muted py-3">No records.</td></tr>';
                } else {
                    var html = '';
                    for (var i = 0; i < data.length; i++) {
                        var row = data[i];
                        var color = STATUS_COLOR[row.status] || '#6c757d';
                        var statusBadge = '<span class="atem-pill" style="background-color:' + escHtml(color) + ';">' +
                            escHtml(row.status || '-') + '</span>';
                        var endDate = row.is_extended && row.extended_date_1 ? formatDate(row
                            .extended_date_1) : formatDate(row.end_date);
                        var closureDate = formatDate(row.closure_date);
                        var roleCell = '-';
                        if (row.my_role && row.my_role.length) {
                            var badges = [];
                            for (var ri = 0; ri < row.my_role.length; ri++) {
                                var rname = row.my_role[ri];
                                var rc = ROLE_COLOR[rname] || '#6c757d';
                                badges.push('<span class="atem-pill" style="background-color:' + escHtml(rc) + ';">' +
                                    escHtml(rname) + '</span>');
                            }
                            roleCell = '<div class="perf-role-cell">' + badges.join('') + '</div>';
                        }
                        html += '<tr>' +
                            '<td>#AT' + row.id + '</td>' +
                            '<td>' + escHtml(row.title) + '</td>' +
                            '<td>' + escHtml(row.level_label || '-') + '</td>' +
                            '<td>' + formatDate(row.start_date) + '</td>' +
                            '<td>' + endDate + '</td>' +
                            '<td>' + closureDate + '</td>' +
                            '<td>' + statusBadge + '</td>' +
                            '<td>' + roleCell + '</td>' +
                            '</tr>';
                    }
                    tbody.innerHTML = html;
                }
                tableWrap.style.display = 'block';
            })
            .catch(function() {
                loading.style.display = 'none';
                errorEl.textContent = 'Request failed. Please try again.';
                errorEl.style.display = 'block';
            });
    }

    document.addEventListener('click', function(e) {
        var cell = e.target.closest('.perf-count-cell');
        if (cell) {
            openAtemDetailModal(parseInt(cell.getAttribute('data-staff-id'), 10), cell.getAttribute('data-col'), _currentPayload, 1);
            return;
        }
        var cellO = e.target.closest('.perfo-count-cell');
        if (cellO) {
            openAtemDetailModal(parseInt(cellO.getAttribute('data-staff-id'), 10), cellO.getAttribute('data-col'), _currentPayloadOutlet, 2);
        }
    });

    // Perf table pager
    var perfPager = document.getElementById('perf-pager');
    if (perfPager) {
        perfPager.addEventListener('click', function(e) {
            var btn = e.target.closest ? e.target.closest('.atem-pager-btn') : null;
            if (btn && !btn.disabled) {
                var p = parseInt(btn.getAttribute('data-page'), 10);
                if (p >= 1) {
                    _perfPage = p;
                    renderTable(_perfAllData, _currentPayload);
                }
            }
        });
        perfPager.addEventListener('change', function(e) {
            if (e.target.classList.contains('atem-perpage-select')) {
                _perfPerPage = parseInt(e.target.value, 10);
                _perfPage = 1;
                renderTable(_perfAllData, _currentPayload);
            }
        });
    }
    var perfoPager = document.getElementById('perfo-pager');
    if (perfoPager) {
        perfoPager.addEventListener('click', function(e) {
            var btn = e.target.closest ? e.target.closest('.atem-pager-btn') : null;
            if (btn && !btn.disabled) {
                var p = parseInt(btn.getAttribute('data-page'), 10);
                if (p >= 1) {
                    _perfoPage = p;
                    renderTableOutlet(_perfoAllData, _currentPayloadOutlet);
                }
            }
        });
        perfoPager.addEventListener('change', function(e) {
            if (e.target.classList.contains('atem-perpage-select')) {
                _perfoPerPage = parseInt(e.target.value, 10);
                _perfoPage = 1;
                renderTableOutlet(_perfoAllData, _currentPayloadOutlet);
            }
        });
    }

    // Lock / Unlock payout — shared confirmation modals (one pair for both HQ
    // and Outlet tabs), remark required. _pendingLockAction/_pendingUnlockAction
    // record what the confirm button should act on: the tab's current filter
    // payload (bar-level = 'all filtered', row-level = narrowed further by an
    // explicit staffIds list from the checked rows), plus where to reload/export
    // afterwards. Targets are ATEM cards, not staff rows — resolved server-side
    // in api.php from the filter + involved staff (issuer/ARCI/area manager),
    // restricted to payout-terminal statuses.
    var payoutLockModalEl = document.getElementById('payoutLockModal');
    var payoutUnlockModalEl = document.getElementById('payoutUnlockModal');
    var bsPayoutLockModal = payoutLockModalEl ? new bootstrap.Modal(payoutLockModalEl) : null;
    var bsPayoutUnlockModal = payoutUnlockModalEl ? new bootstrap.Modal(payoutUnlockModalEl) : null;
    var _pendingLockAction = null;
    var _pendingUnlockAction = null;

    function openLockModal(filterPayload, staffIds, andExport, exportUrl, progressWrapId, progressMsgId, labelElId, reloadFn) {
        _pendingLockAction = {
            filterPayload: filterPayload, staffIds: staffIds || null, andExport: !!andExport,
            exportUrl: exportUrl, progressWrapId: progressWrapId, progressMsgId: progressMsgId,
            labelElId: labelElId, reloadFn: reloadFn
        };
        var remarkEl = document.getElementById('payout-lock-remark');
        if (remarkEl) { remarkEl.value = ''; }
        var errEl = document.getElementById('payout-lock-remark-error');
        if (errEl) { errEl.textContent = ''; }
        if (bsPayoutLockModal) { bsPayoutLockModal.show(); }
    }

    function openUnlockModal(filterPayload, staffIds, labelElId, reloadFn) {
        _pendingUnlockAction = { filterPayload: filterPayload, staffIds: staffIds || null, labelElId: labelElId, reloadFn: reloadFn };
        var remarkEl = document.getElementById('payout-unlock-remark');
        if (remarkEl) { remarkEl.value = ''; }
        var errEl = document.getElementById('payout-unlock-remark-error');
        if (errEl) { errEl.textContent = ''; }
        if (bsPayoutUnlockModal) { bsPayoutUnlockModal.show(); }
    }

    function wireLockButtons(prefix, buildPayloadFn, exportUrlFn, reloadFn, progressWrapId, progressMsgId) {
        var barLockBtn = document.getElementById(prefix + '-lock-btn');
        if (barLockBtn) {
            barLockBtn.addEventListener('click', function() {
                var payload = buildPayloadFn();
                openLockModal(payload, null, false, null, progressWrapId, progressMsgId, prefix + '-filter-label', reloadFn);
            });
        }
        var barExportLockBtn = document.getElementById(prefix + '-export-lock-btn');
        if (barExportLockBtn) {
            barExportLockBtn.addEventListener('click', function() {
                var payload = buildPayloadFn();
                var exportBtn = document.getElementById(prefix + '-export-all-btn');
                openLockModal(payload, null, true, exportBtn ? exportBtn.href : null, progressWrapId, progressMsgId, prefix + '-filter-label', reloadFn);
            });
        }
        var barUnlockBtn = document.getElementById(prefix + '-unlock-btn');
        if (barUnlockBtn) {
            barUnlockBtn.addEventListener('click', function() {
                openUnlockModal(buildPayloadFn(), null, prefix + '-filter-label', reloadFn);
            });
        }
        var selLockBtn = document.getElementById(prefix + '-lock-selected-btn');
        if (selLockBtn) {
            selLockBtn.addEventListener('click', function() {
                var staffIds = selectedStaffIds(prefix);
                if (!staffIds.length) { return; }
                openLockModal(buildPayloadFn(), staffIds, false, null, progressWrapId, progressMsgId, prefix + '-filter-label', reloadFn);
            });
        }
        var selExportLockBtn = document.getElementById(prefix + '-export-lock-selected-btn');
        if (selExportLockBtn) {
            selExportLockBtn.addEventListener('click', function() {
                var staffIds = selectedStaffIds(prefix);
                if (!staffIds.length) { return; }
                var payload = buildPayloadFn();
                openLockModal(payload, staffIds, true, exportUrlFn(payload, staffIds), progressWrapId, progressMsgId, prefix + '-filter-label', reloadFn);
            });
        }
        var selUnlockBtn = document.getElementById(prefix + '-unlock-selected-btn');
        if (selUnlockBtn) {
            selUnlockBtn.addEventListener('click', function() {
                var staffIds = selectedStaffIds(prefix);
                if (!staffIds.length) { return; }
                openUnlockModal(buildPayloadFn(), staffIds, prefix + '-filter-label', reloadFn);
            });
        }
    }

    wireLockButtons('perf', buildPayload, perfExportUrl, function() { loadPerformance(buildPayload()); },
        'export-progress-wrap', 'export-progress-msg');
    wireLockButtons('perfo', buildPayloadOutlet, perfoExportUrl, function() { loadPerformanceOutlet(buildPayloadOutlet()); },
        'perfo-export-progress-wrap', 'perfo-export-progress-msg');

    var payoutLockConfirmBtn = document.getElementById('payout-lock-confirm-btn');
    if (payoutLockConfirmBtn) {
        payoutLockConfirmBtn.addEventListener('click', function() {
            if (!_pendingLockAction) { return; }
            var remarkEl = document.getElementById('payout-lock-remark');
            var errEl = document.getElementById('payout-lock-remark-error');
            var remark = remarkEl ? remarkEl.value.trim() : '';
            if (!remark) {
                if (errEl) { errEl.textContent = 'Remark is required.'; }
                return;
            }
            if (errEl) { errEl.textContent = ''; }

            var action = _pendingLockAction;
            var body = { action: 'bulk-lock-payout', remarks: remark };
            for (var k in action.filterPayload) {
                if (action.filterPayload.hasOwnProperty(k)) { body[k] = action.filterPayload[k]; }
            }
            if (action.staffIds) { body.staff_ids = action.staffIds; }

            payoutLockConfirmBtn.disabled = true;
            fetch(PERF_API_URL, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(body)
                })
                .then(function(r) { return r.json(); })
                .then(function(res) {
                    payoutLockConfirmBtn.disabled = false;
                    if (!res.success) {
                        if (errEl) { errEl.textContent = res.message || 'Failed to lock payout.'; }
                        return;
                    }
                    if (bsPayoutLockModal) { bsPayoutLockModal.hide(); }
                    var labelEl = document.getElementById(action.labelElId);
                    if (labelEl) { labelEl.textContent = 'Locked ' + res.locked + ', skipped ' + res.skipped + '.'; }
                    _pendingLockAction = null;
                    if (action.reloadFn) { action.reloadFn(); }
                    if (action.andExport && action.exportUrl) {
                        triggerExport(action.exportUrl, action.progressWrapId, action.progressMsgId);
                    }
                })
                .catch(function() {
                    payoutLockConfirmBtn.disabled = false;
                    if (errEl) { errEl.textContent = 'Request failed. Please try again.'; }
                });
        });
    }

    var payoutUnlockConfirmBtn = document.getElementById('payout-unlock-confirm-btn');
    if (payoutUnlockConfirmBtn) {
        payoutUnlockConfirmBtn.addEventListener('click', function() {
            if (!_pendingUnlockAction) { return; }
            var remarkEl = document.getElementById('payout-unlock-remark');
            var errEl = document.getElementById('payout-unlock-remark-error');
            var remark = remarkEl ? remarkEl.value.trim() : '';
            if (!remark) {
                if (errEl) { errEl.textContent = 'Remark is required.'; }
                return;
            }
            if (errEl) { errEl.textContent = ''; }

            var action = _pendingUnlockAction;
            var body = { action: 'bulk-unlock-payout', remarks: remark };
            for (var k in action.filterPayload) {
                if (action.filterPayload.hasOwnProperty(k)) { body[k] = action.filterPayload[k]; }
            }
            if (action.staffIds) { body.staff_ids = action.staffIds; }

            payoutUnlockConfirmBtn.disabled = true;
            fetch(PERF_API_URL, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(body)
                })
                .then(function(r) { return r.json(); })
                .then(function(res) {
                    payoutUnlockConfirmBtn.disabled = false;
                    if (!res.success) {
                        if (errEl) { errEl.textContent = res.message || 'Failed to unlock payout.'; }
                        return;
                    }
                    if (bsPayoutUnlockModal) { bsPayoutUnlockModal.hide(); }
                    var labelEl = document.getElementById(action.labelElId);
                    if (labelEl) { labelEl.textContent = 'Unlocked ' + res.unlocked + ', skipped ' + res.skipped + '.'; }
                    _pendingUnlockAction = null;
                    if (action.reloadFn) { action.reloadFn(); }
                })
                .catch(function() {
                    payoutUnlockConfirmBtn.disabled = false;
                    if (errEl) { errEl.textContent = 'Request failed. Please try again.'; }
                });
        });
    }

    // Export progress helper — wrapId/msgId default to the HQ tab's progress
    // bar, the Outlet tab passes its own (perfo-export-progress-*).
    function triggerExport(url, wrapId, msgId) {
        wrapId = wrapId || 'export-progress-wrap';
        msgId  = msgId  || 'export-progress-msg';
        var token = Date.now().toString(36) + Math.random().toString(36).substr(2, 4);
        var sep   = url.indexOf('?') >= 0 ? '&' : '?';
        var wrap  = document.getElementById(wrapId);
        var msg   = document.getElementById(msgId);
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

    var exportAllBtn = document.getElementById('perf-export-all-btn');
    if (exportAllBtn) {
        exportAllBtn.addEventListener('click', function(e) {
            e.preventDefault();
            triggerExport(this.href);
        });
    }
    var exportAllBtnO = document.getElementById('perfo-export-all-btn');
    if (exportAllBtnO) {
        exportAllBtnO.addEventListener('click', function(e) {
            e.preventDefault();
            triggerExport(this.href, 'perfo-export-progress-wrap', 'perfo-export-progress-msg');
        });
    }

    document.addEventListener('click', function(e) {
        var a = e.target.closest ? e.target.closest('.perf-row-export') : null;
        if (a) { e.preventDefault(); triggerExport(a.href); return; }
        var aO = e.target.closest ? e.target.closest('.perfo-row-export') : null;
        if (aO) { e.preventDefault(); triggerExport(aO.href, 'perfo-export-progress-wrap', 'perfo-export-progress-msg'); return; }

        // Per-row Lock/Unlock — same shared modals as the bar-level/Selected
        // buttons, scoped to just this one staff member within the tab's
        // current filter.
        var lockBtn = e.target.closest ? e.target.closest('.perf-row-lock') : null;
        if (lockBtn) {
            var lockSid = parseInt(lockBtn.getAttribute('data-staff-id'), 10);
            openLockModal(buildPayload(), [lockSid], false, null, 'export-progress-wrap', 'export-progress-msg',
                'perf-filter-label', function() { loadPerformance(buildPayload()); });
            return;
        }
        var unlockBtn = e.target.closest ? e.target.closest('.perf-row-unlock') : null;
        if (unlockBtn) {
            var unlockSid = parseInt(unlockBtn.getAttribute('data-staff-id'), 10);
            openUnlockModal(buildPayload(), [unlockSid], 'perf-filter-label', function() { loadPerformance(buildPayload()); });
            return;
        }
        var lockBtnO = e.target.closest ? e.target.closest('.perfo-row-lock') : null;
        if (lockBtnO) {
            var lockSidO = parseInt(lockBtnO.getAttribute('data-staff-id'), 10);
            openLockModal(buildPayloadOutlet(), [lockSidO], false, null, 'perfo-export-progress-wrap', 'perfo-export-progress-msg',
                'perfo-filter-label', function() { loadPerformanceOutlet(buildPayloadOutlet()); });
            return;
        }
        var unlockBtnO = e.target.closest ? e.target.closest('.perfo-row-unlock') : null;
        if (unlockBtnO) {
            var unlockSidO = parseInt(unlockBtnO.getAttribute('data-staff-id'), 10);
            openUnlockModal(buildPayloadOutlet(), [unlockSidO], 'perfo-filter-label', function() { loadPerformanceOutlet(buildPayloadOutlet()); });
        }
    });

});

</script>

<?php include('../footer.php'); ?>