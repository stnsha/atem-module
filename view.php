<?php
$page_title = 'ATEM';
$page_title_actions = '<a class="btn btn-primary atem-btn-new d-inline-flex align-items-center" href="atem/create.php"><i class="bi bi-plus-lg me-1"></i>Create New ATEM</a>';
$extra_css = '<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet">';
include('header.php');

// header.php bootstrapped the odb connection ($conn) and current staff.
// Build id -> name maps from odb so we can show issuer and department names
// for the FK ids returned by the atem-api.
$staff_names = array();
$dept_names  = array();

$staff_res = mysqli_query($conn, "SELECT id, nama_staff FROM staff WHERE recycle != 1");
if ($staff_res) {
    while ($srow = mysqli_fetch_assoc($staff_res)) {
        $staff_names[(int) $srow['id']] = $srow['nama_staff'];
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

$lookups = array('levels' => array(), 'rules' => array(), 'statuses' => array());
$lr = getAtemLookups($staff_id);
if (!empty($lr['success']) && isset($lr['data'])) {
    $lookups = $lr['data'];
}

$include_deleted = ($atem_permission >= 4 || $_is_superadmin);
$list_result = getAtemList($staff_id, $include_deleted);
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
foreach ($rows as $a) {
    $issuer_id = isset($a['issuer_staff_id']) ? (int) $a['issuer_staff_id'] : 0;
    $dept_id   = isset($a['staff_dept_id']) ? (int) $a['staff_dept_id'] : 0;
    $level     = isset($a['level_structure']) && $a['level_structure'] ? $a['level_structure'] : null;
    $status    = isset($a['status']) && $a['status'] ? $a['status'] : null;

    $arci_ids      = array();
    $arci_dept_ids = array();
    $user_roles    = array();
    $accountable   = array();
    if (isset($a['arci']) && is_array($a['arci'])) {
        foreach ($a['arci'] as $m) {
            if (!empty($m['staff_id'])) {
                $m_id = (int) $m['staff_id'];
                $arci_ids[] = $m_id;
                if (!empty($m['staff_dept_id'])) {
                    $arci_dept_ids[] = (int) $m['staff_dept_id'];
                }
                if ($m_id === (int) $staff_id && !empty($m['role'])) {
                    $user_roles[] = $m['role'];
                }
                if (isset($m['role']) && $m['role'] === 'A') {
                    $a_dept_id = isset($m['staff_dept_id']) ? (int) $m['staff_dept_id'] : 0;
                    $accountable[] = array(
                        'name' => isset($staff_names[$m_id]) ? $staff_names[$m_id] : ('Staff #' . $m_id),
                        'dept' => ($a_dept_id && isset($dept_names[$a_dept_id])) ? $dept_names[$a_dept_id] : '-',
                    );
                }
            }
        }
    }

    $view_rows[] = array(
        'id'              => (int) $a['id'],
        'title'           => isset($a['title']) ? $a['title'] : '',
        'issuer_name'     => isset($staff_names[$issuer_id]) ? $staff_names[$issuer_id] : ($issuer_id ? ('Staff #' . $issuer_id) : '-'),
        'department_name' => isset($dept_names[$dept_id]) ? $dept_names[$dept_id] : '-',
        'department_id'   => $dept_id,
        'level_label'     => $level ? $level['level'] : '',
        'system_name'     => $level ? $level['system_name'] : '',
        'status'          => $status ? $status['value'] : '',
        'start_date'      => isset($a['start_date']) ? $a['start_date'] : '',
        'end_date'        => isset($a['end_date']) ? $a['end_date'] : '',
        'extended_date_1' => isset($a['extended_date_1']) ? $a['extended_date_1'] : '',
        'issuer_staff_id' => $issuer_id,
        'arci_staff_ids'  => $arci_ids,
        'user_arci_roles' => $user_roles,
        'accountable'     => $accountable,
        'is_extended'     => !empty($a['is_extended']),
        'is_deleted'      => !empty($a['deleted_at']),
        'deleted_at'      => isset($a['deleted_at']) ? $a['deleted_at'] : null,
    );
    $row_arci_dept_ids[] = $arci_dept_ids;
}

// Apply server-side visibility filtering based on grade.
if ((int)$atem_permission === 1 && !$_is_superadmin) {
    // Grade 1: own cards only (issuer or any ARCI role).
    $filtered = array();
    foreach ($view_rows as $r) {
        if ($r['issuer_staff_id'] === (int)$staff_id
                || in_array((int)$staff_id, $r['arci_staff_ids'])) {
            $filtered[] = $r;
        }
    }
    $view_rows = $filtered;
} elseif (((int)$atem_permission === 2 || (int)$atem_permission === 3) && !$_is_superadmin) {
    // Grades 2 and 3: cards where issuer or any ARCI member belongs to ANY of the
    // user's departments.
    $filtered = array();
    foreach ($view_rows as $idx => $r) {
        if (in_array($r['department_id'], $user_dept_ids)
                || array_intersect($user_dept_ids, $row_arci_dept_ids[$idx])) {
            $filtered[] = $r;
        }
    }
    $view_rows = $filtered;
}
// Grades 4–6: no server-side row filtering.

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
    'departments' => $dept_list,
    'issuers'     => $issuer_list,
    'staffId'     => (int) $staff_id,
    'isSuperAdmin' => $_is_superadmin,
    'userGrade'   => (int)$atem_permission,
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

<style>
.vf-issuer-wrap { position: relative; }
.vf-s2-selection {
    display: flex;
    align-items: center;
    width: 100%;
    padding-left: 0.5rem;
    padding-right: 2.25rem;
    font-size: 0.875rem;
    font-weight: 400;
    color: #212529;
    background-color: #fff;
    background-image: url("data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 16 16'%3e%3cpath fill='none' stroke='%23343a40' stroke-linecap='round' stroke-linejoin='round' stroke-width='2' d='m2 5 6 6 6-6'/%3e%3c/svg%3e");
    background-repeat: no-repeat;
    background-position: right 0.75rem center;
    background-size: 16px 12px;
    border: var(--bs-border-width) solid var(--bs-border-color);
    border-radius: var(--bs-border-radius-sm);
    cursor: pointer;
    user-select: none;
    outline: none;
    box-sizing: border-box;
    overflow: hidden;
    white-space: nowrap;
    text-overflow: ellipsis;
    font-family: 'Inter', sans-serif;
}
.vf-s2-selection:focus,
.vf-s2-selection:hover { outline: none; box-shadow: none; border-color: var(--bs-border-color); }
.vf-s2-dropdown {
    position: absolute;
    top: calc(100% + 2px);
    left: 0;
    right: 0;
    background: #fff;
    border: 1px solid #dee2e6;
    border-radius: 4px;
    box-shadow: 0 4px 12px rgba(0,0,0,.1);
    z-index: 9999;
    display: none;
}
.vf-s2-dropdown.open { display: block; }
.vf-s2-search-wrap { padding: 6px 6px 4px; }
.vf-s2-search {
    width: 100%;
    box-sizing: border-box;
    font-size: 12px;
    font-family: 'Inter', sans-serif;
    border: 1px solid #dee2e6;
    border-radius: 4px;
    padding: 4px 8px;
    outline: none;
}
.vf-s2-search:focus { border-color: #86b7fe; }
.vf-s2-list {
    list-style: none;
    margin: 0;
    padding: 0;
    max-height: 200px;
    overflow-y: auto;
}
.vf-s2-list li {
    padding: 6px 10px;
    font-size: 12px;
    font-family: 'Inter', sans-serif;
    cursor: pointer;
}
.vf-s2-list li:hover,
.vf-s2-list li.active { background: #0d6efd; color: #fff; }
.vf-s2-list li.hidden { display: none; }
.vf-s2-empty { padding: 8px 10px; font-size: 12px; color: #6c757d; font-family: 'Inter', sans-serif; }
</style>

<!-- Filter bar -->
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
            <select class="form-select form-select-sm" id="vf-status">
                <option value="">All statuses</option>
            </select>
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
                        <input class="vf-s2-search" id="vf-issuer-search" type="search" placeholder="Search name...">
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
            <input type="text" class="form-control form-control-sm" id="vf-search" placeholder="Type title or ATEM ID...">
        </div>
    </div>

    <div class="d-flex justify-content-end mt-2">
        <button type="button" class="btn btn-outline-secondary btn-sm" id="vf-reset">Reset Filters</button>
    </div>
</div>

<!-- Table -->
<div class="atem-card">
    <div class="table-responsive">
        <table class="table table-hover align-middle atem-view-tbl" id="atem-view-tbl">
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
            <tbody id="atem-view-body"></tbody>
        </table>
    </div>
    <div class="atem-pager" id="atem-pager"></div>
</div>

<!-- Delete confirmation modal -->
<div class="modal fade" id="atem-delete-modal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header border-0 pb-0">
                <h5 class="modal-title text-danger"><i class="bi bi-exclamation-triangle-fill me-2"></i>Delete ATEM Card</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body pt-2">
                <p id="atem-delete-modal-msg" style="font-size:13px;"></p>
                <label class="form-label fw-semibold" style="font-size:13px;">Remark <span class="text-danger">*</span></label>
                <textarea id="atem-delete-remark" class="form-control form-control-sm" rows="3" placeholder="State the reason for deletion..."></textarea>
                <div id="atem-delete-remark-err" class="text-danger" style="font-size:12px;min-height:16px;margin-top:4px;"></div>
            </div>
            <div class="modal-footer pt-0">
                <button type="button" id="atem-delete-cancel" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                <button type="button" id="atem-delete-confirm" class="btn btn-danger btn-sm">Confirm Delete</button>
            </div>
        </div>
    </div>
</div>

<script>
window.ATEM_VIEW = <?php echo json_encode($view_config); ?>;
</script>
<?php
$page_js = 'atem/js/view.js';
include('footer.php');
?>