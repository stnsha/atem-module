(function () {
    'use strict';

    var selectedStaffId = null;
    var STRUCT_OPTIONS  = [];   // [{id, label, grade}]
    var STRUCT_LABELS   = {};   // id -> label
    var GRADE_OPTIONS   = [];   // [{id, label}]

    function debounce(fn, wait) {
        var t;
        return function () {
            var ctx = this, args = arguments;
            clearTimeout(t);
            t = setTimeout(function () { fn.apply(ctx, args); }, wait);
        };
    }

    function escapeHtml(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }

    // -----------------------------------------------------------------------
    // Searchable select ("select2-lite") — mirrors buildSearchDropdown() in
    // js/index.js, used here for the Outlet ATEM tab's Outlet filter.
    // -----------------------------------------------------------------------
    function syncSearchDropdownSize(baseId, sizeRefId) {
        var btnEl = document.getElementById(baseId + '-btn');
        var refEl = document.getElementById(sizeRefId);
        if (refEl && btnEl) {
            var refStyle = window.getComputedStyle(refEl);
            btnEl.style.height       = refEl.offsetHeight + 'px';
            btnEl.style.border       = refStyle.border;
            btnEl.style.borderRadius = refStyle.borderRadius;
            btnEl.style.fontSize     = refStyle.fontSize;
            btnEl.style.color        = refStyle.color;
        }
    }

    function buildSearchDropdown(baseId, items, allLabel, sizeRefId, onSelect) {
        var listEl   = document.getElementById(baseId + '-list');
        var searchEl = document.getElementById(baseId + '-search');
        var btnEl    = document.getElementById(baseId + '-btn');
        var dropEl   = document.getElementById(baseId + '-dropdown');
        var valEl    = document.getElementById(baseId + '-value');
        var wrapEl   = document.getElementById(baseId + '-wrap');
        if (!listEl || !btnEl || !dropEl) { return; }

        syncSearchDropdownSize(baseId, sizeRefId);

        var html = '<li class="vf-s2-list-item" data-id="0">' + escapeHtml(allLabel) + '</li>';
        for (var i = 0; i < items.length; i++) {
            html += '<li class="vf-s2-list-item" data-id="' + items[i].id + '">' + escapeHtml(items[i].name) + '</li>';
        }
        listEl.innerHTML = html;

        function openDropdown() {
            dropEl.classList.add('open');
            if (searchEl) { searchEl.value = ''; filterList(''); searchEl.focus(); }
        }
        function closeDropdown() { dropEl.classList.remove('open'); }

        function filterList(term) {
            var liItems = listEl.querySelectorAll('li');
            var lower = term.toLowerCase();
            for (var j = 0; j < liItems.length; j++) {
                var text = liItems[j].textContent || '';
                liItems[j].classList.toggle('hidden', !(!lower || text.toLowerCase().indexOf(lower) >= 0));
            }
        }

        function selectItem(id, name) {
            if (valEl) { valEl.value = id; }
            if (btnEl) { btnEl.textContent = name; }
            closeDropdown();
            if (onSelect) { onSelect(id); }
        }

        btnEl.addEventListener('click', function (e) {
            e.stopPropagation();
            if (dropEl.classList.contains('open')) { closeDropdown(); } else { openDropdown(); }
        });
        btnEl.addEventListener('keydown', function (e) {
            if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); openDropdown(); }
        });
        if (searchEl) {
            searchEl.addEventListener('input', function () { filterList(this.value); });
            searchEl.addEventListener('click', function (e) { e.stopPropagation(); });
        }
        listEl.addEventListener('click', function (e) {
            var li = e.target.closest ? e.target.closest('li') : null;
            if (!li) { return; }
            selectItem(parseInt(li.getAttribute('data-id'), 10) || 0, li.textContent);
        });
        document.addEventListener('click', function (e) {
            if (wrapEl && !wrapEl.contains(e.target)) { closeDropdown(); }
        });
    }

    function resetSearchDropdown(baseId, allLabel) {
        var valEl  = document.getElementById(baseId + '-value');
        var btnEl  = document.getElementById(baseId + '-btn');
        var dropEl = document.getElementById(baseId + '-dropdown');
        if (valEl)  { valEl.value = '0'; }
        if (btnEl)  { btnEl.textContent = allLabel; }
        if (dropEl) { dropEl.classList.remove('open'); }
    }

    // -----------------------------------------------------------------------
    // Grade + struct options: load from getLibrary (staff_grade / staff_struct)
    // and populate radio buttons + mgmt. Both lists come from the same tables
    // masterlist.php's "+ Add Grade" / "+ Add Structure" write to, so newly
    // added rows show up here without a code change.
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
                    STRUCT_OPTIONS.push({ id: s.id, label: s.label, is_active: !!s.is_active });
                    STRUCT_LABELS[s.id] = s.label;
                });
                GRADE_OPTIONS = [];
                GRADE_LABELS  = {};
                $.each(response.grades, function (i, g) {
                    GRADE_OPTIONS.push({ id: g.id, label: g.label, is_active: !!g.is_active });
                    GRADE_LABELS[g.id] = g.label;
                });
                if (typeof callback === 'function') { callback(); }
            }
        });
    }

    // Deactivated grades/structs stay assigned to whoever already has them
    // (masterlist's own confirmation text promises this: "Staff currently
    // assigned to it will keep it, but it will no longer be selectable for
    // new assignments"), so the currently-selected id is always kept in the
    // list even when inactive - every other inactive id is dropped.
    function visibleOptions(options, selectedId) {
        var out = [];
        for (var i = 0; i < options.length; i++) {
            var o = options[i];
            if (o.is_active || o.id === selectedId) { out.push(o); }
        }
        return out;
    }

    function renderStructRadios(selectedStructId) {
        var visible = visibleOptions(STRUCT_OPTIONS, selectedStructId);
        if (visible.length === 0) {
            $('#struct-radio-list').html('');
            $('#struct-none-msg').show();
            return;
        }

        $('#struct-none-msg').hide();
        var html = '';
        for (var j = 0; j < visible.length; j++) {
            var s        = visible[j];
            var checked  = (s.id === selectedStructId) ? ' checked' : '';
            var inactive = !s.is_active ? ' <span class="text-muted">(inactive)</span>' : '';
            html += '<div class="form-check">' +
                '<input class="form-check-input" type="radio" name="struct" id="struct-' + s.id + '" value="' + s.id + '"' + checked + '>' +
                '<label class="form-check-label" for="struct-' + s.id + '">' + $('<span>').text(s.label).html() + inactive + '</label>' +
                '</div>';
        }
        $('#struct-radio-list').html(html);
    }

    function renderGradeRadios(selectedGrade) {
        var visible = visibleOptions(GRADE_OPTIONS, selectedGrade);
        if (visible.length === 0) {
            $('#grade-radio-list').html('');
            $('#grade-none-msg').show();
            return;
        }

        $('#grade-none-msg').hide();
        var html = '';
        for (var k = 0; k < visible.length; k++) {
            var g        = visible[k];
            var checked  = (g.id === selectedGrade) ? ' checked' : '';
            var inactive = !g.is_active ? ' <span class="text-muted">(inactive)</span>' : '';
            html += '<div class="form-check">' +
                '<input class="form-check-input" type="radio" name="grade" id="grade-' + g.id + '" value="' + g.id + '"' + checked + '>' +
                '<label class="form-check-label" for="grade-' + g.id + '">' + $('<span>').text(g.label).html() + ' (' + g.id + ')' + inactive + '</label>' +
                '</div>';
        }
        $('#grade-radio-list').html(html);
    }

    // -----------------------------------------------------------------------
    // Returns true when the requester is allowed to edit staff in deptRaw
    // -----------------------------------------------------------------------
    function canEditStaff(deptRaw) {
        if (IS_SUPERADMIN) return true;
        if (!REQUESTER_DEPT_IDS || REQUESTER_DEPT_IDS.length === 0) return false;
        var targetIds = String(deptRaw || '').split(',').map(function (d) {
            return parseInt(d.trim(), 10);
        }).filter(function (d) { return !isNaN(d) && d > 0; });
        for (var i = 0; i < REQUESTER_DEPT_IDS.length; i++) {
            for (var j = 0; j < targetIds.length; j++) {
                if (REQUESTER_DEPT_IDS[i] === targetIds[j]) return true;
            }
        }
        return false;
    }

    // -----------------------------------------------------------------------
    // Struct history & lock
    // -----------------------------------------------------------------------
    function loadStructHistory(staffId) {
        $('#struct-history-section').hide();
        $('#struct-history-list').html('');
        $.ajax({
            url: BACKEND_URL + '?action=getStructHistory&staff_id=' + staffId,
            type: 'GET',
            dataType: 'json',
            success: function (res) {
                if (!res.success) return;
                renderStructHistory(res.history);
                applyStructLock(res.struct_locked, res.lock_reason);
            }
        });
    }

    function renderStructHistory(history) {
        if (!history || history.length === 0) {
            $('#struct-history-section').hide();
            return;
        }
        var quarterLabel = ['Q1', 'Q2', 'Q3', 'Q4'];
        var html = '';
        for (var i = 0; i < history.length; i++) {
            var h = history[i];
            var ql = (h.quarter >= 1 && h.quarter <= 4) ? quarterLabel[h.quarter - 1] : ('Q' + h.quarter);
            html += '<p class="mb-0">' +
                '<span style="color:#6c757d;margin-right:8px;">' + ql + ' ' + h.year + '</span>' +
                $('<span>').text(h.struct_name).html() +
                '</p>';
        }
        $('#struct-history-list').html(html);
        $('#struct-history-section').show();
    }

    function applyStructLock(locked, reason) {
        if (locked) {
            $('input[name="struct"]').prop('disabled', true);
            $('#struct-lock-notice').text(reason).show();
        } else {
            $('input[name="struct"]').prop('disabled', false);
            $('#struct-lock-notice').hide();
        }
    }

    // -----------------------------------------------------------------------
    // Reset form — clears search, hides all right-side panels
    // -----------------------------------------------------------------------
    function resetForm() {
        selectedStaffId = null;
        $('#struct-history-section').hide();
        $('#struct-history-list').html('');
        $('#staff-info').hide();
        $('#struct-lock-notice').hide();
        $('#grade-section').hide();
        $('#struct-section').hide();
        $('input[name="struct"]').prop('disabled', false);
        $('#submit-section').css('display', 'none');
        $('#update-btn').prop('disabled', false);
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

        var outletCodes = staff.outlet_codes || [];
        var outletHtml  = outletCodes.length
            ? $.map(outletCodes, function (code) { return '<li>' + $('<span>').text(code).html() + '</li>'; }).join('')
            : '<li>-</li>';

        $('#info-name').text(staff.nama_staff);
        $('#info-dept').text(staff.department_name || '-');
        $('#info-outlet').html(outletHtml);
        $('#info-status').text(staff.status_semasa || '-');
        $('#info-grade').text(gradeLabel);
        $('#info-struct').text(structName);
        $('#staff-info').show();

        renderGradeRadios(grade);
        $('#grade-section').show();

        renderStructRadios(structId);
        $('#struct-section').show();

        $('#submit-section').css('display', 'flex');
        $('#update-btn').prop('disabled', !canEditStaff(staff.dept_raw));

        loadStructHistory(staff.id);
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
        var staffDeptRaw  = $(this).data('staff-dept-raw') || '0';
        var staffOutletRaw = String($(this).data('staff-outlet') || '');
        var staffOutletCodes = staffOutletRaw === '' ? [] : staffOutletRaw.split(',');
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
            dept_raw:        staffDeptRaw,
            outlet_codes:    staffOutletCodes,
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
    var currentPage     = 1;
    var adminPerPage    = 30;
    var activeNameFilter = '';
    var activeDeptFilter = 0;

    var currentPageOutlet      = 1;
    var adminPerPageOutlet     = 30;
    var activeNameFilterOutlet = '';
    var activeOutletFilter     = 0;

    function loadActiveStaff(page) {
        currentPage = page || 1;
        var colSpan = TABLE_COLS;
        $('#active-staff-tbody').html('<tr><td colspan="' + colSpan + '" class="text-center text-muted py-3">Loading...</td></tr>');

        var url = BACKEND_URL + '?action=getActiveStaff&page=' + currentPage + '&per_page=' + adminPerPage;
        if (activeDeptFilter > 0) { url += '&dept_filter=' + activeDeptFilter; }
        if (activeNameFilter !== '') { url += '&name_filter=' + encodeURIComponent(activeNameFilter); }

        $.ajax({
            url: url,
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
            var canEdit    = canEditStaff(s.dept_raw);
            var outletCodes = s.outlet_codes || [];
            var outletHtml  = outletCodes.length ? $('<span>').text(outletCodes.join(',')).html() : '-';

            html += '<tr>' +
                '<td>' + $('<span>').text(s.nama_staff).html() + '</td>' +
                '<td class="text-muted">' + $('<span>').text(s.department_name).html() + '</td>' +
                '<td><span class="atem-pill ' + gradeBadge + '">' + gradeLabel + '</span></td>' +
                '<td class="text-muted">' + $('<span>').text(structName).html() + '</td>';

            if (SHOW_EDIT) {
                html += '<td><button type="button" class="btn btn-sm btn-outline-secondary edit-btn"' +
                    (canEdit ? '' : ' disabled') +
                    ' data-staff-id="' + s.id + '"' +
                    ' data-staff-name="' + $('<span>').text(s.nama_staff).html() + '"' +
                    ' data-staff-dept="' + $('<span>').text(s.department_name).html() + '"' +
                    ' data-staff-dept-raw="' + $('<span>').text(s.dept_raw || '0').html() + '"' +
                    ' data-staff-outlet="' + $('<span>').text(outletCodes.join(',')).html() + '"' +
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

    function loadActiveStaffOutlet(page) {
        currentPageOutlet = page || 1;
        var colSpan = TABLE_COLS_OUTLET;
        $('#aco-staff-tbody').html('<tr><td colspan="' + colSpan + '" class="text-center text-muted py-3">Loading...</td></tr>');

        var url = BACKEND_URL + '?action=getActiveStaff&outlet_only=1&page=' + currentPageOutlet + '&per_page=' + adminPerPageOutlet;
        if (activeNameFilterOutlet !== '') { url += '&name_filter=' + encodeURIComponent(activeNameFilterOutlet); }
        if (activeOutletFilter > 0) { url += '&outlet_filter=' + activeOutletFilter; }

        $.ajax({
            url: url,
            type: 'GET',
            dataType: 'json',
            success: function (response) {
                if (!response.success) {
                    $('#aco-staff-tbody').html('<tr><td colspan="' + colSpan + '" class="text-center text-muted py-3">Failed to load.</td></tr>');
                    return;
                }
                renderTableOutlet(response.data);
                renderPaginationOutlet(response.page, response.total_pages, response.total, response.per_page);
            },
            error: function () {
                $('#aco-staff-tbody').html('<tr><td colspan="' + colSpan + '" class="text-center text-muted py-3">Error loading data.</td></tr>');
            }
        });
    }

    function renderTableOutlet(data) {
        var colSpan = TABLE_COLS_OUTLET;
        if (!data || data.length === 0) {
            $('#aco-staff-tbody').html('<tr><td colspan="' + colSpan + '" class="text-center text-muted py-3">No records found.</td></tr>');
            return;
        }
        var html = '';
        $.each(data, function (i, s) {
            var grade      = s.grade;
            var gradeLabel = GRADE_LABELS[grade] !== undefined ? GRADE_LABELS[grade] : 'Unknown';
            var gradeBadge = GRADE_BADGES[grade] !== undefined ? GRADE_BADGES[grade] : 'bg-secondary';
            var structName = s.struct_name || '-';
            var structId   = s.struct_id   || 0;
            var canEdit    = canEditStaff(s.dept_raw);
            var outletCodes = s.outlet_codes || [];
            var outletHtml  = outletCodes.length ? $('<span>').text(outletCodes.join(',')).html() : '-';

            html += '<tr>' +
                '<td>' + $('<span>').text(s.nama_staff).html() + '</td>' +
                '<td class="text-muted">' + outletHtml + '</td>' +
                '<td><span class="atem-pill ' + gradeBadge + '">' + gradeLabel + '</span></td>' +
                '<td class="text-muted">' + $('<span>').text(structName).html() + '</td>';

            if (SHOW_EDIT) {
                html += '<td><button type="button" class="btn btn-sm btn-outline-secondary edit-btn"' +
                    (canEdit ? '' : ' disabled') +
                    ' data-staff-id="' + s.id + '"' +
                    ' data-staff-name="' + $('<span>').text(s.nama_staff).html() + '"' +
                    ' data-staff-dept="' + $('<span>').text(s.department_name).html() + '"' +
                    ' data-staff-dept-raw="' + $('<span>').text(s.dept_raw || '0').html() + '"' +
                    ' data-staff-outlet="' + $('<span>').text(outletCodes.join(',')).html() + '"' +
                    ' data-staff-status="' + $('<span>').text(s.status_semasa).html() + '"' +
                    ' data-staff-grade="' + grade + '"' +
                    ' data-staff-struct-id="' + structId + '"' +
                    ' data-staff-struct-name="' + $('<span>').text(structName).html() + '"' +
                    '>Edit</button></td>';
            }

            html += '</tr>';
        });
        $('#aco-staff-tbody').html(html);
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

    function renderPaginationOutlet(page, totalPages, total, perPage) {
        var start = total === 0 ? 0 : (page - 1) * perPage + 1;
        var end   = Math.min(page * perPage, total);
        var pager = document.getElementById('aco-staff-pager');
        if (!pager) { return; }
        if (total === 0) { pager.innerHTML = ''; return; }

        var opts    = [10, 30, 50, 100];
        var selHtml = '<select class="atem-perpage-select">';
        for (var oi = 0; oi < opts.length; oi++) {
            selHtml += '<option value="' + opts[oi] + '"' + (adminPerPageOutlet === opts[oi] ? ' selected' : '') + '>' + opts[oi] + '</option>';
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

    var _adminPagerOutlet = document.getElementById('aco-staff-pager');
    if (_adminPagerOutlet) {
        _adminPagerOutlet.addEventListener('click', function (e) {
            var btn = e.target.closest ? e.target.closest('.atem-pager-btn') : null;
            if (btn && !btn.disabled) {
                var p = parseInt(btn.getAttribute('data-page'), 10);
                if (!isNaN(p) && p >= 1) { loadActiveStaffOutlet(p); }
            }
        });
        _adminPagerOutlet.addEventListener('change', function (e) {
            if (e.target.classList.contains('atem-perpage-select')) {
                adminPerPageOutlet = parseInt(e.target.value, 10);
                currentPageOutlet  = 1;
                loadActiveStaffOutlet(1);
            }
        });
    }

    // -----------------------------------------------------------------------
    // Filter bar (grade 2+ only) — auto-filters as the user types/selects
    // -----------------------------------------------------------------------
    function applyHqNameFilterNow() {
        activeNameFilter = $.trim($('#ac-filter-name').val());
        loadActiveStaff(1);
    }
    var applyHqNameFilterDebounced = debounce(applyHqNameFilterNow, 400);

    $('#ac-filter-dept').on('change', function () {
        activeDeptFilter = HAS_DEPT_FILTER ? (parseInt($(this).val(), 10) || 0) : 0;
        loadActiveStaff(1);
    });

    $('#ac-filter-name').on('input', applyHqNameFilterDebounced);
    $('#ac-filter-name').on('keydown', function (e) {
        if (e.key === 'Enter') { applyHqNameFilterNow(); }
    });

    $('#ac-reset-filter').on('click', function () {
        activeDeptFilter = 0;
        activeNameFilter = '';
        if (HAS_DEPT_FILTER) { $('#ac-filter-dept').val('0'); }
        $('#ac-filter-name').val('');
        loadActiveStaff(1);
    });

    function applyOutletNameFilterNow() {
        activeNameFilterOutlet = $.trim($('#aco-filter-name').val());
        loadActiveStaffOutlet(1);
    }
    var applyOutletNameFilterDebounced = debounce(applyOutletNameFilterNow, 400);

    buildSearchDropdown('aco-filter-outlet', OUTLET_FILTER_OPTIONS || [], 'All Outlets', 'aco-filter-name', function (id) {
        activeOutletFilter = parseInt(id, 10) || 0;
        loadActiveStaffOutlet(1);
    });

    $('#aco-filter-name').on('input', applyOutletNameFilterDebounced);
    $('#aco-filter-name').on('keydown', function (e) {
        if (e.key === 'Enter') { applyOutletNameFilterNow(); }
    });

    $('#aco-reset-filter').on('click', function () {
        activeNameFilterOutlet = '';
        activeOutletFilter     = 0;
        $('#aco-filter-name').val('');
        resetSearchDropdown('aco-filter-outlet', 'All Outlets');
        loadActiveStaffOutlet(1);
    });

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
                    loadActiveStaffOutlet(currentPageOutlet);
                    loadStructHistory(selectedStaffId);

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

    // The Outlet ATEM tab-pane starts hidden (display:none), so offsetHeight
    // reads 0 when buildSearchDropdown() first sized the Outlet filter's
    // button off '#aco-filter-name' — re-sync once the tab is actually shown.
    var acTabOutletBtn = document.getElementById('ac-tab-outlet-btn');
    if (acTabOutletBtn) {
        acTabOutletBtn.addEventListener('shown.bs.tab', function () {
            syncSearchDropdownSize('aco-filter-outlet', 'aco-filter-name');
        });
    }

    // -----------------------------------------------------------------------
    // Init
    // -----------------------------------------------------------------------
    loadAllStructData(function () {
        if (TAB_SINGLE_VIEW !== 'outlet') { loadActiveStaff(1); }
        if (TAB_SINGLE_VIEW !== 'hq') { loadActiveStaffOutlet(1); }
    });

})();
