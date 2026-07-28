<?php
ob_start();

$page_title = 'Staff Grade & Evaluation Structure Masterlist';
include('../header.php');

if ($atem_permission < 4 && !$_is_superadmin) {
    ob_end_clean();
    header('Location: ' . ATEM_BASE . 'index.php');
    exit;
}

ob_end_flush();
?>

<style>
.lib-table td,
.lib-table th {
    vertical-align: middle;
    font-size: 13px;
}

.lib-label-text {
    display: inline-block;
}

.lib-label-input {
    display: none;
    width: 100%;
}

.lib-row.editing .lib-label-text,
.reward-row.editing .lib-label-text {
    display: none;
}

.lib-row.editing .lib-label-input,
.reward-row.editing .lib-label-input {
    display: inline-block;
}

.lib-row.editing .btn-edit,
.reward-row.editing .btn-reward-edit {
    display: none;
}

.lib-row.editing .btn-save,
.lib-row.editing .btn-cancel,
.reward-row.editing .btn-reward-save,
.reward-row.editing .btn-reward-cancel {
    display: inline-block;
}

.btn-save,
.btn-cancel,
.btn-reward-save,
.btn-reward-cancel {
    display: none;
}

.atem-container,
.atem-container * {
    text-align: left !important;
}

.badge {
    text-align: center !important;
}
</style>

<div class="row g-4">

    <!-- Grade Masterlist -->
    <div class="col-md-6">
        <div class="bento-card">
            <p class="mb-3 text-muted"
                style="font-size:11px;text-transform:uppercase;letter-spacing:.06em;font-weight:600;">Staff Grade
                Masterlist</p>
            <div id="grade-alert" class="alert alert-dismissible fade show mb-3" role="alert"
                style="display:none !important;font-size:13px;">
                <span id="grade-alert-msg"></span>
                <button type="button" class="btn-close" onclick="dismissAlert('grade')"></button>
            </div>
            <div class="table-responsive">
                <table class="table table-hover mb-0 lib-table">
                    <thead>
                        <tr>
                            <th style="width:48px;">ID</th>
                            <th>Label</th>
                            <th style="width:70px;">Active</th>
                            <th style="width:120px;"></th>
                        </tr>
                    </thead>
                    <tbody id="grade-tbody">
                        <tr>
                            <td colspan="4" class="text-center text-muted py-3">Loading...</td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <?php if ($_is_superadmin): ?>
            <div class="mt-3 pt-3" style="border-top:1px solid #e9ecef;">
                <button class="btn btn-sm btn-outline-primary btn-add-new" data-type="grade" data-tbody="grade-tbody">+
                    Add Grade</button>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Structure Masterlist -->
    <div class="col-md-6">
        <div class="bento-card">
            <p class="mb-3 text-muted"
                style="font-size:11px;text-transform:uppercase;letter-spacing:.06em;font-weight:600;">Evaluation
                Structure Masterlist
            </p>
            <div id="struct-alert" class="alert alert-dismissible fade show mb-3" role="alert"
                style="display:none !important;font-size:13px;">
                <span id="struct-alert-msg"></span>
                <button type="button" class="btn-close" onclick="dismissAlert('struct')"></button>
            </div>
            <div class="table-responsive">
                <table class="table table-hover mb-0 lib-table">
                    <thead>
                        <tr>
                            <th style="width:48px;">ID</th>
                            <th>Label</th>
                            <th style="width:70px;">Active</th>
                            <th style="width:120px;"></th>
                        </tr>
                    </thead>
                    <tbody id="struct-tbody">
                        <tr>
                            <td colspan="4" class="text-center text-muted py-3">Loading...</td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <?php if ($_is_superadmin): ?>
            <div class="mt-3 pt-3" style="border-top:1px solid #e9ecef;">
                <button class="btn btn-sm btn-outline-primary btn-add-new" data-type="struct"
                    data-tbody="struct-tbody">+ Add Structure</button>
            </div>
            <?php endif; ?>
        </div>
    </div>

</div>

<div class="row g-4 mt-1">

    <!-- Reward Masterlist (Outlet ATEM) -->
    <div class="col-md-6">
        <div class="bento-card">
            <p class="mb-3 text-muted"
                style="font-size:11px;text-transform:uppercase;letter-spacing:.06em;font-weight:600;">Outlet ATEM
                Reward Masterlist</p>
            <div id="reward-alert" class="alert alert-dismissible fade show mb-3" role="alert"
                style="display:none !important;font-size:13px;">
                <span id="reward-alert-msg"></span>
                <button type="button" class="btn-close" onclick="dismissRewardAlert()"></button>
            </div>
            <div class="table-responsive">
                <table class="table table-hover mb-0 lib-table">
                    <thead>
                        <tr>
                            <th style="width:48px;">ID</th>
                            <th>Reward Value</th>
                            <th style="width:70px;">Active</th>
                            <th style="width:120px;"></th>
                        </tr>
                    </thead>
                    <tbody id="reward-tbody">
                        <tr>
                            <td colspan="4" class="text-center text-muted py-3">Loading...</td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <?php if ($_is_superadmin): ?>
            <div class="mt-3 pt-3" style="border-top:1px solid #e9ecef;">
                <button class="btn btn-sm btn-outline-primary btn-reward-add-new">+ Add Reward Value</button>
            </div>
            <?php endif; ?>
        </div>
    </div>

</div>

</div><!-- /.atem-container -->

<!-- Active status change confirmation modal -->
<div class="modal fade" id="lib-active-confirm-modal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Please confirm</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p class="mb-0" id="lib-active-confirm-message">Are you sure?</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-warning" id="lib-active-confirm-ok">Confirm</button>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script>
var BACKEND_URL = <?php echo json_encode(ATEM_BASE . 'access_control/backend.php'); ?>;
// Reward Masterlist is atem-api-backed data (not an ODB table like Grade/
// Struct), so it goes through api.php's JWT bridge instead of backend.php's
// direct mysqli - a separate URL and JSON-body request helper, kept isolated
// from the grade/struct handlers below rather than forced into their shape.
var API_URL = <?php echo json_encode(ATEM_BASE . 'api.php'); ?>;
var IS_SUPERADMIN = <?php echo json_encode((bool) $_is_superadmin); ?>;

function apiCall(action, payload) {
    var body = Object.assign({ action: action }, payload || {});
    return fetch(API_URL, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(body)
    }).then(function (r) { return r.json(); });
}

function buildRow(type, item) {
    var actionsHtml = IS_SUPERADMIN
        ? '<button class="btn btn-sm btn-outline-secondary btn-edit">Edit</button>' +
          '<button class="btn btn-sm btn-primary btn-save me-1">Save</button>' +
          '<button class="btn btn-sm btn-outline-secondary btn-cancel">Cancel</button>'
        : '';
    return '<tr class="lib-row" data-type="' + type + '" data-id="' + item.id + '">' +
        '<td><strong>' + item.id + '</strong></td>' +
        '<td>' +
        '<span class="lib-label-text">' + $('<span>').text(item.label).html() + '</span>' +
        '<input type="text" class="form-control form-control-sm lib-label-input" value="' + $('<span>').text(item.label)
        .html() + '">' +
        '</td>' +
        '<td class="text-center">' +
        '<input type="checkbox" class="form-check-input lib-active-toggle"' +
        (item.is_active ? ' checked' : '') + (IS_SUPERADMIN ? '' : ' disabled') + '>' +
        '</td>' +
        '<td>' +
        actionsHtml +
        '</td>' +
        '</tr>';
}

function loadLibrary() {
    $.ajax({
        url: BACKEND_URL + '?action=getLibrary',
        type: 'GET',
        dataType: 'json',
        success: function(response) {
            if (!response.success) return;

            var gradeHtml = '';
            $.each(response.grades, function(i, item) {
                gradeHtml += buildRow('grade', item);
            });
            $('#grade-tbody').html(gradeHtml ||
                '<tr><td colspan="4" class="text-center text-muted py-3">No records.</td></tr>');

            var structHtml = '';
            $.each(response.structs, function(i, item) {
                structHtml += buildRow('struct', item);
            });
            $('#struct-tbody').html(structHtml ||
                '<tr><td colspan="4" class="text-center text-muted py-3">No records.</td></tr>');
        }
    });
}

$(document).on('click', '.btn-edit', function() {
    $(this).closest('.lib-row').addClass('editing');
});

$(document).on('click', '.btn-cancel', function() {
    var $row = $(this).closest('.lib-row');
    $row.find('.lib-label-input').val($row.find('.lib-label-text').text());
    $row.removeClass('editing');
});

$(document).on('click', '.btn-save', function() {
    var $row = $(this).closest('.lib-row');
    var type = $row.data('type');
    var id = $row.data('id');
    var label = $row.find('.lib-label-input').val().trim();
    var $btn = $(this);

    if (label === '') return;

    $btn.prop('disabled', true).text('Saving...');

    $.ajax({
        url: BACKEND_URL + '?action=updateLibrary',
        type: 'POST',
        dataType: 'json',
        data: {
            type: type,
            id: id,
            label: label
        },
        success: function(response) {
            if (response.success) {
                $row.find('.lib-label-text').text(label);
                $row.removeClass('editing');
                showAlert(type, response.message, true);
            } else {
                showAlert(type, response.message || 'Update failed.', false);
            }
        },
        error: function() {
            showAlert(type, 'Request failed. Please try again.', false);
        },
        complete: function() {
            $btn.prop('disabled', false).text('Save');
        }
    });
});

// Shared confirmation modal (no native confirm()/alert() dialogs). The
// checkbox's native toggle is prevented on click; the visual check state is
// only ever changed after the user explicitly confirms, so a cancel leaves
// the row exactly as it was.
var _libActiveModal = null;

function getLibActiveModal() {
    if (!_libActiveModal && typeof bootstrap !== 'undefined') {
        _libActiveModal = new bootstrap.Modal(document.getElementById('lib-active-confirm-modal'));
    }
    return _libActiveModal;
}

function submitActiveChange($chk, targetActive) {
    var $row = $chk.closest('.lib-row');
    var type = $row.data('type');
    var id = $row.data('id');

    $chk.prop('checked', targetActive).prop('disabled', true);

    $.ajax({
        url: BACKEND_URL + '?action=updateLibrary',
        type: 'POST',
        dataType: 'json',
        data: {
            type: type,
            id: id,
            is_active: targetActive ? 1 : 0
        },
        success: function(response) {
            if (response.success) {
                showAlert(type, response.message, true);
            } else {
                $chk.prop('checked', !targetActive);
                showAlert(type, response.message || 'Update failed.', false);
            }
        },
        error: function() {
            $chk.prop('checked', !targetActive);
            showAlert(type, 'Request failed. Please try again.', false);
        },
        complete: function() {
            $chk.prop('disabled', false);
        }
    });
}

$(document).on('click', '.lib-active-toggle', function(e) {
    e.preventDefault();
    if (!IS_SUPERADMIN) { return; }

    var $chk = $(this);
    var $row = $chk.closest('.lib-row');
    var typeLabel = $row.data('type') === 'grade' ? 'grade' : 'evaluation structure';
    var label = $row.find('.lib-label-text').text();
    // Browsers flip a checkbox's checked state as part of the click's default
    // action BEFORE dispatching the click event, so by the time this handler
    // runs, $chk.prop('checked') already reflects the state the user is
    // trying to move to - not the pre-click state. Use it directly as the
    // target; don't invert it (that would flip it right back).
    var targetActive = $chk.prop('checked');

    var message = targetActive
        ? 'Reactivate the "' + label + '" ' + typeLabel + '? It will become selectable again.'
        : 'Deactivate the "' + label + '" ' + typeLabel + '? Staff currently assigned to it will keep it, but it will no longer be selectable for new assignments. This is a crucial masterlist entry - please confirm before continuing.';

    $('#lib-active-confirm-message').text(message);

    var modal = getLibActiveModal();
    if (!modal) {
        submitActiveChange($chk, targetActive);
        return;
    }

    $('#lib-active-confirm-ok').off('click').on('click', function() {
        modal.hide();
        submitActiveChange($chk, targetActive);
    });
    modal.show();
});

$(document).on('click', '.btn-add-new', function() {
    var type = $(this).data('type');
    var tbody = '#' + $(this).data('tbody');
    if ($(tbody).find('.lib-add-row').length) return;

    var row = '<tr class="lib-add-row">' +
        '<td><em class="text-muted" style="font-size:12px;">auto</em></td>' +
        '<td><input type="text" class="form-control form-control-sm lib-new-label" placeholder="Enter label..." data-type="' +
        type + '"></td>' +
        '<td>' +
        '<button class="btn btn-sm btn-primary btn-add-save me-1">Save</button>' +
        '<button class="btn btn-sm btn-outline-secondary btn-add-cancel">Cancel</button>' +
        '</td>' +
        '</tr>';
    $(tbody).append(row);
    $(tbody).find('.lib-new-label').focus();
});

$(document).on('click', '.btn-add-cancel', function() {
    $(this).closest('.lib-add-row').remove();
});

$(document).on('click', '.btn-add-save', function() {
    var $row = $(this).closest('.lib-add-row');
    var $input = $row.find('.lib-new-label');
    var type = $input.data('type');
    var label = $input.val().trim();
    var $btn = $(this);

    if (label === '') {
        $input.focus();
        return;
    }

    $btn.prop('disabled', true).text('Saving...');

    $.ajax({
        url: BACKEND_URL + '?action=addLibrary',
        type: 'POST',
        dataType: 'json',
        data: {
            type: type,
            label: label
        },
        success: function(response) {
            if (response.success) {
                $row.remove();
                showAlert(type, response.message, true);
                loadLibrary();
            } else {
                showAlert(type, response.message || 'Add failed.', false);
                $btn.prop('disabled', false).text('Save');
            }
        },
        error: function() {
            showAlert(type, 'Request failed. Please try again.', false);
            $btn.prop('disabled', false).text('Save');
        }
    });
});

function showAlert(type, msg, success) {
    var prefix = type === 'grade' ? 'grade' : 'struct';
    var $el = $('#' + prefix + '-alert');
    $el.removeClass('alert-success alert-danger')
        .addClass(success ? 'alert-success' : 'alert-danger')
        .css('display', 'block');
    $('#' + prefix + '-alert-msg').text(msg);
}

function dismissAlert(type) {
    $('#' + type + '-alert').css('display', 'none');
}
window.dismissAlert = dismissAlert;

// --------------------------------------------------- Reward Masterlist
function buildRewardRow(item) {
    var actionsHtml = IS_SUPERADMIN
        ? '<button class="btn btn-sm btn-outline-secondary btn-reward-edit">Edit</button>' +
          '<button class="btn btn-sm btn-primary btn-reward-save me-1">Save</button>' +
          '<button class="btn btn-sm btn-outline-secondary btn-reward-cancel">Cancel</button>'
        : '';
    return '<tr class="reward-row" data-id="' + item.id + '">' +
        '<td><strong>' + item.id + '</strong></td>' +
        '<td>' +
        '<span class="lib-label-text">' + $('<span>').text(item.reward_value).html() + '</span>' +
        '<input type="text" class="form-control form-control-sm lib-label-input" value="' + $('<span>').text(item.reward_value).html() + '">' +
        '</td>' +
        '<td class="text-center">' +
        '<input type="checkbox" class="form-check-input reward-active-toggle"' +
        (item.is_active ? ' checked' : '') + (IS_SUPERADMIN ? '' : ' disabled') + '>' +
        '</td>' +
        '<td>' + actionsHtml + '</td>' +
        '</tr>';
}

function showRewardAlert(msg, success) {
    $('#reward-alert').removeClass('alert-success alert-danger')
        .addClass(success ? 'alert-success' : 'alert-danger')
        .css('display', 'block');
    $('#reward-alert-msg').text(msg);
}

function dismissRewardAlert() { $('#reward-alert').css('display', 'none'); }
window.dismissRewardAlert = dismissRewardAlert;

function loadRewardMasterlist() {
    apiCall('get-reward-masterlist').then(function(response) {
        if (!response || !response.success) {
            $('#reward-tbody').html('<tr><td colspan="4" class="text-center text-danger py-3">' +
                $('<span>').text((response && response.message) || 'Failed to load.').html() + '</td></tr>');
            return;
        }
        var html = '';
        (response.data || []).forEach(function(item) { html += buildRewardRow(item); });
        $('#reward-tbody').html(html || '<tr><td colspan="4" class="text-center text-muted py-3">No records.</td></tr>');
    }).catch(function() {
        $('#reward-tbody').html('<tr><td colspan="4" class="text-center text-danger py-3">Request failed. Please try again.</td></tr>');
    });
}

$(document).on('click', '.btn-reward-edit', function() {
    $(this).closest('.reward-row').addClass('editing');
});

$(document).on('click', '.btn-reward-cancel', function() {
    var $row = $(this).closest('.reward-row');
    $row.find('.lib-label-input').val($row.find('.lib-label-text').text());
    $row.removeClass('editing');
});

$(document).on('click', '.btn-reward-save', function() {
    var $row = $(this).closest('.reward-row');
    var id = $row.data('id');
    var val = $row.find('.lib-label-input').val().trim();
    var $btn = $(this);

    if (val === '') { return; }

    $btn.prop('disabled', true).text('Saving...');

    apiCall('update-reward-masterlist', { id: id, reward_value: val }).then(function(response) {
        if (response.success) {
            $row.find('.lib-label-text').text(val);
            $row.removeClass('editing');
            showRewardAlert('Reward value updated.', true);
        } else {
            showRewardAlert(response.message || 'Update failed.', false);
        }
    }).catch(function() {
        showRewardAlert('Request failed. Please try again.', false);
    }).finally(function() {
        $btn.prop('disabled', false).text('Save');
    });
});

var _rewardActiveModal = null;
function getRewardActiveModal() {
    if (!_rewardActiveModal && typeof bootstrap !== 'undefined') {
        _rewardActiveModal = new bootstrap.Modal(document.getElementById('lib-active-confirm-modal'));
    }
    return _rewardActiveModal;
}

function submitRewardActiveChange($chk, targetActive) {
    var $row = $chk.closest('.reward-row');
    var id = $row.data('id');

    $chk.prop('checked', targetActive).prop('disabled', true);

    apiCall('update-reward-masterlist', { id: id, is_active: targetActive ? 1 : 0 }).then(function(response) {
        if (response.success) {
            showRewardAlert(response.message || 'Updated.', true);
        } else {
            $chk.prop('checked', !targetActive);
            showRewardAlert(response.message || 'Update failed.', false);
        }
    }).catch(function() {
        $chk.prop('checked', !targetActive);
        showRewardAlert('Request failed. Please try again.', false);
    }).finally(function() {
        $chk.prop('disabled', false);
    });
}

$(document).on('click', '.reward-active-toggle', function(e) {
    e.preventDefault();
    if (!IS_SUPERADMIN) { return; }

    var $chk = $(this);
    var $row = $chk.closest('.reward-row');
    var label = $row.find('.lib-label-text').text();
    var targetActive = $chk.prop('checked');

    var message = targetActive
        ? 'Reactivate the "' + label + '" reward value? It will become selectable again on the Outlet ATEM create form.'
        : 'Deactivate the "' + label + '" reward value? Existing cards keep it, but it will no longer be selectable for new Outlet ATEM cards.';

    $('#lib-active-confirm-message').text(message);

    // Reuses the same shared confirm modal as Grade/Struct (#lib-active-confirm-modal) -
    // only one such modal exists per page, and only one toggle can be pending at a time.
    var modal = getRewardActiveModal();
    if (!modal) {
        submitRewardActiveChange($chk, targetActive);
        return;
    }

    $('#lib-active-confirm-ok').off('click').on('click', function() {
        modal.hide();
        submitRewardActiveChange($chk, targetActive);
    });
    modal.show();
});

$(document).on('click', '.btn-reward-add-new', function() {
    if ($('#reward-tbody').find('.lib-add-row').length) { return; }

    var row = '<tr class="lib-add-row">' +
        '<td><em class="text-muted" style="font-size:12px;">auto</em></td>' +
        '<td><input type="text" class="form-control form-control-sm reward-new-value" placeholder="e.g. Free Ticket"></td>' +
        '<td></td>' +
        '<td>' +
        '<button class="btn btn-sm btn-primary btn-reward-add-save me-1">Save</button>' +
        '<button class="btn btn-sm btn-outline-secondary btn-reward-add-cancel">Cancel</button>' +
        '</td>' +
        '</tr>';
    $('#reward-tbody').append(row);
    $('#reward-tbody').find('.reward-new-value').focus();
});

$(document).on('click', '.btn-reward-add-cancel', function() {
    $(this).closest('.lib-add-row').remove();
});

$(document).on('click', '.btn-reward-add-save', function() {
    var $row = $(this).closest('.lib-add-row');
    var $input = $row.find('.reward-new-value');
    var val = $input.val().trim();
    var $btn = $(this);

    if (val === '') {
        $input.focus();
        return;
    }

    $btn.prop('disabled', true).text('Saving...');

    apiCall('add-reward-masterlist', { reward_value: val }).then(function(response) {
        if (response.success) {
            $row.remove();
            showRewardAlert('Reward value added.', true);
            loadRewardMasterlist();
        } else {
            showRewardAlert(response.message || 'Add failed.', false);
            $btn.prop('disabled', false).text('Save');
        }
    }).catch(function() {
        showRewardAlert('Request failed. Please try again.', false);
        $btn.prop('disabled', false).text('Save');
    });
});

loadLibrary();
loadRewardMasterlist();
</script>
</body>

</html>