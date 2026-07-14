/* ATEM listing: renders the table from server-injected rows with client-side
   filtering, title search, sort and pagination (15/page). Filters/search always
   run over the full dataset, then the result is paginated. */
(function () {
    'use strict';

    var CFG = window.ATEM_VIEW || { rows: [], levels: [], statuses: [] };
    var allRows    = CFG.rows || [];
    var hqRows     = allRows.filter(function (r) { return (parseInt(r.atem_type, 10) || 1) === 1; });
    var outletRows = allRows.filter(function (r) { return parseInt(r.atem_type, 10) === 2; });
    // Looks up the current array by reference each call (rather than a
    // snapshot object) so it stays correct after hqRows/outletRows are
    // reassigned, e.g. by the delete-row handler.
    function rowsForTab(prefix) { return (prefix === 'hq') ? hqRows : outletRows; }

    // HQ and Outlet tabs render into separate tables and are sorted/paginated
    // independently, but share the one filter bar above them. Only the
    // active tab is (re-)rendered when a filter changes; the other tab
    // catches up with the current filters when it's switched to.
    var activeTab = 'hq';
    var tabState = {
        hq:     { page: 1, sortCol: null, sortDir: 1, perPage: 30 },
        outlet: { page: 1, sortCol: null, sortDir: 1, perPage: 30 }
    };

    var TODAY         = new Date().toLocaleDateString('en-CA', { timeZone: 'Asia/Kuala_Lumpur' });
    var overdueFilter   = false;
    var minLevelId      = 0;
    var mineFilter      = false;
    // Set only from a `?statuses=A,B` deep link (e.g. dashboard stat cards);
    // overrides the vf-status/vfo-status select until the user changes a filter.
    var statusesPreset  = [];

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
        for (var i = 0; i < allRows.length; i++) {
            var v = allRows[i][key];
            if (v && !seen[v]) { seen[v] = true; out.push(v); }
        }
        out.sort();
        return out;
    }

    function fillSelect(selectEl, placeholder, options) {
        if (!selectEl) { return; }
        var html = '<option value="">' + escapeHtml(placeholder) + '</option>';
        for (var i = 0; i < options.length; i++) {
            html += '<option value="' + escapeHtml(options[i]) + '">' + escapeHtml(options[i]) + '</option>';
        }
        selectEl.innerHTML = html;
    }

    function buildFilters() {
        var levels = (CFG.levels || []).map(function (l) { return l.level; });
        fillSelect($('vf-level'), 'All levels', levels);

        var depts = CFG.departments || [];
        fillSelect($('vf-dept'), 'All departments', depts);

        var pillars = (CFG.pillars || []).map(function (p) { return p.name; });
        fillSelect($('vfo-pillar'), 'All pillars', pillars);

        var canSeeDeleted = (CFG.userGrade >= 4 || CFG.isSuperAdmin);
        var statuses = (CFG.statuses || [])
            .filter(function (s) { return canSeeDeleted || (s.value !== 'Deleted' && s.value !== 'Suspended'); })
            .map(function (s) { return s.value; });
        fillSelect($('vf-status'), 'All statuses', statuses);
        fillSelect($('vfo-status'), 'All statuses', statuses);

        var roleOptions = ['A', 'R', 'C', 'I', 'ARCI', 'Not Applicable'];
        fillSelect($('vf-role'), 'All roles', roleOptions);
        fillSelect($('vfo-role'), 'All roles', roleOptions);

        var issuers = (CFG.issuers || []).map(function (i) { return { id: i.id, label: i.name }; });
        buildS2Dropdown('vf-issuer', issuers, 'All issuers', 'vf-year', function () {
            tabState.hq.page = 1; renderTable('hq', hqRows);
        });
        buildS2Dropdown('vfo-issuer', issuers, 'All issuers', 'vfo-year', function () {
            tabState.outlet.page = 1; renderTable('outlet', outletRows);
        });

        var outlets = (CFG.outlets || []).map(function (o) { return { id: o.id, label: o.code }; });
        buildS2Dropdown('vfo-outlet', outlets, 'All outlets', 'vfo-year', function () {
            tabState.outlet.page = 1; renderTable('outlet', outletRows);
        });
    }

    // ------------------------------------------- generic searchable dropdown
    // baseId is the shared id prefix for this dropdown's elements, e.g.
    // 'vf-issuer' -> #vf-issuer-list, #vf-issuer-btn, #vf-issuer-value, etc.
    // sizeRefId is another control in the same filter bar to match height/
    // border against (a plain <select> renders differently from this div).
    // Copies height/border from a plain form-select-sm in the same filter bar
    // so the custom dropdown button lines up with it exactly. Only works
    // while the reference element is actually visible (offsetHeight reads 0
    // on a hidden Bootstrap tab-pane), so this must be re-run once the tab
    // containing it is shown - see the 'shown.bs.tab' listener in bind().
    function syncS2ButtonSize(baseId, sizeRefId) {
        var btnEl = $(baseId + '-btn');
        var refEl = $(sizeRefId);
        if (refEl && btnEl) {
            var refStyle = window.getComputedStyle(refEl);
            btnEl.style.height       = refEl.offsetHeight + 'px';
            btnEl.style.border       = refStyle.border;
            btnEl.style.borderRadius = refStyle.borderRadius;
        }
    }

    function buildS2Dropdown(baseId, items, allLabel, sizeRefId, onSelect) {
        var listEl   = $(baseId + '-list');
        var searchEl = $(baseId + '-search');
        var btnEl    = $(baseId + '-btn');
        var dropEl   = $(baseId + '-dropdown');
        var valEl    = $(baseId + '-value');
        var wrapEl   = $(baseId + '-wrap');
        var labelEl  = btnEl; // button element is also the label
        if (!listEl || !btnEl || !dropEl) { return; }

        syncS2ButtonSize(baseId, sizeRefId);

        // Build list items: "All ..." first, then each item.
        var html = '<li class="vf-s2-list-item" data-id="0">' + escapeHtml(allLabel) + '</li>';
        for (var i = 0; i < items.length; i++) {
            html += '<li class="vf-s2-list-item" data-id="' + items[i].id + '">' + escapeHtml(items[i].label) + '</li>';
        }
        listEl.innerHTML = html;

        function openDropdown() {
            dropEl.classList.add('open');
            if (searchEl) { searchEl.value = ''; filterList(''); searchEl.focus(); }
        }
        function closeDropdown() { dropEl.classList.remove('open'); }

        function filterList(term) {
            var listItems = listEl.querySelectorAll('li');
            var lower = term.toLowerCase();
            var anyVisible = false;
            for (var j = 0; j < listItems.length; j++) {
                var text = listItems[j].textContent || '';
                if (!lower || text.toLowerCase().indexOf(lower) >= 0) {
                    listItems[j].classList.remove('hidden');
                    anyVisible = true;
                } else {
                    listItems[j].classList.add('hidden');
                }
            }
            var emptyEl = $(baseId + '-empty');
            if (!anyVisible) {
                if (!emptyEl) {
                    var em = document.createElement('div');
                    em.className = 'vf-s2-empty';
                    em.id = baseId + '-empty';
                    em.textContent = 'No results found';
                    listEl.parentNode.appendChild(em);
                }
            } else {
                if (emptyEl) { emptyEl.parentNode.removeChild(emptyEl); }
            }
        }

        function selectItem(id, label) {
            if (valEl) { valEl.value = id; }
            if (labelEl) { labelEl.textContent = label; }
            closeDropdown();
            mineFilter = false;
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

    function resetS2Dropdown(baseId, allLabel) {
        var valEl  = $(baseId + '-value');
        var btnEl  = $(baseId + '-btn');
        var dropEl = $(baseId + '-dropdown');
        if (valEl)  { valEl.value = '0'; }
        if (btnEl)  { btnEl.textContent = allLabel; }
        if (dropEl) { dropEl.classList.remove('open'); }
    }

    // --------------------------------------------------------------- filtering
    function applyHqFilters(sourceRows) {
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

        return sourceRows.filter(function (r) {
            if (year  && (!r.start_date || parseInt(r.start_date.substring(0, 4), 10) !== year))  { return false; }
            if (month && (!r.start_date || parseInt(r.start_date.substring(5, 7), 10) !== month)) { return false; }
            if (issuer && r.issuer_staff_id !== issuer) { return false; }
            if (level && r.level_label !== level) { return false; }
            if (dept) {
                var deptMatches = (r.department_name === dept) ||
                    (r.arci_dept_names && r.arci_dept_names.indexOf(dept) !== -1);
                if (!deptMatches) { return false; }
            }
            if (status && r.status !== status) { return false; }
            if (statusesPreset.length && statusesPreset.indexOf(r.status) === -1) { return false; }
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
            if (mineFilter) {
                var isMyIssue = CFG.staffId && r.issuer_staff_id == CFG.staffId;
                var isMyArci  = r.user_arci_roles && r.user_arci_roles.length > 0;
                if (!isMyIssue && !isMyArci) { return false; }
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

    function applyOutletFilters(sourceRows) {
        var year  = $('vfo-year')  ? parseInt($('vfo-year').value,  10) || 0 : 0;
        var month = $('vfo-month') ? parseInt($('vfo-month').value, 10) || 0 : 0;
        var status = $('vfo-status').value;
        var pillar = $('vfo-pillar').value;
        var role = $('vfo-role').value;
        var from = $('vfo-from').value;
        var to = $('vfo-to').value;
        var term = $('vfo-search').value.toLowerCase().trim();
        var issuerValEl = $('vfo-issuer-value');
        var issuer = issuerValEl ? (parseInt(issuerValEl.value, 10) || 0) : 0;
        var outletValEl = $('vfo-outlet-value');
        var outletId = outletValEl ? (parseInt(outletValEl.value, 10) || 0) : 0;
        var outletCode = '';
        if (outletId) {
            var match = (CFG.outlets || []).filter(function (o) { return o.id === outletId; })[0];
            outletCode = match ? match.code : '';
        }

        return sourceRows.filter(function (r) {
            if (year  && (!r.start_date || parseInt(r.start_date.substring(0, 4), 10) !== year))  { return false; }
            if (month && (!r.start_date || parseInt(r.start_date.substring(5, 7), 10) !== month)) { return false; }
            if (issuer && r.issuer_staff_id !== issuer) { return false; }
            if (status && r.status !== status) { return false; }
            if (statusesPreset.length && statusesPreset.indexOf(r.status) === -1) { return false; }
            if (pillar && r.pillar_name !== pillar) { return false; }
            if (outletCode && (!r.outlet_codes || r.outlet_codes.indexOf(outletCode) < 0)) { return false; }
            if (role) {
                if (role === 'ARCI') {
                    if (!r.user_arci_roles || r.user_arci_roles.length === 0) { return false; }
                } else if (role === 'Not Applicable') {
                    if (r.user_arci_roles && r.user_arci_roles.length > 0) { return false; }
                } else {
                    if (!r.user_arci_roles || r.user_arci_roles.indexOf(role) < 0) { return false; }
                }
            }
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

    function sortRows(list, sortCol, sortDir) {
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

    function updateSortIndicators(prefix) {
        var st = tabState[prefix];
        var ths = document.querySelectorAll('#atem-view-tbl-' + prefix + ' th.atem-sortable');
        for (var i = 0; i < ths.length; i++) {
            ths[i].classList.remove('atem-sort-asc', 'atem-sort-desc');
            if (st.sortCol !== null && ths[i].getAttribute('data-col') === st.sortCol) {
                ths[i].classList.add(st.sortDir === 1 ? 'atem-sort-asc' : 'atem-sort-desc');
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
    function buildActionCell(r) {
        var _canViewDeleted = CFG.isSuperAdmin || CFG.userGrade >= 4
            || (r.status === 'Suspended' && r.issuer_staff_id == CFG.staffId);
        return r.is_deleted
            ? (_canViewDeleted
                ? '<a href="atem/edit.php?id=' + r.id + '&mode=read" class="btn btn-sm btn-outline-secondary" title="View (Suspended)"><i class="bi bi-eye"></i></a>'
                : '')
            + (canDeleteSuspended(r) ? ' <button type="button" class="btn btn-sm btn-outline-danger atem-delete-row" data-id="' + r.id + '" title="Delete"><i class="bi bi-trash"></i></button>' : '')
            : '<a href="atem/edit.php?id=' + r.id + '&mode=read" class="btn btn-sm btn-outline-primary" title="View"><i class="bi bi-eye"></i></a> '
            + (canEdit(r) ? '<a href="atem/edit.php?id=' + r.id + '&mode=edit" class="btn btn-sm btn-outline-secondary" title="Edit"><i class="bi bi-pencil"></i></a>' : '')
            + (canUpdateProgress(r) ? ' <a href="atem/edit.php?id=' + r.id + '&mode=read#atem-progress-section" class="btn btn-sm btn-outline-secondary" title="Edit"><i class="bi bi-pencil"></i></a>' : '')
            + (canDelete(r) ? ' <button type="button" class="btn btn-sm btn-outline-danger atem-delete-row" data-id="' + r.id + '" title="Delete"><i class="bi bi-trash"></i></button>' : '');
    }

    function buildEndCell(r) {
        var endCell = fmtDate(r.end_date);
        if (r.is_extended && r.extended_date_1) {
            endCell += '<div style="margin-top:3px;font-size:11px;color:#fd7e14;font-weight:500;">'
                + '<i class="bi bi-arrow-right" style="font-size:10px;"></i> Extended to '
                + fmtDate(r.extended_date_1) + '</div>';
        }
        return endCell;
    }

    function rowStyleFor(r) {
        return (r.is_deleted && r.status !== 'Suspended') ? ' style="opacity:0.55;"' : '';
    }

    // Shared Issuer/Accountable cell styling (name + sub-label, divider
    // between multiple accountable members) - used by both the HQ and
    // Outlet tables, each as their own separate column.
    function buildIssuerCell(r) {
        return '<div style="font-size:13px;">' + escapeHtml(r.issuer_name) + '</div>'
            + '<div style="font-size:11px;color:#6c757d;">' + escapeHtml(r.department_name) + '</div>';
    }

    function buildAccountableCell(r) {
        if (!r.accountable || r.accountable.length === 0) {
            return '<span style="color:#adb5bd;font-size:12px;">—</span>';
        }
        var html = '';
        for (var ai = 0; ai < r.accountable.length; ai++) {
            if (ai > 0) {
                html += '<div style="border-top:1px solid #dee2e6;margin:4px 0;"></div>';
            }
            html += '<div style="font-size:13px;">' + escapeHtml(r.accountable[ai].name) + '</div>'
                + '<div style="font-size:11px;color:#6c757d;">' + escapeHtml(r.accountable[ai].dept) + '</div>';
        }
        return html;
    }

    function buildHqRowHtml(r) {
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
        return '<tr' + rowStyleFor(r) + '>'
            + '<td><span class="atem-id">#AT' + r.id + '</span></td>'
            + '<td>' + escapeHtml(r.title) + '</td>'
            + '<td>' + buildIssuerCell(r) + '</td>'
            + '<td>' + buildAccountableCell(r) + '</td>'
            + '<td>' + arciCell + '</td>'
            + '<td>' + levelCell + '</td>'
            + '<td>' + fmtDate(r.start_date) + '</td>'
            + '<td>' + buildEndCell(r) + '</td>'
            + '<td>' + statusCell + '</td>'
            + '<td class="atem-view-actions">' + buildActionCell(r) + '</td></tr>';
    }

    function buildOutletRowHtml(r) {
        var statusCell = r.status ? pill(r.status, STATUS_COLOR[r.status] || '#6c757d') : '-';
        var pillarCell = r.pillar_name ? pill(r.pillar_name, '#0A5AA8') : '-';
        return '<tr' + rowStyleFor(r) + '>'
            + '<td><span class="atem-id">#AT' + r.id + '</span></td>'
            + '<td>' + escapeHtml(r.title) + '</td>'
            + '<td>' + buildIssuerCell(r) + '</td>'
            + '<td>' + buildAccountableCell(r) + '</td>'
            + '<td>' + pillarCell + '</td>'
            + '<td>' + fmtDate(r.start_date) + '</td>'
            + '<td>' + buildEndCell(r) + '</td>'
            + '<td>' + statusCell + '</td>'
            + '<td class="atem-view-actions">' + buildActionCell(r) + '</td></tr>';
    }

    function renderTable(prefix, sourceRows) {
        var st = tabState[prefix];
        updateSortIndicators(prefix);
        var filtered = (prefix === 'hq') ? applyHqFilters(sourceRows) : applyOutletFilters(sourceRows);
        var list = sortRows(filtered, st.sortCol, st.sortDir);
        var total = list.length;
        var pages = Math.max(1, Math.ceil(total / st.perPage));
        if (st.page > pages) { st.page = pages; }
        var startIdx = (st.page - 1) * st.perPage;
        var pageRows = list.slice(startIdx, startIdx + st.perPage);

        var body = $('atem-view-body-' + prefix);
        if (total === 0) {
            var colCount = document.querySelectorAll('#atem-view-tbl-' + prefix + ' thead th').length || 9;
            body.innerHTML = '<tr><td colspan="' + colCount + '" class="atem-empty-state" style="text-align:center;padding:24px;">No ATEM cards match the current filters.</td></tr>';
        } else {
            var html = '';
            var buildRowHtml = (prefix === 'hq') ? buildHqRowHtml : buildOutletRowHtml;
            for (var i = 0; i < pageRows.length; i++) { html += buildRowHtml(pageRows[i]); }
            body.innerHTML = html;
        }
        renderPagerFor(prefix, total, pages, startIdx, pageRows.length);
    }

    function renderAll() {
        renderTable('hq', hqRows);
        renderTable('outlet', outletRows);
    }

    // Filter changes only re-render the currently visible tab - the other
    // tab lazily catches up (see the tab 'shown.bs.tab' listener in bind()).
    function renderActiveTab() {
        renderTable(activeTab, rowsForTab(activeTab));
    }

    function renderPagerFor(prefix, total, pages, startIdx, shown) {
        var st = tabState[prefix];
        var pager = $('atem-pager-' + prefix);
        if (total === 0) { pager.innerHTML = ''; return; }

        var opts = [10, 30, 50, 100];
        var selHtml = '<select class="atem-perpage-select">';
        for (var oi = 0; oi < opts.length; oi++) {
            selHtml += '<option value="' + opts[oi] + '"' + (st.perPage === opts[oi] ? ' selected' : '') + '>' + opts[oi] + '</option>';
        }
        selHtml += '</select>';
        var leftHtml = '<div class="atem-pager-left">Show ' + selHtml + ' entries</div>';

        var info = '<span class="atem-pager-info">Showing ' + (startIdx + 1) + ' to ' + (startIdx + shown) + ' of ' + total + ' entries</span>';
        var btns = '<button type="button" class="atem-pager-btn" data-page="' + (st.page - 1) + '"' + (st.page <= 1 ? ' disabled' : '') + '>Previous</button>';

        var win = 2, from = Math.max(1, st.page - win), to = Math.min(pages, st.page + win);
        if (from > 1) { btns += pageBtn(st.page, 1) + (from > 2 ? '<span class="atem-pager-gap">...</span>' : ''); }
        for (var p = from; p <= to; p++) { btns += pageBtn(st.page, p); }
        if (to < pages) { btns += (to < pages - 1 ? '<span class="atem-pager-gap">...</span>' : '') + pageBtn(st.page, pages); }
        btns += '<button type="button" class="atem-pager-btn" data-page="' + (st.page + 1) + '"' + (st.page >= pages ? ' disabled' : '') + '>Next</button>';

        var rightHtml = '<div class="d-flex align-items-center gap-2">' + info + '<div class="atem-pager-bar">' + btns + '</div></div>';
        pager.innerHTML = leftHtml + rightHtml;
    }

    function pageBtn(currentPage, p) {
        return '<button type="button" class="atem-pager-btn' + (p === currentPage ? ' active' : '') + '" data-page="' + p + '">' + p + '</button>';
    }

    // --------------------------------------------------------------- wiring
    function bind() {
        // HQ filter bar - always renders the HQ table (it's only visible
        // while that tab is active anyway).
        ['vf-year', 'vf-month', 'vf-level', 'vf-dept', 'vf-status', 'vf-role', 'vf-from', 'vf-to'].forEach(function (id) {
            var el = $(id);
            if (el) {
                el.addEventListener('change', function () {
                    presetClosed = false; overdueFilter = false; minLevelId = 0; mineFilter = false; statusesPreset = [];
                    tabState.hq.page = 1;
                    renderTable('hq', hqRows);
                });
            }
        });
        $('vf-search').addEventListener('keyup', function () {
            tabState.hq.page = 1;
            renderTable('hq', hqRows);
        });
        $('vf-reset').addEventListener('click', function () {
            ['vf-year', 'vf-status', 'vf-level', 'vf-dept', 'vf-role', 'vf-from', 'vf-to', 'vf-search'].forEach(function (id) { var el = $(id); if (el) { el.value = ''; } });
            var monthEl = $('vf-month'); if (monthEl) { monthEl.value = '0'; }
            resetS2Dropdown('vf-issuer', 'All issuers');
            presetClosed = false; overdueFilter = false; minLevelId = 0; mineFilter = false; statusesPreset = [];
            tabState.hq.page = 1;
            renderTable('hq', hqRows);
        });

        // Outlet filter bar - always renders the Outlet table.
        ['vfo-year', 'vfo-month', 'vfo-status', 'vfo-pillar', 'vfo-from', 'vfo-to'].forEach(function (id) {
            var el = $(id);
            if (el) {
                el.addEventListener('change', function () {
                    presetClosed = false; overdueFilter = false; mineFilter = false; statusesPreset = [];
                    tabState.outlet.page = 1;
                    renderTable('outlet', outletRows);
                });
            }
        });
        $('vfo-search').addEventListener('keyup', function () {
            tabState.outlet.page = 1;
            renderTable('outlet', outletRows);
        });
        $('vfo-reset').addEventListener('click', function () {
            ['vfo-year', 'vfo-status', 'vfo-pillar', 'vfo-from', 'vfo-to', 'vfo-search'].forEach(function (id) { var el = $(id); if (el) { el.value = ''; } });
            var monthEl2 = $('vfo-month'); if (monthEl2) { monthEl2.value = '0'; }
            resetS2Dropdown('vfo-issuer', 'All issuers');
            resetS2Dropdown('vfo-outlet', 'All outlets');
            presetClosed = false; overdueFilter = false; mineFilter = false; statusesPreset = [];
            tabState.outlet.page = 1;
            renderTable('outlet', outletRows);
        });

        var pendingDeleteId = null;
        var _deleteModal = null;
        function getDeleteModal() {
            if (!_deleteModal && typeof bootstrap !== 'undefined') {
                _deleteModal = new bootstrap.Modal(document.getElementById('atem-delete-modal'));
            }
            return _deleteModal;
        }

        ['atem-view-body-hq', 'atem-view-body-outlet'].forEach(function (bodyId) {
        var body = $(bodyId);
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
        });

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
                        hqRows = hqRows.filter(function (r) { return r.id !== atemId; });
                        outletRows = outletRows.filter(function (r) { return r.id !== atemId; });
                        tabState.hq.page = 1; tabState.outlet.page = 1;
                        renderActiveTab();
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

        ['hq', 'outlet'].forEach(function (prefix) {
            var ths = document.querySelectorAll('#atem-view-tbl-' + prefix + ' th.atem-sortable');
            for (var i = 0; i < ths.length; i++) {
                ths[i].addEventListener('click', function () {
                    var col = this.getAttribute('data-col');
                    var st = tabState[prefix];
                    if (st.sortCol === col) { st.sortDir = -st.sortDir; } else { st.sortCol = col; st.sortDir = 1; }
                    renderTable(prefix, rowsForTab(prefix));
                });
            }

            var pagerEl = $('atem-pager-' + prefix);
            if (pagerEl) {
                pagerEl.addEventListener('click', function (e) {
                    var btn = e.target.closest ? e.target.closest('.atem-pager-btn') : null;
                    if (!btn || btn.disabled) { return; }
                    var p = parseInt(btn.getAttribute('data-page'), 10);
                    if (p >= 1) { tabState[prefix].page = p; renderTable(prefix, rowsForTab(prefix)); }
                });

                pagerEl.addEventListener('change', function (e) {
                    if (e.target.classList.contains('atem-perpage-select')) {
                        tabState[prefix].perPage = parseInt(e.target.value, 10);
                        tabState[prefix].page = 1;
                        renderTable(prefix, rowsForTab(prefix));
                    }
                });
            }
        });

        var hqTabBtn = $('atem-tab-hq-btn');
        var outletTabBtn = $('atem-tab-outlet-btn');
        if (hqTabBtn) {
            hqTabBtn.addEventListener('shown.bs.tab', function () { activeTab = 'hq'; renderActiveTab(); });
        }
        if (outletTabBtn) {
            outletTabBtn.addEventListener('shown.bs.tab', function () {
                activeTab = 'outlet';
                // These were built while the Outlet tab-pane was hidden
                // (display:none), so their height/border sync read 0 - redo
                // it now that the pane is actually visible.
                syncS2ButtonSize('vfo-issuer', 'vfo-year');
                syncS2ButtonSize('vfo-outlet', 'vfo-year');
                renderActiveTab();
            });
        }
    }

    document.addEventListener('DOMContentLoaded', function () {
        if ($('atem-tab-hq-count')) { $('atem-tab-hq-count').textContent = hqRows.length; }
        if ($('atem-tab-outlet-count')) { $('atem-tab-outlet-count').textContent = outletRows.length; }
        buildFilters();
        var params = new URLSearchParams(window.location.search);
        if (params.get('statuses')) {
            statusesPreset = params.get('statuses').split(',').map(function (s) { return s.trim(); }).filter(Boolean);
        } else if (params.get('status')) {
            statusesPreset = [params.get('status')];
        }
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
        if (params.get('role'))  { var ro = $('vf-role');  if (ro) { ro.value = params.get('role'); } }
        if (params.get('from'))  { var fr = $('vf-from');  if (fr) { fr.value = params.get('from'); } }
        if (params.get('to'))    { var to = $('vf-to');    if (to) { to.value = params.get('to'); } }
        if (params.get('overdue')       === '1')      { overdueFilter = true; }
        if (params.get('min_level_id'))               { minLevelId = parseInt(params.get('min_level_id'), 10) || 0; }
        if (params.get('mine')          === '1')      { mineFilter = true; }
        if (params.get('issuer') === 'me' && CFG.staffId) {
            var ivEl = $('vf-issuer-value');
            var ibEl = $('vf-issuer-btn');
            if (ivEl) { ivEl.value = CFG.staffId; }
            if (ibEl) {
                var meName = 'Me';
                var issuersList = CFG.issuers || [];
                for (var mi = 0; mi < issuersList.length; mi++) {
                    if (issuersList[mi].id == CFG.staffId) { meName = issuersList[mi].name; break; }
                }
                ibEl.textContent = meName;
            }
        }
        bind();
        renderAll();
    });
})();
