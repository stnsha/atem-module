/* ATEM listing: renders the table from server-injected rows with client-side
   filtering, title search, sort and pagination (15/page). Filters/search always
   run over the full dataset, then the result is paginated. */
(function () {
    'use strict';

    var CFG = window.ATEM_VIEW || { rows: [], levels: [], statuses: [] };
    var rows = CFG.rows || [];
    var PER_PAGE = 30;
    var page = 1;
    var sortCol = null;
    var sortDir = 1;

    var TODAY         = new Date().toLocaleDateString('en-CA', { timeZone: 'Asia/Kuala_Lumpur' });
    var presetClosed  = false;
    var overdueFilter = false;
    var minLevelId    = 0;

    var LEVEL_COLOR = { 'Level 1': '#6c757d', 'Level 2': '#0d6efd', 'Level 3': '#6610f2', 'Level 4': '#003B73' };
    var ARCI_COLOR  = { 'A': '#6610f2', 'R': '#0d6efd', 'C': '#fd7e14', 'I': '#6c757d' };
    var STATUS_COLOR = {
        'Draft': '#6c757d', 'Active': '#0d6efd',
        'Completed': '#198754', 'Completed with Excellence': '#0dcaf0', 'Completed with Extension': '#495057',
        'Extended': '#fd7e14', 'Failed': '#dc3545',
        'Deleted': '#dc3545', 'Suspended': '#e11d48'
    };
    function $(id) { return document.getElementById(id); }

    function escapeHtml(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }

    function pill(text, color, titleAttr) {
        var t = titleAttr ? ' title="' + escapeHtml(titleAttr) + '"' : '';
        return '<span class="atem-pill" style="background-color:' + color + '"' + t + '>' + escapeHtml(text) + '</span>';
    }

    function fmtDate(v) {
        if (!v) { return '-'; }
        var p = String(v).substring(0, 10).split('-'); // YYYY-MM-DD
        return (p.length === 3) ? (p[2] + '-' + p[1] + '-' + p[0]) : v;
    }

    // ----------------------------------------------------------- filter setup
    function distinct(key) {
        var seen = {}, out = [];
        for (var i = 0; i < rows.length; i++) {
            var v = rows[i][key];
            if (v && !seen[v]) { seen[v] = true; out.push(v); }
        }
        out.sort();
        return out;
    }

    function buildFilters() {
        var lvl = $('vf-level');
        var levels = CFG.levels || [];
        var lh = '<option value="">All levels</option>';
        for (var i = 0; i < levels.length; i++) { lh += '<option value="' + escapeHtml(levels[i].level) + '">' + escapeHtml(levels[i].level) + '</option>'; }
        lvl.innerHTML = lh;

        var dep = $('vf-dept');
        var depts = CFG.departments || [];
        var dh = '<option value="">All departments</option>';
        for (var d = 0; d < depts.length; d++) { dh += '<option value="' + escapeHtml(depts[d]) + '">' + escapeHtml(depts[d]) + '</option>'; }
        dep.innerHTML = dh;

        var st = $('vf-status');
        var statuses = CFG.statuses || [];
        var canSeeDeleted = (CFG.userGrade >= 4 || CFG.isSuperAdmin);
        var sh = '<option value="">All statuses</option>';
        for (var s = 0; s < statuses.length; s++) {
            if ((statuses[s].value === 'Deleted' || statuses[s].value === 'Suspended') && !canSeeDeleted) { continue; }
            sh += '<option value="' + escapeHtml(statuses[s].value) + '">' + escapeHtml(statuses[s].value) + '</option>';
        }
        st.innerHTML = sh;

        var roleEl = $('vf-role');
        var roleOptions = ['A', 'R', 'C', 'I', 'ARCI', 'Not Applicable'];
        var rh = '<option value="">All roles</option>';
        for (var ri = 0; ri < roleOptions.length; ri++) { rh += '<option value="' + escapeHtml(roleOptions[ri]) + '">' + escapeHtml(roleOptions[ri]) + '</option>'; }
        roleEl.innerHTML = rh;

        buildIssuerDropdown();
    }

    // ------------------------------------------------- issuer searchable dropdown
    function buildIssuerDropdown() {
        var listEl   = $('vf-issuer-list');
        var searchEl = $('vf-issuer-search');
        var btnEl    = $('vf-issuer-btn');
        var dropEl   = $('vf-issuer-dropdown');
        var valEl    = $('vf-issuer-value');
        var labelEl  = btnEl; // button element is also the label
        if (!listEl || !btnEl || !dropEl) { return; }

        // Sync dimensions and border to adjacent form-select-sm exactly.
        var refEl = $('vf-dept');
        if (refEl && btnEl) {
            var refStyle = window.getComputedStyle(refEl);
            btnEl.style.height       = refEl.offsetHeight + 'px';
            btnEl.style.border       = refStyle.border;
            btnEl.style.borderRadius = refStyle.borderRadius;
        }

        var issuers = CFG.issuers || [];

        // Build list items: "All issuers" first, then each issuer.
        var html = '<li class="vf-s2-list-item" data-id="0">All issuers</li>';
        for (var i = 0; i < issuers.length; i++) {
            html += '<li class="vf-s2-list-item" data-id="' + issuers[i].id + '">' + escapeHtml(issuers[i].name) + '</li>';
        }
        listEl.innerHTML = html;

        function openDropdown() {
            dropEl.classList.add('open');
            if (searchEl) { searchEl.value = ''; filterList(''); searchEl.focus(); }
        }
        function closeDropdown() { dropEl.classList.remove('open'); }

        function filterList(term) {
            var items = listEl.querySelectorAll('li');
            var lower = term.toLowerCase();
            var anyVisible = false;
            for (var j = 0; j < items.length; j++) {
                var text = items[j].textContent || '';
                if (!lower || text.toLowerCase().indexOf(lower) >= 0) {
                    items[j].classList.remove('hidden');
                    anyVisible = true;
                } else {
                    items[j].classList.add('hidden');
                }
            }
            var emptyEl = $('vf-issuer-empty');
            if (!anyVisible) {
                if (!emptyEl) {
                    var em = document.createElement('div');
                    em.className = 'vf-s2-empty';
                    em.id = 'vf-issuer-empty';
                    em.textContent = 'No results found';
                    listEl.parentNode.appendChild(em);
                }
            } else {
                if (emptyEl) { emptyEl.parentNode.removeChild(emptyEl); }
            }
        }

        function selectIssuer(id, name) {
            if (valEl) { valEl.value = id; }
            if (labelEl) { labelEl.textContent = name; }
            closeDropdown();
            page = 1;
            render();
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
            selectIssuer(parseInt(li.getAttribute('data-id'), 10) || 0, li.textContent);
        });

        document.addEventListener('click', function (e) {
            var wrap = $('vf-issuer-wrap');
            if (wrap && !wrap.contains(e.target)) { closeDropdown(); }
        });
    }

    function resetIssuerDropdown() {
        var valEl  = $('vf-issuer-value');
        var btnEl  = $('vf-issuer-btn');
        var dropEl = $('vf-issuer-dropdown');
        if (valEl)  { valEl.value = '0'; }
        if (btnEl)  { btnEl.textContent = 'All issuers'; }
        if (dropEl) { dropEl.classList.remove('open'); }
    }

    // --------------------------------------------------------------- filtering
    function applyFilters() {
        var year  = $('vf-year')  ? parseInt($('vf-year').value,  10) || 0 : 0;
        var month = $('vf-month') ? parseInt($('vf-month').value, 10) || 0 : 0;
        var level = $('vf-level').value;
        var dept = $('vf-dept').value;
        var status = $('vf-status').value;
        var role = $('vf-role').value;
        var from = $('vf-from').value;
        var to = $('vf-to').value;
        var term = $('vf-search').value.toLowerCase().trim();
        var issuerValEl = $('vf-issuer-value');
        var issuer = issuerValEl ? (parseInt(issuerValEl.value, 10) || 0) : 0;

        return rows.filter(function (r) {
            if (year  && (!r.start_date || parseInt(r.start_date.substring(0, 4), 10) !== year))  { return false; }
            if (month && (!r.start_date || parseInt(r.start_date.substring(5, 7), 10) !== month)) { return false; }
            if (issuer && r.issuer_staff_id !== issuer) { return false; }
            if (level && r.level_label !== level) { return false; }
            if (dept && r.department_name !== dept) { return false; }
            if (status && r.status !== status) { return false; }
            if (presetClosed) {
                if (r.status !== 'Completed' && r.status !== 'Completed with Excellence') { return false; }
            }
            if (overdueFilter) {
                var effectiveDue = ((r.is_extended || r.status === 'Extended') && r.extended_date_1)
                    ? String(r.extended_date_1).substring(0, 10)
                    : String(r.end_date || '').substring(0, 10);
                if (r.status !== 'Active' && r.status !== 'Extended') { return false; }
                if (!effectiveDue || effectiveDue >= TODAY) { return false; }
            }
            if (minLevelId > 0) {
                var levelNum = parseInt(String(r.level_label || '').replace(/[^0-9]/g, ''), 10) || 0;
                if (levelNum < minLevelId) { return false; }
            }
            if (role) {
                if (role === 'ARCI') {
                    if (!r.user_arci_roles || r.user_arci_roles.length === 0) { return false; }
                } else if (role === 'Not Applicable') {
                    if (r.user_arci_roles && r.user_arci_roles.length > 0) { return false; }
                } else {
                    if (!r.user_arci_roles || r.user_arci_roles.indexOf(role) < 0) { return false; }
                }
            }
            if (from && (!r.start_date || r.start_date.substring(0, 10) < from)) { return false; }
            if (to && (!r.start_date || r.start_date.substring(0, 10) > to)) { return false; }
            if (term) {
                var atemIdStr = 'at' + r.id;
                var matchesTitle = String(r.title).toLowerCase().indexOf(term) >= 0;
                var matchesId    = atemIdStr.indexOf(term.replace(/^#/, '')) >= 0;
                if (!matchesTitle && !matchesId) { return false; }
            }
            return true;
        });
    }

    var STATUS_SORT_GROUP = {
        'Suspended': 0,
        'Draft': 1,
        'Active': 2, 'Extended': 2,
        'Completed': 3, 'Completed with Excellence': 3, 'Completed with Extension': 3, 'Failed': 3, 'Deleted': 3
    };

    function statusGroup(s) {
        var g = STATUS_SORT_GROUP[s];
        return (g === undefined) ? 2 : g;
    }

    function effectiveSortDate(r) {
        if ((r.is_extended || r.status === 'Extended') && r.extended_date_1) {
            return String(r.extended_date_1).substring(0, 10);
        }
        return String(r.end_date || '');
    }

    function sortRows(list) {
        if (sortCol === null) {
            return list.slice().sort(function (a, b) {
                var ga = statusGroup(a.status), gb = statusGroup(b.status);
                if (ga !== gb) { return ga - gb; }
                var da = effectiveSortDate(a), db = effectiveSortDate(b);
                if (da < db) { return -1; }
                if (da > db) { return 1; }
                return 0;
            });
        }
        return list.slice().sort(function (a, b) {
            var av = a[sortCol], bv = b[sortCol];
            if (sortCol === 'id') { av = Number(av) || 0; bv = Number(bv) || 0; }
            else { av = String(av == null ? '' : av).toLowerCase(); bv = String(bv == null ? '' : bv).toLowerCase(); }
            if (av < bv) { return -1 * sortDir; }
            if (av > bv) { return 1 * sortDir; }
            return 0;
        });
    }

    function updateSortIndicators() {
        var ths = document.querySelectorAll('#atem-view-tbl th.atem-sortable');
        for (var i = 0; i < ths.length; i++) {
            ths[i].classList.remove('atem-sort-asc', 'atem-sort-desc');
            if (sortCol !== null && ths[i].getAttribute('data-col') === sortCol) {
                ths[i].classList.add(sortDir === 1 ? 'atem-sort-asc' : 'atem-sort-desc');
            }
        }
    }

    // --------------------------------------------------------------- delete permission
    function canDelete(r) {
        if (r.is_deleted) { return false; }
        if (CFG.isSuperAdmin) { return true; }
        if (!CFG.staffId) { return false; }
        if (r.issuer_staff_id != CFG.staffId) { return false; }
        var terminal = ['Completed', 'Completed with Excellence', 'Completed with Extension', 'Failed'];
        return terminal.indexOf(r.status) === -1;
    }

    function canDeleteSuspended(r) {
        if (r.status !== 'Suspended') { return false; }
        if (CFG.isSuperAdmin) { return true; }
        if (!CFG.staffId) { return false; }
        return r.issuer_staff_id == CFG.staffId;
    }

    // --------------------------------------------------------------- edit permission
    function canEdit(r) {
        if (CFG.isSuperAdmin) { return true; }
        if (!CFG.staffId) { return false; }
        if (r.issuer_staff_id == CFG.staffId) { return true; }
        if (r.user_arci_roles && r.user_arci_roles.indexOf('A') !== -1) {
            if (r.status === 'Active') { return true; }
            if (r.status === 'Extended') {
                var today = new Date().toISOString().substring(0, 10);
                return !r.extended_date_1 || today <= r.extended_date_1.substring(0, 10);
            }
            return false;
        }
        return false;
    }

    function canUpdateProgress(r) {
        if (!CFG.staffId) { return false; }
        if (r.issuer_staff_id == CFG.staffId) { return false; }
        if (!r.user_arci_roles || r.user_arci_roles.length === 0) { return false; }
        return r.user_arci_roles.indexOf('A') === -1;
    }

    // --------------------------------------------------------------- rendering
    function render() {
        updateSortIndicators();
        var list = sortRows(applyFilters());
        var total = list.length;
        var pages = Math.max(1, Math.ceil(total / PER_PAGE));
        if (page > pages) { page = pages; }
        var startIdx = (page - 1) * PER_PAGE;
        var pageRows = list.slice(startIdx, startIdx + PER_PAGE);

        var body = $('atem-view-body');
        if (total === 0) {
            body.innerHTML = '<tr><td colspan="10" class="atem-empty-state" style="text-align:center;padding:24px;">No ATEM cards match the current filters.</td></tr>';
        } else {
            var html = '';
            for (var i = 0; i < pageRows.length; i++) {
                var r = pageRows[i];
                var levelCell = r.level_label ? pill(r.level_label, LEVEL_COLOR[r.level_label] || '#6c757d', r.system_name) : '-';
                var statusCell = r.status ? pill(r.status, STATUS_COLOR[r.status] || '#6c757d') : '-';
                var arciCell = '';
                if (r.user_arci_roles && r.user_arci_roles.length > 0) {
                    for (var ri = 0; ri < r.user_arci_roles.length; ri++) {
                        var role = r.user_arci_roles[ri];
                        var rc = ARCI_COLOR[role] || '#6c757d';
                        arciCell += '<span style="display:inline-block;background:' + rc + ';color:#fff;font-size:11px;font-weight:600;padding:2px 7px;border-radius:4px;margin:1px 2px;">' + escapeHtml(role) + '</span>';
                    }
                } else {
                    arciCell = '<span style="color:#adb5bd;font-size:12px;">—</span>';
                }
                var accountableCell = '';
                if (r.accountable && r.accountable.length > 0) {
                    for (var ai = 0; ai < r.accountable.length; ai++) {
                        if (ai > 0) {
                            accountableCell += '<div style="border-top:1px solid #dee2e6;margin:4px 0;"></div>';
                        }
                        accountableCell += '<div style="font-size:13px;">' + escapeHtml(r.accountable[ai].name) + '</div>'
                            + '<div style="font-size:11px;color:#6c757d;">' + escapeHtml(r.accountable[ai].dept) + '</div>';
                    }
                } else {
                    accountableCell = '<span style="color:#adb5bd;font-size:12px;">—</span>';
                }
                var endCell = fmtDate(r.end_date);
                if (r.is_extended && r.extended_date_1) {
                    endCell += '<div style="margin-top:3px;font-size:11px;color:#fd7e14;font-weight:500;">'
                        + '<i class="bi bi-arrow-right" style="font-size:10px;"></i> Extended to '
                        + fmtDate(r.extended_date_1) + '</div>';
                }
                var titleCell = escapeHtml(r.title);
                var rowStyle = (r.is_deleted && r.status !== 'Suspended') ? ' style="opacity:0.55;"' : '';
                var _canViewDeleted = CFG.isSuperAdmin || CFG.userGrade >= 4
                    || (r.status === 'Suspended' && r.issuer_staff_id == CFG.staffId);
                var actionCell = r.is_deleted
                    ? (_canViewDeleted
                        ? '<a href="atem/edit.php?id=' + r.id + '&mode=read" class="btn btn-sm btn-outline-secondary" title="View (Suspended)"><i class="bi bi-eye"></i></a>'
                        : '')
                    + (canDeleteSuspended(r) ? ' <button type="button" class="btn btn-sm btn-outline-danger atem-delete-row" data-id="' + r.id + '" title="Delete"><i class="bi bi-trash"></i></button>' : '')
                    : '<a href="atem/edit.php?id=' + r.id + '&mode=read" class="btn btn-sm btn-outline-primary" title="View"><i class="bi bi-eye"></i></a> '
                    + (canEdit(r) ? '<a href="atem/edit.php?id=' + r.id + '&mode=edit" class="btn btn-sm btn-outline-secondary" title="Edit"><i class="bi bi-pencil"></i></a>' : '')
                    + (canUpdateProgress(r) ? ' <a href="atem/edit.php?id=' + r.id + '&mode=read#atem-progress-section" class="btn btn-sm btn-outline-secondary" title="Edit"><i class="bi bi-pencil"></i></a>' : '')
                    + (canDelete(r) ? ' <button type="button" class="btn btn-sm btn-outline-danger atem-delete-row" data-id="' + r.id + '" title="Delete"><i class="bi bi-trash"></i></button>' : '');
                html += '<tr' + rowStyle + '>'
                    + '<td><span class="atem-id">#AT' + r.id + '</span></td>'
                    + '<td>' + titleCell + '</td>'
                    + '<td><div style="font-size:13px;">' + escapeHtml(r.issuer_name) + '</div><div style="font-size:11px;color:#6c757d;">' + escapeHtml(r.department_name) + '</div></td>'
                    + '<td>' + accountableCell + '</td>'
                    + '<td>' + arciCell + '</td>'
                    + '<td>' + levelCell + '</td>'
                    + '<td>' + fmtDate(r.start_date) + '</td>'
                    + '<td>' + endCell + '</td>'
                    + '<td>' + statusCell + '</td>'
                    + '<td class="atem-view-actions">' + actionCell + '</td></tr>';
            }
            body.innerHTML = html;
        }
        renderPager(total, pages, startIdx, pageRows.length);
    }

    function renderPager(total, pages, startIdx, shown) {
        var pager = $('atem-pager');
        if (total === 0) { pager.innerHTML = ''; return; }

        var opts = [10, 30, 50, 100];
        var selHtml = '<select class="atem-perpage-select">';
        for (var oi = 0; oi < opts.length; oi++) {
            selHtml += '<option value="' + opts[oi] + '"' + (PER_PAGE === opts[oi] ? ' selected' : '') + '>' + opts[oi] + '</option>';
        }
        selHtml += '</select>';
        var leftHtml = '<div class="atem-pager-left">Show ' + selHtml + ' entries</div>';

        var info = '<span class="atem-pager-info">Showing ' + (startIdx + 1) + ' to ' + (startIdx + shown) + ' of ' + total + ' entries</span>';
        var btns = '<button type="button" class="atem-pager-btn" data-page="' + (page - 1) + '"' + (page <= 1 ? ' disabled' : '') + '>Previous</button>';

        var win = 2, from = Math.max(1, page - win), to = Math.min(pages, page + win);
        if (from > 1) { btns += pageBtn(1) + (from > 2 ? '<span class="atem-pager-gap">...</span>' : ''); }
        for (var p = from; p <= to; p++) { btns += pageBtn(p); }
        if (to < pages) { btns += (to < pages - 1 ? '<span class="atem-pager-gap">...</span>' : '') + pageBtn(pages); }
        btns += '<button type="button" class="atem-pager-btn" data-page="' + (page + 1) + '"' + (page >= pages ? ' disabled' : '') + '>Next</button>';

        var rightHtml = '<div class="d-flex align-items-center gap-2">' + info + '<div class="atem-pager-bar">' + btns + '</div></div>';
        pager.innerHTML = leftHtml + rightHtml;
    }

    function pageBtn(p) {
        return '<button type="button" class="atem-pager-btn' + (p === page ? ' active' : '') + '" data-page="' + p + '">' + p + '</button>';
    }

    // --------------------------------------------------------------- wiring
    function bind() {
        ['vf-year', 'vf-month', 'vf-level', 'vf-dept', 'vf-status', 'vf-role', 'vf-from', 'vf-to'].forEach(function (id) {
            var el = $(id);
            if (el) { el.addEventListener('change', function () { presetClosed = false; overdueFilter = false; minLevelId = 0; page = 1; render(); }); }
        });
        $('vf-search').addEventListener('keyup', function () { page = 1; render(); });
        $('vf-reset').addEventListener('click', function () {
            ['vf-year', 'vf-level', 'vf-dept', 'vf-status', 'vf-role', 'vf-from', 'vf-to', 'vf-search'].forEach(function (id) { var el = $(id); if (el) { el.value = ''; } });
            var monthEl = $('vf-month'); if (monthEl) { monthEl.value = '0'; }
            resetIssuerDropdown();
            presetClosed = false; overdueFilter = false; minLevelId = 0;
            page = 1; render();
        });

        var pendingDeleteId = null;
        var _deleteModal = null;
        function getDeleteModal() {
            if (!_deleteModal && typeof bootstrap !== 'undefined') {
                _deleteModal = new bootstrap.Modal(document.getElementById('atem-delete-modal'));
            }
            return _deleteModal;
        }

        var body = $('atem-view-body');
        if (body) {
            body.addEventListener('click', function (e) {
                var btn = e.target.closest ? e.target.closest('.atem-delete-row') : null;
                if (!btn) { return; }
                pendingDeleteId = parseInt(btn.getAttribute('data-id'), 10);
                var msgEl = document.getElementById('atem-delete-modal-msg');
                var remarkEl = document.getElementById('atem-delete-remark');
                var errEl = document.getElementById('atem-delete-remark-err');
                if (msgEl) { msgEl.textContent = 'You are about to permanently delete ATEM #AT' + pendingDeleteId + '. This action cannot be undone.'; }
                if (remarkEl) { remarkEl.value = ''; }
                if (errEl) { errEl.textContent = ''; }
                var m = getDeleteModal();
                if (m) { m.show(); }
            });
        }

        var deleteConfirmBtn = document.getElementById('atem-delete-confirm');
        if (deleteConfirmBtn) {
            deleteConfirmBtn.addEventListener('click', function () {
                var remarkEl = document.getElementById('atem-delete-remark');
                var errEl = document.getElementById('atem-delete-remark-err');
                var remark = remarkEl ? remarkEl.value.trim() : '';
                if (!remark) {
                    if (errEl) { errEl.textContent = 'Remark is required before deleting.'; }
                    return;
                }
                if (errEl) { errEl.textContent = ''; }
                var atemId = pendingDeleteId;
                var m = getDeleteModal();
                if (m) { m.hide(); }
                fetch('atem/api.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ action: 'delete-atem', id: atemId, remarks: remark })
                }).then(function (r) { return r.json(); }).then(function (res) {
                    if (res && res.success) {
                        rows = rows.filter(function (r) { return r.id !== atemId; });
                        page = 1; render();
                    } else {
                        var msg = res && res.message ? res.message : 'Failed to delete ATEM.';
                        var msgEl2 = document.getElementById('atem-delete-modal-msg');
                        var remarkEl2 = document.getElementById('atem-delete-remark');
                        var errEl2 = document.getElementById('atem-delete-remark-err');
                        if (msgEl2) { msgEl2.textContent = msg; }
                        if (remarkEl2) { remarkEl2.value = ''; }
                        if (errEl2) { errEl2.textContent = ''; }
                        var m2 = getDeleteModal();
                        if (m2) { m2.show(); }
                    }
                }).catch(function () {
                    var msgEl3 = document.getElementById('atem-delete-modal-msg');
                    if (msgEl3) { msgEl3.textContent = 'Network error. Please try again.'; }
                    var m3 = getDeleteModal();
                    if (m3) { m3.show(); }
                });
            });
        }

        var ths = document.querySelectorAll('#atem-view-tbl th.atem-sortable');
        for (var i = 0; i < ths.length; i++) {
            ths[i].addEventListener('click', function () {
                var col = this.getAttribute('data-col');
                if (sortCol === col) { sortDir = -sortDir; } else { sortCol = col; sortDir = 1; }
                page = 1; render();
            });
        }

        $('atem-pager').addEventListener('click', function (e) {
            var btn = e.target.closest ? e.target.closest('.atem-pager-btn') : null;
            if (!btn || btn.disabled) { return; }
            var p = parseInt(btn.getAttribute('data-page'), 10);
            if (p >= 1) { page = p; render(); }
        });

        $('atem-pager').addEventListener('change', function (e) {
            if (e.target.classList.contains('atem-perpage-select')) {
                PER_PAGE = parseInt(e.target.value, 10);
                page = 1;
                render();
            }
        });
    }

    document.addEventListener('DOMContentLoaded', function () {
        buildFilters();
        var params = new URLSearchParams(window.location.search);
        if (params.get('status')) { $('vf-status').value = params.get('status'); }
        if (params.get('year'))  { var ye = $('vf-year');  if (ye) { ye.value = params.get('year'); } }
        if (params.get('month')) { var mo = $('vf-month'); if (mo) { mo.value = params.get('month'); } }
        if (params.get('level')) { var lv = $('vf-level'); if (lv) { lv.value = params.get('level'); } }
        if (params.get('level_id')) {
            var levelIdNum = parseInt(params.get('level_id'), 10);
            var lvEl = $('vf-level');
            if (lvEl && levelIdNum > 0) {
                var opts = lvEl.options;
                for (var li = 0; li < opts.length; li++) {
                    var lm = opts[li].value.match(/\d+/);
                    if (lm && parseInt(lm[0], 10) === levelIdNum) {
                        lvEl.value = opts[li].value;
                        break;
                    }
                }
            }
        }
        if (params.get('dept'))  { var de = $('vf-dept');  if (de) { de.value = params.get('dept'); } }
        if (params.get('from'))  { var fr = $('vf-from');  if (fr) { fr.value = params.get('from'); } }
        if (params.get('to'))    { var to = $('vf-to');    if (to) { to.value = params.get('to'); } }
        if (params.get('preset')        === 'closed') { presetClosed  = true; }
        if (params.get('overdue')       === '1')      { overdueFilter = true; }
        if (params.get('min_level_id'))               { minLevelId = parseInt(params.get('min_level_id'), 10) || 0; }
        bind();
        render();
    });
})();
