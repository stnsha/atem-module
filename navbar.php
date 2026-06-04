<?php
// Get current page to highlight active nav item
$current_page = basename($_SERVER['PHP_SELF']);
$current_dir  = basename(dirname($_SERVER['PHP_SELF']));

// Determine active class for each nav item
$dashboard_active = ($current_page == 'index.php' && $current_dir == 'atem')  ? 'active' : '';
$admin_active     = ($current_dir == 'admin')                                 ? 'active' : '';

// Resolve role for conditional menu items.
$atem_role = 0;
if (isset($atem_permission)) {
    $atem_role = (int)$atem_permission;
} elseif (isset($currentStaffRole)) {
    $atem_role = (int)$currentStaffRole;
}
?>
<nav class="atem-nav navbar navbar-expand-lg navbar-light mb-3">
    <div class="container-fluid">
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav"
            aria-controls="navbarNav" aria-expanded="false" aria-label="Toggle navigation">
            <i class="bi bi-list"></i>
        </button>
        <div class="collapse navbar-collapse" id="navbarNav">
            <ul class="navbar-nav align-items-lg-center">
                <li class="nav-item me-lg-2">
                    <a class="btn btn-primary atem-btn-new d-inline-flex align-items-center" href="atem/create.php">
                        <i class="bi bi-plus-lg me-1"></i>New ATEM
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?php echo $dashboard_active; ?>" href="atem/index.php">Dashboard</a>
                </li>
                <?php if ($atem_role === 1): ?>
                <li class="nav-item">
                    <a class="nav-link <?php echo $admin_active; ?>" href="atem/admin/index.php">Admin</a>
                </li>
                <?php endif; ?>
            </ul>
        </div>
    </div>
</nav>
