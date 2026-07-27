<?php
/**
 * ATEM base layout - top partial.
 *
 * A page includes this after optionally setting:
 *   $page_title  - shown in <title> and as the page heading (default "ATEM")
 *   $page_js     - optional page-specific script path, emitted by footer.php
 *
 * Include paths are resolved from this file's directory with dirname(__FILE__)
 * so the partial works regardless of the including page's depth
 * (for example atem/index.php and atem/admin/index.php).
 */
ob_start();
$page_title = isset($page_title) ? $page_title : 'ATEM';

// Absolute base URL for this module's own pages/assets, e.g. "/odb/atem/" or
// "/odb/atem-staging/". Derived from this file's own folder name so every
// in-module link, redirect, and asset reference follows whichever copy
// (production or staging) is actually serving the current request, instead
// of a hardcoded folder name.
if (!defined('ATEM_BASE')) {
    define('ATEM_BASE', '/odb/' . basename(dirname(__FILE__)) . '/');
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="Cache-Control" content="no-cache, no-store, must-revalidate">
    <meta http-equiv="Pragma" content="no-cache">
    <meta http-equiv="Expires" content="0">
    <title><?php echo htmlspecialchars($page_title); ?></title>
    <link rel="icon" type="image/svg+xml" href="<?php echo ATEM_BASE; ?>css/logo.svg">
    <base href="/odb/">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">
    <link href="<?php echo ATEM_BASE; ?>css/style.css?v=<?php echo time(); ?>" rel="stylesheet">
    <?php if (isset($extra_css)) echo $extra_css; ?>
</head>
<?php
require_once(dirname(__FILE__) . '/../lock_adv.php');
$connect = 1;
include(dirname(__FILE__) . '/../common/index_adv.php');

$atem_permission = isset($grade) ? (int)$grade : 0;
if (isset($_SESSION['atem_dev_role_override'])) {
    $atem_permission = (int)$_SESSION['atem_dev_role_override'];
}

// Dev department-view override (paired with the grade override above):
// simulates the dev as Outlet-department (id 1) staff or as non-Outlet
// ("HQ") staff, since dept-scoped visibility (grades 2-3) otherwise always
// reflects the real SuperAdmin's own department. No view override set means
// no change - department stays whatever lock_adv.php read from the DB.
if (isset($_SESSION['atem_dev_role_override']) && isset($_SESSION['atem_dev_view_override'])) {
    if ($_SESSION['atem_dev_view_override'] === 'outlet') {
        $department = '1';
    } elseif ($_SESSION['atem_dev_view_override'] === 'hq') {
        $_hq_dept_ids = array();
        foreach (explode(',', (string)$department) as $_hd) {
            $_hd = (int)trim($_hd);
            if ($_hd > 0 && $_hd !== 1) {
                $_hq_dept_ids[] = $_hd;
            }
        }
        if (empty($_hq_dept_ids)) {
            $_hq_fallback_r = mysqli_query($conn, "SELECT id FROM staff_department WHERE id != 1 ORDER BY id ASC LIMIT 1");
            if ($_hq_fallback_r && ($_hq_fallback_row = mysqli_fetch_assoc($_hq_fallback_r))) {
                $_hq_dept_ids[] = (int)$_hq_fallback_row['id'];
            }
        }
        $department = implode(',', $_hq_dept_ids);
    }
}

// SuperAdmin recognition is the union of both module flags: staff.atem is
// already available via lock_adv.php ($atem); staff.okr is not, so it is
// queried directly here. Either flag being set to 1 grants full SuperAdmin
// access to ATEM (and, symmetrically, to OKR - see okr/header.php).
$_atem_okr_flag = 0;
if (isset($id_user)) {
    $_atem_okr_check = mysqli_query($conn, 'SELECT okr FROM staff WHERE id = ' . (int)$id_user);
    if ($_atem_okr_check && ($_atem_okr_row = mysqli_fetch_assoc($_atem_okr_check))) {
        $_atem_okr_flag = (int)$_atem_okr_row['okr'];
    }
}

$_is_superadmin = (!isset($_SESSION['atem_dev_role_override']) && ((isset($atem) && (int)$atem === 1) || $_atem_okr_flag === 1));

if ((int)$atem_permission === 0 && !$_is_superadmin) {
    ob_end_clean();
    if (isset($_SESSION['atem_dev_role_override'])) {
        unset($_SESSION['atem_dev_role_override']);
        header('Location: ' . ATEM_BASE . 'index.php');
    } else {
        header('Location: /odb/index.php');
    }
    exit;
}
?>

<body>
    <?php include(dirname(__FILE__) . '/navbar.php'); ?>
    <div class="header" style="position: relative;">
        <b class="rtop"><b class="r1"></b><b class="r2"></b><b class="r3"></b><b class="r4"></b></b>
        <h1 class="headerH1"><img src='/odb/atem/css/logo.svg' width='20px'>ATEM &amp; OKR</h1>
        <b class="rbottom"><b class="r4"></b><b class="r3"></b><b class="r2"></b><b class="r1"></b></b>
    </div>
    <div class="atem-container mb-3">

        <div class="row mb-4">
            <div class="col-12 d-flex align-items-start justify-content-between flex-wrap gap-2">
                <h1 class="atem-page-title mb-0">
                    <?php echo htmlspecialchars($page_title); ?><?php echo isset($page_title_badge) ? ' ' . $page_title_badge : ''; ?>
                </h1>
                <?php if (!empty($page_title_actions)): ?>
                <div><?php echo $page_title_actions; ?></div>
                <?php endif; ?>
            </div>
        </div>