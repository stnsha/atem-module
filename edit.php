<?php
$mode = 'read';
if (isset($_GET['mode']) && $_GET['mode'] === 'edit')         { $mode = 'edit'; }
elseif (isset($_GET['mode']) && $_GET['mode'] === 'progress') { $mode = 'progress'; }
$is_read     = ($mode !== 'edit');
$is_progress = ($mode === 'progress');
$page_title  = ($mode === 'edit') ? 'Edit ATEM' : 'ATEM';
include('header.php');

// header.php bootstrapped $conn and the current staff. Build id -> name maps so
// we can resolve the FK ids the atem-api returns (it stores ids only).
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

// Build staff grouped by department for the ARCI picker (edit mode adds).
$departments   = array();
$staff_by_dept = array();
$staff_sql = "SELECT s.id, s.nama_staff, s.department, d.depart_name
              FROM staff s
              LEFT JOIN staff_department d ON s.department = d.id
              WHERE s.recycle != 1
              ORDER BY d.depart_name, s.nama_staff";
$staff_res2 = mysqli_query($conn, $staff_sql);
if ($staff_res2) {
    while ($srow = mysqli_fetch_assoc($staff_res2)) {
        $dept_id = (int) $srow['department'];
        if ($dept_id <= 0) {
            continue;
        }
        if (!isset($departments[$dept_id])) {
            $departments[$dept_id] = $srow['depart_name'];
            $staff_by_dept[$dept_id] = array();
        }
        $staff_by_dept[$dept_id][] = array('id' => (int) $srow['id'], 'name' => $srow['nama_staff']);
    }
}
$departments_list = array();
foreach ($departments as $d_id => $d_name) {
    $departments_list[] = array('id' => $d_id, 'name' => $d_name);
}

// Fetch lookups and the record via the JWT proxy (server-side).
define('API_JWT_INCLUDED', true);
include(dirname(__FILE__) . '/api.php');

$lookups = array('levels' => array(), 'rules' => array(), 'statuses' => array());
$lr = getAtemLookups($staff_id);
if (!empty($lr['success']) && isset($lr['data'])) {
    $lookups = $lr['data'];
}

$atem_id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$record = null;
if ($atem_id > 0) {
    $get = getAtem($atem_id, $staff_id);
    if (!empty($get['success']) && isset($get['data'])) {
        $record = $get['data'];
    }
}
$api_unavailable = ($record === null);

// Resolve display names server-side onto the record + its ARCI members.
$issuer_name = '';
$issuer_department = '';
if ($record) {
    $iid = isset($record['issuer_staff_id']) ? (int) $record['issuer_staff_id'] : 0;
    $did = isset($record['staff_dept_id']) ? (int) $record['staff_dept_id'] : 0;
    $issuer_name = isset($staff_names[$iid]) ? $staff_names[$iid] : ($iid ? ('Staff #' . $iid) : '');
    $issuer_department = isset($dept_names[$did]) ? $dept_names[$did] : '';
    if (isset($record['arci']) && is_array($record['arci'])) {
        foreach ($record['arci'] as $k => $m) {
            $sid = isset($m['staff_id']) ? (int) $m['staff_id'] : 0;
            $mdid = isset($m['staff_dept_id']) ? (int) $m['staff_dept_id'] : 0;
            $record['arci'][$k]['staff_name'] = isset($staff_names[$sid]) ? $staff_names[$sid] : ('Staff #' . $sid);
            $record['arci'][$k]['department_name'] = isset($dept_names[$mdid]) ? $dept_names[$mdid] : '';
        }
    }
    if (isset($record['audit_logs']) && is_array($record['audit_logs'])) {
        foreach ($record['audit_logs'] as $k => $entry) {
            $aid = isset($entry['actor_staff_id']) ? (int) $entry['actor_staff_id']
                 : (isset($entry['actor_id']) ? (int) $entry['actor_id'] : 0);
            $record['audit_logs'][$k]['actor_name'] = isset($staff_names[$aid])
                ? $staff_names[$aid]
                : ($aid ? 'Staff #' . $aid : 'System');
        }
    }
    if (isset($record['progress']) && is_array($record['progress'])) {
        foreach ($record['progress'] as $k => $prog) {
            $cid = isset($prog['created_by']) ? (int) $prog['created_by'] : 0;
            $record['progress'][$k]['created_by_name'] = ($cid && isset($staff_names[$cid]))
                ? $staff_names[$cid]
                : '';
        }
    }
}

// Access control: SuperAdmin, the issuer, and ARCI members may open this card.
if ($record) {
    $current_sid = (int) $staff_id;
    $allowed = (isset($atem) && (int)$atem === 1);
    if (!$allowed) {
        $allowed = ($current_sid && $current_sid === (int) (isset($record['issuer_staff_id']) ? $record['issuer_staff_id'] : 0));
    }
    if (!$allowed && isset($record['arci']) && is_array($record['arci'])) {
        foreach ($record['arci'] as $m) {
            if ((int) $m['staff_id'] === $current_sid) {
                $allowed = true;
                break;
            }
        }
    }
    if (!$allowed) {
        $_SESSION['atem_warning'] = 'You do not have permission to view this ATEM card.';
        echo '<script>window.location.replace("atem/view.php");</script>';
        include('footer.php');
        exit;
    }
}

// ATEMs with a terminal status cannot be edited.
$terminal_statuses = array('Failed', 'Completed', 'Completed with Excellence');
$current_status_value = '';
if ($record && isset($record['status']['value'])) {
    $current_status_value = $record['status']['value'];
}
if (!$is_read && in_array($current_status_value, $terminal_statuses)) {
    $mode    = 'read';
    $is_read = true;
}
if ($is_progress && in_array($current_status_value, $terminal_statuses)) {
    $mode        = 'read';
    $is_progress = false;
}


$is_draft      = ($current_status_value === 'Draft');
$is_issuer_now = ($record && (int)$staff_id === (int)$record['issuer_staff_id']);

// Non-issuers cannot use progress mode — downgrade to read.
if ($is_progress && !$is_issuer_now) {
    $mode        = 'read';
    $is_progress = false;
    $is_read     = true;
}

$atem_config = array(
    'atemId'      => $atem_id,
    'apiUrl'      => 'atem/api.php',
    'mode'        => $mode,
    'staffId'     => (int) $staff_id,
    'levels'      => $lookups['levels'],
    'rules'       => $lookups['rules'],
    'statuses'    => $lookups['statuses'],
    'departments' => $departments_list,
    'staffByDept' => $staff_by_dept,
    'record'      => $record,
    'isIssuer'    => (bool) $is_issuer_now,
);

$_bd_enabled = false;
$_bd_result  = mysqli_query($conn, "SELECT setting_value FROM atem_config WHERE setting_key = 'backdate_enabled'");
if ($_bd_result && ($r = mysqli_fetch_assoc($_bd_result))) {
    $_bd_enabled = ($r['setting_value'] === '1');
}
$atem_config['backdate'] = array('enabled' => $_bd_enabled);
?>

<?php if ($api_unavailable): ?>
<div class="alert alert-warning" role="alert" style="font-size:13px;">
    The ATEM could not be loaded. Make sure a valid id is supplied and the atem-api service is running.
</div>
<?php endif; ?>

<div class="atem-bento atem-mode-<?php echo $mode; ?>">

    <!-- ATEM Details -->
    <div class="atem-bento-item atem-span-8">
        <div class="atem-card h-100">
            <h6 class="atem-card-title"><i class="bi bi-file-earmark-text"></i> ATEM Details</h6>
            <p class="atem-card-hint">
                <?php echo $is_read ? 'Viewing an ATEM card (read only).' : 'Edit this ATEM card. Fields marked'; ?>
                <?php if (!$is_read): ?><span class="atem-req">*</span> are required.<?php endif; ?></p>
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
                <div class="col-md-6">
                    <label for="atem-level" class="form-label">ATEM Complexity Level <span
                            class="atem-req">*</span></label>
                    <select class="form-select" id="atem-level">
                        <option value="">Select level</option>
                    </select>
                    <div class="atem-form-error" id="atem-level-error"></div>
                </div>
                <div class="col-md-6">
                    <label for="atem-rule" class="form-label">Incentive Rule <span class="atem-req" id="rule-req-star"
                            style="display:none;">*</span></label>
                    <select class="form-select" id="atem-rule">
                        <option value="">Select rule</option>
                    </select>
                    <div class="atem-form-error" id="atem-rule-error"></div>
                </div>
                <div class="col-12 mt-2">
                    <label class="form-label">ATEM Description</label>
                    <div id="atem-description-editor"></div>
                </div>
            </div>
        </div>
    </div>

    <!-- Right column: Incentive + Attachment + Reference Link -->
    <div class="atem-bento-item atem-span-4">
        <div class="atem-card mb-3" style="display:none;">
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
                    <div class="atem-incentive-stat"><span class="atem-incentive-stat-label">Base</span><span
                            class="atem-incentive-stat-value" id="inc-base">RM0.00</span></div>
                    <div class="atem-incentive-stat"><span class="atem-incentive-stat-label">A &middot;
                            Accountable</span><span class="atem-incentive-stat-value" id="inc-a">RM0.00</span></div>
                    <div class="atem-incentive-stat"><span class="atem-incentive-stat-label" id="inc-r-label">R &middot;
                            Responsible</span><span class="atem-incentive-stat-value" id="inc-r">RM0.00</span></div>
                </div>
                <div class="atem-incentive-note" id="inc-note">Incentive is computed from the level and rule.</div>
            </div>
        </div>

        <!-- Attachment -->
        <div class="atem-card mb-3">
            <h6 class="atem-card-title"><i class="bi bi-paperclip"></i> Attachment</h6>
            <p class="atem-card-hint">Files stored with this ATEM.</p>
            <?php if (!$is_read): ?>
            <div id="atem-dropzone" class="atem-dropzone">
                <input type="file" id="atem-file-input" multiple
                    accept=".jpg,.jpeg,.png,.gif,.pdf,.doc,.docx,.xls,.xlsx,.txt" hidden>
                <div class="atem-dropzone-text"><strong>Drag &amp; drop files here</strong> or <a href="#"
                        id="atem-file-pick">click to select</a></div>
                <small class="atem-dropzone-hint">Maximum 10MB per file. Allowed: Images, PDF, Word, Excel, Text</small>
            </div>
            <div class="atem-form-error" id="atem-file-error"></div>
            <?php endif; ?>
            <div id="atem-attachment-list" class="atem-attachment-list mt-2">
                <div class="atem-empty-state">No attachments.</div>
            </div>
        </div>

        <!-- Reference Link -->
        <div class="atem-card">
            <div class="atem-card-title-row">
                <h6 class="atem-card-title"><i class="bi bi-link-45deg"></i> Reference Link <span class="atem-req">*</span></h6>
                <?php if (!$is_read): ?>
                <button type="button" class="btn btn-primary btn-sm" id="atem-add-reflink-btn">Add Reference
                    Link</button>
                <?php endif; ?>
            </div>
            <p class="atem-card-hint">Named links to related documents or resources.</p>
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
            <p class="atem-card-hint">A (Accountable) is mandatory; maximum 2 members. R (Responsible) supports up to 2 members. C and I are for visibility only and are not incentivised.</p>
            <?php if (!$is_read): ?>
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
                        <div id="arci-staff-list" class="atem-arci-staff-list"></div>
                        <button type="button" class="btn btn-primary btn-sm mt-2 w-100" id="arci-add-btn">Add
                            Selected</button>
                    </div>
                </div>
                <div class="atem-form-error" id="arci-error"></div>
            </div>
            <?php endif; ?>

            <div class="atem-arci-grid" id="arci-grid">
                <?php
                $arci_roles = array('A' => 'Accountable', 'R' => 'Responsible', 'C' => 'Consulted', 'I' => 'Informed');
                foreach ($arci_roles as $rkey => $rlabel):
                ?>
                <div class="atem-arci-col">
                    <div class="atem-arci-col-head">
                        <span><strong><?php echo $rkey; ?></strong> - <?php echo $rlabel; ?></span>
                        <?php if (!$is_read): ?>
                        <button type="button" class="btn btn-outline-secondary btn-sm atem-arci-clear"
                            data-role="<?php echo $rkey; ?>">Delete All</button>
                        <?php endif; ?>
                    </div>
                    <div class="atem-arci-members" data-role="<?php echo $rkey; ?>">
                        <div class="atem-arci-empty">No members assigned</div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <!-- Progress Update -->
    <div class="atem-bento-item atem-span-12">
        <div class="atem-card">
            <div class="atem-card-title-row">
                <h6 class="atem-card-title"><i class="bi bi-bar-chart-steps"></i> Progress Update</h6>
                <?php if ($is_issuer_now && (!$is_read || $is_progress)): ?>
                <button type="button" class="btn btn-primary btn-sm" id="atem-add-progress-btn">Add Progress</button>
                <?php endif; ?>
            </div>
            <p class="atem-card-hint">Periodic progress updates for this task. Status reflects overall health at the
                time of the update.</p>
            <div id="atem-progress-wrap"></div>
            <div class="atem-form-error" id="progress-error"></div>
        </div>
    </div>

    <!-- Timeline -->
    <div class="atem-bento-item atem-span-12">
        <div class="atem-card">
            <h6 class="atem-card-title"><i class="bi bi-calendar-range"></i> Timeline</h6>
            <p class="atem-card-hint">Schedule, status, extensions and closure for this ATEM.</p>
            <div class="row g-3 mt-1">
                <!-- Row 1: Start, End, Status -->
                <div class="col-md-4">
                    <label for="tl-start" class="form-label">Start Date <span class="atem-req">*</span></label>
                    <input type="date" class="form-control" id="tl-start">
                    <div class="atem-form-error" id="tl-start-error"></div>
                </div>
                <div class="col-md-4">
                    <label for="tl-end" class="form-label">End Date <span class="atem-req">*</span></label>
                    <input type="date" class="form-control" id="tl-end">
                    <div class="atem-form-error" id="tl-end-error"></div>
                </div>
                <div class="col-md-4">
                    <label for="tl-status" class="form-label">Status <span class="atem-req">*</span></label>
                    <select class="form-select" id="tl-status">
                        <option value="">Select status</option>
                    </select>
                    <div class="atem-form-error" id="tl-status-error"></div>
                </div>

                <!-- Row 2: Extended -->
                <div class="col-12">
                    <div class="form-check mb-2">
                        <input class="form-check-input" type="checkbox" id="tl-extended">
                        <label class="form-check-label" for="tl-extended">Extended? (once only — cannot be undone)</label>
                    </div>
                    <div class="row g-3">
                        <div class="col-md-4 atem-ext-field" id="tl-ext1-wrap" style="display:none;">
                            <label for="tl-ext1" class="form-label">Extended Date <span class="atem-req"
                                    id="tl-ext1-req" style="display:none;">*</span></label>
                            <input type="date" class="form-control" id="tl-ext1">
                        </div>
                    </div>
                </div>

                <!-- Incentive Approval — visible only when extended, issuer only -->
                <div class="col-12" id="tl-incentive-approval-wrap" style="display:none;">
                    <label class="form-label" style="font-weight:600;">Incentive Approval</label>
                    <div class="form-check">
                        <input class="form-check-input" type="radio" name="tl-incentive-approval"
                               id="tl-incentive-approve-yes" value="1">
                        <label class="form-check-label" for="tl-incentive-approve-yes">
                            Approve — pay estimated incentive
                            (<span id="tl-approval-amount">RM 0.00</span>)
                        </label>
                    </div>
                    <div class="form-check">
                        <input class="form-check-input" type="radio" name="tl-incentive-approval"
                               id="tl-incentive-approve-no" value="0" checked>
                        <label class="form-check-label" for="tl-incentive-approve-no">
                            No incentive (RM 0.00)
                        </label>
                    </div>
                    <div class="atem-form-error" id="tl-incentive-approval-error"></div>
                </div>

                <!-- Row 3: Final Due, Closure (auto, disabled) -->
                <div class="col-md-4">
                    <label for="tl-final-due" class="form-label">Final Due Date</label>
                    <input type="date" class="form-control" id="tl-final-due" disabled>
                </div>
                <div class="col-md-4">
                    <label for="tl-closure" class="form-label">Closure Date</label>
                    <input type="date" class="form-control" id="tl-closure" disabled>
                </div>

                <!-- Row 4: Remarks -->
                <div class="col-12">
                    <label for="tl-remarks" class="form-label">Remarks</label>
                    <textarea class="form-control" id="tl-remarks" rows="4"
                        placeholder="Notes, failure reason or excellence remark"></textarea>
                </div>

                <div class="col-12" id="tl-save-reminder" style="display:none;">
                    <div class="atem-tl-reminder">Click <strong>Save ATEM</strong> to apply timeline changes.</div>
                </div>
            </div>
        </div>
    </div>

</div>

<div class="atem-save-error-wrap">
    <div class="atem-form-error" id="atem-save-error"></div>
</div>
<div class="atem-save-bar">
    <a href="atem/view.php" class="btn btn-outline-secondary">Back to list</a>
    <?php if (!$is_read && $is_draft && $is_issuer_now): ?>
    <button type="button" class="btn btn-outline-danger" id="atem-delete-btn"
        <?php echo $api_unavailable ? 'disabled' : ''; ?>>Delete</button>
    <?php endif; ?>
    <?php if (!$is_read): ?>
    <button type="button" class="btn btn-primary" id="atem-save-btn"
        <?php echo $api_unavailable ? 'disabled' : ''; ?>>Save ATEM</button>
    <?php endif; ?>
</div>

<!-- Audit Log -->
<div class="atem-card mt-4" id="atem-audit-card">
    <h6 class="atem-card-title"><i class="bi bi-clock-history"></i> Audit Log</h6>
    <p class="atem-card-hint">Full history of changes made to this ATEM card.</p>
    <div id="atem-audit-meta" class="atem-audit-meta"></div>
    <div id="atem-audit-log" class="atem-audit-log mt-3"></div>
</div>

<!-- Add Reference Link modal -->
<div class="modal fade" id="atem-reflink-modal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Add Reference Link</h5>
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

<!-- Terminal-status confirmation modal -->
<div class="modal fade" id="atem-terminal-warn-modal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Confirm status change</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p class="mb-0" id="atem-terminal-warn-msg"></p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" id="atem-terminal-warn-ok">Proceed and Save</button>
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
$page_js = 'atem/js/edit.js';
include('footer.php');
?>