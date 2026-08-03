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
        : 'DB Default';
    $_navbar_activeView      = isset($_SESSION['atem_dev_view_override']) ? $_SESSION['atem_dev_view_override'] : 'hq';
    $_navbar_viewLabels      = array('hq' => 'HQ', 'outlet' => 'Outlet');
    $_navbar_currentUri      = isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : ATEM_BASE . 'index.php';
?>
<div
    style="background:#12122a;color:#d0d0f0;padding:5px 14px;font-size:11px;font-family:monospace;display:flex;align-items:center;gap:10px;flex-wrap:wrap;border-bottom:1px solid #333;">
    <span style="color:#888;letter-spacing:.05em;">DEV GRADE</span>
    <strong style="color:#f0c040;">[<?php echo htmlspecialchars($_navbar_activeRoleLabel); ?><?php echo ($_navbar_activeRole !== null) ? ' / ' . htmlspecialchars($_navbar_viewLabels[$_navbar_activeView]) : ''; ?>]</strong>
    <?php foreach ($_navbar_gradeLabels as $_r => $_label): ?>
    <form method="POST" action="<?php echo ATEM_BASE; ?>dev-switch-role.php" style="display:inline;margin:0;">
        <input type="hidden" name="role" value="<?php echo $_r; ?>">
        <input type="hidden" name="view" value="<?php echo htmlspecialchars($_navbar_activeView); ?>">
        <input type="hidden" name="redirect" value="<?php echo htmlspecialchars($_navbar_currentUri); ?>">
        <button type="submit"
            style="background:<?php echo ($_navbar_activeRole === $_r ? '#2e2e6e' : '#1e1e3e'); ?>;color:<?php echo ($_navbar_activeRole === $_r ? '#f0c040' : '#aaa'); ?>;border:1px solid <?php echo ($_navbar_activeRole === $_r ? '#555' : '#333'); ?>;padding:2px 7px;font-size:11px;cursor:pointer;border-radius:3px;font-family:monospace;"><?php echo $_r; ?>:
            <?php echo $_label; ?></button>
    </form>
    <?php endforeach; ?>
    <?php if ($_navbar_activeRole !== null): ?>
    <span style="color:#888;letter-spacing:.05em;">VIEW</span>
    <?php foreach ($_navbar_viewLabels as $_v => $_vLabel): ?>
    <form method="POST" action="<?php echo ATEM_BASE; ?>dev-switch-role.php" style="display:inline;margin:0;">
        <input type="hidden" name="role" value="<?php echo $_navbar_activeRole; ?>">
        <input type="hidden" name="view" value="<?php echo $_v; ?>">
        <input type="hidden" name="redirect" value="<?php echo htmlspecialchars($_navbar_currentUri); ?>">
        <button type="submit"
            style="background:<?php echo ($_navbar_activeView === $_v ? '#2e2e6e' : '#1e1e3e'); ?>;color:<?php echo ($_navbar_activeView === $_v ? '#f0c040' : '#aaa'); ?>;border:1px solid <?php echo ($_navbar_activeView === $_v ? '#555' : '#333'); ?>;padding:2px 7px;font-size:11px;cursor:pointer;border-radius:3px;font-family:monospace;"><?php echo $_vLabel; ?></button>
    </form>
    <?php endforeach; ?>
    <form method="POST" action="<?php echo ATEM_BASE; ?>dev-switch-role.php" style="display:inline;margin:0;">
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
$current_page   = basename(isset($_SERVER['PHP_SELF']) ? $_SERVER['PHP_SELF'] : '');
$current_dir    = basename(dirname(isset($_SERVER['PHP_SELF']) ? $_SERVER['PHP_SELF'] : ''));
$_atem_module_dir = basename(dirname(__FILE__)); // 'atem'

// Determine active class for each nav item
$dashboard_active = ($current_page == 'index.php' && $current_dir == $_atem_module_dir)  ? 'active' : '';
$view_active      = ($current_dir == $_atem_module_dir && ($current_page == 'view.php' || $current_page == 'edit.php' || $current_page == 'create.php')) ? 'active' : '';
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
        <div class="collapse navbar-collapse w-100" id="navbarNav">
            <ul class="navbar-nav align-items-lg-center w-100">
                <li class="nav-item">
                    <a class="nav-link <?php echo $dashboard_active; ?>" href="<?php echo ATEM_BASE; ?>index.php">Dashboard</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?php echo $view_active; ?>" href="<?php echo ATEM_BASE; ?>view.php">ATEM</a>
                </li>
                <?php if ($atem_role >= 3 || $_is_superadmin || in_array((int)(isset($struct) ? $struct : 0), array(4, 5), true)): ?>
                <li class="nav-item">
                    <a class="nav-link" href="okr/list.php">OKR</a>
                </li>
                <?php endif; ?>
                <?php if ($atem_role >= 3 || $_is_superadmin): ?>
                <li class="nav-item">
                    <a class="nav-link <?php echo $performance_active; ?>"
                        href="<?php echo ATEM_BASE; ?>staff_performance/index.php">Performance</a>
                </li>
                <?php endif; ?>
                <?php if ($atem_role >= 1 || $_is_superadmin): ?>
                <li class="nav-item">
                    <a class="nav-link <?php echo $admin_active; ?>" href="<?php echo ATEM_BASE; ?>access_control/index.php">Access Control</a>
                </li>
                <?php endif; ?>
                <?php if ($_is_superadmin || $atem_permission >= 4): ?>
                <li class="nav-item">
                    <a class="nav-link <?php echo $masterlist_active; ?>" href="<?php echo ATEM_BASE; ?>access_control/masterlist.php">Masterlist</a>
                </li>
                <?php endif; ?>
                <?php if ($_is_superadmin): ?>
                <li class="nav-item">
                    <a class="nav-link <?php echo $admin_settings_active; ?>" href="<?php echo ATEM_BASE; ?>admin/index.php">Admin</a>
                </li>
                <?php endif; ?>
                <?php if ($atem_role >= 1 || $_is_superadmin): ?>
                <li class="nav-item dropdown atem-notif-dropdown ms-lg-auto">
                    <a class="nav-link position-relative" href="#" id="atemNotifBell" role="button"
                        data-bs-toggle="dropdown" aria-expanded="false" title="Notifications">
                        <i class="bi bi-bell"></i>
                        <span id="atem-notif-badge" class="atem-notif-badge atem-hidden">0</span>
                    </a>
                    <div class="dropdown-menu dropdown-menu-end atem-notif-menu" aria-labelledby="atemNotifBell">
                        <div class="atem-notif-menu-header">
                            <span>Notifications</span>
                            <button type="button" class="btn btn-link btn-sm p-0" id="atem-notif-markall">Mark all read</button>
                        </div>
                        <div id="atem-notif-list" class="atem-notif-list">
                            <div class="atem-notif-empty">No notifications.</div>
                        </div>
                    </div>
                </li>
                <?php endif; ?>
            </ul>
        </div>
    </div>
</nav>
<?php if ($atem_role >= 1 || $_is_superadmin): ?>
<script>
(function () {
    'use strict';
    var API_URL = <?php echo json_encode(ATEM_BASE); ?> + 'api.php';
    var EDIT_URL = <?php echo json_encode(ATEM_BASE); ?> + 'edit.php';
    var POLL_MS = 8000;

    function apiCall(action, payload) {
        var body = { action: action };
        if (payload) { for (var k in payload) { if (payload.hasOwnProperty(k)) { body[k] = payload[k]; } } }
        return fetch(API_URL, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) })
            .then(function (r) { return r.json(); });
    }

    function escapeHtml(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }

    // Renders in the browser's local timezone (not UTC) - new Date() on an ISO
    // string with a Z suffix parses as UTC, but getHours()/getMinutes() below
    // read back the local-time equivalent, same as edit.js's formatDateTime().
    function formatDateTime(v) {
        if (!v) { return ''; }
        var d = new Date(String(v).replace(' ', 'T'));
        if (isNaN(d.getTime())) { return v; }
        var m = d.getMonth() + 1, day = d.getDate();
        var hh = String(d.getHours()).padStart(2, '0');
        var mm = String(d.getMinutes()).padStart(2, '0');
        return d.getFullYear() + '-' + (m < 10 ? '0' + m : '' + m) + '-' + (day < 10 ? '0' + day : '' + day) + ' ' + hh + ':' + mm;
    }

    function setBadge(count) {
        var badge = document.getElementById('atem-notif-badge');
        if (!badge) { return; }
        if (count > 0) {
            badge.textContent = count > 99 ? '99+' : String(count);
            badge.classList.remove('atem-hidden');
        } else {
            badge.classList.add('atem-hidden');
        }
    }

    function renderList(items) {
        var list = document.getElementById('atem-notif-list');
        if (!list) { return; }
        if (!items.length) {
            list.innerHTML = '<div class="atem-notif-empty">No notifications.</div>';
            return;
        }
        var html = '';
        for (var i = 0; i < items.length; i++) {
            var n = items[i];
            var snippet;
            if (n.type === 'chat_message' && n.atem_id) {
                snippet = 'You received a chat on ATEM ID ' + n.atem_id;
            } else if (n.type === 'atem_suspended' && n.atem_id) {
                snippet = 'Your ATEM ID ' + n.atem_id + ' has been suspended.';
            } else if (n.type === 'atem_appealed' && n.atem_id) {
                snippet = 'An appeal was submitted for ATEM ID ' + n.atem_id + '.';
            } else {
                snippet = 'New activity on ATEM #' + n.atem_id;
            }
            html += '<div class="atem-notif-item' + (!n.read_at ? ' atem-notif-item-unread' : '') + '" data-id="' + n.id + '" data-atem-id="' + (n.atem_id || '') + '">'
                + '<div class="atem-notif-item-snippet">' + escapeHtml(snippet) + '</div>'
                + '<div class="atem-notif-item-time">' + escapeHtml(formatDateTime(n.created_at)) + '</div></div>';
        }
        list.innerHTML = html;
    }

    function refresh() {
        apiCall('notif-list', {}).then(function (res) {
            if (res && res.success) {
                setBadge(res.unread_count || 0);
                renderList(res.data || []);
            }
        }).catch(function () {});
    }

    document.addEventListener('click', function (e) {
        var item = e.target.closest('.atem-notif-item');
        if (item) {
            var id = item.getAttribute('data-id');
            var atemId = item.getAttribute('data-atem-id');
            apiCall('notif-mark-read', { id: id }).then(function () {
                if (atemId) {
                    window.location.href = EDIT_URL + '?id=' + atemId;
                } else {
                    refresh();
                }
            });
            return;
        }
        if (e.target.closest('#atem-notif-markall')) {
            e.preventDefault();
            apiCall('notif-mark-all-read', {}).then(refresh);
        }
    });

    refresh();
    setInterval(refresh, POLL_MS);
})();
</script>
<?php endif; ?>