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

    renderStructWindowStatus(STRUCT_WINDOW_OPEN);
    renderBackdateStatus(BACKDATE_ENABLED);

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

})();
