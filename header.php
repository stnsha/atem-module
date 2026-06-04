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
$page_title = isset($page_title) ? $page_title : 'ATEM';
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
    <base href="/odb/">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">
    <link href="atem/css/style.css?v=<?php echo time(); ?>" rel="stylesheet">
</head>
<?php
require_once(dirname(__FILE__) . '/../lock_adv.php');
$connect = 1;
include(dirname(__FILE__) . '/../common/index_adv.php');

// Placeholder for the real atem permission lookup. When the role/permission
// column is defined, query it here for the logged-in user ($id_user) and set
// $atem_permission so navbar.php can gate role-specific items.
?>

<body>
    <?php include(dirname(__FILE__) . '/navbar.php'); ?>
    <div class="header" style="position: relative;">
        <b class="rtop"><b class="r1"></b><b class="r2"></b><b class="r3"></b><b class="r4"></b></b>
        <h1 class="headerH1">ATEM</h1>
        <b class="rbottom"><b class="r4"></b><b class="r3"></b><b class="r2"></b><b class="r1"></b></b>
    </div>
    <div class="atem-container mb-3">

        <div class="row mb-4">
            <div class="col-12">
                <h1 class="atem-page-title"><?php echo htmlspecialchars($page_title); ?></h1>
            </div>
        </div>