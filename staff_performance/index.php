<?php
ob_start();

$page_title = 'Staff Performance';
include('../header.php');

if ($atem_permission < 4 && !$_is_superadmin) {
    ob_end_clean();
    header('Location: /odb/atem/index.php');
    exit;
}

ob_end_flush();

// Build ODB lookup maps for filter dropdowns
$dept_names    = array();
$grade_labels  = array();
$struct_labels = array();

$dr = mysqli_query($conn, "SELECT id, depart_name FROM staff_department");
if ($dr) { while ($row = mysqli_fetch_assoc($dr)) { $dept_names[(int)$row['id']] = $row['depart_name']; } }

$gr = mysqli_query($conn, "SELECT id, grade_name FROM staff_grade ORDER BY id ASC");
if ($gr) { while ($row = mysqli_fetch_assoc($gr)) { $grade_labels[(int)$row['id']] = $row['grade_name']; } }

$str_r = mysqli_query($conn, "SELECT id, struct_name FROM staff_struct ORDER BY id ASC");
if ($str_r) { while ($row = mysqli_fetch_assoc($str_r)) { $struct_labels[(int)$row['id']] = $row['struct_name']; } }

// Build department options respecting grade
$dept_filter_options = array();
foreach ($dept_names as $did => $dname) {
    if ((int)$atem_permission >= 3) {
        $dept_filter_options[$did] = $dname;
    } elseif (isset($department) && (int)$department === $did) {
        $dept_filter_options[$did] = $dname;
    }
}

$init_month = (int)date('n');
$init_year  = max(2026, (int)date('Y'));
$year_options = array();
for ($y = 2026; $y <= $init_year; $y++) {
    $year_options[] = $y;
}
?>

<style>
.perf-count-link {
    color: #0d6efd;
    text-decoration: underline;
    text-underline-offset: 2px;
    font-weight: 500;
}

.perf-count-cell:hover .perf-count-link {
    color: #0a58ca;
}

.perf-role-cell {
    display: flex;
    flex-wrap: wrap;
    gap: 4px;
}

#atem-detail-tbody td:first-child,
#atem-detail-tbody td:nth-child(4),
#atem-detail-tbody td:nth-child(5) {
    white-space: nowrap;
}

#recalc-progress-wrap span {
    font-size: 12px;
}

#export-progress-wrap span { font-size: 12px; color: #6c757d; }

@keyframes atem-export-slide {
    0%   { transform: translateX(-150%); }
    100% { transform: translateX(400%); }
}
.atem-export-bar-anim {
    background: #198754;
    height: 100%;
    width: 35%;
    animation: atem-export-slide 1.2s ease-in-out infinite;
}
</style>

<!-- Filter Card -->
<div class="atem-card atem-filter mb-3">
    <h6 class="atem-card-title"><i class="bi bi-funnel"></i> Filter</h6>
    <div class="row g-2 mt-1 align-items-end">
        <div class="col-md-2 col-sm-4">
            <label class="form-label">Year</label>
            <select id="perf-filter-year" class="form-select form-select-sm">
                <?php foreach ($year_options as $y): ?>
                <option value="<?php echo $y; ?>" <?php echo ($y === $init_year) ? ' selected' : ''; ?>>
                    <?php echo $y; ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-2 col-sm-4">
            <label class="form-label">Month</label>
            <select id="perf-filter-month" class="form-select form-select-sm">
                <option value="0">All Month</option>
                <?php foreach (array(1=>'January',2=>'February',3=>'March',4=>'April',5=>'May',6=>'June',7=>'July',8=>'August',9=>'September',10=>'October',11=>'November',12=>'December') as $mn => $ml): ?>
                <option value="<?php echo $mn; ?>">
                    <?php echo htmlspecialchars($ml); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-2 col-sm-4">
            <label class="form-label">Quarter</label>
            <select id="perf-filter-quarter" class="form-select form-select-sm">
                <option value="0">All Quarter</option>
                <option value="1">Q1 (Jan-Mar)</option>
                <option value="2">Q2 (Apr-Jun)</option>
                <option value="3">Q3 (Jul-Sep)</option>
                <option value="4">Q4 (Oct-Dec)</option>
            </select>
        </div>
        <?php if (!empty($dept_filter_options)): ?>
        <div class="col-md-2 col-sm-6">
            <label class="form-label">Department</label>
            <select id="perf-filter-dept" class="form-select form-select-sm">
                <option value="0">All Department</option>
                <?php foreach ($dept_filter_options as $did => $dname): ?>
                <option value="<?php echo $did; ?>"><?php echo htmlspecialchars($dname); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php endif; ?>
        <div class="col-md-2 col-sm-6">
            <label class="form-label">Grade</label>
            <select id="perf-filter-grade" class="form-select form-select-sm">
                <option value="0">All Grade</option>
                <?php foreach ($grade_labels as $gid => $gname): ?>
                <option value="<?php echo $gid; ?>"><?php echo htmlspecialchars($gname); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-2 col-sm-6">
            <label class="form-label">Evaluation Structure</label>
            <select id="perf-filter-struct" class="form-select form-select-sm">
                <option value="0">All Evaluation Structure</option>
                <?php foreach ($struct_labels as $sid2 => $sname): ?>
                <option value="<?php echo $sid2; ?>"><?php echo htmlspecialchars($sname); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-auto d-flex align-items-end gap-2 ms-auto">
            <button class="btn btn-sm btn-primary" id="perf-apply-filter">Apply</button>
            <button class="btn btn-sm btn-outline-secondary" id="perf-reset-filter">Reset</button>
            <a id="perf-export-all-btn" href="#" class="btn btn-outline-success btn-sm">Export</a>
        </div>
    </div>
    <div class="mt-2 text-end">
        <span class="text-muted" id="perf-filter-label" style="font-size:12px;"></span>
    </div>
</div>

<!-- Action Buttons -->
<div class="d-flex gap-2 mb-3 justify-content-end">
    <button class="btn btn-outline-success btn-sm" id="export-selected-btn" disabled>Export Selected</button>
</div>

<div id="export-progress-wrap" style="display:none;margin-bottom:12px;max-width:400px;">
    <div style="margin-bottom:4px;">
        <span id="export-progress-msg">Preparing export...</span>
    </div>
    <div style="background:#e9ecef;border-radius:4px;height:8px;overflow:hidden;">
        <div class="atem-export-bar-anim"></div>
    </div>
</div>

<!-- Table -->
<div class="atem-card">
    <p class="mb-3 text-muted" id="perf-period-label"
        style="font-size:11px;text-transform:uppercase;letter-spacing:.06em;font-weight:600;">
        Staff Performance
    </p>
    <div class="table-responsive">
        <table class="table table-hover align-middle atem-view-tbl">
            <thead>
                <tr>
                    <th style="width:36px;"><input type="checkbox" id="select-all" title="Select all"></th>
                    <th>Staff Details</th>
                    <th>Grade</th>
                    <th>Evaluation Structure</th>
                    <th class="text-center" title="Click to view details">ATEM</th>
                    <th class="text-center" title="Click to view details">Complete</th>
                    <th class="text-center" title="Click to view details">Active</th>
                    <th class="text-center" title="Click to view details">Extend</th>
                    <th class="text-center" title="Click to view details">Failed</th>
                    <th class="text-end">Est. Reward</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody id="perf-tbody">
                <tr>
                    <td colspan="11" class="text-center text-muted py-4">Loading...</td>
                </tr>
            </tbody>
        </table>
    </div>
    <div class="atem-pager" id="perf-pager"></div>
</div>

<!-- ATEM Detail Modal -->
<div class="modal fade" id="atemDetailModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header py-2">
                <h6 class="modal-title mb-0" id="atem-detail-modal-title">ATEM Details</h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-0">
                <div id="atem-detail-loading" class="text-center py-4" style="display:none;">Loading...</div>
                <div id="atem-detail-error" class="text-danger px-3 py-2" style="display:none;"></div>
                <div class="table-responsive" id="atem-detail-table-wrap">
                    <table class="table table-hover align-middle atem-view-tbl mb-0">
                        <thead>
                            <tr>
                                <th>ATEM ID</th>
                                <th>Title</th>
                                <th>Level / Complexity</th>
                                <th>Start</th>
                                <th>End</th>
                                <th>Status</th>
                                <th>My Role</th>
                            </tr>
                        </thead>
                        <tbody id="atem-detail-tbody"></tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

</div><!-- /.atem-container -->

<script>
var PERF_CFG = <?php echo json_encode(array(
    'apiUrl'     => '/odb/atem/api.php',
    'permission' => $atem_permission,
    'initMonth'  => $init_month,
    'initYear'   => $init_year,
)); ?>;

var PERF_API_URL = PERF_CFG.apiUrl;

var PERF_COL_STATUSES = {
    'atem': null,
    'complete': ['Completed', 'Completed with Excellence'],
    'active': ['Active'],
    'extend': ['Extended'],
    'failed': ['Failed']
};
var PERF_COL_LABELS = {
    'atem': 'All ATEM',
    'complete': 'Completed',
    'active': 'Active',
    'extend': 'Extended',
    'failed': 'Failed'
};
var STATUS_COLOR = {
    'Draft': '#6c757d',
    'Active': '#0d6efd',
    'Completed': '#198754',
    'Completed with Excellence': '#0dcaf0',
    'Extended': '#fd7e14',
    'Failed': '#dc3545'
};
var MONTHS_LABEL = {
    1: 'January',
    2: 'February',
    3: 'March',
    4: 'April',
    5: 'May',
    6: 'June',
    7: 'July',
    8: 'August',
    9: 'September',
    10: 'October',
    11: 'November',
    12: 'December'
};
var QUARTERS_LABEL = {
    1: 'Q1 (Jan-Mar)',
    2: 'Q2 (Apr-Jun)',
    3: 'Q3 (Jul-Sep)',
    4: 'Q4 (Oct-Dec)'
};

var _currentPayload = {};
var _perfAllData = [];
var _perfPage = 1;
var _perfPerPage = 30;

function escHtml(s) {
    return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
}

function formatDate(s) {
    if (!s) {
        return '-';
    }
    var parts = s.split('-');
    if (parts.length === 3) {
        return parts[2] + '-' + parts[1] + '-' + parts[0];
    }
    return s;
}

function formatNumber(n) {
    var x = parseFloat(n) || 0;
    return x.toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
}

function buildPayload() {
    var year = parseInt(document.getElementById('perf-filter-year').value, 10) || PERF_CFG.initYear;
    var month = parseInt(document.getElementById('perf-filter-month').value, 10) || 0;
    var quarter = parseInt(document.getElementById('perf-filter-quarter').value, 10) || 0;
    var deptEl = document.getElementById('perf-filter-dept');
    var gradeEl = document.getElementById('perf-filter-grade');
    var structEl = document.getElementById('perf-filter-struct');
    var dept = deptEl ? (parseInt(deptEl.value, 10) || 0) : 0;
    var grade = gradeEl ? (parseInt(gradeEl.value, 10) || 0) : 0;
    var struct = structEl ? (parseInt(structEl.value, 10) || 0) : 0;
    if (quarter > 0) {
        month = 0;
    }
    return {
        year: year,
        month: month,
        quarter: quarter,
        dept: dept,
        grade: grade,
        struct: struct
    };
}

function buildLabel(payload) {
    if (payload.quarter > 0) {
        return (QUARTERS_LABEL[payload.quarter] || ('Q' + payload.quarter)) + ' ' + payload.year;
    }
    if (payload.month > 0) {
        return (MONTHS_LABEL[payload.month] || payload.month) + ' ' + payload.year;
    }
    return String(payload.year);
}

function updateExportSelected() {
    var checked = document.querySelectorAll('.perf-row-cb:checked');
    var btn = document.getElementById('export-selected-btn');
    if (btn) {
        btn.disabled = (checked.length === 0);
    }
}

function updateActionUrls(payload) {
    var month = payload.month || 0;
    var year = payload.year || PERF_CFG.initYear;
    var quarter = payload.quarter || 0;
    var dept = payload.dept || 0;
    var grade = payload.grade || 0;
    var struct = payload.struct || 0;

    var exportBtn = document.getElementById('perf-export-all-btn');
    if (exportBtn) {
        exportBtn.href = '/odb/atem/staff_performance/export.php?type=performance' +
            '&month=' + month + '&year=' + year + '&quarter=' + quarter +
            '&dept=' + dept + '&grade=' + grade + '&struct=' + struct;
    }

    var recalcBtn = document.getElementById('recalc-btn');
    if (recalcBtn) {
        recalcBtn.setAttribute('data-month', month > 0 ? month : PERF_CFG.initMonth);
        recalcBtn.setAttribute('data-year', year);
    }
}

function perfPageBtn(p) {
    return '<button type="button" class="atem-pager-btn' + (p === _perfPage ? ' active' : '') + '" data-page="' + p +
        '">' + p + '</button>';
}

function renderPerfPager(total) {
    var pager = document.getElementById('perf-pager');
    if (!pager) {
        return;
    }
    if (total === 0) {
        pager.innerHTML = '';
        return;
    }
    var pages = Math.max(1, Math.ceil(total / _perfPerPage));
    var startIdx = (_perfPage - 1) * _perfPerPage;
    var shown = Math.min(_perfPerPage, total - startIdx);

    var opts = [10, 30, 50, 100];
    var selHtml = '<select class="atem-perpage-select">';
    for (var oi = 0; oi < opts.length; oi++) {
        selHtml += '<option value="' + opts[oi] + '"' + (_perfPerPage === opts[oi] ? ' selected' : '') + '>' + opts[
            oi] + '</option>';
    }
    selHtml += '</select>';
    var leftHtml = '<div class="atem-pager-left">Show ' + selHtml + ' entries</div>';

    var info = '<span class="atem-pager-info">Showing ' + (startIdx + 1) + ' to ' + (startIdx + shown) + ' of ' +
        total + ' entries</span>';
    var btns = '<button type="button" class="atem-pager-btn" data-page="' + (_perfPage - 1) + '"' + (_perfPage <= 1 ?
        ' disabled' : '') + '>Previous</button>';

    var win = 2,
        pfrom = Math.max(1, _perfPage - win),
        pto = Math.min(pages, _perfPage + win);
    if (pfrom > 1) {
        btns += perfPageBtn(1) + (pfrom > 2 ? '<span class="atem-pager-gap">...</span>' : '');
    }
    for (var pp = pfrom; pp <= pto; pp++) {
        btns += perfPageBtn(pp);
    }
    if (pto < pages) {
        btns += (pto < pages - 1 ? '<span class="atem-pager-gap">...</span>' : '') + perfPageBtn(pages);
    }
    btns += '<button type="button" class="atem-pager-btn" data-page="' + (_perfPage + 1) + '"' + (_perfPage >= pages ?
        ' disabled' : '') + '>Next</button>';

    var rightHtml = '<div class="d-flex align-items-center gap-2">' + info + '<div class="atem-pager-bar">' + btns +
        '</div></div>';
    pager.innerHTML = leftHtml + rightHtml;
}

function renderTable(data, payload) {
    var tbody = document.getElementById('perf-tbody');
    var selectAll = document.getElementById('select-all');
    if (selectAll) {
        selectAll.checked = false;
    }
    updateExportSelected();

    if (!data || !data.length) {
        tbody.innerHTML =
            '<tr><td colspan="11" class="text-center text-muted py-4">No records found for this period.</td></tr>';
        renderPerfPager(0);
        return;
    }

    var total = data.length;
    var pages = Math.max(1, Math.ceil(total / _perfPerPage));
    if (_perfPage > pages) {
        _perfPage = pages;
    }
    var startIdx = (_perfPage - 1) * _perfPerPage;
    var pageData = data.slice(startIdx, startIdx + _perfPerPage);

    var month = (payload && payload.month) ? payload.month : 0;
    var year = (payload && payload.year) ? payload.year : PERF_CFG.initYear;

    function countCell(n) {
        return n > 0 ?
            '<span class="perf-count-link">' + n + '</span>' :
            '<span class="text-muted">0</span>';
    }

    var html = '';
    for (var i = 0; i < pageData.length; i++) {
        var rec = pageData[i];

        var editUrl = '/odb/atem/staff_performance/edit.php?id=' + rec.id + '&sid=' + rec.staff_id +
            '&month=' + month + '&year=' + year;
        var exportUrl = '/odb/atem/staff_performance/export.php?type=performance&ids=' + rec.id +
            '&month=' + month + '&year=' + year;

        html += '<tr>' +
            '<td><input type="checkbox" class="perf-row-cb" value="' + rec.id + '"></td>' +
            '<td>' +
            '<div style="font-weight:500;">' + escHtml(rec.staff_name) + '</div>' +
            '<div class="text-muted" style="font-size:11px;">' + escHtml(rec.dept_name) + '</div>' +
            '</td>' +
            '<td>' + escHtml(rec.grade_label) + '</td>' +
            '<td>' + escHtml(rec.struct_label) + '</td>' +
            '<td class="text-center perf-count-cell" data-staff-id="' + rec.staff_id +
            '" data-col="atem" style="cursor:pointer;">' + countCell(rec.total_atem) + '</td>' +
            '<td class="text-center perf-count-cell" data-staff-id="' + rec.staff_id +
            '" data-col="complete" style="cursor:pointer;">' + countCell(rec.complete_count) + '</td>' +
            '<td class="text-center perf-count-cell" data-staff-id="' + rec.staff_id +
            '" data-col="active" style="cursor:pointer;">' + countCell(rec.active_count) + '</td>' +
            '<td class="text-center perf-count-cell" data-staff-id="' + rec.staff_id +
            '" data-col="extend" style="cursor:pointer;">' + countCell(rec.extend_count) + '</td>' +
            '<td class="text-center perf-count-cell" data-staff-id="' + rec.staff_id +
            '" data-col="failed" style="cursor:pointer;">' + countCell(rec.failed_count) + '</td>' +
            '<td class="text-end">RM ' + formatNumber(rec.total_incentive) + '</td>' +
            '<td style="white-space:nowrap;">' +
            '<a class="btn btn-sm btn-outline-secondary me-1" href="' + escHtml(editUrl) + '">Edit</a>' +
            '<a class="btn btn-sm btn-outline-success perf-row-export" href="' + escHtml(exportUrl) + '">Export</a>' +
            '</td>' +
            '</tr>';
    }
    tbody.innerHTML = html;
    renderPerfPager(total);
}

function loadPerformance(payload) {
    _currentPayload = payload;

    var tbody = document.getElementById('perf-tbody');
    var labelEl = document.getElementById('perf-filter-label');
    var periodEl = document.getElementById('perf-period-label');
    var label = buildLabel(payload);

    tbody.innerHTML = '<tr><td colspan="11" class="text-center text-muted py-4">Loading...</td></tr>';
    if (labelEl) {
        labelEl.textContent = label;
    }
    if (periodEl) {
        periodEl.textContent = label + ' — Staff Performance';
    }

    updateActionUrls(payload);

    var body = {
        action: 'get-performance-list'
    };
    for (var k in payload) {
        if (payload.hasOwnProperty(k)) {
            body[k] = payload[k];
        }
    }

    fetch(PERF_API_URL, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify(body)
        })
        .then(function(r) {
            return r.json();
        })
        .then(function(res) {
            if (!res.success) {
                tbody.innerHTML = '<tr><td colspan="11" class="text-center text-danger py-3">' +
                    escHtml(res.message || 'Failed to load records.') + '</td></tr>';
                return;
            }
            _perfAllData = res.data || [];
            _perfPage = 1;
            renderTable(_perfAllData, payload);
        })
        .catch(function() {
            tbody.innerHTML =
                '<tr><td colspan="11" class="text-center text-danger py-3">Request failed. Please try again.</td></tr>';
        });
}

document.addEventListener('DOMContentLoaded', function() {

    // Initial load
    loadPerformance({
        year: PERF_CFG.initYear,
        month: 0,
        quarter: 0,
        dept: 0,
        grade: 0,
        struct: 0
    });

    // Apply filter
    document.getElementById('perf-apply-filter').addEventListener('click', function() {
        loadPerformance(buildPayload());
    });

    // Reset filter
    document.getElementById('perf-reset-filter').addEventListener('click', function() {
        document.getElementById('perf-filter-year').value = PERF_CFG.initYear;
        document.getElementById('perf-filter-month').value = PERF_CFG.initMonth;
        document.getElementById('perf-filter-quarter').value = '0';
        var deptEl = document.getElementById('perf-filter-dept');
        var gradeEl = document.getElementById('perf-filter-grade');
        var structEl = document.getElementById('perf-filter-struct');
        if (deptEl) {
            deptEl.value = '0';
        }
        if (gradeEl) {
            gradeEl.value = '0';
        }
        if (structEl) {
            structEl.value = '0';
        }
        loadPerformance({
            year: PERF_CFG.initYear,
            month: 0,
            quarter: 0,
            dept: 0,
            grade: 0,
            struct: 0
        });
    });

    // Month / quarter mutual exclusion
    document.getElementById('perf-filter-month').addEventListener('change', function() {
        if (this.value && this.value !== '0') {
            document.getElementById('perf-filter-quarter').value = '0';
        }
    });
    document.getElementById('perf-filter-quarter').addEventListener('change', function() {
        if (this.value && this.value !== '0') {
            document.getElementById('perf-filter-month').value = '0';
        }
    });

    // Select-all checkbox
    var selectAll = document.getElementById('select-all');
    if (selectAll) {
        selectAll.addEventListener('change', function() {
            var cbs = document.querySelectorAll('.perf-row-cb');
            for (var i = 0; i < cbs.length; i++) {
                cbs[i].checked = selectAll.checked;
            }
            updateExportSelected();
        });
    }

    // Row checkbox change (event delegation)
    document.addEventListener('change', function(e) {
        if (e.target && e.target.classList.contains('perf-row-cb')) {
            updateExportSelected();
            var cbs = document.querySelectorAll('.perf-row-cb');
            var allChecked = cbs.length > 0;
            for (var i = 0; i < cbs.length; i++) {
                if (!cbs[i].checked) {
                    allChecked = false;
                    break;
                }
            }
            if (selectAll) {
                selectAll.checked = allChecked;
            }
        }
    });

    // Export selected
    var exportSelectedBtn = document.getElementById('export-selected-btn');
    if (exportSelectedBtn) {
        exportSelectedBtn.addEventListener('click', function() {
            var checked = document.querySelectorAll('.perf-row-cb:checked');
            if (!checked.length) {
                return;
            }
            var ids = [];
            for (var i = 0; i < checked.length; i++) {
                ids.push(checked[i].value);
            }
            var month = _currentPayload.month || 0;
            var year = _currentPayload.year || PERF_CFG.initYear;
            var quarter = _currentPayload.quarter || 0;
            var qs = 'type=performance&ids=' + ids.join(',') +
                '&month=' + month + '&year=' + year + '&quarter=' + quarter;
            triggerExport('/odb/atem/staff_performance/export.php?' + qs);
        });
    }

    // Count cell click -> ATEM detail modal
    var detailModal = document.getElementById('atemDetailModal');
    var bsDetailModal = new bootstrap.Modal(detailModal);

    document.addEventListener('click', function(e) {
        var cell = e.target.closest('.perf-count-cell');
        if (!cell) {
            return;
        }
        var targetSid = parseInt(cell.getAttribute('data-staff-id'), 10);
        var col = cell.getAttribute('data-col');
        if (!targetSid) {
            return;
        }

        var modalTitle = document.getElementById('atem-detail-modal-title');
        var loading = document.getElementById('atem-detail-loading');
        var errorEl = document.getElementById('atem-detail-error');
        var tableWrap = document.getElementById('atem-detail-table-wrap');
        var tbody = document.getElementById('atem-detail-tbody');

        modalTitle.textContent = PERF_COL_LABELS[col] || 'ATEM Details';
        loading.style.display = 'block';
        tableWrap.style.display = 'none';
        errorEl.style.display = 'none';
        tbody.innerHTML = '';
        bsDetailModal.show();

        fetch(PERF_API_URL, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({
                    action: 'get-staff-atem-list',
                    target_staff_id: targetSid
                })
            })
            .then(function(r) {
                return r.json();
            })
            .then(function(res) {
                loading.style.display = 'none';
                if (!res.success) {
                    errorEl.textContent = res.message || 'Failed to load ATEM data.';
                    errorEl.style.display = 'block';
                    return;
                }
                var data = res.data || [];
                var statuses = PERF_COL_STATUSES[col];
                if (statuses !== null) {
                    data = data.filter(function(r) {
                        return statuses.indexOf(r.status) !== -1;
                    });
                }
                var ROLE_COLOR = {
                    'Issuer': '#198754',
                    'A': '#6610f2',
                    'R': '#0d6efd',
                    'C': '#fd7e14',
                    'I': '#6c757d'
                };
                if (!data.length) {
                    tbody.innerHTML =
                        '<tr><td colspan="7" class="text-center text-muted py-3">No records.</td></tr>';
                } else {
                    var html = '';
                    for (var i = 0; i < data.length; i++) {
                        var row = data[i];
                        var color = STATUS_COLOR[row.status] || '#6c757d';
                        var statusBadge = '<span class="atem-pill" style="background-color:' + escHtml(color) + ';">' +
                            escHtml(row.status || '-') + '</span>';
                        var endDate = row.is_extended && row.extended_date_1 ? formatDate(row
                            .extended_date_1) : formatDate(row.end_date);
                        var roleCell = '-';
                        if (row.my_role && row.my_role.length) {
                            var badges = [];
                            for (var ri = 0; ri < row.my_role.length; ri++) {
                                var rname = row.my_role[ri];
                                var rc = ROLE_COLOR[rname] || '#6c757d';
                                badges.push('<span class="atem-pill" style="background-color:' + escHtml(rc) + ';">' +
                                    escHtml(rname) + '</span>');
                            }
                            roleCell = '<div class="perf-role-cell">' + badges.join('') + '</div>';
                        }
                        html += '<tr>' +
                            '<td>#AT' + row.id + '</td>' +
                            '<td>' + escHtml(row.title) + '</td>' +
                            '<td>' + escHtml(row.level_label || '-') + '</td>' +
                            '<td>' + formatDate(row.start_date) + '</td>' +
                            '<td>' + endDate + '</td>' +
                            '<td>' + statusBadge + '</td>' +
                            '<td>' + roleCell + '</td>' +
                            '</tr>';
                    }
                    tbody.innerHTML = html;
                }
                tableWrap.style.display = 'block';
            })
            .catch(function() {
                loading.style.display = 'none';
                errorEl.textContent = 'Request failed. Please try again.';
                errorEl.style.display = 'block';
            });
    });

    // Perf table pager
    var perfPager = document.getElementById('perf-pager');
    if (perfPager) {
        perfPager.addEventListener('click', function(e) {
            var btn = e.target.closest ? e.target.closest('.atem-pager-btn') : null;
            if (btn && !btn.disabled) {
                var p = parseInt(btn.getAttribute('data-page'), 10);
                if (p >= 1) {
                    _perfPage = p;
                    renderTable(_perfAllData, _currentPayload);
                }
            }
        });
        perfPager.addEventListener('change', function(e) {
            if (e.target.classList.contains('atem-perpage-select')) {
                _perfPerPage = parseInt(e.target.value, 10);
                _perfPage = 1;
                renderTable(_perfAllData, _currentPayload);
            }
        });
    }

    // Export progress helper
    function triggerExport(url) {
        var token = Date.now().toString(36) + Math.random().toString(36).substr(2, 4);
        var sep   = url.indexOf('?') >= 0 ? '&' : '?';
        var wrap  = document.getElementById('export-progress-wrap');
        var msg   = document.getElementById('export-progress-msg');
        if (wrap) { wrap.style.display = 'block'; }
        if (msg)  { msg.textContent = 'Preparing export...'; }

        window.location.href = url + sep + 'dl_token=' + token;

        var cookieName = 'export_done_' + token;
        var pollTimer  = setInterval(function() {
            if (document.cookie.indexOf(cookieName) >= 0) {
                clearInterval(pollTimer);
                document.cookie = cookieName + '=;expires=Thu, 01 Jan 1970 00:00:00 GMT;path=/';
                if (msg) { msg.textContent = 'Done.'; }
                setTimeout(function() { if (wrap) { wrap.style.display = 'none'; } }, 1500);
            }
        }, 500);
        setTimeout(function() { clearInterval(pollTimer); if (wrap) { wrap.style.display = 'none'; } }, 60000);
    }

    var exportAllBtn = document.getElementById('perf-export-all-btn');
    if (exportAllBtn) {
        exportAllBtn.addEventListener('click', function(e) {
            e.preventDefault();
            triggerExport(this.href);
        });
    }

    document.addEventListener('click', function(e) {
        var a = e.target.closest ? e.target.closest('.perf-row-export') : null;
        if (a) { e.preventDefault(); triggerExport(a.href); }
    });

});

</script>

<?php include('../footer.php'); ?>