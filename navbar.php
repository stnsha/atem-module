<?php
// Resolve role first so it can gate the dev toolbar.
$atem_role = 0;
if (isset($atem_permission)) {
    $atem_role = (int)$atem_permission;
} elseif (isset($currentStaffRole)) {
    $atem_role = (int)$currentStaffRole;
}

// Dev grade switcher toolbar (localhost + SuperAdmin only)
$_navbar_serverName = isset($_SERVER['SERVER_NAME']) ? $_SERVER['SERVER_NAME'] : '';
$_navbar_httpHost   = isset($_SERVER['HTTP_HOST'])   ? $_SERVER['HTTP_HOST']   : '';
$_navbar_isLocal    = in_array($_navbar_serverName, array('localhost', '127.0.0.1'))
    || strpos($_navbar_serverName, 'localhost') !== false
    || strpos($_navbar_httpHost,   'localhost') !== false
    || strpos($_navbar_httpHost,   '127.0.0.1') !== false;

$_navbar_realRole = isset($grade) ? (int)$grade : $atem_role;
if (isset($atem) && (int)$atem === 1) {
    $_navbar_realRole = 6;
}
if ($_navbar_isLocal && $_navbar_realRole === 6) {
    if (session_id() == '') {
        session_start();
    }

    $_navbar_gradeLabels = array(
        1 => 'Frontline / Operational Staff',
        2 => 'Middle management',
        3 => 'Senior management',
        4 => 'C suite executive',
        5 => 'CEO/board'
    );

    $_navbar_activeRole      = isset($_SESSION['atem_dev_role_override']) ? (int)$_SESSION['atem_dev_role_override'] : null;
    $_navbar_activeRoleLabel = ($_navbar_activeRole !== null && isset($_navbar_gradeLabels[$_navbar_activeRole]))
        ? $_navbar_gradeLabels[$_navbar_activeRole]
        : ($_navbar_activeRole !== null ? 'Role ' . $_navbar_activeRole : 'DB Default');
    $_navbar_currentUri      = isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '/odb/atem/index.php';
?>
<div
    style="background:#12122a;color:#d0d0f0;padding:5px 14px;font-size:11px;font-family:monospace;display:flex;align-items:center;gap:10px;flex-wrap:wrap;border-bottom:1px solid #333;">
    <span style="color:#888;letter-spacing:.05em;">DEV GRADE</span>
    <strong style="color:#f0c040;">[<?php echo htmlspecialchars($_navbar_activeRoleLabel); ?>]</strong>
    <?php foreach ($_navbar_gradeLabels as $_r => $_label): ?>
    <form method="POST" action="/odb/atem/dev-switch-role.php" style="display:inline;margin:0;">
        <input type="hidden" name="role" value="<?php echo $_r; ?>">
        <input type="hidden" name="redirect" value="<?php echo htmlspecialchars($_navbar_currentUri); ?>">
        <button type="submit"
            style="background:<?php echo ($_navbar_activeRole === $_r ? '#2e2e6e' : '#1e1e3e'); ?>;color:<?php echo ($_navbar_activeRole === $_r ? '#f0c040' : '#aaa'); ?>;border:1px solid <?php echo ($_navbar_activeRole === $_r ? '#555' : '#333'); ?>;padding:2px 7px;font-size:11px;cursor:pointer;border-radius:3px;font-family:monospace;"><?php echo $_r; ?>:
            <?php echo $_label; ?></button>
    </form>
    <?php endforeach; ?>
    <?php if ($_navbar_activeRole !== null): ?>
    <form method="POST" action="/odb/atem/dev-switch-role.php" style="display:inline;margin:0;">
        <input type="hidden" name="role" value="clear">
        <input type="hidden" name="redirect" value="<?php echo htmlspecialchars($_navbar_currentUri); ?>">
        <button type="submit"
            style="background:#3a1010;color:#ff8888;border:1px solid #a44;padding:2px 7px;font-size:11px;cursor:pointer;border-radius:3px;font-family:monospace;">Clear
            Override</button>
    </form>
    <?php endif; ?>
</div>
<?php } ?>

<?php
// Get current page to highlight active nav item
$_navbar_phpSelf = isset($_SERVER['PHP_SELF']) ? $_SERVER['PHP_SELF'] : '';
$current_page = basename($_navbar_phpSelf);
$current_dir  = basename(dirname($_navbar_phpSelf));

// Determine active class for each nav item
$dashboard_active = ($current_page == 'index.php' && $current_dir == 'atem')  ? 'active' : '';
$view_active      = ($current_dir == 'atem' && ($current_page == 'view.php' || $current_page == 'edit.php' || $current_page == 'create.php')) ? 'active' : '';
$admin_active          = ($current_dir == 'access_control' && $current_page == 'index.php')   ? 'active' : '';
$masterlist_active     = ($current_dir == 'access_control' && $current_page == 'masterlist.php') ? 'active' : '';
$admin_settings_active = ($current_dir == 'admin' && $current_page == 'index.php') ? 'active' : '';
$performance_active = ($current_dir == 'staff_performance') ? 'active' : '';

?>
<nav class="atem-nav navbar navbar-expand-lg navbar-light mb-3">
    <div class="container-fluid">
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav"
            aria-controls="navbarNav" aria-expanded="false" aria-label="Toggle navigation">
            <i class="bi bi-list"></i>
        </button>
        <div class="collapse navbar-collapse" id="navbarNav">
            <ul class="navbar-nav align-items-lg-center">
                <li class="nav-item">
                    <a class="nav-link <?php echo $dashboard_active; ?>" href="atem/index.php">Dashboard</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?php echo $view_active; ?>" href="atem/view.php">ATEM</a>
                </li>
                <?php if ($atem_role >= 4 || $_is_superadmin): ?>
                <li class="nav-item">
                    <a class="nav-link <?php echo $performance_active; ?>"
                        href="atem/staff_performance/index.php">Performance</a>
                </li>
                <?php endif; ?>
                <?php if ($atem_role >= 1): ?>
                <li class="nav-item">
                    <a class="nav-link <?php echo $admin_active; ?>" href="atem/access_control/index.php">Access Control</a>
                </li>
                <?php endif; ?>
                <?php if ($_is_superadmin || $atem_permission >= 4): ?>
                <li class="nav-item">
                    <a class="nav-link <?php echo $masterlist_active; ?>" href="atem/access_control/masterlist.php">Masterlist</a>
                </li>
                <?php endif; ?>
                <?php if ($_is_superadmin): ?>
                <li class="nav-item">
                    <a class="nav-link <?php echo $admin_settings_active; ?>" href="atem/admin/index.php">Admin</a>
                </li>
                <?php endif; ?>
            </ul>
        </div>
    </div>
</nav>