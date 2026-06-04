<?php
$page_title = 'New ATEM';
include('header.php');

// header.php has bootstrapped the odb connection ($conn) and the current
// staff ($id_user, $nama_staff, $department). Resolve the issuer department
// name for display.
$issuer_name        = isset($nama_staff) ? $nama_staff : '';
$issuer_department  = '';
if (isset($department) && $department !== '') {
    $dept_sql = "SELECT depart_name FROM staff_department WHERE id = '" . mysqli_real_escape_string($conn, $department) . "' LIMIT 1";
    $dept_res = mysqli_query($conn, $dept_sql);
    if ($dept_res && mysqli_num_rows($dept_res) > 0) {
        $dept_row = mysqli_fetch_assoc($dept_res);
        $issuer_department = $dept_row['depart_name'];
    }
}

// Build staff grouped by department (for the ARCI pickers).
$departments   = array();
$staff_by_dept = array();
$staff_sql = "SELECT s.id, s.nama_staff, s.department, d.depart_name
              FROM staff s
              LEFT JOIN staff_department d ON s.department = d.id
              WHERE s.recycle != 1
              ORDER BY d.depart_name, s.nama_staff";
$staff_res = mysqli_query($conn, $staff_sql);
if ($staff_res) {
    while ($srow = mysqli_fetch_assoc($staff_res)) {
        $dept_id = (int) $srow['department'];
        if ($dept_id <= 0) {
            continue;
        }
        if (!isset($departments[$dept_id])) {
            $departments[$dept_id] = $srow['depart_name'];
            $staff_by_dept[$dept_id] = array();
        }
        $staff_by_dept[$dept_id][] = array(
            'id'   => (int) $srow['id'],
            'name' => $srow['nama_staff'],
        );
    }
}

// Normalise departments for JS as a list of {id, name}.
$departments_list = array();
foreach ($departments as $d_id => $d_name) {
    $departments_list[] = array('id' => $d_id, 'name' => $d_name);
}

// Fetch lookups and create a draft ATEM via the JWT proxy (server-side).
define('API_JWT_INCLUDED', true);
include(dirname(__FILE__) . '/api.php');

$lookups = array('levels' => array(), 'rules' => array(), 'statuses' => array());
$lookup_result = getAtemLookups($staff_id);
if (!empty($lookup_result['success']) && isset($lookup_result['data'])) {
    $lookups = $lookup_result['data'];
}

$issuer_auth = getStaffAuthData($staff_id);

$atem_id = 0;
$draft_result = createAtemDraft(array(
    'issuer_staff_id' => $issuer_auth ? $issuer_auth['staff_id'] : $staff_id,
    'issuer_name'     => $issuer_auth ? $issuer_auth['staff_name'] : $issuer_name,
    'department_id'   => $issuer_auth ? $issuer_auth['department_id'] : null,
    'department_name' => $issuer_auth ? $issuer_auth['department_name'] : $issuer_department,
), $staff_id);
if (!empty($draft_result['success']) && isset($draft_result['data']['id'])) {
    $atem_id = (int) $draft_result['data']['id'];
}

$atem_config = array(
    'atemId'        => $atem_id,
    'apiUrl'        => 'atem/api.php',
    'levels'        => $lookups['levels'],
    'rules'         => $lookups['rules'],
    'statuses'      => $lookups['statuses'],
    'issuer'        => array(
        'id'              => $issuer_auth ? $issuer_auth['staff_id'] : $staff_id,
        'name'            => $issuer_name,
        'department_id'   => $issuer_auth ? $issuer_auth['department_id'] : null,
        'department_name' => $issuer_department,
    ),
    'departments'   => $departments_list,
    'staffByDept'   => $staff_by_dept,
);

$api_unavailable = ($atem_id <= 0);
?>

<?php if ($api_unavailable): ?>
<div class="alert alert-warning" role="alert" style="font-size:13px;">
    The ATEM service is not reachable, so a draft could not be created. Saving is disabled.
    Please ensure the atem-api service is running, then reload this page.
</div>
<?php endif; ?>

<p class="atem-page-hint">File in a new ATEM card. Issuer and department are captured automatically. Fields marked <span class="atem-req">*</span> are required.</p>

<div class="atem-bento">

    <!-- ATEM Details -->
    <div class="atem-bento-item atem-span-8">
        <div class="atem-card h-100">
            <h6 class="atem-card-title"><i class="bi bi-file-earmark-text"></i> ATEM Details</h6>
            <div class="row g-3 mt-1">
                <div class="col-12">
                    <label for="atem-title" class="form-label">ATEM Title <span class="atem-req">*</span></label>
                    <input type="text" class="form-control" id="atem-title" placeholder="Short, searchable title">
                </div>
                <div class="col-12">
                    <label for="atem-google-link" class="form-label">ATEM Google Link <span class="atem-req">*</span></label>
                    <input type="url" class="form-control" id="atem-google-link" placeholder="https://...">
                </div>
                <div class="col-md-6">
                    <label class="form-label">Issuer</label>
                    <input type="text" class="form-control" value="<?php echo htmlspecialchars($issuer_name); ?>" readonly>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Department</label>
                    <input type="text" class="form-control" value="<?php echo htmlspecialchars($issuer_department); ?>" readonly>
                </div>
                <div class="col-md-6">
                    <label for="atem-level" class="form-label">ATEM Level <span class="atem-req">*</span></label>
                    <select class="form-select" id="atem-level">
                        <option value="">Select level</option>
                    </select>
                </div>
                <div class="col-md-6">
                    <label for="atem-rule" class="form-label">Incentive Rule</label>
                    <select class="form-select" id="atem-rule">
                        <option value="">Select rule</option>
                    </select>
                </div>
                <div class="col-12">
                    <label for="atem-description" class="form-label">ATEM Description</label>
                    <textarea class="form-control" id="atem-description" name="description"></textarea>
                </div>
            </div>
        </div>
    </div>

    <!-- Incentive (live) -->
    <div class="atem-bento-item atem-span-4">
        <div class="atem-card h-100">
            <h6 class="atem-card-title"><i class="bi bi-cash-coin"></i> Incentive</h6>
            <div class="atem-incentive">
                <div class="atem-incentive-total-block">
                    <div class="atem-incentive-total-label">Total Incentive</div>
                    <div class="atem-incentive-total-amount" id="inc-total">RM0.00</div>
                </div>
                <div class="atem-incentive-breakdown">
                    <div class="atem-incentive-stat">
                        <span class="atem-incentive-stat-label">Base</span>
                        <span class="atem-incentive-stat-value" id="inc-base">RM0.00</span>
                    </div>
                    <div class="atem-incentive-stat">
                        <span class="atem-incentive-stat-label">A &middot; Accountable</span>
                        <span class="atem-incentive-stat-value" id="inc-a">RM0.00</span>
                    </div>
                    <div class="atem-incentive-stat">
                        <span class="atem-incentive-stat-label">R &middot; Responsible</span>
                        <span class="atem-incentive-stat-value" id="inc-r">RM0.00</span>
                    </div>
                </div>
                <div class="atem-incentive-note" id="inc-note">
                    Select an ATEM level and rule to calculate incentive. C and I are not incentivised.
                </div>
            </div>
        </div>
    </div>

    <!-- ARCI -->
    <div class="atem-bento-item atem-span-12">
        <div class="atem-card">
            <h6 class="atem-card-title"><i class="bi bi-people"></i> ARCI</h6>
            <p class="atem-card-hint">Tag the team. A (Accountable) is mandatory and limited to one person. C and I are for visibility only and are not incentivised.</p>

            <div class="atem-arci-add">
                <div class="atem-arci-add-grid">
                    <div>
                        <label class="form-label">Role</label>
                        <select class="form-select" id="arci-role">
                            <option value="">Select role</option>
                            <option value="A">A - Accountable</option>
                            <option value="R">R - Responsible</option>
                            <option value="C">C - Consulted</option>
                            <option value="I">I - Informed</option>
                        </select>
                    </div>
                    <div>
                        <label class="form-label">Department</label>
                        <input type="text" class="form-control mb-1" id="arci-dept-search" placeholder="Search department...">
                        <select class="form-select" id="arci-dept-select" size="6">
                            <option value="">Select department</option>
                        </select>
                    </div>
                    <div>
                        <label class="form-label">Staff</label>
                        <input type="text" class="form-control mb-1" id="arci-staff-search" placeholder="Search staff...">
                        <div id="arci-staff-list" class="atem-arci-staff-list">
                            <div class="text-muted" style="font-size:13px;">Select a department to load staff</div>
                        </div>
                        <button type="button" class="btn btn-primary btn-sm mt-2 w-100" id="arci-add-btn">Add Selected</button>
                    </div>
                </div>
            </div>

            <div class="atem-arci-grid" id="arci-grid">
                <?php
                $arci_roles = array('A' => 'Accountable', 'R' => 'Responsible', 'C' => 'Consulted', 'I' => 'Informed');
                foreach ($arci_roles as $rkey => $rlabel):
                ?>
                <div class="atem-arci-col">
                    <div class="atem-arci-col-head">
                        <span><strong><?php echo $rkey; ?></strong> - <?php echo $rlabel; ?></span>
                        <button type="button" class="btn btn-outline-secondary btn-sm atem-arci-clear" data-role="<?php echo $rkey; ?>">Delete All</button>
                    </div>
                    <div class="atem-arci-members" data-role="<?php echo $rkey; ?>">
                        <div class="atem-arci-empty">No members assigned</div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <!-- Timeline -->
    <div class="atem-bento-item atem-span-12">
        <div class="atem-card">
            <h6 class="atem-card-title"><i class="bi bi-calendar-range"></i> Timeline</h6>
            <div class="row g-3 mt-1">
                <div class="col-md-3">
                    <label for="tl-start" class="form-label">Start Date <span class="atem-req">*</span></label>
                    <input type="date" class="form-control" id="tl-start">
                </div>
                <div class="col-md-3">
                    <label for="tl-end" class="form-label">End Date <span class="atem-req">*</span></label>
                    <input type="date" class="form-control" id="tl-end">
                </div>
                <div class="col-md-3">
                    <label for="tl-status" class="form-label">Status</label>
                    <select class="form-select" id="tl-status">
                        <option value="">Select status</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <label for="tl-final-due" class="form-label">Final Due Date</label>
                    <input type="date" class="form-control" id="tl-final-due" readonly>
                </div>

                <div class="col-12">
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" id="tl-extended">
                        <label class="form-check-label" for="tl-extended" style="font-size:13px;">Extended? (maximum 2 extensions)</label>
                    </div>
                </div>

                <div class="col-md-3 atem-ext-field" id="tl-ext1-wrap" style="display:none;">
                    <label for="tl-ext1" class="form-label">Extended Date 1</label>
                    <input type="date" class="form-control" id="tl-ext1">
                </div>
                <div class="col-md-3 atem-ext-field" id="tl-ext2-wrap" style="display:none;">
                    <label for="tl-ext2" class="form-label">Extended Date 2</label>
                    <input type="date" class="form-control" id="tl-ext2">
                </div>
                <div class="col-md-3">
                    <label for="tl-closure" class="form-label">Closure Date</label>
                    <input type="date" class="form-control" id="tl-closure" readonly>
                </div>

                <div class="col-12">
                    <label for="tl-remarks" class="form-label">Remarks</label>
                    <textarea class="form-control" id="tl-remarks" rows="2" placeholder="Notes, failure reason or excellence remark"></textarea>
                </div>
            </div>
        </div>
    </div>

</div>

<div class="atem-save-bar">
    <a href="atem/index.php" class="btn btn-outline-secondary">Cancel</a>
    <button type="button" class="btn btn-primary" id="atem-save-btn" <?php echo $api_unavailable ? 'disabled' : ''; ?>>Save ATEM</button>
</div>

<script>
    var ATEM_CONFIG = <?php echo json_encode($atem_config); ?>;
</script>
<script src="faqOcto/tinymce/js/tinymce/tinymce.min.js" referrerpolicy="origin"></script>
<?php
$page_js = 'atem/js/create.js';
include('footer.php');
?>
