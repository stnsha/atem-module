<?php
ob_start();

$page_title = 'IIDAS Migration Preview';
include('header.php');

if ($atem_permission < 4 && !$_is_superadmin) {
    ob_end_clean();
    header('Location: ' . ATEM_BASE . 'index.php');
    exit;
}

ob_end_flush();

define('API_JWT_INCLUDED', true);
include(dirname(__FILE__) . '/api.php');

$page      = isset($_GET['page'])      ? max(1, (int)$_GET['page'])    : 1;
$status    = isset($_GET['status'])    ? trim($_GET['status'])          : '';
$committed = isset($_GET['committed']) ? trim($_GET['committed'])       : '';

$allowed_statuses = array('Draft', 'Active', 'Completed', 'Deleted');
if (!in_array($status, $allowed_statuses)) {
    $status = '';
}
if ($committed !== '0' && $committed !== '1') {
    $committed = '';
}

$summary_result = getIidasMigrationSummary($staff_id);
$preview_result = getIidasMigrationPreview($staff_id, $page, $status, $committed);

$summary_by_status = array();
if (!empty($summary_result['data'])) {
    foreach ($summary_result['data'] as $s) {
        if (isset($s['mapped_status'])) {
            $summary_by_status[$s['mapped_status']] = $s;
        }
    }
}

$rows      = isset($preview_result['data']) ? $preview_result['data'] : array();
$meta      = isset($preview_result['meta']) ? $preview_result['meta'] : array();
$total     = isset($meta['total'])        ? (int)$meta['total']        : 0;
$last_page = isset($meta['last_page'])    ? (int)$meta['last_page']    : 1;
$cur_page  = isset($meta['current_page']) ? (int)$meta['current_page'] : 1;

function buildPreviewUrl($page_num, $status_filter, $committed_filter)
{
    $parts = array();
    if ($page_num > 1)            { $parts[] = 'page='      . (int)$page_num; }
    if ($status_filter !== '')    { $parts[] = 'status='    . urlencode($status_filter); }
    if ($committed_filter !== '') { $parts[] = 'committed=' . urlencode($committed_filter); }
    return 'migration_preview.php' . (!empty($parts) ? '?' . implode('&', $parts) : '');
}

$badge_map = array(
    'Draft'     => 'secondary',
    'Active'    => 'primary',
    'Completed' => 'success',
    'Deleted'   => 'danger',
);
$statuses = array('Draft', 'Active', 'Completed', 'Deleted');
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <h5 class="mb-0">IIDAS Migration Preview</h5>
    <span class="text-muted small">Run <code>php artisan iidas:migrate-atems --preview</code> to refresh data</span>
</div>

<?php if (empty($rows) && empty($summary_by_status)): ?>
<div class="alert alert-info">
    No preview data found. Run <code>php artisan iidas:migrate-atems --preview</code> on the server first.
</div>
<?php else: ?>

<div class="d-flex flex-wrap gap-3 mb-4">
<?php foreach ($statuses as $sv):
    $s     = isset($summary_by_status[$sv]) ? $summary_by_status[$sv] : array('total' => 0, 'pending' => 0, 'committed' => 0);
    $badge = isset($badge_map[$sv]) ? $badge_map[$sv] : 'secondary';
?>
<div class="card" style="min-width:150px">
    <div class="card-body p-3">
        <div class="mb-1"><span class="badge bg-<?php echo $badge; ?>"><?php echo htmlspecialchars($sv); ?></span></div>
        <div class="fs-4 fw-bold"><?php echo isset($s['total']) ? (int)$s['total'] : 0; ?></div>
        <div class="small text-muted">
            <?php echo isset($s['pending']) ? (int)$s['pending'] : 0; ?> pending &middot; <?php echo isset($s['committed']) ? (int)$s['committed'] : 0; ?> committed
        </div>
    </div>
</div>
<?php endforeach; ?>
</div>

<form method="GET" action="migration_preview.php" class="d-flex gap-2 mb-3 flex-wrap align-items-end">
    <div>
        <label class="form-label small mb-1">Status</label>
        <select name="status" class="form-select form-select-sm" style="min-width:140px">
            <option value="">All Statuses</option>
            <?php foreach ($statuses as $sv): ?>
            <option value="<?php echo htmlspecialchars($sv); ?>"<?php if ($status === $sv) echo ' selected'; ?>><?php echo htmlspecialchars($sv); ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div>
        <label class="form-label small mb-1">Committed</label>
        <select name="committed" class="form-select form-select-sm" style="min-width:140px">
            <option value="">All</option>
            <option value="0"<?php if ($committed === '0') echo ' selected'; ?>>Pending only</option>
            <option value="1"<?php if ($committed === '1') echo ' selected'; ?>>Committed only</option>
        </select>
    </div>
    <div>
        <button type="submit" class="btn btn-sm btn-outline-secondary">Filter</button>
        <?php if ($status !== '' || $committed !== ''): ?>
        <a href="migration_preview.php" class="btn btn-sm btn-link text-muted">Clear</a>
        <?php endif; ?>
    </div>
</form>

<div class="table-responsive">
<table class="table table-sm table-bordered table-hover align-middle" style="font-size:13px">
    <thead class="table-light">
        <tr>
            <th>IIDAS ID</th>
            <th>Title</th>
            <th>Status</th>
            <th>Issuer ID</th>
            <th>Dept ID</th>
            <th>Start</th>
            <th>End</th>
            <th>Delete?</th>
            <th class="text-center">ARCI</th>
            <th class="text-center">Subtasks</th>
            <th class="text-center">Refs</th>
            <th class="text-center">Attach.</th>
            <th>Committed At</th>
        </tr>
    </thead>
    <tbody>
    <?php if (empty($rows)): ?>
        <tr>
            <td colspan="13" class="text-center text-muted py-3">No records match the current filter.</td>
        </tr>
    <?php else: ?>
        <?php foreach ($rows as $row):
            $ms    = isset($row['mapped_status']) ? $row['mapped_status'] : '';
            $badge = isset($badge_map[$ms]) ? $badge_map[$ms] : 'secondary';
        ?>
        <tr>
            <td><?php echo isset($row['source_id']) ? (int)$row['source_id'] : 0; ?></td>
            <td><?php echo htmlspecialchars(isset($row['title']) ? $row['title'] : ''); ?></td>
            <td><span class="badge bg-<?php echo $badge; ?>"><?php echo htmlspecialchars($ms); ?></span></td>
            <td><?php echo isset($row['issuer_staff_id']) ? (int)$row['issuer_staff_id'] : 0; ?></td>
            <td><?php echo isset($row['staff_dept_id']) ? (int)$row['staff_dept_id'] : '&mdash;'; ?></td>
            <td><?php echo !empty($row['start_date']) ? htmlspecialchars(substr($row['start_date'], 0, 10)) : '&mdash;'; ?></td>
            <td><?php echo !empty($row['end_date'])   ? htmlspecialchars(substr($row['end_date'],   0, 10)) : '&mdash;'; ?></td>
            <td>
                <?php if (isset($row['would_soft_delete']) && (int)$row['would_soft_delete']): ?>
                    <span class="text-danger fw-semibold">Yes</span>
                <?php else: ?>
                    <span class="text-muted">No</span>
                <?php endif; ?>
            </td>
            <td class="text-center"><?php echo isset($row['arci_count']) ? (int)$row['arci_count'] : 0; ?></td>
            <td class="text-center"><?php echo isset($row['subtask_count']) ? (int)$row['subtask_count'] : 0; ?></td>
            <td class="text-center"><?php echo isset($row['ref_count']) ? (int)$row['ref_count'] : 0; ?></td>
            <td class="text-center"><?php echo isset($row['attachment_count']) ? (int)$row['attachment_count'] : 0; ?></td>
            <td>
                <?php if (!empty($row['committed_at'])): ?>
                    <span class="badge bg-success"><?php echo htmlspecialchars(substr($row['committed_at'], 0, 10)); ?></span>
                <?php else: ?>
                    <span class="badge bg-warning text-dark">Pending</span>
                <?php endif; ?>
            </td>
        </tr>
        <?php endforeach; ?>
    <?php endif; ?>
    </tbody>
</table>
</div>

<div class="d-flex justify-content-between align-items-center mt-2 mb-4">
    <div class="small text-muted">
        Showing <?php echo count($rows); ?> of <?php echo $total; ?> records
    </div>
    <?php if ($last_page > 1): ?>
    <nav>
        <ul class="pagination pagination-sm mb-0">
            <?php if ($cur_page > 1): ?>
            <li class="page-item">
                <a class="page-link" href="<?php echo buildPreviewUrl($cur_page - 1, $status, $committed); ?>">Prev</a>
            </li>
            <?php else: ?>
            <li class="page-item disabled"><span class="page-link">Prev</span></li>
            <?php endif; ?>

            <?php
            $start_p = max(1, $cur_page - 2);
            $end_p   = min($last_page, $cur_page + 2);
            for ($i = $start_p; $i <= $end_p; $i++):
            ?>
            <li class="page-item<?php if ($i === $cur_page) echo ' active'; ?>">
                <a class="page-link" href="<?php echo buildPreviewUrl($i, $status, $committed); ?>"><?php echo $i; ?></a>
            </li>
            <?php endfor; ?>

            <?php if ($cur_page < $last_page): ?>
            <li class="page-item">
                <a class="page-link" href="<?php echo buildPreviewUrl($cur_page + 1, $status, $committed); ?>">Next</a>
            </li>
            <?php else: ?>
            <li class="page-item disabled"><span class="page-link">Next</span></li>
            <?php endif; ?>
        </ul>
    </nav>
    <?php endif; ?>
</div>

<?php endif; ?>

<?php include('footer.php'); ?>
