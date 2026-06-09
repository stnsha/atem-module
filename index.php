<?php
$page_title = 'Dashboard';
ob_start();
?>
<div class="d-flex flex-column align-items-end gap-1">
    <div class="d-flex align-items-center flex-wrap gap-2">
        <select id="dash-filter-year" class="form-select form-select-sm" style="width:auto;">
            <option value="">All Years</option>
            <option value="2026" selected>2026</option>
            <option value="2025">2025</option>
        </select>
        <select id="dash-filter-period" class="form-select form-select-sm" style="width:auto;">
            <option value="">All Months</option>
            <option value="m:1">January</option>
            <option value="m:2">February</option>
            <option value="m:3">March</option>
            <option value="m:4">April</option>
            <option value="m:5">May</option>
            <option value="m:6">June</option>
            <option value="m:7">July</option>
            <option value="m:8">August</option>
            <option value="m:9">September</option>
            <option value="m:10">October</option>
            <option value="m:11">November</option>
            <option value="m:12">December</option>
            <option value="q:1">Q1 (Jan - Mar)</option>
            <option value="q:2">Q2 (Apr - Jun)</option>
            <option value="q:3">Q3 (Jul - Sep)</option>
            <option value="q:4">Q4 (Oct - Dec)</option>
        </select>
        <button class="btn btn-sm btn-primary" id="dash-apply-filter">Apply</button>
        <button class="btn btn-sm btn-outline-secondary" id="dash-reset-filter">Reset</button>
    </div>
    <span class="text-muted" id="dash-filter-label" style="font-size:12px;"></span>
</div>
<?php
$page_title_actions = ob_get_clean();
include('header.php');
?>

<script>
window.ATEM_DASH = <?php echo json_encode(array('apiUrl' => 'atem/api.php')); ?>;
</script>

<!-- Stat Cards -->
<div class="row g-3 mb-4">
    <div class="col-12 col-sm-6 col-xl">
        <div class="atem-card h-100">
            <div class="atem-card-title mb-1">Total ATEM Cards</div>
            <div class="atem-stat-value atem-stat-value--blue" id="dash-total">---</div>
            <div class="atem-stat-label">created YTD</div>
        </div>
    </div>
    <div class="col-12 col-sm-6 col-xl">
        <div class="atem-card h-100">
            <div class="atem-card-title mb-1">Active / On Hand</div>
            <div class="atem-stat-value atem-stat-value--blue" id="dash-active">---</div>
            <div class="atem-stat-label">not yet closed</div>
        </div>
    </div>
    <div class="col-12 col-sm-6 col-xl">
        <div class="atem-card h-100">
            <div class="atem-card-title mb-1">Complete + Excellence</div>
            <div class="atem-stat-value atem-stat-value--green" id="dash-closed">---</div>
            <div class="atem-stat-label">eligible for completion count</div>
        </div>
    </div>
    <div class="col-12 col-sm-6 col-xl">
        <div class="atem-card h-100">
            <div class="atem-card-title mb-1">Failed ATEM</div>
            <div class="atem-stat-value atem-stat-value--red" id="dash-failed">---</div>
            <div class="atem-stat-label" id="dash-fail-rate">failure rate</div>
        </div>
    </div>
    <div class="col-12 col-sm-6 col-xl">
        <div class="atem-card h-100">
            <div class="atem-card-title mb-1">Incentive Forecast</div>
            <div class="atem-stat-value atem-stat-value--orange" id="dash-incentive">---</div>
            <div class="atem-stat-label">Level 2-4 payout</div>
        </div>
    </div>
</div>

<!-- Level table + Bar chart -->
<div class="row g-3">
    <div class="col-lg-6">
        <div class="atem-card h-100">
            <h6 class="atem-card-title mb-0">ATEM by Level</h6>
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
                    <div class="atem-bar-label">Complete</div>
                    <div class="atem-bar-track">
                        <div class="atem-bar-fill" id="bar-complete" style="width:0%;background:#198754;"></div>
                    </div>
                    <div class="atem-bar-count" id="bar-complete-n">-</div>
                </div>
                <div class="atem-bar-row">
                    <div class="atem-bar-label">Excellence</div>
                    <div class="atem-bar-track">
                        <div class="atem-bar-fill" id="bar-excellence" style="width:0%;background:#0d6efd;"></div>
                    </div>
                    <div class="atem-bar-count" id="bar-excellence-n">-</div>
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

<?php
$page_js = 'atem/js/index.js';
include('footer.php');
?>