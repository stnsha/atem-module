<?php
ob_start();

$page_title = 'Access Control';
$extra_css  = '<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet">';

include('../header.php');

if ($atem_permission < 1) {
    ob_end_clean();
    header('Location: /odb/atem/index.php');
    exit;
}

ob_end_flush();

$grade_labels = array(
    0 => 'Non-Graded',
    1 => 'Frontline / Operational Staff',
    2 => 'Middle management',
    3 => 'Senior management',
    4 => 'C suite executive',
    5 => 'CEO/board',
    6 => 'SuperAdmin'
);

$grade_badges = array(
    0 => 'bg-secondary',
    1 => 'bg-success',
    2 => 'bg-info text-dark',
    3 => 'bg-primary',
    4 => 'bg-warning text-dark',
    5 => 'bg-danger',
    6 => 'bg-dark'
);

$show_edit          = ($atem_permission > 1 || $_is_superadmin);
$table_cols         = $show_edit ? 5 : 4;
$requester_dept_ids = array();

if (isset($department) && $department !== '') {
    foreach (explode(',', (string)$department) as $_rd) {
        $_rd = (int)trim($_rd);
        if ($_rd > 0) {
            $requester_dept_ids[] = $_rd;
        }
    }
}

$dept_filter_options = array();
if ($atem_permission >= 2 || $_is_superadmin) {
    $all_depts_r = mysqli_query($conn, "SELECT id, depart_name FROM staff_department ORDER BY depart_name ASC");
    if ($all_depts_r) {
        while ($dr = mysqli_fetch_assoc($all_depts_r)) {
            $did = (int)$dr['id'];
            if ($atem_permission >= 3 || $_is_superadmin) {
                $dept_filter_options[$did] = $dr['depart_name'];
            } elseif (in_array($did, $requester_dept_ids)) {
                $dept_filter_options[$did] = $dr['depart_name'];
            }
        }
    }
}
?>

<style>
.select2-container .select2-selection--single {
    height: 38px;
    border: 1px solid #dee2e6;
    border-radius: 6px;
}

.select2-container--default .select2-selection--single .select2-selection__rendered {
    line-height: 36px;
    font-size: 14px;
    font-family: 'Inter', sans-serif;
    color: #212529;
    padding-left: 10px;
}

.select2-container--default .select2-selection--single .select2-selection__arrow {
    height: 36px;
}

.select2-dropdown {
    border: 1px solid #dee2e6;
    border-radius: 6px;
    font-size: 12px;
    font-family: 'Inter', sans-serif;
}

.select2-container--default .select2-results__option--highlighted[aria-selected] {
    background-color: #0d6efd;
}

.select2-search--dropdown .select2-search__field {
    font-size: 12px;
    font-family: 'Inter', sans-serif;
    border: 1px solid #dee2e6;
    border-radius: 4px;
    padding: 4px 8px;
}

.select2-results__option,
.select2-results__message {
    text-align: left !important;
    font-size: 12px !important;
    font-family: 'Inter', sans-serif;
}

.staff-info-box {
    background: #f8f9fa;
    border: 1px solid #dee2e6;
    border-radius: 6px;
    padding: 12px 14px;
    font-size: 12px !important;
    display: none;
}

.staff-info-box *,
.staff-info-box p,
.staff-info-box strong,
.staff-info-box span {
    font-size: 12px !important;
    margin-bottom: 4px;
}

.atem-container,
.atem-container * {
    text-align: left !important;
}

.badge,
.status-value {
    text-align: center !important;
}

.grade-list .form-check {
    padding-left: 1.75rem;
    margin-bottom: 6px;
}

.grade-list .form-check-label {
    font-size: 12px;
    cursor: pointer;
}

.grade-list .form-check-input[type="radio"] {
    -webkit-appearance: radio !important;
    -moz-appearance: radio !important;
    appearance: radio !important;
    width: 1em !important;
    height: 1em !important;
    margin-top: 0.25em;
    border: 1px solid #adb5bd !important;
    background-color: #fff !important;
    cursor: pointer;
}

.grade-list .form-check-input[type="radio"]:checked {
    background-color: #0d6efd !important;
    border-color: #0d6efd !important;
}
</style>

<?php if ($atem_permission >= 2 || $_is_superadmin): ?>
<div class="atem-card atem-filter mb-3">
    <h6 class="atem-card-title"><i class="bi bi-funnel"></i> Filter</h6>
    <div class="row g-2 mt-1 align-items-end">
        <?php if (!empty($dept_filter_options)): ?>
        <div class="col-md-3 col-sm-6">
            <label class="form-label">Department</label>
            <select id="ac-filter-dept" class="form-select form-select-sm">
                <option value="0">All Department</option>
                <?php foreach ($dept_filter_options as $did => $dname): ?>
                <option value="<?php echo $did; ?>"><?php echo htmlspecialchars($dname); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php endif; ?>
        <div class="col-md-3 col-sm-6">
            <label class="form-label">Staff Name</label>
            <input type="text" id="ac-filter-name" class="form-control form-control-sm" placeholder="Search name...">
        </div>
        <div class="col-auto d-flex align-items-end gap-2">
            <button class="btn btn-sm btn-primary" id="ac-apply-filter">Apply</button>
            <button class="btn btn-sm btn-outline-secondary" id="ac-reset-filter">Reset</button>
        </div>
    </div>
</div>
<?php endif; ?>

<div class="row g-4">

    <!-- Left: staff table -->
    <div class="<?php echo $show_edit ? 'col-md-7' : 'col-md-12'; ?>">
        <div class="bento-card">
            <p class="mb-3 text-muted"
                style="font-size: 11px; text-transform: uppercase; letter-spacing: .06em; font-weight: 600;">
                <?php echo $show_edit ? '' : 'Your Grade &amp; Evaluation Structure'; ?>
            </p>
            <div class="table-responsive">
                <table class="table table-hover align-middle atem-view-tbl mb-0">
                    <thead>
                        <tr>
                            <th>Staff Name</th>
                            <th>Department</th>
                            <th>Grade</th>
                            <th>Evaluation Structure</th>
                            <?php if ($show_edit): ?><th></th><?php endif; ?>
                        </tr>
                    </thead>
                    <tbody id="active-staff-tbody">
                        <tr>
                            <td colspan="<?php echo $table_cols; ?>" class="text-center text-muted py-3">Loading...</td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <div class="atem-pager" id="admin-staff-pager"
                style="margin-top:12px;padding-top:12px;border-top:1px solid #e9ecef;"></div>
        </div>
    </div>

    <?php if ($show_edit): ?>
    <!-- Right: update form -->
    <div class="col-md-5" id="update-form-col">

        <div class="bento-card">
            <p class="mb-3 text-muted"
                style="font-size: 11px; text-transform: uppercase; letter-spacing: .06em; font-weight: 600;">Update
                Staff</p>

            <div id="form-alert" class="alert alert-dismissible fade show mb-3" role="alert"
                style="display:none !important; font-size: 12px !important;">
                <span id="form-alert-msg" style="font-size:12px;"></span>
                <button type="button" class="btn-close" onclick="dismissAlert()"></button>
            </div>

            <div class="mb-3">
                <label class="form-label" style="font-size: 12px;">Staff Name</label>
                <select id="staff-search" style="width:100%;"></select>
            </div>

            <div class="staff-info-box mb-3" id="staff-info">
                <p><strong>Name:</strong> <span id="info-name"></span></p>
                <p><strong>Department:</strong> <span id="info-dept"></span></p>
                <p><strong>Status:</strong> <span id="info-status"></span></p>
                <p><strong>Current Grade:</strong> <span id="info-grade"></span></p>
                <p><strong>Evaluation Structure:</strong> <span id="info-struct"></span></p>
                <div id="struct-history-section"
                    style="display:none; margin-top:6px; padding-top:6px; border-top:1px solid #dee2e6;">
                    <p class="mb-1" style="color:#6c757d;">History</p>
                    <div id="struct-history-list"></div>
                </div>
            </div>

            <div class="mb-3 grade-list" id="grade-section" style="display:none;">
                <label class="form-label" style="font-size: 12px;">Grade</label>
                <?php foreach ($grade_labels as $val => $label): ?>
                <div class="form-check">
                    <input class="form-check-input" type="radio" name="grade" id="grade-<?php echo $val; ?>"
                        value="<?php echo $val; ?>">
                    <label class="form-check-label" for="grade-<?php echo $val; ?>">
                        <?php echo htmlspecialchars($label); ?> (<?php echo $val; ?>)
                    </label>
                </div>
                <?php endforeach; ?>
            </div>

            <div id="struct-lock-notice" class="mb-2" style="display:none; font-size:12px; color:#dc3545;"></div>

            <div class="mb-3 grade-list" id="struct-section" style="display:none;">
                <label class="form-label" style="font-size: 12px;">Evaluation Structure</label>
                <div id="struct-radio-list"></div>
                <p id="struct-none-msg" class="text-muted mb-0" style="display:none; font-size:12px; margin-top:4px;">No
                    evaluation structures defined.</p>
            </div>

            <div class="d-flex justify-content-end gap-2" id="submit-section" style="display:none !important;">
                <button type="button" class="btn btn-outline-secondary btn-sm" id="cancel-btn">Cancel</button>
                <button type="button" class="btn btn-primary btn-sm" id="update-btn">Update</button>
            </div>
        </div>
    </div>
    <?php endif; ?>

</div>

</div><!-- /.atem-container -->

<script>
var GRADE_LABELS = <?php echo json_encode($grade_labels); ?>;
var GRADE_BADGES = <?php echo json_encode($grade_badges); ?>;
var REQUESTER_GRADE = <?php echo (int)$atem_permission; ?>;
var REQUESTER_DEPT_IDS = <?php echo json_encode(array_values($requester_dept_ids)); ?>;
var IS_SUPERADMIN = <?php echo $_is_superadmin ? 'true' : 'false'; ?>;
var SHOW_EDIT = <?php echo $show_edit ? 'true' : 'false'; ?>;
var TABLE_COLS = <?php echo $table_cols; ?>;
var BACKEND_URL = 'atem/access_control/backend.php';
var HAS_DEPT_FILTER = <?php echo !empty($dept_filter_options) ? 'true' : 'false'; ?>;
</script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<script src="/odb/atem/js/admin_access.js?v=<?php echo time(); ?>"></script>
</body>

</html>