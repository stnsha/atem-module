(function () {
    'use strict';

    var selectedStaffId = null;
    var STRUCT_OPTIONS  = [];   // [{id, label, grade}]
    var STRUCT_LABELS   = {};   // id -> label

    // -----------------------------------------------------------------------
    // Struct options: load from getLibrary and populate radio buttons + mgmt
    // -----------------------------------------------------------------------
    function loadAllStructData(callback) {
        $.ajax({
            url: BACKEND_URL + '?action=getLibrary',
            type: 'GET',
            dataType: 'json',
            success: function (response) {
                if (!response.success) return;
                STRUCT_OPTIONS = [];
                STRUCT_LABELS  = {};
                $.each(response.structs, function (i, s) {
                    STRUCT_OPTIONS.push({ id: s.id, label: s.label });
                    STRUCT_LABELS[s.id] = s.label;
                });
                if (typeof callback === 'function') { callback(); }
            }
        });
    }

    function renderStructRadios(selectedStructId) {
        if (STRUCT_OPTIONS.length === 0) {
            $('#struct-radio-list').html('');
            $('#struct-none-msg').show();
            return;
        }

        $('#struct-none-msg').hide();
        var html = '';
        for (var j = 0; j < STRUCT_OPTIONS.length; j++) {
            var s       = STRUCT_OPTIONS[j];
            var checked = (s.id === selectedStructId) ? ' checked' : '';
            html += '<div class="form-check">' +
                '<input class="form-check-input" type="radio" name="struct" id="struct-' + s.id + '" value="' + s.id + '"' + checked + '>' +
                '<label class="form-check-label" for="struct-' + s.id + '">' + $('<span>').text(s.label).html() + '</label>' +
                '</div>';
        }
        $('#struct-radio-list').html(html);
    }

    // -----------------------------------------------------------------------
    // Reset form — clears search, hides all right-side panels
    // -----------------------------------------------------------------------
    function resetForm() {
        selectedStaffId = null;
        $('#staff-info').hide();
        $('#grade-section').hide();
        $('#struct-section').hide();
        $('#submit-section').css('display', 'none');
        $('#staff-search').val(null).trigger('change');
    }

    // -----------------------------------------------------------------------
    // Search (Select2) and staff info — only wired when grade 2+
    // -----------------------------------------------------------------------
    if (SHOW_EDIT) {
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
                data: function (params) { return { search_term: params.term }; },
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
        $('#staff-search').on('select2:select', function (e) { showStaffInfo(e.params.data.staff_data); });
        $('#staff-search').on('select2:clear', function () { resetForm(); });
    }

    function showStaffInfo(staff) {
        selectedStaffId = staff.id;
        var grade       = parseInt(staff.grade, 10) || 0;
        var gradeLabel  = GRADE_LABELS[grade] !== undefined ? GRADE_LABELS[grade] + ' (' + grade + ')' : 'Unknown';
        var structName  = staff.struct_name || '-';
        var structId    = parseInt(staff.struct_id, 10) || 0;

        $('#info-name').text(staff.nama_staff);
        $('#info-dept').text(staff.department_name || '-');
        $('#info-status').text(staff.status_semasa || '-');
        $('#info-grade').text(gradeLabel);
        $('#info-struct').text(structName);
        $('#staff-info').show();

        $('input[name="grade"][value="' + grade + '"]').prop('checked', true);
        $('#grade-section').show();

        renderStructRadios(structId);
        $('#struct-section').show();

        $('#submit-section').css('display', 'flex');
    }

    // Grade radio change → ensure struct section is visible
    $(document).on('change', 'input[name="grade"]', function () {
        if (!selectedStaffId) return;
        $('#struct-section').show();
    });

    $(document).on('click', '.edit-btn', function () {
        var staffId       = $(this).data('staff-id');
        var staffName     = $(this).data('staff-name');
        var staffDept     = $(this).data('staff-dept');
        var staffStatus   = $(this).data('staff-status')      || '-';
        var staffGrade    = parseInt($(this).data('staff-grade'),     10) || 0;
        var staffStructId = parseInt($(this).data('staff-struct-id'), 10) || 0;
        var staffStructNm = $(this).data('staff-struct-name') || '-';

        selectedStaffId = staffId;

        var option = new Option(staffName, staffId, true, true);
        $('#staff-search').append(option).trigger('change');

        showStaffInfo({
            id:              staffId,
            nama_staff:      staffName,
            department_name: staffDept,
            status_semasa:   staffStatus,
            grade:           staffGrade,
            struct_id:       staffStructId,
            struct_name:     staffStructNm
        });
    });

    $('#cancel-btn').on('click', function () { resetForm(); });

    // -----------------------------------------------------------------------
    // Active staff table
    // -----------------------------------------------------------------------
    var currentPage  = 1;
    var adminPerPage = 30;

    function loadActiveStaff(page) {
        currentPage = page || 1;
        var colSpan = TABLE_COLS;
        $('#active-staff-tbody').html('<tr><td colspan="' + colSpan + '" class="text-center text-muted py-3">Loading...</td></tr>');

        $.ajax({
            url: BACKEND_URL + '?action=getActiveStaff&page=' + currentPage + '&per_page=' + adminPerPage,
            type: 'GET',
            dataType: 'json',
            success: function (response) {
                if (!response.success) {
                    $('#active-staff-tbody').html('<tr><td colspan="' + colSpan + '" class="text-center text-muted py-3">Failed to load.</td></tr>');
                    return;
                }
                renderTable(response.data);
                renderPagination(response.page, response.total_pages, response.total, response.per_page);
            },
            error: function () {
                $('#active-staff-tbody').html('<tr><td colspan="' + colSpan + '" class="text-center text-muted py-3">Error loading data.</td></tr>');
            }
        });
    }

    function renderTable(data) {
        var colSpan = TABLE_COLS;
        if (!data || data.length === 0) {
            $('#active-staff-tbody').html('<tr><td colspan="' + colSpan + '" class="text-center text-muted py-3">No records found.</td></tr>');
            return;
        }
        var html = '';
        $.each(data, function (i, s) {
            var grade      = s.grade;
            var gradeLabel = GRADE_LABELS[grade] !== undefined ? GRADE_LABELS[grade] : 'Unknown';
            var gradeBadge = GRADE_BADGES[grade] !== undefined ? GRADE_BADGES[grade] : 'bg-secondary';
            var structName = s.struct_name || '-';
            var structId   = s.struct_id   || 0;

            html += '<tr>' +
                '<td>' + $('<span>').text(s.nama_staff).html() + '</td>' +
                '<td class="text-muted">' + $('<span>').text(s.department_name).html() + '</td>' +
                '<td><span class="atem-pill ' + gradeBadge + '">' + gradeLabel + '</span></td>' +
                '<td class="text-muted">' + $('<span>').text(structName).html() + '</td>';

            if (SHOW_EDIT) {
                html += '<td><button type="button" class="btn btn-sm btn-outline-secondary edit-btn"' +
                    ' data-staff-id="' + s.id + '"' +
                    ' data-staff-name="' + $('<span>').text(s.nama_staff).html() + '"' +
                    ' data-staff-dept="' + $('<span>').text(s.department_name).html() + '"' +
                    ' data-staff-status="' + $('<span>').text(s.status_semasa).html() + '"' +
                    ' data-staff-grade="' + grade + '"' +
                    ' data-staff-struct-id="' + structId + '"' +
                    ' data-staff-struct-name="' + $('<span>').text(structName).html() + '"' +
                    '>Edit</button></td>';
            }

            html += '</tr>';
        });
        $('#active-staff-tbody').html(html);
    }

    function adminPageBtn(p, activePg) {
        return '<button type="button" class="atem-pager-btn' + (p === activePg ? ' active' : '') + '" data-page="' + p + '">' + p + '</button>';
    }

    function renderPagination(page, totalPages, total, perPage) {
        var start = total === 0 ? 0 : (page - 1) * perPage + 1;
        var end   = Math.min(page * perPage, total);
        var pager = document.getElementById('admin-staff-pager');
        if (!pager) { return; }
        if (total === 0) { pager.innerHTML = ''; return; }

        var opts    = [10, 30, 50, 100];
        var selHtml = '<select class="atem-perpage-select">';
        for (var oi = 0; oi < opts.length; oi++) {
            selHtml += '<option value="' + opts[oi] + '"' + (adminPerPage === opts[oi] ? ' selected' : '') + '>' + opts[oi] + '</option>';
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
        if (pfrom > 1) { btns += adminPageBtn(1, page) + (pfrom > 2 ? '<span class="atem-pager-gap">...</span>' : ''); }
        for (var p = pfrom; p <= pto; p++) { btns += adminPageBtn(p, page); }
        if (pto < totalPages) { btns += (pto < totalPages - 1 ? '<span class="atem-pager-gap">...</span>' : '') + adminPageBtn(totalPages, page); }
        btns += '<button type="button" class="atem-pager-btn" data-page="' + (page + 1) + '"' + (page === totalPages ? ' disabled' : '') + '>Next</button>';

        var rightHtml = '<div class="d-flex align-items-center gap-2">' + info + '<div class="atem-pager-bar">' + btns + '</div></div>';
        pager.innerHTML = leftHtml + rightHtml;
    }

    var _adminPager = document.getElementById('admin-staff-pager');
    if (_adminPager) {
        _adminPager.addEventListener('click', function (e) {
            var btn = e.target.closest ? e.target.closest('.atem-pager-btn') : null;
            if (btn && !btn.disabled) {
                var p = parseInt(btn.getAttribute('data-page'), 10);
                if (!isNaN(p) && p >= 1) { loadActiveStaff(p); }
            }
        });
        _adminPager.addEventListener('change', function (e) {
            if (e.target.classList.contains('atem-perpage-select')) {
                adminPerPage = parseInt(e.target.value, 10);
                currentPage  = 1;
                loadActiveStaff(1);
            }
        });
    }

    // -----------------------------------------------------------------------
    // Update staff (grade + struct)
    // -----------------------------------------------------------------------
    $('#update-btn').on('click', function () {
        if (!selectedStaffId) return;

        var grade = $('input[name="grade"]:checked').val();
        if (grade === undefined) {
            showAlert('Please select a grade level.', false);
            return;
        }

        var structId = $('input[name="struct"]:checked').val() || '0';
        var btn      = $(this);
        btn.prop('disabled', true).text('Saving...');

        $.ajax({
            url: BACKEND_URL + '?action=updateAccess',
            type: 'POST',
            dataType: 'json',
            data: { staff_id: selectedStaffId, grade: grade, struct_id: structId },
            success: function (response) {
                if (response.success) {
                    showAlert(response.message, true);
                    loadActiveStaff(currentPage);

                    var g      = parseInt(grade, 10);
                    var label  = GRADE_LABELS[g] !== undefined ? GRADE_LABELS[g] + ' (' + g + ')' : grade;
                    $('#info-grade').text(label);

                    var sId    = parseInt(structId, 10) || 0;
                    var sLabel = (sId && STRUCT_LABELS[sId]) ? STRUCT_LABELS[sId] : '-';
                    $('#info-struct').text(sLabel);
                } else {
                    showAlert(response.message || 'Update failed.', false);
                }
            },
            error: function () { showAlert('Request failed. Please try again.', false); },
            complete: function () { btn.prop('disabled', false).text('Update'); }
        });
    });

    function showAlert(msg, success) {
        var el = $('#form-alert');
        el.removeClass('alert-success alert-danger')
          .addClass(success ? 'alert-success' : 'alert-danger')
          .css('display', 'block');
        $('#form-alert-msg').text(msg);
    }

    function dismissAlert() { $('#form-alert').css('display', 'none'); }
    window.dismissAlert = dismissAlert;

    // -----------------------------------------------------------------------
    // Init
    // -----------------------------------------------------------------------
    loadAllStructData(function () { loadActiveStaff(1); });

})();
