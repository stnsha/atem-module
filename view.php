<?php
$page_title = 'ATEM';
// header.php (and its ATEM_BASE constant) hasn't been included yet at this point,
// since header.php itself echoes $page_title_actions during its own run — so the
// module base is computed locally here the same way ATEM_BASE will be.
$_view_atem_base = '/odb/' . basename(__DIR__) . '/';
$page_title_actions = '<a class="btn btn-primary atem-btn-new d-inline-flex align-items-center" href="' . $_view_atem_base . 'create.php"><i class="bi bi-plus-lg me-1"></i>Create New ATEM</a>';
$extra_css = '<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet">';
include('header.php');

// header.php bootstrapped the odb connection ($conn) and current staff.
// Department id -> name map for the Department filter dropdown. Card rows
// themselves are built (names resolved by id) by api.php's view-list action.
$dept_names = array();
$dept_res = mysqli_query($conn, "SELECT id, depart_name FROM staff_department");
if ($dept_res) {
    while ($drow = mysqli_fetch_assoc($dept_res)) {
        $dept_names[(int) $drow['id']] = $drow['depart_name'];
    }
}

// Fetch the ATEM list + lookups via the JWT proxy (server-side).
// api.php also sets $staff_id and $department from the session.
define('API_JWT_INCLUDED', true);
include(dirname(__FILE__) . '/api.php');

// staff.department is comma-separated (e.g. "3,7"); a user can belong to several
// departments. Parse all of them so dept-scoped grades match any overlap.
$user_dept_ids = array();
if (isset($department) && $department !== '') {
    foreach (explode(',', (string)$department) as $_dpart) {
        $_dpart = (int)trim($_dpart);
        if ($_dpart > 0) { $user_dept_ids[] = $_dpart; }
    }
}

// Grade 1, 2, 3, and 6 (a real, non-SA staff.grade value - not just the dev
// toolbar's simulated one) only ever belong to one side (HQ or Outlet, per
// their own department) - collapse to the single matching tab, hiding the
// other side entirely. Grade 4+ and SuperAdmin keep seeing both tabs.
$grade1_single_view = null;
if (in_array((int)$atem_permission, array(1, 2, 3, 6), true) && !$_is_superadmin) {
    $grade1_single_view = in_array(1, $user_dept_ids, true) ? 'outlet' : 'hq';
}

// Build grade-scoped issuer list for the filter dropdown.
$issuer_list = array();
if (((int)$atem_permission === 1 || (int)$atem_permission === 2 || (int)$atem_permission === 6) && !$_is_superadmin) {
    // Grade 1, Grade 2, and dev-simulated Grade 6: own cards only, so the
    // issuer filter is limited to the viewer themself.
    $me_id = (int)$staff_id;
    $issuer_list[] = array('id' => $me_id, 'name' => (isset($nama_staff) && $nama_staff !== '') ? $nama_staff : 'You');
} elseif ((int)$atem_permission === 3 && !$_is_superadmin) {
    if (!empty($user_dept_ids)) {
        $dept_parts = array();
        foreach ($user_dept_ids as $_did) {
            $s = mysqli_real_escape_string($conn, (string)(int)$_did);
            $dept_parts[] = "(department = '$s' OR department LIKE '$s,%' OR department LIKE '%,$s' OR department LIKE '%,$s,%')";
        }
        $i_sql = "SELECT id, nama_staff FROM staff WHERE recycle != 1 AND (" . implode(' OR ', $dept_parts) . ") ORDER BY nama_staff ASC";
        $i_res = mysqli_query($conn, $i_sql);
        if ($i_res) {
            while ($ir = mysqli_fetch_assoc($i_res)) {
                $issuer_list[] = array('id' => (int)$ir['id'], 'name' => $ir['nama_staff']);
            }
        }
    }
} else {
    $i_res = mysqli_query($conn, "SELECT id, nama_staff FROM staff WHERE recycle != 1 ORDER BY nama_staff ASC");
    if ($i_res) {
        while ($ir = mysqli_fetch_assoc($i_res)) {
            $issuer_list[] = array('id' => (int)$ir['id'], 'name' => $ir['nama_staff']);
        }
    }
}

// staff.outlet is comma-separated (e.g. an Area Manager covering several
// outlets). Used only to narrow the Outlet/Region FILTER DROPDOWN options
// below for grade 1, 2, and 6 (a real, non-SA staff.grade value, not just the
// dev toolbar's simulated one) - the same "own only" population whose cards
// can only ever reference their own outlet(s) anyway. $outlet_names/
// $outlet_region_ids stay unrestricted since they're used to resolve display
// codes/regions for whatever rows the viewer does see.
$user_outlet_ids = [];
if (isset($outlet) && $outlet !== '') {
    foreach (explode(',', (string)$outlet) as $_opart) {
        $_opart = (int)trim($_opart);
        if ($_opart > 0) { $user_outlet_ids[] = $_opart; }
    }
}
$_view_outlet_scoped = (in_array((int)$atem_permission, [1, 2, 6], true) && !$_is_superadmin);

// Grade 6 (Flexi KPI) is only granted the outlet-wide carve-out below (see
// the row-filtering block further down) when they hold the Area Manager
// position (status_rym = 134, same id used by the Outlet ATEM Area Manager
// picker) - any other grade-6 position stays own-only. Mirrors api.php's
// dashboard-stats/list-atems-scoped identical check.
$_is_area_manager = false;
if ((int)$atem_permission === 6 && !$_is_superadmin && (int)$staff_id > 0) {
    $_am_res = mysqli_query($conn, "SELECT status_rym FROM staff WHERE id = " . (int)$staff_id . " AND recycle != 1");
    if ($_am_res && ($_am_row = mysqli_fetch_assoc($_am_res))) {
        $_is_area_manager = ((int)$_am_row['status_rym'] === 134);
    }
}

// Outlet filter + code lookup used to resolve atem_outlets.outlet_id to a
// display code below. regional_id also lets each row resolve its region(s)
// (odb.outlet.regional_id -> odb.outlet_regional.id) for the Region filter.
$outlet_list       = [];
$outlet_names      = [];
$outlet_region_ids = [];
$outlet_list_region_ids = [];
$outlet_res = mysqli_query($conn, "SELECT id, code, regional_id FROM outlet ORDER BY code ASC");
if ($outlet_res) {
    while ($orow = mysqli_fetch_assoc($outlet_res)) {
        $o_id = (int) $orow['id'];
        $outlet_names[$o_id]      = $orow['code'];
        $outlet_region_ids[$o_id] = (int) $orow['regional_id'];
        if ($_view_outlet_scoped && !in_array($o_id, $user_outlet_ids, true)) { continue; }
        $outlet_list[] = ['id' => $o_id, 'code' => $orow['code']];
        $outlet_list_region_ids[(int) $orow['regional_id']] = true;
    }
}

$region_list  = [];
$region_names = [];
$region_res = mysqli_query($conn, "SELECT id, regional FROM outlet_regional ORDER BY regional ASC");
if ($region_res) {
    while ($rrow = mysqli_fetch_assoc($region_res)) {
        $r_id = (int) $rrow['id'];
        $region_names[$r_id] = $rrow['regional'];
        if ($_view_outlet_scoped && !isset($outlet_list_region_ids[$r_id])) { continue; }
        $region_list[] = ['id' => $r_id, 'name' => $rrow['regional']];
    }
}


$lookups = ['levels' => [], 'rules' => [], 'statuses' => []];
$lr = getAtemLookups($staff_id);
if (!empty($lr['success']) && isset($lr['data'])) {
    $lookups = $lr['data'];
}

$include_deleted = ($atem_permission >= 4 || $_is_superadmin) && empty($_GET['no_deleted']);

// Rows are no longer embedded in the page: js/view.js loads one page at a
// time from api.php's view-list action, which atem-api filters, sorts and
// paginates (POST /api/atem/search) under the exact grade visibility rule
// built by atemViewViewerParams(). Here we only need the per-tab visible
// counts - for the tab badges and to decide whether the Outlet tab shows.
$_view_viewer = atemViewViewerParams(
    (int)$atem_permission,
    (bool)$_is_superadmin,
    $_is_area_manager,
    (int)$staff_id,
    $user_dept_ids,
    $user_outlet_ids,
    !$include_deleted
);
$_view_counts_result = atemSearch(['viewer' => $_view_viewer, 'counts_only' => true], $staff_id);
$api_unavailable = empty($_view_counts_result['success']);
$view_counts = [
    'hq'     => (int)($_view_counts_result['counts']['hq'] ?? 0),
    'outlet' => (int)($_view_counts_result['counts']['outlet'] ?? 0),
];

$atem_warning = '';
if (isset($_SESSION['atem_warning'])) {
    $atem_warning = $_SESSION['atem_warning'];
    unset($_SESSION['atem_warning']);
}

// A non-Outlet-dept grade 1/2/3 viewer can still have own Outlet-type cards
// (issuer, ARCI, or tagged Area Manager) even though their own department
// collapsed them onto the HQ-only tab. Un-collapse so that tab (and only
// their own outlet rows within it) becomes visible.
if ($grade1_single_view === 'hq' && $view_counts['outlet'] > 0) {
    $grade1_single_view = null;
}

// Department filter options as {id, name} for the searchable dropdown in
// js/view.js. The filter itself still matches by NAME (api.php's view-list
// maps the name to every department id carrying it), so a name shared by
// several department ids is listed once, under its lowest id.
$_dept_source = array();
if ((int)$atem_permission <= 3 && !$_is_superadmin) {
    foreach ($user_dept_ids as $_uid) {
        if (isset($dept_names[$_uid])) {
            $_dept_source[$_uid] = $dept_names[$_uid];
        }
    }
} else {
    $_dept_source = $dept_names;
}
ksort($_dept_source);
$_dept_by_name = array();
foreach ($_dept_source as $_did => $_dname) {
    if (!isset($_dept_by_name[$_dname])) {
        $_dept_by_name[$_dname] = array('id' => (int)$_did, 'name' => $_dname);
    }
}
ksort($_dept_by_name, SORT_STRING | SORT_FLAG_CASE);
$dept_list = array_values($_dept_by_name);

$_view_cur_year = max(2026, (int)date('Y'));
$view_year_opts = array();
for ($y = 2025; $y <= $_view_cur_year; $y++) {
    $view_year_opts[] = $y;
}

$view_config = array(
    'counts'      => $view_counts,
    'noDeleted'   => !$include_deleted,
    'levels'      => isset($lookups['levels']) ? $lookups['levels'] : array(),
    'statuses'    => isset($lookups['statuses']) ? $lookups['statuses'] : array(),
    'departments' => $dept_list,
    'issuers'     => $issuer_list,
    'outlets'     => $outlet_list,
    'regions'     => $region_list,
    'staffId'     => (int) $staff_id,
    'isSuperAdmin' => $_is_superadmin,
    'userGrade'   => (int)$atem_permission,
    'tabSingleView' => $grade1_single_view,
);

?>

<?php if ($api_unavailable): ?>
<div class="alert alert-warning" role="alert" style="font-size:13px;">
    The ATEM service is not reachable, so the list could not be loaded. Please ensure the atem-api service is running,
    then reload this page.
</div>
<?php endif; ?>

<?php if ($atem_warning): ?>
<div class="alert alert-warning alert-dismissible fade show" role="alert" style="font-size:13px;">
    <?php echo htmlspecialchars($atem_warning); ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
</div>
<?php endif; ?>

<!-- Table -->
<div class="atem-card">
    <?php if ($grade1_single_view === null): ?>
    <ul class="nav nav-tabs atem-view-tabs" id="atem-view-tabs" role="tablist">
        <li class="nav-item" role="presentation">
            <button class="nav-link active atem-tab-color-hq" id="atem-tab-hq-btn" data-bs-toggle="tab"
                data-bs-target="#atem-tab-hq" type="button" role="tab" aria-controls="atem-tab-hq" aria-selected="true">
                <i class="bi bi-building"></i> HQ ATEM <span class="atem-tab-count" id="atem-tab-hq-count">0</span>
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link atem-tab-color-outlet" id="atem-tab-outlet-btn" data-bs-toggle="tab"
                data-bs-target="#atem-tab-outlet" type="button" role="tab" aria-controls="atem-tab-outlet"
                aria-selected="false">
                <i class="bi bi-shop"></i> Outlet ATEM <span class="atem-tab-count" id="atem-tab-outlet-count">0</span>
            </button>
        </li>
    </ul>
    <?php endif; ?>
    <div class="tab-content pt-3">
        <div class="tab-pane fade<?php echo ($grade1_single_view === null || $grade1_single_view === 'hq') ? ' show active' : ''; ?>"
            id="atem-tab-hq" role="tabpanel" aria-labelledby="atem-tab-hq-btn">

            <!-- HQ Filter bar -->
            <div class="atem-card atem-filter mb-3">
                <h6 class="atem-card-title"><i class="bi bi-funnel"></i> Filter</h6>

                <!-- Row 1: Year/Month | Start Date | End Date | Closure Date -->
                <div class="row row-cols-md-4 row-cols-2 g-2 mt-1">
                    <div class="col">
                        <label class="form-label">Year / Month</label>
                        <div class="d-flex gap-1">
                            <select class="form-select form-select-sm" id="vf-year">
                                <option value="">All Year</option>
                                <?php foreach ($view_year_opts as $y): ?>
                                <option value="<?php echo $y; ?>"><?php echo $y; ?></option>
                                <?php endforeach; ?>
                            </select>
                            <select class="form-select form-select-sm" id="vf-month">
                                <option value="0">All Month</option>
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
                    </div>
                    <div class="col">
                        <label class="form-label">Start Date</label>
                        <input type="date" class="form-control form-control-sm" id="vf-start-date" title="Start Date">
                    </div>
                    <div class="col">
                        <label class="form-label">End Date</label>
                        <input type="date" class="form-control form-control-sm" id="vf-end-date" title="End Date">
                    </div>
                    <div class="col">
                        <label class="form-label">Closure Date</label>
                        <div class="d-flex gap-1">
                            <input type="date" class="form-control form-control-sm" id="vf-closure-from" title="Closure Date From">
                            <input type="date" class="form-control form-control-sm" id="vf-closure-to" title="Closure Date To">
                        </div>
                    </div>
                </div>

                <!-- Row 2: Level | Department | Staff | Your Role with ARCI/Issuer | Status -->
                <div class="row row-cols-md-5 row-cols-2 g-2 mt-0">
                    <div class="col">
                        <label class="form-label">Level</label>
                        <select class="form-select form-select-sm" id="vf-level">
                            <option value="">All levels</option>
                        </select>
                    </div>
                    <div class="col">
                        <label class="form-label">Department</label>
                        <div class="vf-issuer-wrap" id="vf-dept-wrap">
                            <div class="vf-s2-selection" id="vf-dept-btn" tabindex="0">All departments</div>
                            <div class="vf-s2-dropdown" id="vf-dept-dropdown">
                                <div class="vf-s2-search-wrap">
                                    <input class="vf-s2-search" id="vf-dept-search" type="search"
                                        placeholder="Search department...">
                                </div>
                                <ul class="vf-s2-list" id="vf-dept-list"></ul>
                            </div>
                            <input type="hidden" id="vf-dept-value" value="0">
                        </div>
                    </div>
                    <div class="col">
                        <label class="form-label">Staff</label>
                        <div class="vf-issuer-wrap" id="vf-issuer-wrap">
                            <div class="vf-s2-selection" id="vf-issuer-btn" tabindex="0">All staff</div>
                            <div class="vf-s2-dropdown" id="vf-issuer-dropdown">
                                <div class="vf-s2-search-wrap">
                                    <input class="vf-s2-search" id="vf-issuer-search" type="search"
                                        placeholder="Search name...">
                                </div>
                                <ul class="vf-s2-list" id="vf-issuer-list"></ul>
                            </div>
                            <input type="hidden" id="vf-issuer-value" value="0">
                        </div>
                    </div>
                    <div class="col">
                        <label class="form-label">Your Role with ARCI/Issuer</label>
                        <div class="vf-issuer-wrap" id="vf-role-wrap">
                            <div class="vf-s2-selection" id="vf-role-btn" tabindex="0">All roles</div>
                            <div class="vf-s2-dropdown" id="vf-role-dropdown">
                                <ul class="vf-s2-list" id="vf-role-list" style="padding:4px 0;"></ul>
                            </div>
                        </div>
                    </div>
                    <div class="col">
                        <label class="form-label">Status</label>
                        <div class="vf-issuer-wrap" id="vf-status-wrap">
                            <div class="vf-s2-selection" id="vf-status-btn" tabindex="0">All statuses</div>
                            <div class="vf-s2-dropdown" id="vf-status-dropdown">
                                <ul class="vf-s2-list" id="vf-status-list" style="padding:4px 0;"></ul>
                            </div>
                        </div>
                        <div class="form-check mt-2">
                            <input class="form-check-input" type="checkbox" id="vf-overdue">
                            <label class="form-check-label" for="vf-overdue">Overdue</label>
                        </div>
                    </div>
                </div>

                <!-- Row 3: Search (full width) -->
                <div class="row g-2 mt-0">
                    <div class="col-12">
                        <label class="form-label">Search title or ID</label>
                        <input type="text" class="form-control form-control-sm" id="vf-search"
                            placeholder="Type title or ATEM ID...">
                    </div>
                </div>

                <div class="d-flex justify-content-end mt-2">
                    <button type="button" class="btn btn-outline-secondary btn-sm" id="vf-reset">Reset Filters</button>
                </div>
            </div>

            <div class="table-responsive">
                <table class="table table-hover align-middle atem-view-tbl" id="atem-view-tbl-hq">
                    <thead>
                        <tr>
                            <th class="atem-sortable" data-col="id">ATEM ID</th>
                            <th class="atem-sortable" data-col="title">Title</th>
                            <th class="atem-sortable" data-col="issuer_name">Issuer / Accountable</th>
                            <th>ARCI</th>
                            <th class="atem-sortable" data-col="level_label">Level</th>
                            <th class="atem-sortable" data-col="start_date">Start</th>
                            <th class="atem-sortable" data-col="end_date">End</th>
                            <th class="atem-sortable" data-col="closure_date">Closure Date</th>
                            <th class="atem-sortable" data-col="status">Status</th>
                            <th style="width:110px;">Action</th>
                        </tr>
                    </thead>
                    <tbody id="atem-view-body-hq"></tbody>
                </table>
            </div>
            <div class="atem-pager" id="atem-pager-hq"></div>
        </div>
        <div class="tab-pane fade<?php echo ($grade1_single_view === 'outlet') ? ' show active' : ''; ?>"
            id="atem-tab-outlet" role="tabpanel" aria-labelledby="atem-tab-outlet-btn">

            <!-- Outlet Filter bar -->
            <div class="atem-card atem-filter mb-3">
                <h6 class="atem-card-title"><i class="bi bi-funnel"></i> Filter</h6>

                <!-- Row 1: Year/Month | Start Date | End Date | Closure Date -->
                <div class="row row-cols-md-4 row-cols-2 g-2 mt-1">
                    <div class="col">
                        <label class="form-label">Year / Month</label>
                        <div class="d-flex gap-1">
                            <select class="form-select form-select-sm" id="vfo-year">
                                <option value="">All Year</option>
                                <?php foreach ($view_year_opts as $y): ?>
                                <option value="<?php echo $y; ?>"><?php echo $y; ?></option>
                                <?php endforeach; ?>
                            </select>
                            <select class="form-select form-select-sm" id="vfo-month">
                                <option value="0">All Month</option>
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
                    </div>
                    <div class="col">
                        <label class="form-label">Start Date</label>
                        <input type="date" class="form-control form-control-sm" id="vfo-start-date" title="Start Date">
                    </div>
                    <div class="col">
                        <label class="form-label">End Date</label>
                        <input type="date" class="form-control form-control-sm" id="vfo-end-date" title="End Date">
                    </div>
                    <div class="col">
                        <label class="form-label">Closure Date</label>
                        <div class="d-flex gap-1">
                            <input type="date" class="form-control form-control-sm" id="vfo-closure-from" title="Closure Date From">
                            <input type="date" class="form-control form-control-sm" id="vfo-closure-to" title="Closure Date To">
                        </div>
                    </div>
                </div>

                <!-- Row 2: Staff | Outlet | Region | Role | Status -->
                <div class="row row-cols-md-5 row-cols-2 g-2 mt-0">
                    <div class="col">
                        <label class="form-label">Staff</label>
                        <div class="vf-issuer-wrap" id="vfo-issuer-wrap">
                            <div class="vf-s2-selection" id="vfo-issuer-btn" tabindex="0">All staff</div>
                            <div class="vf-s2-dropdown" id="vfo-issuer-dropdown">
                                <div class="vf-s2-search-wrap">
                                    <input class="vf-s2-search" id="vfo-issuer-search" type="search"
                                        placeholder="Search name...">
                                </div>
                                <ul class="vf-s2-list" id="vfo-issuer-list"></ul>
                            </div>
                            <input type="hidden" id="vfo-issuer-value" value="0">
                        </div>
                    </div>
                    <div class="col">
                        <label class="form-label">Outlet</label>
                        <div class="vf-issuer-wrap" id="vfo-outlet-wrap">
                            <div class="vf-s2-selection" id="vfo-outlet-btn" tabindex="0">All outlets</div>
                            <div class="vf-s2-dropdown" id="vfo-outlet-dropdown">
                                <div class="vf-s2-search-wrap">
                                    <input class="vf-s2-search" id="vfo-outlet-search" type="search"
                                        placeholder="Search outlet...">
                                </div>
                                <ul class="vf-s2-list" id="vfo-outlet-list"></ul>
                            </div>
                            <input type="hidden" id="vfo-outlet-value" value="0">
                        </div>
                    </div>
                    <div class="col">
                        <label class="form-label">Region</label>
                        <select class="form-select form-select-sm" id="vfo-region">
                            <option value="">All regions</option>
                        </select>
                    </div>
                    <div class="col">
                        <label class="form-label">Your Role with ARCI/Issuer</label>
                        <div class="vf-issuer-wrap" id="vfo-role-wrap">
                            <div class="vf-s2-selection" id="vfo-role-btn" tabindex="0">All roles</div>
                            <div class="vf-s2-dropdown" id="vfo-role-dropdown">
                                <ul class="vf-s2-list" id="vfo-role-list" style="padding:4px 0;"></ul>
                            </div>
                        </div>
                    </div>
                    <div class="col">
                        <label class="form-label">Status</label>
                        <div class="vf-issuer-wrap" id="vfo-status-wrap">
                            <div class="vf-s2-selection" id="vfo-status-btn" tabindex="0">All statuses</div>
                            <div class="vf-s2-dropdown" id="vfo-status-dropdown">
                                <ul class="vf-s2-list" id="vfo-status-list" style="padding:4px 0;"></ul>
                            </div>
                        </div>
                        <div class="form-check mt-2">
                            <input class="form-check-input" type="checkbox" id="vfo-overdue">
                            <label class="form-check-label" for="vfo-overdue">Overdue</label>
                        </div>
                    </div>
                </div>

                <!-- Row 3: Search (full width) -->
                <div class="row g-2 mt-0">
                    <div class="col-12">
                        <label class="form-label">Search title or ID</label>
                        <input type="text" class="form-control form-control-sm" id="vfo-search"
                            placeholder="Type title or ATEM ID...">
                    </div>
                </div>

                <div class="d-flex justify-content-end mt-2">
                    <button type="button" class="btn btn-outline-secondary btn-sm" id="vfo-reset">Reset Filters</button>
                </div>
            </div>

            <div class="table-responsive">
                <table class="table table-hover align-middle atem-view-tbl" id="atem-view-tbl-outlet">
                    <thead>
                        <tr>
                            <th class="atem-sortable" data-col="id">ATEM ID</th>
                            <th class="atem-sortable" data-col="title">Title</th>
                            <th class="atem-sortable" data-col="issuer_name">Issuer / Accountable</th>
                            <th>ARCI</th>
                            <th class="atem-sortable" data-col="pillar_name">Pillars</th>
                            <th class="atem-sortable" data-col="start_date">Start</th>
                            <th class="atem-sortable" data-col="end_date">End</th>
                            <th class="atem-sortable" data-col="closure_date">Closure Date</th>
                            <th class="atem-sortable" data-col="status">Status</th>
                            <th style="width:110px;">Action</th>
                        </tr>
                    </thead>
                    <tbody id="atem-view-body-outlet"></tbody>
                </table>
            </div>
            <div class="atem-pager" id="atem-pager-outlet"></div>
        </div>
    </div>
</div>

<!-- Delete confirmation modal -->
<div class="modal fade" id="atem-delete-modal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header border-0 pb-0">
                <h5 class="modal-title text-danger"><i class="bi bi-exclamation-triangle-fill me-2"></i>Delete ATEM Card
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body pt-2">
                <p id="atem-delete-modal-msg" style="font-size:13px;"></p>
                <label class="form-label fw-semibold" style="font-size:13px;">Remark <span
                        class="text-danger">*</span></label>
                <textarea id="atem-delete-remark" class="form-control form-control-sm" rows="3"
                    placeholder="State the reason for deletion..."></textarea>
                <div id="atem-delete-remark-err" class="text-danger"
                    style="font-size:12px;min-height:16px;margin-top:4px;"></div>
            </div>
            <div class="modal-footer pt-0">
                <button type="button" id="atem-delete-cancel" class="btn btn-secondary btn-sm"
                    data-bs-dismiss="modal">Cancel</button>
                <button type="button" id="atem-delete-confirm" class="btn btn-danger btn-sm">Confirm Delete</button>
            </div>
        </div>
    </div>
</div>

<script>
window.ATEM_VIEW = <?php echo json_encode($view_config); ?>;
</script>
<?php
$page_js = ATEM_BASE . 'js/view.js';
include('footer.php');
?>