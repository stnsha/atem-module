(function () {
    'use strict';

    var selectedStaffId = null;
    var currentPage     = 1;
    var saPerPage        = 30;
    var nameFilter       = '';

    function badge(isYes) {
        return isYes
            ? '<span class="atem-pill bg-success">Yes</span>'
            : '<span class="atem-pill bg-secondary">No</span>';
    }

    function loadStaff(page) {
        currentPage = page || 1;
        $('#sa-staff-tbody').html('<tr><td colspan="5" class="text-center text-muted py-3">Loading...</td></tr>');

        var url = ADMIN_BACKEND_URL + '?action=getSuperAdminStaff&page=' + currentPage + '&per_page=' + saPerPage;
        if (nameFilter !== '') { url += '&name_filter=' + encodeURIComponent(nameFilter); }

        $.ajax({
            url: url,
            type: 'GET',
            dataType: 'json',
            success: function (response) {
                if (!response.success) {
                    $('#sa-staff-tbody').html('<tr><td colspan="5" class="text-center text-muted py-3">Failed to load.</td></tr>');
                    return;
                }
                renderTable(response.data);
                renderPagination(response.page, response.total_pages, response.total, response.per_page);
            },
            error: function () {
                $('#sa-staff-tbody').html('<tr><td colspan="5" class="text-center text-muted py-3">Error loading data.</td></tr>');
            }
        });
    }

    function renderTable(data) {
        if (!data || data.length === 0) {
            $('#sa-staff-tbody').html('<tr><td colspan="5" class="text-center text-muted py-3">No records found.</td></tr>');
            return;
        }
        var html = '';
        $.each(data, function (i, s) {
            html += '<tr>' +
                '<td>' + $('<span>').text(s.nama_staff).html() + '</td>' +
                '<td class="text-muted">' + $('<span>').text(s.department_name).html() + '</td>' +
                '<td>' + badge(s.atem === 1) + '</td>' +
                '<td>' + badge(s.okr === 1) + '</td>' +
                '<td><button type="button" class="btn btn-sm btn-outline-secondary sa-edit-btn"' +
                ' data-staff-id="' + s.id + '"' +
                ' data-staff-name="' + $('<span>').text(s.nama_staff).html() + '"' +
                ' data-staff-dept="' + $('<span>').text(s.department_name).html() + '"' +
                ' data-staff-atem="' + s.atem + '"' +
                ' data-staff-okr="' + s.okr + '"' +
                '>Edit</button></td>' +
                '</tr>';
        });
        $('#sa-staff-tbody').html(html);
    }

    function saPageBtn(p, activePg) {
        return '<button type="button" class="atem-pager-btn' + (p === activePg ? ' active' : '') + '" data-page="' + p + '">' + p + '</button>';
    }

    function renderPagination(page, totalPages, total, perPage) {
        var start = total === 0 ? 0 : (page - 1) * perPage + 1;
        var end   = Math.min(page * perPage, total);
        var pager = document.getElementById('sa-staff-pager');
        if (!pager) { return; }
        if (total === 0) { pager.innerHTML = ''; return; }

        var opts    = [10, 30, 50, 100];
        var selHtml = '<select class="atem-perpage-select">';
        for (var oi = 0; oi < opts.length; oi++) {
            selHtml += '<option value="' + opts[oi] + '"' + (saPerPage === opts[oi] ? ' selected' : '') + '>' + opts[oi] + '</option>';
        }
        selHtml += '</select>';
        var leftHtml = '<div class="atem-pager-left">Show ' + selHtml + ' entries</div>';
        var info     = '<span class="atem-pager-info">Showing ' + start + ' to ' + end + ' of ' + total + ' entries</span>';

        if (totalPages <= 1) {
            pager.innerHTML = leftHtml + '<div class="d-flex align-items-center gap-2">' + info + '</div>';
            return;
        }

        var win = 2, pfrom = Math.max(1, page - win), pto = Math.min(totalPages, page + win);
        var btns = '<button type="button" class="atem-pager-btn" data-page="' + (page - 1) + '"' + (page === 1 ? ' disabled' : '') + '>Previous</button>';
        if (pfrom > 1) { btns += saPageBtn(1, page) + (pfrom > 2 ? '<span class="atem-pager-gap">...</span>' : ''); }
        for (var p = pfrom; p <= pto; p++) { btns += saPageBtn(p, page); }
        if (pto < totalPages) { btns += (pto < totalPages - 1 ? '<span class="atem-pager-gap">...</span>' : '') + saPageBtn(totalPages, page); }
        btns += '<button type="button" class="atem-pager-btn" data-page="' + (page + 1) + '"' + (page === totalPages ? ' disabled' : '') + '>Next</button>';

        var rightHtml = '<div class="d-flex align-items-center gap-2">' + info + '<div class="atem-pager-bar">' + btns + '</div></div>';
        pager.innerHTML = leftHtml + rightHtml;
    }

    var _saPager = document.getElementById('sa-staff-pager');
    if (_saPager) {
        _saPager.addEventListener('click', function (e) {
            var btn = e.target.closest ? e.target.closest('.atem-pager-btn') : null;
            if (btn && !btn.disabled) {
                var p = parseInt(btn.getAttribute('data-page'), 10);
                if (!isNaN(p) && p >= 1) { loadStaff(p); }
            }
        });
        _saPager.addEventListener('change', function (e) {
            if (e.target.classList.contains('atem-perpage-select')) {
                saPerPage   = parseInt(e.target.value, 10);
                currentPage = 1;
                loadStaff(1);
            }
        });
    }

    $('#sa-apply-filter').on('click', function () {
        nameFilter = $.trim($('#sa-filter-name').val());
        loadStaff(1);
    });

    $('#sa-reset-filter').on('click', function () {
        nameFilter = '';
        $('#sa-filter-name').val('');
        loadStaff(1);
    });

    $('#sa-filter-name').on('keydown', function (e) {
        if (e.key === 'Enter') { $('#sa-apply-filter').trigger('click'); }
    });

    function resetSaForm() {
        selectedStaffId = null;
        $('#sa-edit-panel').hide();
        $('#sa-empty-msg').show();
    }

    $(document).on('click', '.sa-edit-btn', function () {
        selectedStaffId = $(this).data('staff-id');
        $('#sa-info-name').text($(this).data('staff-name'));
        $('#sa-info-dept').text($(this).data('staff-dept'));
        $('#sa-atem-toggle').prop('checked', parseInt($(this).data('staff-atem'), 10) === 1);
        $('#sa-okr-toggle').prop('checked', parseInt($(this).data('staff-okr'), 10) === 1);
        $('#sa-empty-msg').hide();
        $('#sa-edit-panel').show();
    });

    $('#sa-cancel-btn').on('click', resetSaForm);

    $('#sa-update-btn').on('click', function () {
        if (!selectedStaffId) return;

        var atemVal = $('#sa-atem-toggle').prop('checked') ? 1 : 0;
        var okrVal  = $('#sa-okr-toggle').prop('checked') ? 1 : 0;
        var btn     = $(this);
        btn.prop('disabled', true).text('Saving...');

        $.ajax({
            url: ADMIN_BACKEND_URL + '?action=updateSuperAdmin',
            type: 'POST',
            dataType: 'json',
            data: { staff_id: selectedStaffId, atem: atemVal, okr: okrVal },
            success: function (response) {
                if (response.success) {
                    showSaAlert(response.message, true);
                    loadStaff(currentPage);
                } else {
                    showSaAlert(response.message || 'Update failed.', false);
                }
            },
            error: function () { showSaAlert('Request failed. Please try again.', false); },
            complete: function () { btn.prop('disabled', false).text('Update'); }
        });
    });

    function showSaAlert(msg, success) {
        var el = $('#sa-form-alert');
        el.removeClass('alert-success alert-danger')
          .addClass(success ? 'alert-success' : 'alert-danger')
          .css('display', 'block');
        $('#sa-form-alert-msg').text(msg);
    }

    function dismissSaAlert() { $('#sa-form-alert').css('display', 'none'); }
    window.dismissSaAlert = dismissSaAlert;

    loadStaff(1);
})();
