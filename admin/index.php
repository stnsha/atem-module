<?php
$page_title = 'Admin Settings';
include('../header.php');

if (!$_is_superadmin) {
    header('Location: ' . ATEM_BASE . 'index.php');
    exit;
}

$cfg_keys = array('struct_window_override', 'backdate_enabled');
$cfg_values = array(
    'struct_window_override' => '0',
    'backdate_enabled'       => '0',
);

$escaped_keys = array();
foreach ($cfg_keys as $k) {
    $escaped_keys[] = "'" . mysqli_real_escape_string($conn, $k) . "'";
}
$cfg_result = mysqli_query($conn, "SELECT setting_key, setting_value FROM atem_config WHERE setting_key IN (" . implode(',', $escaped_keys) . ")");
if ($cfg_result) {
    while ($cfg_row = mysqli_fetch_assoc($cfg_result)) {
        $cfg_values[$cfg_row['setting_key']] = $cfg_row['setting_value'];
    }
}

$struct_window_open = ($cfg_values['struct_window_override'] === '1');
$backdate_enabled   = ($cfg_values['backdate_enabled'] === '1');

// OKR's own backdate toggle (okr_config.backdate_enabled) - the OKR module
// has no admin page of its own; ATEM and OKR are combined under this single
// Admin menu, so this setting is read/written here even though it governs
// date pickers in the sibling okr/ folder, not this one.
$_okr_cfg_result = mysqli_query($conn, "SELECT setting_value FROM okr_config WHERE setting_key = 'backdate_enabled'");
$okr_backdate_enabled = false;
if ($_okr_cfg_result && ($_okr_cfg_row = mysqli_fetch_assoc($_okr_cfg_result))) {
    $okr_backdate_enabled = ($_okr_cfg_row['setting_value'] === '1');
}
?>

<div class="row g-4">
    <div class="col-md-6">

        <div class="bento-card mb-4">
            <p class="mb-1" style="font-size:13px;font-weight:600;">Evaluation Structure Update Window</p>
            <p class="mb-3 text-muted" style="font-size:12px;">When enabled, all users may update their evaluation
                structure regardless of the quarterly date window.</p>
            <div class="d-flex align-items-center gap-2">
                <div class="form-check form-switch mb-0">
                    <input class="form-check-input" type="checkbox" role="switch" id="struct-window-toggle"
                        <?php echo $struct_window_open ? 'checked' : ''; ?>
                        style="width:2.5em;height:1.25em;cursor:pointer;">
                </div>
                <span id="struct-window-status" style="font-size:12px;"></span>
            </div>
        </div>

        <div class="bento-card mb-4">
            <p class="mb-1" style="font-size:13px;font-weight:600;">ATEM Backdated Cards</p>
            <p class="mb-3 text-muted" style="font-size:12px;">When enabled, all date inputs across the system allow
                past dates to be selected.</p>
            <div class="d-flex align-items-center gap-2">
                <div class="form-check form-switch mb-0">
                    <input class="form-check-input" type="checkbox" role="switch" id="backdate-toggle"
                        <?php echo $backdate_enabled ? 'checked' : ''; ?>
                        style="width:2.5em;height:1.25em;cursor:pointer;">
                </div>
                <span id="backdate-status" style="font-size:12px;"></span>
            </div>
        </div>

        <div class="bento-card">
            <p class="mb-1" style="font-size:13px;font-weight:600;">Allow Backdated OKRs</p>
            <p class="mb-3 text-muted" style="font-size:12px;">When enabled, Start Date, End Date and Extended Date
                inputs across the OKR module allow past dates to be selected.</p>
            <div class="d-flex align-items-center gap-2">
                <div class="form-check form-switch mb-0">
                    <input class="form-check-input" type="checkbox" role="switch" id="okr-backdate-toggle"
                        <?php echo $okr_backdate_enabled ? 'checked' : ''; ?>
                        style="width:2.5em;height:1.25em;cursor:pointer;">
                </div>
                <span id="okr-backdate-status" style="font-size:12px;"></span>
            </div>
        </div>

    </div>

    <!-- Right: Manage ATEM & OKR SuperAdmin Access -->
    <div class="col-md-6">

        <div class="bento-card mb-4">
            <p class="mb-1" style="font-size:13px;font-weight:600;">Manage ATEM &amp; OKR SuperAdmin Access</p>

            <div class="row g-2 mt-1 align-items-end mb-3">
                <div class="col-md-8 col-sm-8">
                    <label class="form-label" style="font-size:12px;">Staff Name</label>
                    <input type="text" id="sa-filter-name" class="form-control form-control-sm"
                        placeholder="Search name...">
                </div>
                <div class="col-auto d-flex align-items-end gap-2">
                    <button class="btn btn-sm btn-primary" id="sa-apply-filter">Apply</button>
                    <button class="btn btn-sm btn-outline-secondary" id="sa-reset-filter">Reset</button>
                </div>
            </div>

            <div class="table-responsive">
                <table class="table table-hover align-middle atem-view-tbl mb-0">
                    <thead>
                        <tr>
                            <th>Staff Name</th>
                            <th>Department</th>
                            <th>ATEM Admin</th>
                            <th>OKR Admin</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody id="sa-staff-tbody">
                        <tr>
                            <td colspan="5" class="text-center text-muted py-3">Loading...</td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <div class="atem-pager" id="sa-staff-pager"
                style="margin-top:12px;padding-top:12px;border-top:1px solid #e9ecef;"></div>
        </div>

        <div class="bento-card">
            <p class="mb-1" style="font-size:13px;font-weight:600;">Update SuperAdmin Access</p>

            <div id="sa-form-alert" class="alert alert-dismissible fade show mb-3" role="alert"
                style="display:none !important; font-size:12px !important;">
                <span id="sa-form-alert-msg" style="font-size:12px;"></span>
                <button type="button" class="btn-close" onclick="dismissSaAlert()"></button>
            </div>

            <div id="sa-empty-msg" class="text-muted" style="font-size:12px;">Select a staff member from the list to
                update their SuperAdmin access.</div>

            <div id="sa-edit-panel" style="display:none;">
                <div class="mb-3"
                    style="background:#f8f9fa;border:1px solid #dee2e6;border-radius:6px;padding:12px 14px;font-size:12px !important;">
                    <p class="mb-1" style="font-size:12px !important;"><strong style="font-size:12px !important;">Name:</strong> <span id="sa-info-name" style="font-size:12px !important;"></span></p>
                    <p class="mb-0" style="font-size:12px !important;"><strong style="font-size:12px !important;">Department:</strong> <span id="sa-info-dept" style="font-size:12px !important;"></span></p>
                </div>

                <div class="mb-2 d-flex align-items-center gap-2">
                    <div class="form-check form-switch mb-0">
                        <input class="form-check-input" type="checkbox" role="switch" id="sa-atem-toggle"
                            style="width:2.5em;height:1.25em;cursor:pointer;">
                    </div>
                    <label for="sa-atem-toggle" style="font-size:13px;cursor:pointer;">ATEM Admin</label>
                </div>
                <div class="mb-3 d-flex align-items-center gap-2">
                    <div class="form-check form-switch mb-0">
                        <input class="form-check-input" type="checkbox" role="switch" id="sa-okr-toggle"
                            style="width:2.5em;height:1.25em;cursor:pointer;">
                    </div>
                    <label for="sa-okr-toggle" style="font-size:13px;cursor:pointer;">OKR Admin</label>
                </div>

                <div class="d-flex justify-content-end gap-2">
                    <button type="button" class="btn btn-outline-secondary btn-sm" id="sa-cancel-btn">Cancel</button>
                    <button type="button" class="btn btn-primary btn-sm" id="sa-update-btn">Update</button>
                </div>
            </div>
        </div>

    </div>
</div>

</div><!-- /.atem-container -->

<script>
var STRUCT_WINDOW_OPEN   = <?php echo $struct_window_open ? 'true' : 'false'; ?>;
var BACKDATE_ENABLED     = <?php echo $backdate_enabled ? 'true' : 'false'; ?>;
var OKR_BACKDATE_ENABLED = <?php echo $okr_backdate_enabled ? 'true' : 'false'; ?>;
var ADMIN_BACKEND_URL    = <?php echo json_encode(ATEM_BASE . 'admin/backend.php'); ?>;
</script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="<?php echo ATEM_BASE; ?>js/admin_settings.js?v=<?php echo time(); ?>"></script>
<script src="<?php echo ATEM_BASE; ?>js/admin_superadmin.js?v=<?php echo time(); ?>"></script>
</body>

</html>
