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
if ($_navbar_isLocal && $_navbar_realRole === 6) {
    if (session_id() == '') {
        session_start();
    }

    $_navbar_gradeLabels = array(
        0 => 'Non-Graded',
        1 => 'Frontline / Operational Staff',
        2 => 'Middle management',
        3 => 'Senior management',
        4 => 'C suite executive',
        5 => 'CEO/board',
        6 => 'SuperAdmin',
    );

    $_navbar_activeRole      = isset($_SESSION['atem_dev_role_override']) ? (int)$_SESSION['atem_dev_role_override'] : null;
    $_navbar_activeRoleLabel = ($_navbar_activeRole !== null)
        ? $_navbar_gradeLabels[$_navbar_activeRole]
        : 'DB Default';
    $_navbar_currentUri      = isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '/odb/atem/index.php';
?>
<div style="background:#12122a;color:#d0d0f0;padding:5px 14px;font-size:11px;font-family:monospace;display:flex;align-items:center;gap:10px;flex-wrap:wrap;border-bottom:1px solid #333;">
    <span style="color:#888;letter-spacing:.05em;">DEV GRADE</span>
    <strong style="color:#f0c040;">[<?php echo htmlspecialchars($_navbar_activeRoleLabel); ?>]</strong>
    <?php foreach ($_navbar_gradeLabels as $_r => $_label): ?>
        <form method="POST" action="/odb/atem/dev-switch-role.php" style="display:inline;margin:0;">
            <input type="hidden" name="role" value="<?php echo $_r; ?>">
            <input type="hidden" name="redirect" value="<?php echo htmlspecialchars($_navbar_currentUri); ?>">
            <button type="submit" style="background:<?php echo ($_navbar_activeRole === $_r ? '#2e2e6e' : '#1e1e3e'); ?>;color:<?php echo ($_navbar_activeRole === $_r ? '#f0c040' : '#aaa'); ?>;border:1px solid <?php echo ($_navbar_activeRole === $_r ? '#555' : '#333'); ?>;padding:2px 7px;font-size:11px;cursor:pointer;border-radius:3px;font-family:monospace;"><?php echo $_r; ?>: <?php echo $_label; ?></button>
        </form>
    <?php endforeach; ?>
    <?php if ($_navbar_activeRole !== null): ?>
        <form method="POST" action="/odb/atem/dev-switch-role.php" style="display:inline;margin:0;">
            <input type="hidden" name="role" value="clear">
            <input type="hidden" name="redirect" value="<?php echo htmlspecialchars($_navbar_currentUri); ?>">
            <button type="submit" style="background:#3a1010;color:#ff8888;border:1px solid #a44;padding:2px 7px;font-size:11px;cursor:pointer;border-radius:3px;font-family:monospace;">Clear Override</button>
        </form>
    <?php endif; ?>
</div>
<?php } ?>

<?php
// Get current page to highlight active nav item
$current_page = basename($_SERVER['PHP_SELF']);
$current_dir  = basename(dirname($_SERVER['PHP_SELF']));

// Determine active class for each nav item
$dashboard_active = ($current_page == 'index.php' && $current_dir == 'atem')  ? 'active' : '';
$view_active      = ($current_page == 'view.php' || $current_page == 'edit.php') ? 'active' : '';
$admin_active     = ($current_dir == 'admin' && $current_page == 'index.php') ? 'active' : '';
$library_active   = ($current_dir == 'admin' && $current_page == 'library.php') ? 'active' : '';

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
                        <i class="bi bi-plus-lg me-1"></i>ATEM
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?php echo $dashboard_active; ?>" href="atem/index.php">Dashboard</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?php echo $view_active; ?>" href="atem/view.php">ATEM</a>
                </li>
                <?php if ($atem_role >= 3): ?>
                <li class="nav-item">
                    <a class="nav-link <?php echo $admin_active; ?>" href="atem/admin/index.php">Admin</a>
                </li>
                <?php endif; ?>
                <?php if ($atem_role === 6): ?>
                <li class="nav-item">
                    <a class="nav-link <?php echo $library_active; ?>" href="atem/admin/library.php">Library</a>
                </li>
                <?php endif; ?>
            </ul>
        </div>
    </div>
</nav>
