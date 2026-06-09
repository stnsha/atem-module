<?php
ob_start();

$page_title = 'Staff Performance';
include('header.php');

if ($atem_permission < 3) {
    ob_end_clean();
    header('Location: /odb/atem/index.php');
    exit;
}

ob_end_flush();

// Filter params
$filter_month = isset($_GET['month']) ? (int)$_GET['month'] : (int)date('n');
$filter_year  = isset($_GET['year'])  ? (int)$_GET['year']  : (int)date('Y');
if ($filter_month < 1 || $filter_month > 12) { $filter_month = (int)date('n'); }
if ($filter_year  < 2000)                    { $filter_year  = (int)date('Y'); }

// Build ODB lookup maps
$staff_names   = array();
$dept_names    = array();
$grade_labels  = array();
$struct_labels = array();

$sr = mysqli_query($conn, "SELECT id, nama_staff FROM staff WHERE recycle != 1");
if ($sr) { while ($row = mysqli_fetch_assoc($sr)) { $staff_names[(int)$row['id']] = $row['nama_staff']; } }

$dr = mysqli_query($conn, "SELECT id, depart_name FROM staff_department");
if ($dr) { while ($row = mysqli_fetch_assoc($dr)) { $dept_names[(int)$row['id']] = $row['depart_name']; } }

$gr = mysqli_query($conn, "SELECT id, grade_name FROM staff_grade ORDER BY id ASC");
if ($gr) { while ($row = mysqli_fetch_assoc($gr)) { $grade_labels[(int)$row['id']] = $row['grade_name']; } }

$str_r = mysqli_query($conn, "SELECT id, struct_name FROM staff_struct ORDER BY id ASC");
if ($str_r) { while ($row = mysqli_fetch_assoc($str_r)) { $struct_labels[(int)$row['id']] = $row['struct_name']; } }

// Fetch bonus eligibility records from API
define('API_JWT_INCLUDED', true);
include(dirname(__FILE__) . '/api.php');

$bonus_result = getBonusEligibilityList($filter_month, $filter_year, null, $staff_id);
$records      = (!empty($bonus_result['success']) && isset($bonus_result['data'])) ? $bonus_result['data'] : array();
$api_unavailable = empty($bonus_result['success']);

$month_names = array(
    1 => 'January', 2 => 'February', 3 => 'March',    4 => 'April',
    5 => 'May',     6 => 'June',     7 => 'July',      8 => 'August',
    9 => 'September', 10 => 'October', 11 => 'November', 12 => 'December'
);
?>

<?php if ($api_unavailable): ?>
<div class="alert alert-warning" style="font-size:13px;">Unable to reach the ATEM API. Please try again later.</div>
<?php endif; ?>

<!-- Filters -->
<div class="bento-card mb-3">
    <form method="GET" action="atem/staff_performance.php" class="row g-2 align-items-end">
        <div class="col-auto">
            <label class="form-label" style="font-size:12px;">Month</label>
            <select name="month" class="form-select form-select-sm" style="min-width:130px;">
                <?php foreach ($month_names as $mn => $ml): ?>
                <option value="<?php echo $mn; ?>" <?php echo ($mn === $filter_month) ? 'selected' : ''; ?>>
                    <?php echo htmlspecialchars($ml); ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-auto">
            <label class="form-label" style="font-size:12px;">Year</label>
            <input type="number" name="year" class="form-control form-control-sm" value="<?php echo $filter_year; ?>"
                min="2024" max="2099" style="width:90px;">
        </div>
        <div class="col-auto">
            <button type="submit" class="btn btn-primary btn-sm">Filter</button>
            <a href="atem/staff_performance.php" class="btn btn-outline-secondary btn-sm ms-1">Reset</a>
        </div>
        <?php if ($atem_permission >= 3): ?>
        <div class="col-auto ms-auto">
            <button type="button" class="btn btn-outline-secondary btn-sm" id="recalc-btn"
                data-month="<?php echo $filter_month; ?>" data-year="<?php echo $filter_year; ?>"
                <?php echo $api_unavailable ? 'disabled' : ''; ?>>
                Recalculate
            </button>
        </div>
        <?php endif; ?>
    </form>
</div>

<!-- Table -->
<div class="bento-card">
    <p class="mb-3 text-muted" style="font-size:11px;text-transform:uppercase;letter-spacing:.06em;font-weight:600;">
        <?php echo htmlspecialchars($month_names[$filter_month] . ' ' . $filter_year); ?> &mdash; Staff Performance
    </p>
    <div class="table-responsive">
        <table class="table table-hover mb-0" style="font-size:13px;">
            <thead>
                <tr>
                    <th>Staff Details</th>
                    <th>Grade</th>
                    <th>Performance Structure</th>
                    <th class="text-center">Total Completed ATEM</th>
                    <th class="text-end">Total Incentive</th>
                    <th>Remark</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($records)): ?>
                <tr>
                    <td colspan="7" class="text-center text-muted py-4">No records found for this period.</td>
                </tr>
                <?php else: ?>
                <?php foreach ($records as $rec): ?>
                <?php
                    $sid       = isset($rec['staff_id'])    ? (int)$rec['staff_id']    : 0;
                    $dept_id   = isset($rec['staff_dept_id']) ? (int)$rec['staff_dept_id'] : 0;
                    $grade_id  = isset($rec['staff_grade'])  ? (int)$rec['staff_grade']  : null;
                    $struct_id = isset($rec['staff_struct']) ? (int)$rec['staff_struct'] : null;

                    $sname     = isset($staff_names[$sid])          ? $staff_names[$sid]          : ('Staff #' . $sid);
                    $dname     = ($dept_id && isset($dept_names[$dept_id])) ? $dept_names[$dept_id] : '-';
                    $glabel    = ($grade_id !== null && isset($grade_labels[$grade_id]))   ? $grade_labels[$grade_id]   : '-';
                    $slabel    = ($struct_id !== null && isset($struct_labels[$struct_id])) ? $struct_labels[$struct_id] : '-';

                    $total_atem      = isset($rec['total_atem'])      ? (int)$rec['total_atem']            : 0;
                    $total_incentive = isset($rec['total_incentive'])  ? (float)$rec['total_incentive']     : 0.0;
                    $remark          = isset($rec['remark'])           ? $rec['remark']                     : '';
                    $rec_id          = isset($rec['id'])               ? (int)$rec['id']                    : 0;
                ?>
                <tr>
                    <td>
                        <div style="font-weight:500;"><?php echo htmlspecialchars($sname); ?></div>
                        <div class="text-muted" style="font-size:11px;"><?php echo htmlspecialchars($dname); ?></div>
                    </td>
                    <td><?php echo htmlspecialchars($glabel); ?></td>
                    <td><?php echo htmlspecialchars($slabel); ?></td>
                    <td class="text-center"><?php echo $total_atem; ?></td>
                    <td class="text-end">RM <?php echo number_format($total_incentive, 2); ?></td>
                    <td style="max-width:200px;">
                        <span class="perf-remark-text"
                            data-id="<?php echo $rec_id; ?>"><?php echo htmlspecialchars($remark); ?></span>
                    </td>
                    <td style="white-space:nowrap;">
                        <a class="btn btn-sm btn-outline-primary me-1"
                            href="atem/view.php?staff_id=<?php echo $sid; ?>">View</a>
                        <button type="button" class="btn btn-sm btn-outline-secondary perf-edit-btn"
                            data-id="<?php echo $rec_id; ?>"
                            data-remark="<?php echo htmlspecialchars($remark, ENT_QUOTES); ?>"
                            <?php echo $api_unavailable ? 'disabled' : ''; ?>>Edit</button>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Edit Remark Modal -->
<div class="modal fade" id="editRemarkModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-sm">
        <div class="modal-content">
            <div class="modal-header py-2">
                <h6 class="modal-title mb-0">Edit Remark</h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <textarea class="form-control" id="remark-input" rows="3" maxlength="500"
                    style="font-size:13px;"></textarea>
                <div class="atem-form-error mt-1" id="remark-error"></div>
            </div>
            <div class="modal-footer py-2">
                <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary btn-sm" id="remark-save-btn">Save</button>
            </div>
        </div>
    </div>
</div>

</div><!-- /.atem-container -->

<script>
var PERF_API_URL = '/odb/atem/api.php';
var editingRecordId = null;
var editingRow = null;

document.addEventListener('DOMContentLoaded', function() {
    var modal = document.getElementById('editRemarkModal');
    var bsModal = new bootstrap.Modal(modal);

    document.addEventListener('click', function(e) {
        var btn = e.target.closest('.perf-edit-btn');
        if (!btn) {
            return;
        }
        editingRecordId = parseInt(btn.getAttribute('data-id'), 10);
        editingRow = btn.closest('tr');
        document.getElementById('remark-input').value = btn.getAttribute('data-remark') || '';
        document.getElementById('remark-error').textContent = '';
        bsModal.show();
    });

    document.getElementById('remark-save-btn').addEventListener('click', function() {
        var btn = this;
        var remark = document.getElementById('remark-input').value.trim();
        btn.disabled = true;
        btn.textContent = 'Saving...';

        fetch(PERF_API_URL, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({
                    action: 'bonus-update-remark',
                    id: editingRecordId,
                    remark: remark || null
                })
            })
            .then(function(r) {
                return r.json();
            })
            .then(function(res) {
                if (res.success) {
                    var span = editingRow ? editingRow.querySelector('.perf-remark-text') : null;
                    var editBtn = editingRow ? editingRow.querySelector('.perf-edit-btn') : null;
                    if (span) {
                        span.textContent = remark;
                    }
                    if (editBtn) {
                        editBtn.setAttribute('data-remark', remark);
                    }
                    bsModal.hide();
                } else {
                    document.getElementById('remark-error').textContent = res.message ||
                        'Save failed.';
                }
            })
            .catch(function() {
                document.getElementById('remark-error').textContent =
                    'Request failed. Please try again.';
            })
            .finally(function() {
                btn.disabled = false;
                btn.textContent = 'Save';
            });
    });

    var recalcBtn = document.getElementById('recalc-btn');
    if (recalcBtn) {
        recalcBtn.addEventListener('click', function() {
            var btn = this;
            var month = parseInt(btn.getAttribute('data-month'), 10);
            var year = parseInt(btn.getAttribute('data-year'), 10);
            btn.disabled = true;
            btn.textContent = 'Calculating...';

            fetch(PERF_API_URL, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify({
                        action: 'bonus-trigger-calculate',
                        month: month,
                        year: year
                    })
                })
                .then(function(r) {
                    return r.json();
                })
                .then(function(res) {
                    if (res.success) {
                        window.location.reload();
                    } else {
                        alert(res.message || 'Calculation failed.');
                        btn.disabled = false;
                        btn.textContent = 'Recalculate';
                    }
                })
                .catch(function() {
                    alert('Request failed. Please try again.');
                    btn.disabled = false;
                    btn.textContent = 'Recalculate';
                });
        });
    }
});
</script>

<?php include('footer.php'); ?>