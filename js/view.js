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
    // Defaults to the single-view tab when a grade-1 user only ever has one
    // (no tab nav exists to switch it via 'shown.bs.tab' in that case).
    var activeTab = (CFG.tabSingleView === 'outlet') ? 'outlet' : 'hq';
    var tabState = {
        hq:     { page: 1, sortCol: null, sortDir: 1, perPage: 30 },
        outlet: { page: 1, sortCol: null, sortDir: 1, perPage: 30 }
    };

    var TODAY           = new Date().toLocaleDateString('en-CA', { timeZone: 'Asia/Kuala_Lumpur' });
    var presetClosed    = false;
    var overdueFilter   = false;
    var minLevelId      = 0;
    var mineFilter      = false;

    var LEVEL_COLOR = { 'Level 1': '#6c757d', 'Level 2': '#0d6efd', 'Level 3': '#6610f2', 'Level 4': '#003B73' };
    var ARCI_COLOR  = { 'A': '#6610f2', 'R': '#0d6efd', 'C': '#fd7e14', 'I': '#6c757d' };
    var STATUS_COLOR = {
        'Draft': '#6c757d', 'Active': '#0d6efd',
        'Completed': '#198754', 'Completed with Excellence': '#0dcaf0', 'Completed with Extension': '#495057',
        'Extended': '#fd7e14', 'Failed': '#dc3545',
        'Deleted': '#dc3545', 'Suspended': '#e11d48', 'Force Terminated': '#7c3aed'
    };
    function $(id) { return document.getElementById(id); }

    // Remembers which tab is active so create.php can pre-select the matching
    // ATEM Type on load, without a URL param - read once via sessionStorage,
    // the manual HQ/Outlet toggle there still works exactly as before.
    function rememberActiveTabForCreate() {
        try { sessionStorage.setItem('atem_create_type', activeTab); } catch (e) { /* storage unavailable, ignore */ }
    }

    function escapeHtml(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }

    function pill(text, color, titleAttr) {
        var t = titleAttr ? ' title="' + escapeHtml(titleAttr) + '"' : '';
        return '<span class="atem-pill" style="background-color:' + color + '"' + t + '>' + escapeHtml(text) + '</span>';
    }

    // "Level 1" -> "L1", etc. - shown in the table; the full label + system
    // name still appear on hover via the pill's title attribute.
    function shortLevelLabel(label) {
        return label ? String(label).replace(/^Level\s*/i, 'L') : label;
    }

    function fmtDate(v) {
        if (!v) { return '-'; }
        var p = String(v).substring(0, 10).split('-'); // YYYY-MM-DD
        return (p.length === 3) ? (p[2] + '-' + p[1] + '-' + p[0]) : v;
    }

    // Same muted em-dash style used for empty ARCI cells.
    function fmtDateOrDash(v) {
        return v ? fmtDate(v) : '<span style="color:#adb5bd;font-size:12px;">—</span>';
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

        var regions = (CFG.regions || []).map(function (r) { return r.name; });
        fillSelect($('vfo-region'), 'All regions', regions);

        var canSeeDeleted = (CFG.userGrade >= 4 || CFG.isSuperAdmin);
        var statuses = (CFG.statuses || [])
            .filter(function (s) { return canSeeDeleted || (s.value !== 'Deleted' && s.value !== 'Suspended' && s.value !== 'Force Terminated'); })
            .map(function (s) { return s.value; });
        buildStatusOptions('vf-status', statuses);
        buildStatusOptions('vfo-status', statuses);
        buildStatusDropdown('vf-status', 'vf-year', function () {
            presetClosed = false; overdueFilter = false; minLevelId = 0; mineFilter = false;
            tabState.hq.page = 1; renderTable('hq', hqRows);
        });
        buildStatusDropdown('vfo-status', 'vfo-year', function () {
            presetClosed = false; overdueFilter = false; mineFilter = false;
            tabState.outlet.page = 1; renderTable('outlet', outletRows);
        });

        var roleOptions = ['Issuer', 'A', 'R', 'C', 'I'];
        buildRoleOptions('vf-role', roleOptions);
        buildRoleOptions('vfo-role', roleOptions);
        buildRoleDropdown('vf-role', 'vf-year', function () {
            tabState.hq.page = 1; renderTable('hq', hqRows);
        });
        buildRoleDropdown('vfo-role', 'vfo-year', function () {
            tabState.outlet.page = 1; renderTable('outlet', outletRows);
        });

        var issuers = (CFG.issuers || []).map(function (i) { return { id: i.id, label: i.name }; });
        buildS2Dropdown('vf-issuer', issuers, 'All staff', 'vf-year', function () {
            tabState.hq.page = 1; renderTable('hq', hqRows);
        });
        buildS2Dropdown('vfo-issuer', issuers, 'All staff', 'vfo-year', function () {
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

    // ------------------------------------------------- status checkbox dropdown
    // baseId is 'vf-status' (HQ tab) or 'vfo-status' (Outlet tab) - each tab
    // has its own independent multi-select so switching tabs doesn't reset it.
    var DEFAULT_STATUSES = ['Active', 'Extended', 'Suspended'];

    function buildStatusOptions(baseId, statusValues) {
        var listEl = $(baseId + '-list');
        if (!listEl) { return; }
        var html = '';
        for (var s = 0; s < statusValues.length; s++) {
            var sv = statusValues[s];
            var isDefault = DEFAULT_STATUSES.indexOf(sv) !== -1;
            html += '<li class="vf-s2-list-item" style="cursor:default;">' +
                '<label style="display:flex;align-items:center;gap:6px;width:100%;cursor:pointer;margin:0;">' +
                '<input type="checkbox" value="' + escapeHtml(sv) + '"' + (isDefault ? ' checked' : '') + '> ' + escapeHtml(sv) +
                '</label></li>';
        }
        listEl.innerHTML = html;
        updateStatusButtonLabel(baseId);
    }

    function allStatusCheckboxes(baseId) {
        var listEl = $(baseId + '-list');
        return listEl ? listEl.querySelectorAll('input[type=checkbox]') : [];
    }

    function getSelectedStatuses(baseId) {
        var listEl = $(baseId + '-list');
        if (!listEl) { return []; }
        var boxes = listEl.querySelectorAll('input[type=checkbox]:checked');
        var out = [];
        for (var i = 0; i < boxes.length; i++) { out.push(boxes[i].value); }
        return out;
    }

    function updateStatusButtonLabel(baseId) {
        var btn = $(baseId + '-btn');
        if (!btn) { return; }
        var selected = getSelectedStatuses(baseId);
        var all = allStatusCheckboxes(baseId);
        if (selected.length === 0) {
            btn.textContent = 'No status selected';
        } else if (selected.length === all.length) {
            btn.textContent = 'All statuses';
        } else if (selected.length <= 2) {
            btn.textContent = selected.join(', ');
        } else {
            btn.textContent = selected.length + ' statuses selected';
        }
    }

    function resetStatusDropdown(baseId) {
        var boxes = allStatusCheckboxes(baseId);
        for (var i = 0; i < boxes.length; i++) { boxes[i].checked = (DEFAULT_STATUSES.indexOf(boxes[i].value) !== -1); }
        updateStatusButtonLabel(baseId);
        var dropEl = $(baseId + '-dropdown');
        if (dropEl) { dropEl.classList.remove('open'); }
    }

    function buildStatusDropdown(baseId, sizeRefId, onChange) {
        var btnEl  = $(baseId + '-btn');
        var dropEl = $(baseId + '-dropdown');
        var wrapEl = $(baseId + '-wrap');
        if (!btnEl || !dropEl) { return; }

        // Sync dimensions, border and font-size to a form-select-sm exactly —
        // otherwise this custom div falls back to its own CSS box model and
        // renders taller/rounder than the selects next to it. Only works while
        // the reference element is visible - re-run on tab show, see bind().
        var refEl = $(sizeRefId);
        if (refEl) {
            var refStyle = window.getComputedStyle(refEl);
            btnEl.style.height       = refEl.offsetHeight + 'px';
            btnEl.style.border       = refStyle.border;
            btnEl.style.borderRadius = refStyle.borderRadius;
            btnEl.style.fontSize     = refStyle.fontSize;
            btnEl.style.color        = refStyle.color;
        }

        updateStatusButtonLabel(baseId);

        btnEl.addEventListener('click', function (e) {
            e.stopPropagation();
            dropEl.classList.toggle('open');
        });
        btnEl.addEventListener('keydown', function (e) {
            if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); dropEl.classList.toggle('open'); }
        });
        document.addEventListener('click', function (e) {
            if (wrapEl && !wrapEl.contains(e.target)) { dropEl.classList.remove('open'); }
        });
        dropEl.addEventListener('change', function (e) {
            if (e.target && e.target.type === 'checkbox') {
                updateStatusButtonLabel(baseId);
                if (onChange) { onChange(); }
            }
        });
    }

    // --------------------------------------------------- role checkbox dropdown
    // baseId is 'vf-role' (HQ tab) or 'vfo-role' (Outlet tab) - same widget
    // shape as the status checkbox dropdown above, own independent state.
    var DEFAULT_ROLES = ['Issuer', 'A', 'R', 'C', 'I'];

    function buildRoleOptions(baseId, roleValues) {
        var listEl = $(baseId + '-list');
        if (!listEl) { return; }
        var html = '';
        for (var s = 0; s < roleValues.length; s++) {
            var rv = roleValues[s];
            var isDefault = DEFAULT_ROLES.indexOf(rv) !== -1;
            html += '<li class="vf-s2-list-item" style="cursor:default;">' +
                '<label style="display:flex;align-items:center;gap:6px;width:100%;cursor:pointer;margin:0;">' +
                '<input type="checkbox" value="' + escapeHtml(rv) + '"' + (isDefault ? ' checked' : '') + '> ' + escapeHtml(rv) +
                '</label></li>';
        }
        listEl.innerHTML = html;
        updateRoleButtonLabel(baseId);
    }

    function allRoleCheckboxes(baseId) {
        var listEl = $(baseId + '-list');
        return listEl ? listEl.querySelectorAll('input[type=checkbox]') : [];
    }

    function getSelectedRoles(baseId) {
        var listEl = $(baseId + '-list');
        if (!listEl) { return []; }
        var boxes = listEl.querySelectorAll('input[type=checkbox]:checked');
        var out = [];
        for (var i = 0; i < boxes.length; i++) { out.push(boxes[i].value); }
        return out;
    }

    function updateRoleButtonLabel(baseId) {
        var btn = $(baseId + '-btn');
        if (!btn) { return; }
        var selected = getSelectedRoles(baseId);
        var all = allRoleCheckboxes(baseId);
        if (selected.length === 0) {
            btn.textContent = 'No role selected';
        } else if (selected.length === all.length) {
            btn.textContent = 'All roles';
        } else if (selected.length <= 2) {
            btn.textContent = selected.join(', ');
        } else {
            btn.textContent = selected.length + ' roles selected';
        }
    }

    function resetRoleDropdown(baseId) {
        var boxes = allRoleCheckboxes(baseId);
        for (var i = 0; i < boxes.length; i++) { boxes[i].checked = (DEFAULT_ROLES.indexOf(boxes[i].value) !== -1); }
        updateRoleButtonLabel(baseId);
        var dropEl = $(baseId + '-dropdown');
        if (dropEl) { dropEl.classList.remove('open'); }
    }

    function buildRoleDropdown(baseId, sizeRefId, onChange) {
        var btnEl  = $(baseId + '-btn');
        var dropEl = $(baseId + '-dropdown');
        var wrapEl = $(baseId + '-wrap');
        if (!btnEl || !dropEl) { return; }

        var refEl = $(sizeRefId);
        if (refEl) {
            var refStyle = window.getComputedStyle(refEl);
            btnEl.style.height       = refEl.offsetHeight + 'px';
            btnEl.style.border       = refStyle.border;
            btnEl.style.borderRadius = refStyle.borderRadius;
            btnEl.style.fontSize     = refStyle.fontSize;
            btnEl.style.color        = refStyle.color;
        }

        updateRoleButtonLabel(baseId);

        btnEl.addEventListener('click', function (e) {
            e.stopPropagation();
            dropEl.classList.toggle('open');
        });
        btnEl.addEventListener('keydown', function (e) {
            if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); dropEl.classList.toggle('open'); }
        });
        document.addEventListener('click', function (e) {
            if (wrapEl && !wrapEl.contains(e.target)) { dropEl.classList.remove('open'); }
        });
        dropEl.addEventListener('change', function (e) {
            if (e.target && e.target.type === 'checkbox') {
                updateRoleButtonLabel(baseId);
                if (onChange) { onChange(); }
            }
        });
    }

    // Shared role-match test: a row matches if it holds ANY of the selected
    // roles (Issuer via issuer_staff_id, A/R/C/I via user_arci_roles).
    function rowMatchesRoles(r, selectedRoles, staffId) {
        for (var i = 0; i < selectedRoles.length; i++) {
            var rv = selectedRoles[i];
            if (rv === 'Issuer') {
                if (staffId && r.issuer_staff_id == staffId) { return true; }
            } else if (r.user_arci_roles && r.user_arci_roles.indexOf(rv) >= 0) {
                return true;
            }
        }
        return false;
    }

    // --------------------------------------------------------------- filtering
    // Active/Draft cards haven't closed yet, so the Year/Month/From-To filters
    // go by when they started; every other status (Completed family, Extended,
    // Failed) is bucketed by when it closed - mirrors api.php's
    // atem_status_period_field()/dashboard-stats convention, so the counts
    // shown here and on the dashboard agree for the same filter selection.
    function periodDateOf(r) {
        // Active/Draft haven't closed yet, so period by start_date; Suspended/
        // Force Terminated never get a closure_date either (they're soft-deleted
        // without ever actually closing) - period them by start_date too, or the
        // Year/Month filter would silently exclude every one of them.
        if (r.status === 'Active' || r.status === 'Draft' || r.status === 'Suspended' || r.status === 'Force Terminated') {
            return r.start_date;
        }
        return r.closure_date;
    }

    function applyHqFilters(sourceRows) {
        var year  = $('vf-year')  ? parseInt($('vf-year').value,  10) || 0 : 0;
        var month = $('vf-month') ? parseInt($('vf-month').value, 10) || 0 : 0;
        var level = $('vf-level').value;
        var dept = $('vf-dept').value;
        var statuses = getSelectedStatuses('vf-status');
        var allStatusCount = allStatusCheckboxes('vf-status').length;
        var roles = getSelectedRoles('vf-role');
        var allRoleCount = allRoleCheckboxes('vf-role').length;
        var startDate = $('vf-start-date').value;
        var endDate = $('vf-end-date').value;
        var closureFrom = $('vf-closure-from').value;
        var closureTo = $('vf-closure-to').value;
        var term = $('vf-search').value.toLowerCase().trim();
        var issuerValEl = $('vf-issuer-value');
        var issuer = issuerValEl ? (parseInt(issuerValEl.value, 10) || 0) : 0;

        return sourceRows.filter(function (r) {
            var pDate = periodDateOf(r);
            if (year  && (!pDate || parseInt(pDate.substring(0, 4), 10) !== year))  { return false; }
            if (month && (!pDate || parseInt(pDate.substring(5, 7), 10) !== month)) { return false; }
            if (issuer && r.issuer_staff_id !== issuer && (!r.arci_staff_ids || r.arci_staff_ids.indexOf(issuer) === -1)) { return false; }
            if (level && r.level_label !== level) { return false; }
            if (dept) {
                var deptMatches = (r.department_name === dept) ||
                    (r.arci_dept_names && r.arci_dept_names.indexOf(dept) !== -1);
                if (!deptMatches) { return false; }
            }
            if (statuses.length === 0) { return false; }
            if (statuses.length < allStatusCount && statuses.indexOf(r.status) === -1) { return false; }
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
            if (roles.length === 0) { return false; }
            if (roles.length < allRoleCount && !rowMatchesRoles(r, roles, CFG.staffId)) { return false; }
            if (mineFilter) {
                var isMyIssue = CFG.staffId && r.issuer_staff_id == CFG.staffId;
                var isMyArci  = r.user_arci_roles && r.user_arci_roles.length > 0;
                if (!isMyIssue && !isMyArci) { return false; }
            }
            if (startDate && (!r.start_date || String(r.start_date).substring(0, 10) !== startDate)) { return false; }
            if (endDate && (!r.end_date || String(r.end_date).substring(0, 10) !== endDate)) { return false; }
            if (closureFrom && (!r.closure_date || String(r.closure_date).substring(0, 10) < closureFrom)) { return false; }
            if (closureTo && (!r.closure_date || String(r.closure_date).substring(0, 10) > closureTo)) { return false; }
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
        var statuses = getSelectedStatuses('vfo-status');
        var allStatusCount = allStatusCheckboxes('vfo-status').length;
        var region = $('vfo-region').value;
        var roles = getSelectedRoles('vfo-role');
        var allRoleCount = allRoleCheckboxes('vfo-role').length;
        var startDate = $('vfo-start-date').value;
        var endDate = $('vfo-end-date').value;
        var closureFrom = $('vfo-closure-from').value;
        var closureTo = $('vfo-closure-to').value;
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
            var pDate = periodDateOf(r);
            if (year  && (!pDate || parseInt(pDate.substring(0, 4), 10) !== year))  { return false; }
            if (month && (!pDate || parseInt(pDate.substring(5, 7), 10) !== month)) { return false; }
            if (issuer && r.issuer_staff_id !== issuer && (!r.arci_staff_ids || r.arci_staff_ids.indexOf(issuer) === -1)) { return false; }
            if (statuses.length === 0) { return false; }
            if (statuses.length < allStatusCount && statuses.indexOf(r.status) === -1) { return false; }
            if (region && (!r.region_names || r.region_names.indexOf(region) < 0)) { return false; }
            if (outletCode && (!r.outlet_codes || r.outlet_codes.indexOf(outletCode) < 0)) { return false; }
            if (roles.length === 0) { return false; }
            if (roles.length < allRoleCount && !rowMatchesRoles(r, roles, CFG.staffId)) { return false; }
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
            if (startDate && (!r.start_date || String(r.start_date).substring(0, 10) !== startDate)) { return false; }
            if (endDate && (!r.end_date || String(r.end_date).substring(0, 10) !== endDate)) { return false; }
            if (closureFrom && (!r.closure_date || String(r.closure_date).substring(0, 10) < closureFrom)) { return false; }
            if (closureTo && (!r.closure_date || String(r.closure_date).substring(0, 10) > closureTo)) { return false; }
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
        if (r.payout_status === 'Closed') { return false; }
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

    // --------------------------------------------------------------- rendering
    // The Edit link always points at mode=edit and is shown for every row,
    // whether or not this viewer can actually edit it. edit.php's own
    // $can_edit backstop (issuer / Accountable ARCI / real SuperAdmin) is the
    // sole authority and downgrades unauthorized viewers to a read-only page
    // server-side, so the button doubles as "open" for everyone else.
    function buildActionCell(r) {
        var _canViewDeleted = CFG.isSuperAdmin || CFG.userGrade >= 4
            || (r.status === 'Suspended' && r.issuer_staff_id == CFG.staffId);
        var _base = window.ATEM_MODULE_BASE || 'atem/';
        var editLink = '<a href="' + _base + 'edit.php?id=' + r.id + '&mode=edit" class="btn btn-sm btn-outline-secondary" title="Edit"><i class="bi bi-pencil"></i></a>';
        return r.is_deleted
            ? (_canViewDeleted ? editLink : '')
            + (canDeleteSuspended(r) ? ' <button type="button" class="btn btn-sm btn-outline-danger atem-delete-row" data-id="' + r.id + '" title="Delete"><i class="bi bi-trash"></i></button>' : '')
            : editLink
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

    // Shared "-" divider for merged columns (Start/End, Issuer/Accountable).
    // display:block + width:100% guarantee centering regardless of the plain
    // text/inline content stacked around it; darker color + bold for visibility.
    // Left-aligned (not centered on the full cell width) so it lines up under
    // the text above/below it, which is itself left-aligned and narrower than
    // the column.
    var CELL_DIVIDER_HTML = '<div style="text-align:left;color:#6c757d;font-weight:700;font-size:12px;margin:3px 0;">-</div>';

    function rowStyleFor(r) {
        return ((r.is_deleted && r.status !== 'Suspended') || r.payout_status === 'Closed')
            ? ' style="opacity:0.55;"' : '';
    }

    // Shared Issuer/Accountable cell styling (name + sub-label, divider
    // between multiple accountable members) - used by both the HQ and
    // Outlet tables, each as their own separate column.
    var CELL_LABEL_STYLE = 'font-size:10px;color:#0A5AA8;font-weight:700;text-transform:uppercase;letter-spacing:.03em;';

    function buildIssuerCell(r) {
        return '<div style="' + CELL_LABEL_STYLE + '">Issuer</div>'
            + '<div style="font-size:13px;">' + escapeHtml(r.issuer_name) + '</div>'
            + '<div style="font-size:11px;color:#6c757d;">' + escapeHtml(r.department_name) + '</div>';
    }

    function buildAccountableCell(r) {
        var label = '<div style="' + CELL_LABEL_STYLE + '">Accountable</div>';
        if (!r.accountable || r.accountable.length === 0) {
            return label + '<span style="color:#adb5bd;font-size:12px;">—</span>';
        }
        var html = label;
        for (var ai = 0; ai < r.accountable.length; ai++) {
            if (ai > 0) {
                html += '<div style="border-top:1px solid #dee2e6;margin:4px 0;"></div>';
            }
            html += '<div style="font-size:13px;">' + escapeHtml(r.accountable[ai].name) + '</div>'
                + '<div style="font-size:11px;color:#6c757d;">' + escapeHtml(r.accountable[ai].dept) + '</div>';
        }
        return html;
    }

    // Merged Issuer + Accountable column: Issuer on top, a "-" divider, then
    // Accountable below.
    function buildIssuerAccountableCell(r) {
        return buildIssuerCell(r) + CELL_DIVIDER_HTML + buildAccountableCell(r);
    }

    function buildHqRowHtml(r) {
        var levelCell = r.level_label ? pill(shortLevelLabel(r.level_label), LEVEL_COLOR[r.level_label] || '#6c757d', r.level_label + (r.system_name ? ' - ' + r.system_name : '')) : '-';
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
            + '<td>' + buildIssuerAccountableCell(r) + '</td>'
            + '<td>' + arciCell + '</td>'
            + '<td>' + levelCell + '</td>'
            + '<td>' + fmtDate(r.start_date) + '</td>'
            + '<td>' + buildEndCell(r) + '</td>'
            + '<td>' + fmtDateOrDash(r.closure_date) + '</td>'
            + '<td>' + statusCell + '</td>'
            + '<td class="atem-view-actions">' + buildActionCell(r) + '</td></tr>';
    }

    function buildOutletRowHtml(r) {
        var statusCell = r.status ? pill(r.status, STATUS_COLOR[r.status] || '#6c757d') : '-';
        var pillarCell = r.pillar_name ? pill(r.pillar_name, '#0A5AA8') : '-';
        return '<tr' + rowStyleFor(r) + '>'
            + '<td><span class="atem-id">#AT' + r.id + '</span></td>'
            + '<td>' + escapeHtml(r.title) + '</td>'
            + '<td>' + buildIssuerAccountableCell(r) + '</td>'
            + '<td>' + pillarCell + '</td>'
            + '<td>' + fmtDate(r.start_date) + '</td>'
            + '<td>' + buildEndCell(r) + '</td>'
            + '<td>' + fmtDateOrDash(r.closure_date) + '</td>'
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
        ['vf-year', 'vf-month', 'vf-level', 'vf-dept', 'vf-start-date', 'vf-end-date', 'vf-closure-from', 'vf-closure-to'].forEach(function (id) {
            var el = $(id);
            if (el) {
                el.addEventListener('change', function () {
                    presetClosed = false; overdueFilter = false; minLevelId = 0; mineFilter = false;
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
            ['vf-year', 'vf-level', 'vf-dept', 'vf-start-date', 'vf-end-date', 'vf-closure-from', 'vf-closure-to', 'vf-search'].forEach(function (id) { var el = $(id); if (el) { el.value = ''; } });
            var monthEl = $('vf-month'); if (monthEl) { monthEl.value = '0'; }
            resetS2Dropdown('vf-issuer', 'All staff');
            resetStatusDropdown('vf-status');
            resetRoleDropdown('vf-role');
            presetClosed = false; overdueFilter = false; minLevelId = 0; mineFilter = false;
            tabState.hq.page = 1;
            renderTable('hq', hqRows);
        });

        // Outlet filter bar - always renders the Outlet table.
        ['vfo-year', 'vfo-month', 'vfo-region', 'vfo-start-date', 'vfo-end-date', 'vfo-closure-from', 'vfo-closure-to'].forEach(function (id) {
            var el = $(id);
            if (el) {
                el.addEventListener('change', function () {
                    presetClosed = false; overdueFilter = false; mineFilter = false;
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
            ['vfo-year', 'vfo-region', 'vfo-start-date', 'vfo-end-date', 'vfo-closure-from', 'vfo-closure-to', 'vfo-search'].forEach(function (id) { var el = $(id); if (el) { el.value = ''; } });
            var monthEl2 = $('vfo-month'); if (monthEl2) { monthEl2.value = '0'; }
            resetS2Dropdown('vfo-issuer', 'All staff');
            resetS2Dropdown('vfo-outlet', 'All outlets');
            resetStatusDropdown('vfo-status');
            resetRoleDropdown('vfo-role');
            presetClosed = false; overdueFilter = false; mineFilter = false;
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
                fetch((window.ATEM_MODULE_BASE || 'atem/') + 'api.php', {
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
            hqTabBtn.addEventListener('shown.bs.tab', function () { activeTab = 'hq'; rememberActiveTabForCreate(); renderActiveTab(); });
        }
        if (outletTabBtn) {
            outletTabBtn.addEventListener('shown.bs.tab', function () {
                activeTab = 'outlet';
                rememberActiveTabForCreate();
                // These were built while the Outlet tab-pane was hidden
                // (display:none), so their height/border sync read 0 - redo
                // it now that the pane is actually visible.
                syncS2ButtonSize('vfo-issuer', 'vfo-year');
                syncS2ButtonSize('vfo-outlet', 'vfo-year');
                syncS2ButtonSize('vfo-status', 'vfo-year');
                syncS2ButtonSize('vfo-role', 'vfo-year');
                renderActiveTab();
            });
        }
    }

    document.addEventListener('DOMContentLoaded', function () {
        if ($('atem-tab-hq-count')) { $('atem-tab-hq-count').textContent = hqRows.length; }
        if ($('atem-tab-outlet-count')) { $('atem-tab-outlet-count').textContent = outletRows.length; }
        rememberActiveTabForCreate();
        buildFilters();
        var params = new URLSearchParams(window.location.search);
        if (params.get('statuses')) {
            var wantedList = params.get('statuses').split(',').map(function (s) { return s.trim(); }).filter(Boolean);
            ['vf-status', 'vfo-status'].forEach(function (baseId) {
                var boxes = allStatusCheckboxes(baseId);
                for (var i = 0; i < boxes.length; i++) { boxes[i].checked = (wantedList.indexOf(boxes[i].value) !== -1); }
                updateStatusButtonLabel(baseId);
            });
        } else if (params.get('status')) {
            var wantedStatus = params.get('status');
            ['vf-status', 'vfo-status'].forEach(function (baseId) {
                var boxes = allStatusCheckboxes(baseId);
                for (var i = 0; i < boxes.length; i++) { boxes[i].checked = (boxes[i].value === wantedStatus); }
                updateStatusButtonLabel(baseId);
            });
        }
        // Deep links from the dashboard carry ?tab=outlet to land directly on the
        // Outlet tab; year/month/role/from/to/issuer=me apply to whichever tab's
        // controls (vf-* or vfo-*) the link is targeting. dept/level/level_id have
        // no Outlet equivalent, so those stay HQ-only; region/outlet_id have no HQ
        // equivalent, so they always target vfo-region/vfo-outlet regardless of tab.
        var isOutletDeepLink = params.get('tab') === 'outlet';
        var _tabPrefix = isOutletDeepLink ? 'vfo' : 'vf';
        if (params.get('year'))  { var ye = $(_tabPrefix + '-year');  if (ye) { ye.value = params.get('year'); } }
        if (params.get('month')) { var mo = $(_tabPrefix + '-month'); if (mo) { mo.value = params.get('month'); } }
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
        if (params.get('dept'))   { var de = $('vf-dept');    if (de) { de.value = params.get('dept'); } }
        if (params.get('region')) { var rg = $('vfo-region'); if (rg) { rg.value = params.get('region'); } }
        if (params.get('outlet_id')) {
            // Deep link from the dashboard's Outlet Breakdown table.
            var outletIdNum = parseInt(params.get('outlet_id'), 10);
            var ovEl = $('vfo-outlet-value');
            var obEl = $('vfo-outlet-btn');
            if (ovEl && outletIdNum > 0) { ovEl.value = outletIdNum; }
            if (obEl && outletIdNum > 0) {
                var outletCode = 'Outlet #' + outletIdNum;
                var outletsList = CFG.outlets || [];
                for (var oi = 0; oi < outletsList.length; oi++) {
                    if (outletsList[oi].id == outletIdNum) { outletCode = outletsList[oi].code; break; }
                }
                obEl.textContent = outletCode;
            }
        }
        if (params.get('role')) {
            var wantedRole = params.get('role');
            var boxesRole = allRoleCheckboxes(_tabPrefix + '-role');
            for (var rbi = 0; rbi < boxesRole.length; rbi++) { boxesRole[rbi].checked = (boxesRole[rbi].value === wantedRole); }
            updateRoleButtonLabel(_tabPrefix + '-role');
        }
        if (params.get('from'))  { var fr = $(_tabPrefix + '-from'); if (fr) { fr.value = params.get('from'); } }
        if (params.get('to'))    { var to = $(_tabPrefix + '-to');   if (to) { to.value = params.get('to'); } }
        if (params.get('preset')        === 'closed') { presetClosed  = true; }
        if (params.get('overdue')       === '1')      { overdueFilter = true; }
        if (params.get('min_level_id'))               { minLevelId = parseInt(params.get('min_level_id'), 10) || 0; }
        if (params.get('mine')          === '1')      { mineFilter = true; }
        if (params.get('issuer') === 'me' && CFG.staffId) {
            var ivEl = $(_tabPrefix + '-issuer-value');
            var ibEl = $(_tabPrefix + '-issuer-btn');
            if (ivEl) { ivEl.value = CFG.staffId; }
            if (ibEl) {
                var meName = 'Me';
                var issuersList = CFG.issuers || [];
                for (var mi = 0; mi < issuersList.length; mi++) {
                    if (issuersList[mi].id == CFG.staffId) { meName = issuersList[mi].name; break; }
                }
                ibEl.textContent = meName;
            }
        } else if (params.get('issuer_id')) {
            // Deep link from the dashboard's Suspended/Force Terminated breakdown -
            // like issuer=me but for an arbitrary staff id, not just the viewer.
            var issuerIdNum = parseInt(params.get('issuer_id'), 10);
            var ivEl2 = $(_tabPrefix + '-issuer-value');
            var ibEl2 = $(_tabPrefix + '-issuer-btn');
            if (ivEl2 && issuerIdNum > 0) { ivEl2.value = issuerIdNum; }
            if (ibEl2 && issuerIdNum > 0) {
                var targetName = 'Staff #' + issuerIdNum;
                var issuersList2 = CFG.issuers || [];
                for (var mi2 = 0; mi2 < issuersList2.length; mi2++) {
                    if (issuersList2[mi2].id == issuerIdNum) { targetName = issuersList2[mi2].name; break; }
                }
                ibEl2.textContent = targetName;
            }
        }
        bind();
        if (isOutletDeepLink) {
            var outletTabBtnEl = $('atem-tab-outlet-btn');
            if (outletTabBtnEl && typeof bootstrap !== 'undefined') {
                bootstrap.Tab.getOrCreateInstance(outletTabBtnEl).show();
            }
        }
        renderAll();
    });
})();
