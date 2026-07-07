<?php
$page_title = 'New ATEM';
$page_title_badge = '<span class="atem-pill atem-pill-draft">Draft</span>';
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
        if ($dept_id <= 0 || empty($srow['depart_name'])) {
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

// Outlets for the derived outlet-scope display (outlet staff flow only).
$outlets_list = array();
$outlet_res = mysqli_query($conn, "SELECT id, code FROM outlet ORDER BY code ASC");
if ($outlet_res) {
    while ($orow = mysqli_fetch_assoc($outlet_res)) {
        $outlets_list[] = array('id' => (int) $orow['id'], 'code' => $orow['code']);
    }
}

// Area Managers for the Area Manager(s) picker (outlet staff flow only).
// status_rym = 134 identifies the "Area Manager" position in position_rymnet.
$area_managers_list = array();
$am_sql = "SELECT s.id, s.nama_staff, s.outlet, p.position_name
           FROM staff s
           JOIN position_rymnet p ON p.id = s.status_rym
           WHERE s.status_rym = 134 AND s.recycle != 1
           ORDER BY s.nama_staff";
$am_res = mysqli_query($conn, $am_sql);
if ($am_res) {
    while ($arow = mysqli_fetch_assoc($am_res)) {
        $am_outlet_ids = array();
        foreach (explode(',', (string) $arow['outlet']) as $oid) {
            $oid = (int) trim($oid);
            if ($oid > 0) {
                $am_outlet_ids[] = $oid;
            }
        }
        $area_managers_list[] = array(
            'id'         => (int) $arow['id'],
            'name'       => $arow['nama_staff'],
            'position'   => $arow['position_name'],
            'outlet_ids' => $am_outlet_ids,
        );
    }
}

// Staff grouped by outlet (for the ARCI picker on Outlet-type ATEMs). A staff
// member can belong to several outlets (comma-separated staff.outlet), so they
// are bucketed under every outlet id they belong to, not just one.
$staff_by_outlet = array();
$all_staff_sql = "SELECT s.id, s.nama_staff, s.outlet, p.position_name
                  FROM staff s
                  LEFT JOIN position_rymnet p ON p.id = s.status_rym
                  WHERE s.recycle != 1";
$all_staff_res = mysqli_query($conn, $all_staff_sql);
if ($all_staff_res) {
    while ($orow2 = mysqli_fetch_assoc($all_staff_res)) {
        if (empty($orow2['outlet'])) {
            continue;
        }
        foreach (explode(',', (string) $orow2['outlet']) as $oid) {
            $oid = (int) trim($oid);
            if ($oid <= 0) {
                continue;
            }
            if (!isset($staff_by_outlet[$oid])) {
                $staff_by_outlet[$oid] = array();
            }
            $staff_by_outlet[$oid][] = array(
                'id'       => (int) $orow2['id'],
                'name'     => $orow2['nama_staff'],
                'position' => $orow2['position_name'],
            );
        }
    }
}

// Fetch lookups via the JWT proxy (server-side). No DB row is created here: the
// in-progress card lives in the PHP session until the user saves.
define('API_JWT_INCLUDED', true);
include(dirname(__FILE__) . '/api.php');

$lookups = array('levels' => array(), 'rules' => array(), 'statuses' => array(), 'pillars' => array());
$lookup_result = getAtemLookups($staff_id);
if (!empty($lookup_result['success']) && isset($lookup_result['data'])) {
    $lookups = $lookup_result['data'];
}

$issuer_auth = getStaffAuthData($staff_id);

// Hydrate any in-progress draft saved to the session (survives refresh). Staged
// attachments live in their own key; merge them in for the frontend to restore.
$session_draft = isset($_SESSION['atem_draft']) ? $_SESSION['atem_draft'] : null;
$session_files = isset($_SESSION['atem_draft_files']) ? $_SESSION['atem_draft_files'] : null;
if ($session_files !== null) {
    if (!is_array($session_draft)) {
        $session_draft = array();
    }
    $session_draft['attachments'] = $session_files;
}

$atem_config = array(
    'atemId'        => 0,
    'apiUrl'        => 'atem/api.php',
    'mode'          => 'create',
    'levels'        => $lookups['levels'],
    'rules'         => $lookups['rules'],
    'statuses'      => $lookups['statuses'],
    'pillars'       => $lookups['pillars'],
    'issuer'        => array(
        'id'              => $issuer_auth ? $issuer_auth['staff_id'] : $staff_id,
        'name'            => $issuer_name,
        'staff_dept_id'   => $issuer_auth ? $issuer_auth['staff_dept_id'] : null,
        'department_name' => $issuer_department,
    ),
    'departments'   => $departments_list,
    'staffByDept'   => $staff_by_dept,
    'outlets'       => $outlets_list,
    'areaManagers'  => $area_managers_list,
    'staffByOutlet' => $staff_by_outlet,
    'draft'         => $session_draft,
);

// Read backdate config so the JS can skip the today-min restriction when enabled.
$_bd_enabled = false;
$_bd_result  = mysqli_query($conn, "SELECT setting_value FROM atem_config WHERE setting_key = 'backdate_enabled'");
if ($_bd_result && ($r = mysqli_fetch_assoc($_bd_result))) {
    $_bd_enabled = ($r['setting_value'] === '1');
}
$atem_config['backdate'] = array('enabled' => $_bd_enabled);

// Saving is available as long as the atem-api service answered the lookups call.
$api_unavailable = empty($lookup_result['success']);
?>

<?php if ($api_unavailable): ?>
<div class="alert alert-warning" role="alert" style="font-size:13px;">
    The ATEM service is not reachable, so saving is disabled.
    Please ensure the atem-api service is running, then reload this page.
</div>
<?php endif; ?>

<div class="atem-bento">

    <!-- Staff Type -->
    <div class="atem-bento-item atem-span-12">
        <div class="atem-card">
            <h6 class="atem-card-title"><i class="bi bi-person-badge"></i> ATEM Type</h6>
            <p class="atem-card-hint">Select who this ATEM is being issued for.</p>
            <div class="atem-staff-type-options">
                <button type="button" class="atem-staff-type-btn" id="staff-type-hq" data-type="hq">
                    <i class="bi bi-building"></i>
                    <span class="atem-staff-type-label">HQ ATEM</span>
                </button>
                <button type="button" class="atem-staff-type-btn" id="staff-type-outlet" data-type="outlet">
                    <i class="bi bi-shop"></i>
                    <span class="atem-staff-type-label">Outlet ATEM</span>
                </button>
            </div>
        </div>
    </div>

    <!-- ATEM Details -->
    <div class="atem-bento-item atem-span-8" id="atem-details-section">
        <div class="atem-card h-100">
            <h6 class="atem-card-title"><i class="bi bi-file-earmark-text"></i> ATEM Details</h6>
            <p class="atem-card-hint">Fields marked <span class="atem-req">*</span> are required.</p>
            <div class="row g-3 mt-1">
                <div class="col-12">
                    <label for="atem-title" class="form-label">ATEM Title <span class="atem-req">*</span></label>
                    <input type="text" class="form-control" id="atem-title" placeholder="Short, searchable title">
                    <div class="atem-form-error" id="atem-title-error"></div>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Issuer</label>
                    <input type="text" class="form-control" id="atem-issuer"
                        value="<?php echo htmlspecialchars($issuer_name); ?>" readonly>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Department</label>
                    <input type="text" class="form-control" id="atem-department"
                        value="<?php echo htmlspecialchars($issuer_department); ?>" readonly>
                </div>
                <div class="col-md-6 atem-outlet-only atem-hidden" id="atem-reward-amount-group">
                    <label for="atem-reward-amount" class="form-label">Reward Amount</label>
                    <select class="form-select" id="atem-reward-amount">
                        <option value="0" selected>RM0</option>
                        <option value="50">+ RM50</option>
                        <option value="100">+ RM100</option>
                        <option value="200">+ RM200</option>
                    </select>
                    <div class="atem-form-error" id="atem-reward-amount-error"></div>
                </div>
                <div class="col-md-6 atem-outlet-only atem-hidden" id="atem-deduction-amount-group">
                    <label for="atem-deduction-amount" class="form-label">Deduction Amount</label>
                    <select class="form-select" id="atem-deduction-amount">
                        <option value="0" selected>RM0</option>
                        <option value="50">- RM50</option>
                        <option value="100">- RM100</option>
                        <option value="200">- RM200</option>
                    </select>
                    <div class="atem-form-error" id="atem-deduction-amount-error"></div>
                </div>
                <div class="col-md-6 atem-hq-only" id="atem-level-group">
                    <label for="atem-level" class="form-label">ATEM Complexity Level<span
                            class="atem-req">*</span></label>
                    <select class="form-select" id="atem-level">
                        <option value="">Select level</option>
                    </select>
                    <div class="atem-form-error" id="atem-level-error"></div>
                </div>
                <div class="col-md-6 atem-hq-only" id="atem-rule-group">
                    <label for="atem-rule" class="form-label">Incentive Rule <span class="atem-req" id="rule-req-star"
                            style="display:none;">*</span></label>
                    <select class="form-select" id="atem-rule">
                        <option value="">Select rule</option>
                    </select>
                    <div class="atem-form-error" id="atem-rule-error"></div>
                </div>
                <div class="col-md-6 atem-outlet-only atem-hidden" id="atem-pillars-group">
                    <label for="atem-pillars" class="form-label">5 Pillars</label>
                    <select class="form-select" id="atem-pillars">
                        <option value="">Select pillar</option>
                    </select>
                    <div class="atem-form-error" id="atem-pillars-error"></div>
                </div>
                <div class="col-md-6 atem-outlet-only atem-hidden" id="atem-am-tag-group">
                    <label class="form-label">Area Manager(s) <span class="atem-req">*</span></label>
                    <div class="atem-outlet-picker" id="atem-am-picker-wrap">
                        <div class="atem-outlet-picker-btn" id="atem-am-picker-btn" tabindex="0">Select area manager(s)...
                        </div>
                        <div class="atem-outlet-picker-dropdown" id="atem-am-picker-dropdown">
                            <div class="atem-outlet-picker-search-wrap">
                                <input class="atem-outlet-picker-search" id="atem-am-picker-search" type="search"
                                    placeholder="Search area manager...">
                            </div>
                            <ul class="atem-outlet-picker-list" id="atem-am-picker-list"></ul>
                        </div>
                    </div>
                    <div id="atem-am-tags" class="atem-outlet-tags mt-2">
                        <span class="atem-empty-state">No area manager tagged.</span>
                    </div>
                    <div class="atem-form-error" id="atem-am-error"></div>
                </div>
                <div class="col-md-6">
                    <label for="tl-start" class="form-label">Start Date <span class="atem-req">*</span></label>
                    <input type="date" class="form-control" id="tl-start">
                    <div class="atem-form-error" id="tl-start-error"></div>
                </div>
                <div class="col-md-6">
                    <label for="tl-end" class="form-label">End Date <span class="atem-req">*</span></label>
                    <input type="date" class="form-control" id="tl-end">
                    <div class="atem-form-error" id="tl-end-error"></div>
                </div>
                <div class="col-12 mt-2">
                    <label class="form-label">ATEM Description</label>
                    <div id="atem-description-editor"></div>
                </div>
            </div>
        </div>
    </div>

    <!-- Right column: Incentive (live) + Attachment + Reference Link -->
    <div class="atem-bento-item atem-span-4">
        <div class="atem-card mb-3" id="atem-incentive-section">
            <h6 class="atem-card-title"><i class="bi bi-cash-coin"></i> Estimated Incentive</h6>
            <p class="atem-card-hint">This shows an estimated incentive based on the selected level and rule. The
                company reserves the right to determine the final payout under its incentive scheme. C and I roles are
                not incentivised.</p>
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
                        <span class="atem-incentive-stat-label" id="inc-r-label">R &middot; Responsible</span>
                        <span class="atem-incentive-stat-value" id="inc-r">RM0.00</span>
                    </div>
                </div>
                <div class="atem-incentive-note" id="inc-note">
                    Select an ATEM Complexity Leveland rule to calculate incentive. C and I are not incentivised.
                </div>
            </div>
        </div>

        <div class="atem-card mb-3 atem-hidden" id="atem-reward-section">
            <h6 class="atem-card-title"><i class="bi bi-cash-coin"></i> Estimated Reward</h6>
            <p class="atem-card-hint">This shows an estimated reward based on the selected pillar and reward mechanism.
                The company reserves the right to determine the final payout under its incentive scheme.</p>
            <div class="atem-incentive">
                <div class="atem-incentive-total-block">
                    <div class="atem-incentive-total-label">Total Reward</div>
                    <div class="atem-incentive-total-amount" id="reward-total">RM0.00</div>
                </div>
            </div>
        </div>

        <!-- Attachment -->
        <div class="atem-card mb-3">
            <h6 class="atem-card-title"><i class="bi bi-paperclip"></i> Attachment</h6>
            <p class="atem-card-hint">Upload supporting files (max 10MB each). Stored with this ATEM.</p>
            <div id="atem-dropzone" class="atem-dropzone">
                <input type="file" id="atem-file-input" multiple
                    accept=".jpg,.jpeg,.png,.gif,.pdf,.doc,.docx,.xls,.xlsx,.txt" hidden>
                <div class="atem-dropzone-text"><strong>Drag &amp; drop files here</strong> or <a href="#"
                        id="atem-file-pick">click to select</a></div>
                <small class="atem-dropzone-hint">Maximum 10MB per file. Allowed: Images, PDF, Word, Excel, Text</small>
            </div>
            <div class="atem-form-error" id="atem-file-error"></div>
            <div id="atem-file-list" class="atem-file-list mt-2">
                <div class="atem-empty-state">No files attached.</div>
            </div>
        </div>

        <!-- Reference Link -->
        <div class="atem-card">
            <div class="atem-card-title-row">
                <h6 class="atem-card-title"><i class="bi bi-link-45deg"></i> Reference Link <span
                        class="atem-req">*</span></h6>
                <button type="button" class="btn btn-primary btn-sm" id="atem-add-reflink-btn">Add Reference
                    Link</button>
            </div>
            <p class="atem-card-hint">Add named links to related documents or resources.</p>
            <div id="atem-reflink-list" class="atem-reflink-list">
                <div class="atem-empty-state">No Reference Link added.</div>
            </div>
            <div class="atem-form-error" id="reflink-section-error"></div>
        </div>
    </div>

    <!-- ARCI -->
    <div class="atem-bento-item atem-span-12">
        <div class="atem-card">
            <h6 class="atem-card-title"><i class="bi bi-people"></i> Project Team (ARCI)</h6>
            <p class="atem-card-hint">Tag the team. A (Accountable) supports up to 2 members. R (Responsible) supports
                up to 2 members. C and I are for visibility only and are not incentivised.</p>

            <div class="alert alert-warning atem-hidden" id="atem-arci-orphan-warning" role="alert">
                <span id="atem-arci-orphan-warning-text"></span>
                <button type="button" class="btn-close" id="atem-arci-orphan-warning-close" aria-label="Close"></button>
            </div>

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
                        <label class="form-label" id="arci-dept-label">Department</label>
                        <div class="atem-outlet-only atem-hidden mb-1" id="arci-scope-toggle">
                            <div class="form-check form-check-inline">
                                <input class="form-check-input" type="radio" name="arci-scope" id="arci-scope-outlet" checked>
                                <label class="form-check-label" for="arci-scope-outlet">Outlet</label>
                            </div>
                            <div class="form-check form-check-inline">
                                <input class="form-check-input" type="radio" name="arci-scope" id="arci-scope-department">
                                <label class="form-check-label" for="arci-scope-department">Department</label>
                            </div>
                        </div>
                        <input type="text" class="form-control mb-1" id="arci-dept-search"
                            placeholder="Search department...">
                        <select class="form-select" id="arci-dept-select" size="6">
                            <option value="">Select department</option>
                        </select>
                    </div>
                    <div>
                        <label class="form-label">Staff</label>
                        <input type="text" class="form-control mb-1" id="arci-staff-search"
                            placeholder="Search staff...">
                        <div id="arci-staff-list" class="atem-arci-staff-list">
                            <div class="text-muted" style="font-size:13px;">Select a department to load staff</div>
                        </div>
                        <button type="button" class="btn btn-primary btn-sm mt-2 w-100" id="arci-add-btn">Add
                            Selected</button>
                    </div>
                </div>
                <div class="atem-form-error" id="arci-error"></div>
            </div>

            <div class="atem-arci-grid" id="arci-grid">
                <?php
                $arci_roles = array('A' => 'Accountable', 'R' => 'Responsible', 'C' => 'Consulted', 'I' => 'Informed');
                foreach ($arci_roles as $rkey => $rlabel):
                ?>
                <div class="atem-arci-col">
                    <div class="atem-arci-col-head">
                        <span><strong><?php echo $rkey; ?></strong> - <?php echo $rlabel; ?></span>
                        <button type="button" class="btn btn-outline-secondary btn-sm atem-arci-clear"
                            data-role="<?php echo $rkey; ?>">Delete All</button>
                    </div>
                    <div class="atem-arci-members" data-role="<?php echo $rkey; ?>">
                        <div class="atem-arci-empty">No members assigned</div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

</div>

<div class="atem-save-error-wrap">
    <div class="atem-form-error" id="atem-save-error"></div>
</div>
<div class="atem-save-bar">
    <button type="button" class="btn btn-outline-secondary" id="atem-cancel-btn">Cancel</button>
    <button type="button" class="btn btn-primary" id="atem-save-btn"
        <?php echo $api_unavailable ? 'disabled' : ''; ?>>Save ATEM</button>
</div>

<!-- Add Reference Link modal -->
<div class="modal fade" id="atem-reflink-modal" tabindex="-1" aria-labelledby="atem-reflink-modal-title"
    aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="atem-reflink-modal-title">Add Reference Link</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label for="reflink-name" class="form-label">Name <span class="atem-req">*</span></label>
                    <input type="text" class="form-control" id="reflink-name" placeholder="Enter reference name">
                </div>
                <div class="mb-2">
                    <label for="reflink-url" class="form-label">URL <span class="atem-req">*</span></label>
                    <input type="url" class="form-control" id="reflink-url" placeholder="https://example.com">
                </div>
                <div class="atem-form-error" id="reflink-error"></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" id="reflink-save-btn">Save</button>
            </div>
        </div>
    </div>
</div>

<!-- Leave / cancel confirmation modal -->
<div class="modal fade" id="atem-leave-modal" tabindex="-1" aria-labelledby="atem-leave-modal-title" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="atem-leave-modal-title">Leave this ATEM?</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p class="mb-0">This ATEM has not been saved. Do you want to cancel it, or keep it as a draft?</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Continue
                    editing</button>
                <button type="button" class="btn btn-danger" id="atem-leave-cancel">Cancel ATEM</button>
                <button type="button" class="btn btn-primary" id="atem-leave-draft">Save as draft</button>
            </div>
        </div>
    </div>
</div>

<!-- Removal confirmation modal -->
<div class="modal fade" id="atem-confirm-modal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Please confirm</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p class="mb-0" id="atem-confirm-message">Are you sure?</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-danger" id="atem-confirm-ok">Remove</button>
            </div>
        </div>
    </div>
</div>

<!-- ATEM Type switch warning modal -->
<div class="modal fade" id="atem-type-switch-modal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Reset Required</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p class="mb-0">This ATEM already has data filled in. Changing the ATEM Type requires resetting the
                    form first - all entered fields, tags, and attachments will be cleared.</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-danger" id="atem-type-switch-reset-btn">Reset Form</button>
            </div>
        </div>
    </div>
</div>

<script>
var ATEM_CONFIG = <?php echo json_encode($atem_config); ?>;
</script>
<link href="https://cdn.quilljs.com/1.3.6/quill.snow.css" rel="stylesheet">
<script src="https://cdn.quilljs.com/1.3.6/quill.js"></script>
<?php
$page_js = 'atem/js/create.js';
include('footer.php');
?>