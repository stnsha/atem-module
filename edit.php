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
        if ($dept_id <= 0 || empty($srow['depart_name'])) {
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

// Access control (view): grades 2-5 and a real SuperAdmin may open any card.
// Grade 1 may only open cards where they are the issuer or an ARCI member.
// Both $atem_permission and $_is_superadmin (header.php) are dev-override aware.
$is_arci_member  = false;
$user_arci_roles = array();
if ($record) {
    $current_sid = (int) $staff_id;
    $is_issuer = ($current_sid && $current_sid === (int) (isset($record['issuer_staff_id']) ? $record['issuer_staff_id'] : 0));
    if (isset($record['arci']) && is_array($record['arci'])) {
        foreach ($record['arci'] as $m) {
            if ((int) $m['staff_id'] === $current_sid) {
                $is_arci_member = true;
                if (!empty($m['role'])) { $user_arci_roles[] = $m['role']; }
            }
        }
    }

    $can_view = $_is_superadmin
        || (int) $atem_permission >= 2
        || $is_issuer
        || $is_arci_member;

    if (!$can_view) {
        $_SESSION['atem_warning'] = 'You do not have permission to view this ATEM card.';
        echo '<script>window.location.replace("atem/view.php");</script>';
        include('footer.php');
        exit;
    }
}

// Detect soft-deleted cards (deleted or suspended) and restrict access.
// Suspended state is keyed off status alone, not deleted_at, so the button/read-only
// logic stays correct even if a record's deleted_at drifted from its status.
$record_is_suspended = ($record
    && isset($record['status']['value'])
    && $record['status']['value'] === 'Suspended');
$record_is_deleted = ($record && !empty($record['deleted_at'])) || $record_is_suspended;
if ($record_is_deleted) {
    if (!$_is_superadmin && (int)$atem_permission < 4) {
        // Suspended cards: allow the issuer to view their own card in read-only.
        if (!($record_is_suspended && $is_issuer)) {
            $_SESSION['atem_warning'] = $record_is_suspended
                ? 'This ATEM card has been suspended and is no longer accessible.'
                : 'This ATEM card has been deleted and is no longer accessible.';
            echo '<script>window.location.replace("atem/view.php");</script>';
            include('footer.php');
            exit;
        }
    }
    // Force read-only — soft-deleted cards cannot be edited by anyone.
    $mode        = 'read';
    $is_read     = true;
    $is_progress = false;
}

// ATEMs with a terminal status cannot be edited.
$terminal_statuses = array('Failed', 'Completed', 'Completed with Excellence', 'Completed with Extension', 'Deleted', 'Suspended');
$current_status_value = '';
if ($record && isset($record['status']['value'])) {
    $current_status_value = $record['status']['value'];
}
$is_draft      = ($current_status_value === 'Draft');
$is_issuer_now = ($record && (int)$staff_id === (int)$record['issuer_staff_id']);

// SuperAdmin may edit Completed or Failed cards — but only Level, Rule, and Status (-> Draft).
$superadmin_terminal_statuses = array('Completed', 'Failed', 'Completed with Extension');
$superadmin_terminal_edit = $_is_superadmin
    && !$record_is_deleted
    && in_array($current_status_value, $superadmin_terminal_statuses);

// Issuer may revert Completed or Completed with Excellence to any earlier status.
$issuer_completed_statuses = array('Completed', 'Completed with Excellence', 'Completed with Extension');
$issuer_completed_edit = $is_issuer_now
    && !$record_is_deleted
    && in_array($current_status_value, $issuer_completed_statuses);

if (!$is_read && in_array($current_status_value, $terminal_statuses)) {
    if (!$superadmin_terminal_edit && !$issuer_completed_edit) {
        $mode    = 'read';
        $is_read = true;
    }
}
if ($is_progress && in_array($current_status_value, $terminal_statuses)) {
    $mode        = 'read';
    $is_progress = false;
}

$can_suspend = ($record && !$record_is_deleted && !$api_unavailable)
    && ($_is_superadmin || (int)$atem_permission >= 4);

$can_unsuspend = $record_is_suspended
    && ($_is_superadmin || (int)$atem_permission >= 4 || $is_issuer_now);

$show_suspension_history = ($record && !empty($record['suspended_by']))
    && ($is_issuer_now || $_is_superadmin || (int)$atem_permission >= 4);

$can_add_progress = ($is_issuer_now || $is_arci_member)
    && !$record_is_deleted
    && !in_array($current_status_value, $terminal_statuses);

// While suspended, the Issuer may still edit Title, Description, Reference Links,
// and Attachments. Everything else (level, rule, timeline, status, ARCI, incentive)
// stays frozen until the card is unsuspended.
$suspended_issuer_edit = $record_is_suspended && $is_issuer_now;

// Non-issuers cannot use progress mode — downgrade to read.
if ($is_progress && !$is_issuer_now) {
    $mode        = 'read';
    $is_progress = false;
    $is_read     = true;
}

// Edit access backstop: only the issuer, an Accountable ARCI member, or a real
// SuperAdmin may edit. Everyone else (including grades 2-5 viewing an unrelated
// card via a crafted ?mode=edit URL) is downgraded to read. Mirrors canEdit() in
// js/view.js so the edit button and the page agree.
$can_edit = $_is_superadmin || $is_issuer_now || in_array('A', $user_arci_roles);
if (!$is_read && !$can_edit) {
    $mode    = 'read';
    $is_read = true;
}

$atem_config = array(
    'atemId'       => $atem_id,
    'apiUrl'       => 'atem/api.php',
    'mode'         => $mode,
    'staffId'      => (int) $staff_id,
    'userGrade'    => (int) $atem_permission,
    'isSuperAdmin' => (bool) $_is_superadmin,
    'levels'       => $lookups['levels'],
    'rules'        => $lookups['rules'],
    'statuses'     => $lookups['statuses'],
    'departments'  => $departments_list,
    'staffByDept'  => $staff_by_dept,
    'record'       => $record,
    'isIssuer'             => (bool) $is_issuer_now,
    'superadminTerminalEdit' => (bool) $superadmin_terminal_edit,
    'issuerCompletedEdit'    => (bool) $issuer_completed_edit,
    'suspendedIssuerEdit'    => (bool) $suspended_issuer_edit,
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

<?php if ($record_is_deleted && !$record_is_suspended): ?>
<div class="alert alert-danger d-flex align-items-center gap-2" role="alert" style="font-size:13px;">
    <i class="bi bi-trash3-fill flex-shrink-0"></i>
    <div>
        <strong>This ATEM card has been deleted.</strong>
        It is displayed here in read-only mode for audit purposes. No changes can be made.
        <?php
        $deleted_by_id = isset($record['closed_by']) ? (int)$record['closed_by'] : 0;
        $deleted_by_name = ($deleted_by_id && isset($staff_names[$deleted_by_id])) ? $staff_names[$deleted_by_id] : ('Staff #' . $deleted_by_id);
        $deleted_at_raw  = isset($record['deleted_at']) ? $record['deleted_at'] : '';
        $deleted_at_fmt  = $deleted_at_raw ? date('d-m-Y H:i', strtotime($deleted_at_raw)) : '';
        if ($deleted_by_id || $deleted_at_fmt):
        ?>
        <span class="ms-2 text-muted" style="font-size:12px;">
            Deleted by <?php echo htmlspecialchars($deleted_by_name); ?><?php echo $deleted_at_fmt ? ' on ' . htmlspecialchars($deleted_at_fmt) : ''; ?>.
        </span>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<?php if ($record_is_suspended): ?>
<div class="alert alert-warning d-flex align-items-center gap-2" role="alert" style="font-size:13px;">
    <i class="bi bi-slash-circle flex-shrink-0"></i>
    <div>
        <strong>This ATEM card has been suspended.</strong>
        <?php if ($can_unsuspend): ?>
        Unsuspending it will restore the card to its previous status.
        <?php else: ?>
        It is displayed here in read-only mode. No changes can be made.
        <?php endif; ?>
        <?php
        $sb_id   = isset($record['suspended_by']) ? (int)$record['suspended_by'] : 0;
        $sb_name = ($sb_id && isset($staff_names[$sb_id])) ? $staff_names[$sb_id] : ('Staff #' . $sb_id);
        $sb_ts   = !empty($record['deleted_at']) ? $record['deleted_at'] : (isset($record['closure_date']) ? $record['closure_date'] : '');
        $sb_at   = $sb_ts ? date('d-m-Y H:i', strtotime($sb_ts)) : '';
        if ($sb_id || $sb_at):
        ?>
        <span class="ms-2 text-muted" style="font-size:12px;">
            Suspended by <?php echo htmlspecialchars($sb_name); ?><?php echo $sb_at ? ' on ' . htmlspecialchars($sb_at) : ''; ?>.
        </span>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<div class="atem-bento atem-mode-<?php echo $mode; ?><?php echo $suspended_issuer_edit ? ' atem-suspended-desc-edit' : ''; ?>">

    <!-- ATEM Details -->
    <div class="atem-bento-item atem-span-8">
        <div class="atem-card h-100">
            <h6 class="atem-card-title"><i class="bi bi-file-earmark-text"></i> ATEM Details</h6>
            <p class="atem-card-hint">
                <?php if ($suspended_issuer_edit): ?>
                This card is suspended. You may still update the Title and Description below.
                <?php else: ?>
                <?php echo $is_read ? 'Viewing an ATEM card (read only).' : 'Edit this ATEM card. Fields marked'; ?>
                <?php if (!$is_read): ?><span class="atem-req">*</span> are required.<?php endif; ?>
                <?php endif; ?>
            </p>
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
                <?php if ($suspended_issuer_edit): ?>
                <div class="col-12 d-flex justify-content-end align-items-center gap-2">
                    <div class="atem-form-error flex-grow-1 mb-0" id="atem-suspended-save-error"></div>
                    <button type="button" class="btn btn-primary btn-sm" id="atem-suspended-save-btn">Save Title &amp; Description</button>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Right column: Incentive + Attachment + Reference Link -->
    <div class="atem-bento-item atem-span-4">
        <div class="atem-card mb-3">
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
            <?php if ($suspended_issuer_edit || (!$is_read && !$superadmin_terminal_edit && !$issuer_completed_edit)): ?>
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
                <?php if ($suspended_issuer_edit || (!$is_read && !$superadmin_terminal_edit && !$issuer_completed_edit)): ?>
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
            <?php if (!$is_read && !$superadmin_terminal_edit && !$issuer_completed_edit): ?>
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
                        <?php if (!$is_read && !$superadmin_terminal_edit && !$issuer_completed_edit): ?>
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
    <div class="atem-bento-item atem-span-12" id="atem-progress-section">
        <div class="atem-card">
            <div class="atem-card-title-row">
                <h6 class="atem-card-title"><i class="bi bi-bar-chart-steps"></i> Progress Update</h6>
                <?php if ($can_add_progress): ?>
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

    <?php if ($show_suspension_history): ?>
    <!-- Suspension Details -->
    <div class="atem-bento-item atem-span-12">
        <div class="atem-card" style="border-left:4px solid #ffc107;">
            <h6 class="atem-card-title" style="color:#856404;"><i class="bi bi-slash-circle"></i> Suspension Details</h6>
            <?php if ($record_is_suspended): ?>
            <p class="atem-card-hint">This card has been suspended. All estimated incentives have been reset to zero.</p>
            <?php else: ?>
            <p class="atem-card-hint">This card was previously suspended. Details are kept for reference.</p>
            <?php endif; ?>
            <div class="row g-3 mt-1">
                <div class="col-md-4">
                    <label class="form-label">Suspended By</label>
                    <div style="font-size:13px;"><?php
                        $sb_id   = isset($record['suspended_by']) ? (int)$record['suspended_by'] : 0;
                        $sb_name = ($sb_id && isset($staff_names[$sb_id])) ? $staff_names[$sb_id] : ($sb_id ? 'Staff #' . $sb_id : '&mdash;');
                        echo htmlspecialchars($sb_name);
                    ?></div>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Suspended On</label>
                    <div style="font-size:13px;"><?php
                        $sb_ts = !empty($record['deleted_at']) ? $record['deleted_at'] : (isset($record['closure_date']) ? $record['closure_date'] : '');
                        echo htmlspecialchars($sb_ts ? date('d-m-Y', strtotime($sb_ts)) : '&mdash;');
                    ?></div>
                </div>
                <div class="col-12">
                    <label class="form-label">Reason</label>
                    <div style="font-size:13px;white-space:pre-wrap;"><?php
                        echo htmlspecialchars(isset($record['suspended_remark']) && $record['suspended_remark'] !== '' ? $record['suspended_remark'] : '&mdash;');
                    ?></div>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

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
    <?php if ($can_suspend): ?>
    <button type="button" class="btn btn-warning" id="atem-suspend-btn">Suspend ATEM</button>
    <?php endif; ?>
    <?php if ($can_unsuspend): ?>
    <button type="button" class="btn btn-success" id="atem-unsuspend-btn">Unsuspend ATEM</button>
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

<!-- Suspend modal -->
<div class="modal fade" id="atem-suspend-modal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Suspend ATEM Card</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p style="font-size:13px;" class="mb-3">
                    Suspending this card will change its status to Suspended and reset all estimated incentives to zero.
                    The card can be unsuspended later to restore it to its previous status.
                </p>
                <div class="mb-2">
                    <label for="suspend-remarks" class="form-label">Reason for Suspension <span class="atem-req">*</span></label>
                    <textarea class="form-control" id="suspend-remarks" rows="3"
                        placeholder="Enter reason for suspension"></textarea>
                    <div class="atem-form-error" id="suspend-remarks-error"></div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-warning" id="atem-suspend-confirm-btn">Suspend Card</button>
            </div>
        </div>
    </div>
</div>

<!-- Unsuspend modal -->
<div class="modal fade" id="atem-unsuspend-modal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Unsuspend ATEM Card</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p style="font-size:13px;" class="mb-0">
                    This will restore the card to its previous status and recalculate estimated incentives.
                    The suspension details will remain visible to the issuer for reference.
                </p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-success" id="atem-unsuspend-confirm-btn">Unsuspend Card</button>
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