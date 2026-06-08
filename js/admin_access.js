(function () {
    'use strict';

    var selectedStaffId = null;

    var select2Config = {
        placeholder: 'Type staff name to search...',
        minimumInputLength: 2,
        allowClear: true,
        width: '100%',
        ajax: {
            url: BACKEND_URL + '?action=searchStaff',
            type: 'POST',
            dataType: 'json',
            delay: 250,
            data: function (params) {
                return { search_term: params.term };
            },
            processResults: function (data) {
                return {
                    results: $.map(data, function (s) {
                        return { id: s.id, text: s.nama_staff, staff_data: s };
                    })
                };
            },
            cache: true
        },
        templateResult: function (data) {
            if (!data.id) return data.text;
            return $('<span style="font-size:13px;font-family:Inter,sans-serif;">' + data.text + '</span>');
        },
        templateSelection: function (data) {
            return $('<span style="font-size:13px;font-family:Inter,sans-serif;">' + data.text + '</span>');
        }
    };

    $('#staff-search').select2(select2Config);

    $('#staff-search').on('select2:select', function (e) {
        showStaffInfo(e.params.data.staff_data);
    });

    $('#staff-search').on('select2:clear', function () {
        selectedStaffId = null;
        $('#staff-info').hide();
        $('#grade-section').hide();
        $('#submit-section').css('display', 'none');
    });

    function showStaffInfo(staff) {
        selectedStaffId = staff.id;
        var grade      = staff.grade;
        var gradeLabel = GRADE_LABELS[grade] !== undefined ? GRADE_LABELS[grade] + ' (' + grade + ')' : 'Unknown';

        $('#info-name').text(staff.nama_staff);
        $('#info-dept').text(staff.department_name || '-');
        $('#info-status').text(staff.status_semasa || '-');
        $('#info-grade').text(gradeLabel);
        $('#staff-info').show();

        $('input[name="grade"][value="' + grade + '"]').prop('checked', true);
        $('#grade-section').show();
        $('#submit-section').css('display', 'flex');
    }

    $(document).on('click', '.edit-btn', function () {
        var staffId     = $(this).data('staff-id');
        var staffName   = $(this).data('staff-name');
        var staffDept   = $(this).data('staff-dept');
        var staffStatus = $(this).data('staff-status') || '-';
        var staffGrade  = parseInt($(this).data('staff-grade'), 10);

        selectedStaffId = staffId;

        var option = new Option(staffName, staffId, true, true);
        $('#staff-search').append(option).trigger('change');

        showStaffInfo({
            id:              staffId,
            nama_staff:      staffName,
            department_name: staffDept,
            status_semasa:   staffStatus,
            grade:           staffGrade
        });
    });

    var currentPage = 1;

    function loadActiveStaff(page) {
        currentPage = page || 1;
        $('#active-staff-tbody').html('<tr><td colspan="4" class="text-center text-muted py-3">Loading...</td></tr>');

        $.ajax({
            url: BACKEND_URL + '?action=getActiveStaff&page=' + currentPage,
            type: 'GET',
            dataType: 'json',
            success: function (response) {
                if (!response.success) {
                    $('#active-staff-tbody').html('<tr><td colspan="4" class="text-center text-muted py-3">Failed to load.</td></tr>');
                    return;
                }
                renderTable(response.data);
                renderPagination(response.page, response.total_pages, response.total, response.per_page);
            },
            error: function () {
                $('#active-staff-tbody').html('<tr><td colspan="4" class="text-center text-muted py-3">Error loading data.</td></tr>');
            }
        });
    }

    function renderTable(data) {
        if (!data || data.length === 0) {
            $('#active-staff-tbody').html('<tr><td colspan="4" class="text-center text-muted py-3">No staff with active grades.</td></tr>');
            return;
        }
        var html = '';
        $.each(data, function (i, s) {
            var grade      = s.grade;
            var gradeLabel = GRADE_LABELS[grade] !== undefined ? GRADE_LABELS[grade] : 'Unknown';
            var gradeBadge = GRADE_BADGES[grade] !== undefined ? GRADE_BADGES[grade] : 'bg-secondary';
            html += '<tr>' +
                '<td>' + $('<span>').text(s.nama_staff).html() + '</td>' +
                '<td class="text-muted">' + $('<span>').text(s.department_name).html() + '</td>' +
                '<td><span class="badge ' + gradeBadge + '">' + gradeLabel + '</span></td>' +
                '<td><button type="button" class="btn btn-sm btn-outline-secondary edit-btn"' +
                    ' data-staff-id="' + s.id + '"' +
                    ' data-staff-name="' + $('<span>').text(s.nama_staff).html() + '"' +
                    ' data-staff-dept="' + $('<span>').text(s.department_name).html() + '"' +
                    ' data-staff-status="' + $('<span>').text(s.status_semasa).html() + '"' +
                    ' data-staff-grade="' + grade + '">Edit</button></td>' +
                '</tr>';
        });
        $('#active-staff-tbody').html(html);
    }

    function renderPagination(page, totalPages, total, perPage) {
        var start = total === 0 ? 0 : (page - 1) * perPage + 1;
        var end   = Math.min(page * perPage, total);
        $('#table-info').text('Showing ' + start + ' to ' + end + ' of ' + total + ' entries');

        if (totalPages <= 1) {
            $('#table-pagination').html('');
            return;
        }

        var html = '';
        html += '<li class="page-item ' + (page === 1 ? 'disabled' : '') + '">' +
            '<a class="page-link" href="#" data-page="' + (page - 1) + '">Previous</a></li>';

        for (var p = 1; p <= totalPages; p++) {
            html += '<li class="page-item ' + (p === page ? 'active' : '') + '">' +
                '<a class="page-link" href="#" data-page="' + p + '">' + p + '</a></li>';
        }

        html += '<li class="page-item ' + (page === totalPages ? 'disabled' : '') + '">' +
            '<a class="page-link" href="#" data-page="' + (page + 1) + '">Next</a></li>';

        $('#table-pagination').html(html);
    }

    $(document).on('click', '#table-pagination a.page-link', function (e) {
        e.preventDefault();
        var page = parseInt($(this).data('page'), 10);
        if (!isNaN(page) && page >= 1) {
            loadActiveStaff(page);
        }
    });

    loadActiveStaff(1);

    $('#update-btn').on('click', function () {
        if (!selectedStaffId) return;

        var grade = $('input[name="grade"]:checked').val();
        if (grade === undefined) {
            showAlert('Please select a grade level.', false);
            return;
        }

        var btn = $(this);
        btn.prop('disabled', true).text('Saving...');

        $.ajax({
            url: BACKEND_URL + '?action=updateAccess',
            type: 'POST',
            dataType: 'json',
            data: { staff_id: selectedStaffId, grade: grade },
            success: function (response) {
                if (response.success) {
                    showAlert(response.message, true);
                    loadActiveStaff(currentPage);
                    var g = parseInt(grade, 10);
                    var label = GRADE_LABELS[g] !== undefined ? GRADE_LABELS[g] + ' (' + g + ')' : grade;
                    $('#info-grade').text(label);
                } else {
                    showAlert(response.message || 'Update failed.', false);
                }
            },
            error: function () {
                showAlert('Request failed. Please try again.', false);
            },
            complete: function () {
                btn.prop('disabled', false).text('Update Grade');
            }
        });
    });

    function showAlert(msg, success) {
        var el = $('#form-alert');
        el.removeClass('alert-success alert-danger')
          .addClass(success ? 'alert-success' : 'alert-danger')
          .css('display', 'block');
        $('#form-alert-msg').text(msg);
    }

    function dismissAlert() {
        $('#form-alert').css('display', 'none');
    }
    window.dismissAlert = dismissAlert;

})();
