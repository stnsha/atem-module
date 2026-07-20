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
</body>

</html>
