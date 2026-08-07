<?php
ob_start();

$page_title = 'Access Control';
$extra_css  = '<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet">';

include('../header.php');

if ($atem_permission < 1 && !$_is_superadmin) {
    ob_end_clean();
    header('Location: ' . ATEM_BASE . 'index.php');
    exit;
}

ob_end_flush();

// Grade labels are no longer hardcoded here - they're loaded client-side
// from staff_grade via getLibrary (see js/admin_access.js), the same table
// masterlist.php's "+ Add Grade" writes to, so newly added grades show up
// in the edit form without a code change.
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
$table_cols_hq      = $show_edit ? 5 : 4;
$table_cols_outlet  = $show_edit ? 5 : 4;
$requester_dept_ids = array();

if (isset($department) && $department !== '') {
    foreach (explode(',', (string)$department) as $_rd) {
        $_rd = (int)trim($_rd);
        if ($_rd > 0) {
            $requester_dept_ids[] = $_rd;
        }
    }
}

// Grade 1 and 2 users only ever belong to one side (HQ or Outlet, per their
// own department), so showing both tabs is misleading noise - collapse to the
// single matching tab, like the pre-tab single-view page. Grade 3+ and
// SuperAdmin keep seeing both tabs as today (deferred to a future task).
// Dev-simulated grade 1/2 (via atem_dev_role_override) is included here since
// $atem_permission/$department already reflect the simulation by this point.
$grade1_single_view = null;
if (((int)$atem_permission === 1 || (int)$atem_permission === 2) && !$_is_superadmin) {
    $grade1_single_view = in_array(1, $requester_dept_ids, true) ? 'outlet' : 'hq';
}

$dept_filter_options = array();
if ($atem_permission >= 2 || $_is_superadmin) {
    $all_depts_r = mysqli_query($conn, "SELECT id, depart_name FROM staff_department ORDER BY depart_name ASC");
    if ($all_depts_r) {
        while ($dr = mysqli_fetch_assoc($all_depts_r)) {
            $did = (int)$dr['id'];
            if ($atem_permission >= 4 || $_is_superadmin) {
                $dept_filter_options[$did] = $dr['depart_name'];
            } elseif (in_array($did, $requester_dept_ids)) {
                $dept_filter_options[$did] = $dr['depart_name'];
            }
        }
    }
}

$outlet_filter_options = array();
if ($atem_permission >= 2 || $_is_superadmin) {
    $all_outlets_r = mysqli_query($conn, "SELECT id, code FROM outlet ORDER BY code ASC");
    if ($all_outlets_r) {
        while ($or = mysqli_fetch_assoc($all_outlets_r)) {
            $outlet_filter_options[(int)$or['id']] = $or['code'];
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

<div class="row g-4">

    <!-- Left: staff table (tabbed) -->
    <div class="<?php echo $show_edit ? 'col-md-7' : 'col-md-12'; ?>">

        <?php if ($grade1_single_view === null): ?>
        <ul class="nav nav-tabs atem-view-tabs mb-3" id="ac-view-tabs" role="tablist">
            <li class="nav-item" role="presentation">
                <button class="nav-link active atem-tab-color-hq" id="ac-tab-hq-btn" data-bs-toggle="tab"
                    data-bs-target="#ac-tab-hq" type="button" role="tab" aria-controls="ac-tab-hq"
                    aria-selected="true">
                    <i class="bi bi-building"></i> HQ ATEM
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link atem-tab-color-outlet" id="ac-tab-outlet-btn" data-bs-toggle="tab"
                    data-bs-target="#ac-tab-outlet" type="button" role="tab" aria-controls="ac-tab-outlet"
                    aria-selected="false">
                    <i class="bi bi-shop"></i> Outlet ATEM
                </button>
            </li>
        </ul>
        <?php endif; ?>

        <div class="tab-content">

            <!-- HQ ATEM pane -->
            <div class="tab-pane fade<?php echo ($grade1_single_view === null || $grade1_single_view === 'hq') ? ' show active' : ''; ?>"
                id="ac-tab-hq" role="tabpanel" aria-labelledby="ac-tab-hq-btn">

                <?php if ($atem_permission >= 2 || $_is_superadmin): ?>
                <div class="atem-card atem-filter mb-3">
                    <h6 class="atem-card-title"><i class="bi bi-funnel"></i> Filter</h6>
                    <div class="row g-2 mt-1 align-items-end">
                        <?php if (!empty($dept_filter_options)): ?>
                        <div class="col-md-4 col-sm-6">
                            <label class="form-label">Department</label>
                            <select id="ac-filter-dept" class="form-select form-select-sm">
                                <option value="0">All Department</option>
                                <?php foreach ($dept_filter_options as $did => $dname): ?>
                                <option value="<?php echo $did; ?>"><?php echo htmlspecialchars($dname); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <?php endif; ?>
                        <div class="col-md-4 col-sm-6">
                            <label class="form-label">Staff Name</label>
                            <input type="text" id="ac-filter-name" class="form-control form-control-sm"
                                placeholder="Search name...">
                        </div>
                        <div class="col-auto d-flex align-items-end gap-2">
                            <button class="btn btn-sm btn-outline-secondary" id="ac-reset-filter">Reset</button>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

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
                                    <td colspan="<?php echo $table_cols_hq; ?>" class="text-center text-muted py-3">
                                        Loading...</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <div class="atem-pager" id="admin-staff-pager"
                        style="margin-top:12px;padding-top:12px;border-top:1px solid #e9ecef;"></div>
                </div>
            </div>

            <!-- Outlet ATEM pane -->
            <div class="tab-pane fade<?php echo ($grade1_single_view === 'outlet') ? ' show active' : ''; ?>"
                id="ac-tab-outlet" role="tabpanel" aria-labelledby="ac-tab-outlet-btn">

                <?php if ($atem_permission >= 2 || $_is_superadmin): ?>
                <div class="atem-card atem-filter mb-3">
                    <h6 class="atem-card-title"><i class="bi bi-funnel"></i> Filter</h6>
                    <div class="row g-2 mt-1 align-items-end">
                        <div class="col-md-4 col-sm-6">
                            <label class="form-label">Outlet</label>
                            <div class="vf-issuer-wrap" id="aco-filter-outlet-wrap">
                                <div class="vf-s2-selection" id="aco-filter-outlet-btn" tabindex="0">All Outlets</div>
                                <div class="vf-s2-dropdown" id="aco-filter-outlet-dropdown">
                                    <div class="vf-s2-search-wrap">
                                        <input class="vf-s2-search" id="aco-filter-outlet-search" type="search"
                                            placeholder="Search outlet...">
                                    </div>
                                    <ul class="vf-s2-list" id="aco-filter-outlet-list"></ul>
                                </div>
                                <input type="hidden" id="aco-filter-outlet-value" value="0">
                            </div>
                        </div>
                        <div class="col-md-4 col-sm-6">
                            <label class="form-label">Staff Name</label>
                            <input type="text" id="aco-filter-name" class="form-control form-control-sm"
                                placeholder="Search name...">
                        </div>
                        <div class="col-auto d-flex align-items-end gap-2">
                            <button class="btn btn-sm btn-outline-secondary" id="aco-reset-filter">Reset</button>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

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
                                    <th>Outlet</th>
                                    <th>Grade</th>
                                    <th>Evaluation Structure</th>
                                    <?php if ($show_edit): ?><th></th><?php endif; ?>
                                </tr>
                            </thead>
                            <tbody id="aco-staff-tbody">
                                <tr>
                                    <td colspan="<?php echo $table_cols_outlet; ?>"
                                        class="text-center text-muted py-3">Loading...</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <div class="atem-pager" id="aco-staff-pager"
                        style="margin-top:12px;padding-top:12px;border-top:1px solid #e9ecef;"></div>
                </div>
            </div>

        </div><!-- /.tab-content -->
    </div>

    <?php if ($show_edit): ?>
    <!-- Right: update form -->
    <div class="col-md-5" id="update-form-col">

        <div class="bento-card">
            <p class="mb-3 text-muted"
                style="font-size: 11px; text-transform: uppercase; letter-spacing: .06em; font-weight: 600;">
                Create/Update
                Staff Grade and Evaluation Structure</p>

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
                <p class="mb-1"><strong>Outlet:</strong></p>
                <ul id="info-outlet" class="mb-2 ps-3"></ul>
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
                <div id="grade-radio-list"></div>
                <p id="grade-none-msg" class="text-muted mb-0" style="display:none; font-size:12px; margin-top:4px;">No
                    grades defined.</p>
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
var GRADE_LABELS = {};
var GRADE_BADGES = <?php echo json_encode($grade_badges); ?>;
var REQUESTER_GRADE = <?php echo (int)$atem_permission; ?>;
var REQUESTER_DEPT_IDS = <?php echo json_encode(array_values($requester_dept_ids)); ?>;
var IS_SUPERADMIN = <?php echo $_is_superadmin ? 'true' : 'false'; ?>;
var SHOW_EDIT = <?php echo $show_edit ? 'true' : 'false'; ?>;
var TAB_SINGLE_VIEW = <?php echo json_encode($grade1_single_view); ?>;
var TABLE_COLS = <?php echo $table_cols_hq; ?>;
var TABLE_COLS_OUTLET = <?php echo $table_cols_outlet; ?>;
var BACKEND_URL = <?php echo json_encode(ATEM_BASE . 'access_control/backend.php'); ?>;
var HAS_DEPT_FILTER = <?php echo !empty($dept_filter_options) ? 'true' : 'false'; ?>;
var OUTLET_FILTER_OPTIONS = <?php
    $_outlet_opts = array();
    foreach ($outlet_filter_options as $oid => $ocode) {
        $_outlet_opts[] = array('id' => $oid, 'name' => $ocode);
    }
    echo json_encode($_outlet_opts);
?>;
</script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<script src="<?php echo ATEM_BASE; ?>js/admin_access.js?v=<?php echo time(); ?>"></script>
</body>

</html>