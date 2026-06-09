<?php
$page_title = 'ATEM';
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
define('API_JWT_INCLUDED', true);
include(dirname(__FILE__) . '/api.php');

$lookups = array('levels' => array(), 'rules' => array(), 'statuses' => array());
$lr = getAtemLookups($staff_id);
if (!empty($lr['success']) && isset($lr['data'])) {
    $lookups = $lr['data'];
}

$list_result = getAtemList($staff_id);
$rows = (!empty($list_result['success']) && isset($list_result['data'])) ? $list_result['data'] : array();
$api_unavailable = empty($list_result['success']);

$atem_warning = '';
if (isset($_SESSION['atem_warning'])) {
    $atem_warning = $_SESSION['atem_warning'];
    unset($_SESSION['atem_warning']);
}

// Enrich each row with resolved names + flattened display fields.
$view_rows = array();
foreach ($rows as $a) {
    $issuer_id = isset($a['issuer_staff_id']) ? (int) $a['issuer_staff_id'] : 0;
    $dept_id   = isset($a['department_id']) ? (int) $a['department_id'] : 0;
    $level     = isset($a['level_structure']) && $a['level_structure'] ? $a['level_structure'] : null;
    $status    = isset($a['status']) && $a['status'] ? $a['status'] : null;

    $arci_ids   = array();
    $user_roles = array();
    if (isset($a['arci']) && is_array($a['arci'])) {
        foreach ($a['arci'] as $m) {
            if (!empty($m['staff_id'])) {
                $arci_ids[] = (int) $m['staff_id'];
                if ((int) $m['staff_id'] === (int) $staff_id && !empty($m['role'])) {
                    $user_roles[] = $m['role'];
                }
            }
        }
    }

    $view_rows[] = array(
        'id'              => (int) $a['id'],
        'title'           => isset($a['title']) ? $a['title'] : '',
        'issuer_name'     => isset($staff_names[$issuer_id]) ? $staff_names[$issuer_id] : ($issuer_id ? ('Staff #' . $issuer_id) : '-'),
        'department_name' => isset($dept_names[$dept_id]) ? $dept_names[$dept_id] : '-',
        'level_label'     => $level ? $level['level'] : '',
        'system_name'     => $level ? $level['system_name'] : '',
        'status'          => $status ? $status['value'] : '',
        'start_date'      => isset($a['start_date']) ? $a['start_date'] : '',
        'end_date'        => isset($a['end_date']) ? $a['end_date'] : '',
        'issuer_staff_id' => $issuer_id,
        'arci_staff_ids'  => $arci_ids,
        'user_arci_roles' => $user_roles,
    );
}

$view_config = array(
    'rows'     => $view_rows,
    'levels'   => isset($lookups['levels']) ? $lookups['levels'] : array(),
    'statuses' => isset($lookups['statuses']) ? $lookups['statuses'] : array(),
    'staffId'  => (int) $staff_id,
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

<!-- Filter bar -->
<div class="atem-card atem-filter mb-3">
    <h6 class="atem-card-title"><i class="bi bi-funnel"></i> Filter</h6>
    <div class="row g-2 mt-1 align-items-end">
        <div class="col-md-3">
            <label class="form-label">Level</label>
            <select class="form-select form-select-sm" id="vf-level"><option value="">All levels</option></select>
        </div>
        <div class="col-md-3">
            <label class="form-label">Department</label>
            <select class="form-select form-select-sm" id="vf-dept"><option value="">All departments</option></select>
        </div>
        <div class="col-md-3">
            <label class="form-label">Status</label>
            <select class="form-select form-select-sm" id="vf-status"><option value="">All statuses</option></select>
        </div>
        <div class="col-md-3">
            <label class="form-label">Role with ARCI</label>
            <select class="form-select form-select-sm" id="vf-role"><option value="">All roles</option></select>
        </div>
        <div class="col-md-3">
            <label class="form-label">Start from</label>
            <input type="date" class="form-control form-control-sm" id="vf-from">
        </div>
        <div class="col-md-3">
            <label class="form-label">Start to</label>
            <input type="date" class="form-control form-control-sm" id="vf-to">
        </div>
        <div class="col-md-4">
            <label class="form-label">Search title</label>
            <input type="text" class="form-control form-control-sm" id="vf-search" placeholder="Type to search...">
        </div>
        <div class="col-md-2 d-flex align-items-end">
            <button type="button" class="btn btn-outline-secondary btn-sm atem-reset-btn w-100" id="vf-reset">Reset Filters</button>
        </div>
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

<script>
window.ATEM_VIEW = <?php echo json_encode($view_config); ?>;
</script>
<?php
$page_js = 'atem/js/view.js';
include('footer.php');
?>
