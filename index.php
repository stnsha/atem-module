<?php
$page_title = 'Dashboard Overview';
include('header.php');

$_dash_cur_year = max(2026, (int)date('Y'));
$dash_year_options = array();
for ($y = 2026; $y <= $_dash_cur_year; $y++) {
    $dash_year_options[] = $y;
}

$dash_dept_options = array();
$_dept_res = mysqli_query($conn, "SELECT id, depart_name FROM staff_department ORDER BY depart_name");
if ($_dept_res) {
    while ($_drow = mysqli_fetch_assoc($_dept_res)) {
        if ((int)$atem_permission >= 3) {
            $dash_dept_options[] = array('id' => (int)$_drow['id'], 'name' => $_drow['depart_name']);
        } elseif ((int)$atem_permission === 2 && (int)$_drow['id'] === (int)$department) {
            $dash_dept_options[] = array('id' => (int)$_drow['id'], 'name' => $_drow['depart_name']);
        }
    }
}
?>

<script>
window.ATEM_DASH = <?php echo json_encode(array(
    'apiUrl'      => 'atem/api.php',
    'departments' => $dash_dept_options,
)); ?>;
</script>

<!-- Filter card -->
<div class="atem-card atem-filter mb-3">
    <h6 class="atem-card-title"><i class="bi bi-funnel"></i> Filter</h6>
    <div class="row g-2 mt-1 align-items-end">
        <div class="col-md-2 col-sm-6">
            <label class="form-label">Year</label>
            <select id="dash-filter-year" class="form-select form-select-sm">
                <option value="">All Years</option>
                <?php foreach ($dash_year_options as $y): ?>
                <option value="<?php echo $y; ?>"<?php echo ($y === 2026) ? ' selected' : ''; ?>><?php echo $y; ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-2 col-sm-6">
            <label class="form-label">Month</label>
            <select id="dash-filter-month" class="form-select form-select-sm">
                <option value="">All Months</option>
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
        <div class="col-md-2 col-sm-6">
            <label class="form-label">Quarter</label>
            <select id="dash-filter-quarter" class="form-select form-select-sm">
                <option value="">All Quarters</option>
                <option value="1">Q1 (Jan &ndash; Mar)</option>
                <option value="2">Q2 (Apr &ndash; Jun)</option>
                <option value="3">Q3 (Jul &ndash; Sep)</option>
                <option value="4">Q4 (Oct &ndash; Dec)</option>
            </select>
        </div>
        <div class="col-md-3 col-sm-6" id="dash-dept-col"<?php if (empty($dash_dept_options)) { echo ' style="display:none;"'; } ?>>
            <label class="form-label">Department</label>
            <select id="dash-filter-dept" class="form-select form-select-sm">
                <option value="">All Departments</option>
            </select>
        </div>
        <div class="col-md-3 col-sm-12 d-flex align-items-end gap-2">
            <button class="btn btn-sm btn-primary" id="dash-apply-filter">Apply</button>
            <button class="btn btn-sm btn-outline-secondary" id="dash-reset-filter">Reset</button>
        </div>
    </div>
    <div class="mt-2">
        <span class="text-muted" id="dash-filter-label" style="font-size:12px;"></span>
    </div>
</div>

<!-- Stat Cards -->
<div class="row g-3 mb-4">
    <div class="col-12 col-sm-6 col-xl">
        <div class="atem-card atem-dash-stat h-100" style="cursor:pointer;">
            <div class="atem-card-title mb-1">Total ATEM Cards</div>
            <div class="atem-stat-value atem-stat-value--blue" id="dash-total">---</div>
            <div class="atem-stat-label">created YTD</div>
        </div>
    </div>
    <div class="col-12 col-sm-6 col-xl">
        <div class="atem-card atem-dash-stat h-100" data-status="Active" style="cursor:pointer;">
            <div class="atem-card-title mb-1">Active / On Hand</div>
            <div class="atem-stat-value atem-stat-value--blue" id="dash-active">---</div>
            <div class="atem-stat-label">not yet closed</div>
        </div>
    </div>
    <div class="col-12 col-sm-6 col-xl">
        <div class="atem-card atem-dash-stat h-100" data-status="Completed" style="cursor:pointer;">
            <div class="atem-card-title mb-1">Complete + Excellence</div>
            <div class="atem-stat-value atem-stat-value--green" id="dash-closed">---</div>
            <div class="atem-stat-label">eligible for completion count</div>
        </div>
    </div>
    <div class="col-12 col-sm-6 col-xl">
        <div class="atem-card atem-dash-stat h-100" data-status="Failed" style="cursor:pointer;">
            <div class="atem-card-title mb-1">Failed ATEM</div>
            <div class="atem-stat-value atem-stat-value--red" id="dash-failed">---</div>
            <div class="atem-stat-label" id="dash-fail-rate">failure rate</div>
        </div>
    </div>
    <div class="col-12 col-sm-6 col-xl">
        <div class="atem-card atem-dash-stat h-100" style="cursor:pointer;">
            <div class="atem-card-title mb-1">Overdue Cards</div>
            <div class="atem-stat-value atem-stat-value--red" id="dash-overdue">---</div>
            <div class="atem-stat-label">active/extended past end date</div>
        </div>
    </div>
    <div class="col-12 col-sm-6 col-xl">
        <div class="atem-card atem-dash-stat h-100" style="cursor:pointer;">
            <div class="atem-card-title mb-1">Est. Incentive Forecast</div>
            <div class="atem-stat-value atem-stat-value--orange" id="dash-incentive">---</div>
            <div class="atem-stat-label">Level 2-4 payout</div>
        </div>
    </div>
</div>

<!-- Level table + Bar chart -->
<div class="row g-3">
    <div class="col-lg-6">
        <div class="atem-card h-100">
            <h6 class="atem-card-title mb-0">ATEM Complexity Reward</h6>
            <div class="text-muted mb-3" style="font-size:12px;padding-top:4px;">Level 1 RM0; Level 2-4 follow incentive
                value</div>
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                    <thead>
                        <tr>
                            <th style="font-size:12px;text-align:left;">Level</th>
                            <th style="font-size:12px;text-align:left;">Cards</th>
                            <th style="font-size:12px;text-align:left;">Complete</th>
                            <th style="font-size:12px;text-align:left;">Excellence</th>
                            <th style="font-size:12px;text-align:left;">Fail</th>
                            <th style="font-size:12px;text-align:left;">Forecast</th>
                        </tr>
                    </thead>
                    <tbody id="dash-level-body">
                        <tr>
                            <td colspan="6" class="text-muted" style="font-size:12px;">Loading...</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <div class="col-lg-6">
        <div class="atem-card h-100">
            <h6 class="atem-card-title mb-0">Closure &amp; Failure Analysis</h6>
            <div class="text-muted mb-3" style="font-size:12px;padding-top:4px;">Issuer-only closure: Complete,
                Excellence, Extend, Fail</div>
            <div id="dash-bars">
                <div class="atem-bar-row">
                    <div class="atem-bar-label">Excellence</div>
                    <div class="atem-bar-track">
                        <div class="atem-bar-fill" id="bar-excellence" style="width:0%;background:#198754;"></div>
                    </div>
                    <div class="atem-bar-count" id="bar-excellence-n">-</div>
                </div>
                <div class="atem-bar-row">
                    <div class="atem-bar-label">Complete</div>
                    <div class="atem-bar-track">
                        <div class="atem-bar-fill" id="bar-complete" style="width:0%;background:#0d6efd;"></div>
                    </div>
                    <div class="atem-bar-count" id="bar-complete-n">-</div>
                </div>
                <div class="atem-bar-row">
                    <div class="atem-bar-label">Extended</div>
                    <div class="atem-bar-track">
                        <div class="atem-bar-fill" id="bar-extended" style="width:0%;background:#fd7e14;"></div>
                    </div>
                    <div class="atem-bar-count" id="bar-extended-n">-</div>
                </div>
                <div class="atem-bar-row">
                    <div class="atem-bar-label">Fail</div>
                    <div class="atem-bar-track">
                        <div class="atem-bar-fill" id="bar-failed" style="width:0%;background:#dc3545;"></div>
                    </div>
                    <div class="atem-bar-count" id="bar-failed-n">-</div>
                </div>
            </div>
            <div class="text-muted mt-3" style="font-size:11px;">Critical CEO use: identify failure rate by department,
                issuer, level and month.</div>
        </div>
    </div>
</div>

<!-- Department Breakdown -->
<div class="row g-3 mt-0">
    <div class="col-12">
        <div class="atem-card">
            <h6 class="atem-card-title mb-0">Department Breakdown</h6>
            <div class="text-muted mb-3" style="font-size:12px;padding-top:4px;">Cards, outcomes and incentive forecast by issuer department</div>
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                    <thead>
                        <tr>
                            <th style="font-size:12px;text-align:left;">Department</th>
                            <th style="font-size:12px;text-align:left;">Cards</th>
                            <th style="font-size:12px;text-align:left;">Complete</th>
                            <th style="font-size:12px;text-align:left;">Excellence</th>
                            <th style="font-size:12px;text-align:left;">Fail</th>
                            <th style="font-size:12px;text-align:left;">Fail Rate</th>
                            <th style="font-size:12px;text-align:left;">Forecast</th>
                        </tr>
                    </thead>
                    <tbody id="dash-dept-body">
                        <tr><td colspan="7" class="text-muted" style="font-size:12px;">Loading...</td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<?php
$page_js = 'atem/js/index.js';
include('footer.php');
?>
