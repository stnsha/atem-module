(function () {
    'use strict';

    function renderStructWindowStatus(open) {
        var el = $('#struct-window-status');
        if (open) {
            el.text('Open').css('color', '#198754');
        } else {
            el.text('Closed').css('color', '#6c757d');
        }
    }

    function renderBackdateStatus(enabled) {
        var el = $('#backdate-status');
        if (enabled) {
            el.text('Enabled').css('color', '#198754');
        } else {
            el.text('Disabled').css('color', '#6c757d');
        }
    }

    function renderOkrBackdateStatus(enabled) {
        var el = $('#okr-backdate-status');
        if (enabled) {
            el.text('Enabled').css('color', '#198754');
        } else {
            el.text('Disabled').css('color', '#6c757d');
        }
    }

    renderStructWindowStatus(STRUCT_WINDOW_OPEN);
    renderBackdateStatus(BACKDATE_ENABLED);
    renderOkrBackdateStatus(OKR_BACKDATE_ENABLED);

    $('#struct-window-toggle').on('change', function () {
        var newVal = $(this).prop('checked') ? 1 : 0;
        var toggle = $(this);
        toggle.prop('disabled', true);
        $.ajax({
            url: ADMIN_BACKEND_URL + '?action=toggleStructWindow',
            type: 'POST',
            dataType: 'json',
            data: { value: newVal },
            success: function (res) {
                if (res.success) {
                    renderStructWindowStatus(res.value === 1);
                } else {
                    toggle.prop('checked', !toggle.prop('checked'));
                }
            },
            error: function () {
                toggle.prop('checked', !toggle.prop('checked'));
            },
            complete: function () { toggle.prop('disabled', false); }
        });
    });

    $('#backdate-toggle').on('change', function () {
        var newVal = $(this).prop('checked') ? 1 : 0;
        var toggle = $(this);
        toggle.prop('disabled', true);
        $.ajax({
            url: ADMIN_BACKEND_URL + '?action=toggleBackdate',
            type: 'POST',
            dataType: 'json',
            data: { value: newVal },
            success: function (res) {
                if (res.success) {
                    renderBackdateStatus(res.value === 1);
                } else {
                    toggle.prop('checked', !toggle.prop('checked'));
                }
            },
            error: function () {
                toggle.prop('checked', !toggle.prop('checked'));
            },
            complete: function () { toggle.prop('disabled', false); }
        });
    });

    $('#payout-lock-window-save').on('click', function () {
        var btn = $(this);
        var statusEl = $('#payout-lock-window-status');
        var data = {};
        var inputs = {};
        for (var q = 1; q <= 4; q++) {
            inputs[q] = $('#payout-lock-window-q' + q);
            data['q' + q] = parseInt(inputs[q].val(), 10) || 10;
        }
        btn.prop('disabled', true);
        statusEl.text('');
        $.ajax({
            url: ADMIN_BACKEND_URL + '?action=updatePayoutLockWindow',
            type: 'POST',
            dataType: 'json',
            data: data,
            success: function (res) {
                if (res.success) {
                    for (var q = 1; q <= 4; q++) { inputs[q].val(res.values[q]); }
                    statusEl.text('Saved').css('color', '#198754');
                } else {
                    statusEl.text('Failed to save').css('color', '#dc3545');
                }
            },
            error: function () {
                statusEl.text('Failed to save').css('color', '#dc3545');
            },
            complete: function () {
                btn.prop('disabled', false);
                setTimeout(function () { statusEl.text(''); }, 2000);
            }
        });
    });

    $('#okr-backdate-toggle').on('change', function () {
        var newVal = $(this).prop('checked') ? 1 : 0;
        var toggle = $(this);
        toggle.prop('disabled', true);
        $.ajax({
            url: ADMIN_BACKEND_URL + '?action=toggleOkrBackdate',
            type: 'POST',
            dataType: 'json',
            data: { value: newVal },
            success: function (res) {
                if (res.success) {
                    renderOkrBackdateStatus(res.value === 1);
                } else {
                    toggle.prop('checked', !toggle.prop('checked'));
                }
            },
            error: function () {
                toggle.prop('checked', !toggle.prop('checked'));
            },
            complete: function () { toggle.prop('disabled', false); }
        });
    });

})();
