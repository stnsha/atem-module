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
// Build id -> name maps from odb so we can show issuer and department names
// for the FK ids returned by the atem-api.
$staff_names      = array();
$staff_positions  = array();
$staff_has_outlet = array();
$dept_names       = array();

$staff_res = mysqli_query($conn, "SELECT s.id, s.nama_staff, s.outlet, p.position_name
                                   FROM staff s
                                   LEFT JOIN position_rymnet p ON p.id = s.status_rym
                                   WHERE s.recycle != 1");
if ($staff_res) {
    while ($srow = mysqli_fetch_assoc($staff_res)) {
        $staff_names[(int) $srow['id']]      = $srow['nama_staff'];
        $staff_positions[(int) $srow['id']]  = $srow['position_name'];
        // A staff member "is from outlet" based on their own staff.outlet
        // assignment (can be several, e.g. Area Managers), not on whether a
        // single ATEM's ARCI row happens to carry an outlet_id - an Area
        // Manager accountable for many outlets has no one outlet to pin to.
        $staff_has_outlet[(int) $srow['id']] = !empty($srow['outlet']);
    }
}
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

// staff.outlet is comma-separated too (e.g. an Area Manager covering several
// outlets). Used to narrow a grade-2 Outlet-department viewer down to their
// own specific outlet(s), instead of every outlet company-wide. $outlet is
// set by lock_adv.php and is untouched by api.php's own bootstrap below.
$user_outlet_ids = array();
if (isset($outlet) && $outlet !== '') {
    foreach (explode(',', (string)$outlet) as $_opart) {
        $_opart = (int)trim($_opart);
        if ($_opart > 0) { $user_outlet_ids[] = $_opart; }
    }
}

// Grade 1 and 2 users only ever belong to one side (HQ or Outlet, per their
// own department), so showing both tabs is misleading noise - collapse to the
// single matching tab, like the pre-tab single-view page. Grade 3+ and
// SuperAdmin keep seeing both tabs as today (deferred to a future task).
$grade1_single_view = null;
if (((int)$atem_permission === 1 || (int)$atem_permission === 2) && !$_is_superadmin) {
    $grade1_single_view = in_array(1, $user_dept_ids, true) ? 'outlet' : 'hq';
}

// Build grade-scoped issuer list for the filter dropdown.
$issuer_list = array();
if ((int)$atem_permission === 1 && !$_is_superadmin) {
    $me_id = (int)$staff_id;
    $issuer_list[] = array('id' => $me_id, 'name' => isset($staff_names[$me_id]) ? $staff_names[$me_id] : 'You');
} elseif (((int)$atem_permission === 2 || (int)$atem_permission === 3) && !$_is_superadmin) {
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

// Outlet filter (display only for now; not yet wired to ATEM rows) + code
// lookup used to resolve atem_outlets.outlet_id to a display code below.
$outlet_list  = [];
$outlet_names = [];
$outlet_res = mysqli_query($conn, "SELECT id, code FROM outlet ORDER BY code ASC");
if ($outlet_res) {
    while ($orow = mysqli_fetch_assoc($outlet_res)) {
        $outlet_list[] = ['id' => (int) $orow['id'], 'code' => $orow['code']];
        $outlet_names[(int) $orow['id']] = $orow['code'];
    }
}

$lookups = ['levels' => [], 'rules' => [], 'statuses' => [], 'pillars' => []];
$lr = getAtemLookups($staff_id);
if (!empty($lr['success']) && isset($lr['data'])) {
    $lookups = $lr['data'];
}

$include_deleted = ($atem_permission >= 4 || $_is_superadmin) && empty($_GET['no_deleted']);
// Always fetch soft-deleted records; grade-based filtering below decides which ones to surface.
$list_result = getAtemList($staff_id, true);
$rows = (!empty($list_result['success']) && isset($list_result['data'])) ? $list_result['data'] : array();
$api_unavailable = empty($list_result['success']);

$atem_warning = '';
if (isset($_SESSION['atem_warning'])) {
    $atem_warning = $_SESSION['atem_warning'];
    unset($_SESSION['atem_warning']);
}

// Enrich each row with resolved names + flattened display fields.
$view_rows         = array();
$row_arci_dept_ids = array(); // parallel array used only for grade 2 server-side filtering
$row_outlet_ids    = array(); // parallel array used only for grade-2-Outlet server-side filtering
foreach ($rows as $a) {
    $issuer_id = isset($a['issuer_staff_id']) ? (int) $a['issuer_staff_id'] : 0;
    $dept_id   = isset($a['staff_dept_id']) ? (int) $a['staff_dept_id'] : 0;
    $level     = isset($a['level_structure']) && $a['level_structure'] ? $a['level_structure'] : null;
    $pillar    = isset($a['pillar']) && $a['pillar'] ? $a['pillar'] : null;
    $status    = isset($a['status']) && $a['status'] ? $a['status'] : null;

    $outlet_codes = [];
    $outlet_ids   = [];
    if (isset($a['outlets']) && is_array($a['outlets'])) {
        foreach ($a['outlets'] as $o) {
            $o_id = isset($o['outlet_id']) ? (int) $o['outlet_id'] : 0;
            if ($o_id) {
                $outlet_codes[] = isset($outlet_names[$o_id]) ? $outlet_names[$o_id] : ('Outlet #' . $o_id);
                $outlet_ids[]   = $o_id;
            }
        }
    }

    $arci_ids        = array();
    $arci_dept_ids   = array();
    $arci_dept_names = array();
    $user_roles    = array();
    $accountable   = array();
    if (isset($a['arci']) && is_array($a['arci'])) {
        foreach ($a['arci'] as $m) {
            if (!empty($m['staff_id'])) {
                $m_id = (int) $m['staff_id'];
                $arci_ids[] = $m_id;
                if (!empty($m['staff_dept_id'])) {
                    $m_dept_id = (int) $m['staff_dept_id'];
                    $arci_dept_ids[] = $m_dept_id;
                    if (isset($dept_names[$m_dept_id])) {
                        $arci_dept_names[] = $dept_names[$m_dept_id];
                    }
                }
                if ($m_id === (int) $staff_id && !empty($m['role'])) {
                    $user_roles[] = $m['role'];
                }
                if (isset($m['role']) && $m['role'] === 'A') {
                    $a_dept_id = isset($m['staff_dept_id']) ? (int) $m['staff_dept_id'] : 0;
                    $accountable[] = array(
                        'name' => isset($staff_names[$m_id]) ? $staff_names[$m_id] : ('Staff #' . $m_id),
                        // Staff member is from outlet (their own staff.outlet is set,
                        // e.g. Area Managers covering several outlets) -> show position.
                        // Otherwise they're HQ/department staff -> show department.
                        'dept' => !empty($staff_has_outlet[$m_id])
                            ? (!empty($staff_positions[$m_id]) ? $staff_positions[$m_id] : '-')
                            : (($a_dept_id && isset($dept_names[$a_dept_id])) ? $dept_names[$a_dept_id] : '-'),
                    );
                }
            }
        }
    }

    $view_rows[] = array(
        'id'              => (int) (isset($a['id']) ? $a['id'] : 0),
        'title'           => isset($a['title']) ? $a['title'] : '',
        'atem_type'       => isset($a['atem_type']) ? (int) $a['atem_type'] : 1,
        'outlet_codes'    => $outlet_codes,
        'issuer_name'     => isset($staff_names[$issuer_id]) ? $staff_names[$issuer_id] : ($issuer_id ? ('Staff #' . $issuer_id) : '-'),
        'department_name' => isset($dept_names[$dept_id]) ? $dept_names[$dept_id] : '-',
        'department_id'   => $dept_id,
        'level_label'     => ($level && isset($level['level']))       ? $level['level']       : '',
        'system_name'     => ($level && isset($level['system_name'])) ? $level['system_name'] : '',
        'pillar_name'     => ($pillar && isset($pillar['name']))      ? $pillar['name']       : '',
        'status'          => ($status && isset($status['value']))     ? $status['value']      : '',
        'start_date'      => isset($a['start_date']) ? $a['start_date'] : '',
        'end_date'        => isset($a['end_date']) ? $a['end_date'] : '',
        'extended_date_1' => isset($a['extended_date_1']) ? $a['extended_date_1'] : '',
        'issuer_staff_id' => $issuer_id,
        'arci_staff_ids'  => $arci_ids,
        'arci_dept_names' => array_values(array_unique($arci_dept_names)),
        'user_arci_roles' => $user_roles,
        'accountable'     => $accountable,
        'is_extended'     => !empty($a['is_extended']),
        // Suspended is keyed off status alone (not just deleted_at) so a suspended
        // card is always locked down even if its deleted_at drifted from its status.
        'is_deleted'      => (!empty($a['deleted_at']) || ($status && $status['value'] === 'Suspended')),
        'deleted_at'      => isset($a['deleted_at']) ? $a['deleted_at'] : null,
        'payout_status'   => isset($a['payout_status']) ? $a['payout_status'] : null,
    );
    $row_arci_dept_ids[] = $arci_dept_ids;
    $row_outlet_ids[]    = $outlet_ids;
}

// Apply server-side visibility filtering based on grade.
if ((int)$atem_permission === 1 && !$_is_superadmin) {
    // Grade 1: own cards only (issuer or any ARCI role).
    // Also show own suspended cards (soft-deleted with status Suspended).
    $filtered = array();
    foreach ($view_rows as $r) {
        if ($r['is_deleted']) {
            if ($r['status'] === 'Suspended' && $r['issuer_staff_id'] === (int)$staff_id) {
                $filtered[] = $r;
            }
            continue;
        }
        if ($r['issuer_staff_id'] === (int)$staff_id
                || in_array((int)$staff_id, $r['arci_staff_ids'])) {
            $filtered[] = $r;
        }
    }
    $view_rows = $filtered;
} elseif ((int)$atem_permission === 2 && !$_is_superadmin && in_array(1, $user_dept_ids, true)) {
    // Grade 2, Outlet department: narrowed to the viewer's own specific
    // outlet(s) (staff.outlet overlap with the card's own linked outlets) -
    // department=1 alone is shared by every outlet company-wide, so the
    // regular dept-overlap rule below would show every outlet's cards.
    $filtered = array();
    foreach ($view_rows as $idx => $r) {
        if ($r['is_deleted']) {
            if ($r['status'] === 'Suspended' && $r['issuer_staff_id'] === (int)$staff_id) {
                $filtered[] = $r;
            }
            continue;
        }
        if (array_intersect($user_outlet_ids, $row_outlet_ids[$idx])) {
            $filtered[] = $r;
        }
    }
    $view_rows = $filtered;
} elseif (((int)$atem_permission === 2 || (int)$atem_permission === 3) && !$_is_superadmin) {
    // Grades 2 and 3: cards where issuer or any ARCI member belongs to ANY of the
    // user's departments. Also show own suspended cards regardless of department.
    $filtered = array();
    foreach ($view_rows as $idx => $r) {
        if ($r['is_deleted']) {
            if ($r['status'] === 'Suspended' && $r['issuer_staff_id'] === (int)$staff_id) {
                $filtered[] = $r;
            }
            continue;
        }
        if (in_array($r['department_id'], $user_dept_ids)
                || array_intersect($user_dept_ids, $row_arci_dept_ids[$idx])) {
            $filtered[] = $r;
        }
    }
    $view_rows = $filtered;
} elseif (!$include_deleted) {
    // Grade 4+/SA with ?no_deleted: strip all soft-deleted rows.
    $filtered = array();
    foreach ($view_rows as $r) {
        if (!$r['is_deleted']) { $filtered[] = $r; }
    }
    $view_rows = $filtered;
}
// Grade 4+/SA (include_deleted=true): no filtering — all rows shown.

$dept_list = array();
if ((int)$atem_permission <= 3 && !$_is_superadmin) {
    foreach ($user_dept_ids as $_uid) {
        if (isset($dept_names[$_uid])) {
            $dept_list[] = $dept_names[$_uid];
        }
    }
    sort($dept_list);
} else {
    foreach ($dept_names as $dept_name_val) {
        $dept_list[] = $dept_name_val;
    }
    sort($dept_list);
}

$_view_cur_year = max(2026, (int)date('Y'));
$view_year_opts = array();
for ($y = 2026; $y <= $_view_cur_year; $y++) {
    $view_year_opts[] = $y;
}

$view_config = array(
    'rows'        => $view_rows,
    'levels'      => isset($lookups['levels']) ? $lookups['levels'] : array(),
    'statuses'    => isset($lookups['statuses']) ? $lookups['statuses'] : array(),
    'pillars'     => isset($lookups['pillars']) ? $lookups['pillars'] : array(),
    'departments' => $dept_list,
    'issuers'     => $issuer_list,
    'outlets'     => $outlet_list,
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

                <!-- Row 1: Year | Month | Start Date | End Date | Status -->
                <div class="row row-cols-md-5 row-cols-2 g-2 mt-1">
                    <div class="col">
                        <label class="form-label">Year</label>
                        <select class="form-select form-select-sm" id="vf-year">
                            <option value="">All Year</option>
                            <?php foreach ($view_year_opts as $y): ?>
                            <option value="<?php echo $y; ?>"><?php echo $y; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col">
                        <label class="form-label">Month</label>
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
                    <div class="col">
                        <label class="form-label">Start Date</label>
                        <input type="date" class="form-control form-control-sm" id="vf-from">
                    </div>
                    <div class="col">
                        <label class="form-label">End Date</label>
                        <input type="date" class="form-control form-control-sm" id="vf-to">
                    </div>
                    <div class="col">
                        <label class="form-label">Status</label>
                        <div class="vf-issuer-wrap" id="vf-status-wrap">
                            <div class="vf-s2-selection" id="vf-status-btn" tabindex="0">All statuses</div>
                            <div class="vf-s2-dropdown" id="vf-status-dropdown">
                                <ul class="vf-s2-list" id="vf-status-list" style="padding:4px 0;"></ul>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Row 2: Issuer | Department | Level | Role | Search -->
                <div class="row row-cols-md-5 row-cols-2 g-2 mt-0">
                    <div class="col">
                        <label class="form-label">Issuer</label>
                        <div class="vf-issuer-wrap" id="vf-issuer-wrap">
                            <div class="vf-s2-selection" id="vf-issuer-btn" tabindex="0">All issuers</div>
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
                        <label class="form-label">Department</label>
                        <select class="form-select form-select-sm" id="vf-dept">
                            <option value="">All departments</option>
                        </select>
                    </div>
                    <div class="col">
                        <label class="form-label">Level</label>
                        <select class="form-select form-select-sm" id="vf-level">
                            <option value="">All levels</option>
                        </select>
                    </div>
                    <div class="col">
                        <label class="form-label">Your Role with ARCI</label>
                        <select class="form-select form-select-sm" id="vf-role">
                            <option value="">All roles</option>
                        </select>
                    </div>
                    <div class="col">
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
                            <th class="atem-sortable" data-col="issuer_name">Issuer</th>
                            <th>Accountable</th>
                            <th>ARCI</th>
                            <th class="atem-sortable" data-col="level_label">Level Structure</th>
                            <th class="atem-sortable" data-col="start_date">Start</th>
                            <th class="atem-sortable" data-col="end_date">End</th>
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

                <!-- Row 1: Year | Month | Start Date | End Date | Status -->
                <div class="row row-cols-md-5 row-cols-2 g-2 mt-1">
                    <div class="col">
                        <label class="form-label">Year</label>
                        <select class="form-select form-select-sm" id="vfo-year">
                            <option value="">All Year</option>
                            <?php foreach ($view_year_opts as $y): ?>
                            <option value="<?php echo $y; ?>"><?php echo $y; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col">
                        <label class="form-label">Month</label>
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
                    <div class="col">
                        <label class="form-label">Start Date</label>
                        <input type="date" class="form-control form-control-sm" id="vfo-from">
                    </div>
                    <div class="col">
                        <label class="form-label">End Date</label>
                        <input type="date" class="form-control form-control-sm" id="vfo-to">
                    </div>
                    <div class="col">
                        <label class="form-label">Status</label>
                        <div class="vf-issuer-wrap" id="vfo-status-wrap">
                            <div class="vf-s2-selection" id="vfo-status-btn" tabindex="0">All statuses</div>
                            <div class="vf-s2-dropdown" id="vfo-status-dropdown">
                                <ul class="vf-s2-list" id="vfo-status-list" style="padding:4px 0;"></ul>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Row 2: Issuer | Outlet | Pillar | Role | Search -->
                <div class="row row-cols-md-5 row-cols-2 g-2 mt-0">
                    <div class="col">
                        <label class="form-label">Issuer</label>
                        <div class="vf-issuer-wrap" id="vfo-issuer-wrap">
                            <div class="vf-s2-selection" id="vfo-issuer-btn" tabindex="0">All issuers</div>
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
                        <label class="form-label">Pillar</label>
                        <select class="form-select form-select-sm" id="vfo-pillar">
                            <option value="">All pillars</option>
                        </select>
                    </div>
                    <div class="col">
                        <label class="form-label">Your Role with ARCI</label>
                        <select class="form-select form-select-sm" id="vfo-role">
                            <option value="">All roles</option>
                        </select>
                    </div>
                    <div class="col">
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
                            <th class="atem-sortable" data-col="issuer_name">Issuer</th>
                            <th>Accountable</th>
                            <th class="atem-sortable" data-col="pillar_name">Pillars</th>
                            <th class="atem-sortable" data-col="start_date">Start Date</th>
                            <th class="atem-sortable" data-col="end_date">End Date</th>
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