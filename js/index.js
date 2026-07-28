(function () {
    'use strict';

    var CFG = window.ATEM_DASH || {};
    var currentInvolvementScope = 'me';

    function apiCall(action, payload) {
        var body = { action: action };
        if (payload) {
            for (var k in payload) {
                if (payload.hasOwnProperty(k)) { body[k] = payload[k]; }
            }
        }
        return fetch(CFG.apiUrl, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(body)
        }).then(function (r) { return r.json(); });
    }

    function formatNumber(n) {
        return (n || 0).toLocaleString('en-MY');
    }

    function formatRM(n) {
        var val = Math.round(n || 0);
        return 'RM' + val.toLocaleString('en-MY');
    }

    function setText(id, val) {
        var el = document.getElementById(id);
        if (el) { el.textContent = val; }
    }

    function setWidth(id, pct) {
        var el = document.getElementById(id);
        if (el) { el.style.width = pct + '%'; }
    }

    // Scoped to a container id so the HQ and Outlet tabs' independent loads
    // don't dim/un-dim each other's stat cards.
    function setLoading(on, containerId) {
        var scope = containerId ? document.getElementById(containerId) : document;
        if (!scope) { return; }
        var cards = scope.querySelectorAll('.atem-stat-value');
        for (var i = 0; i < cards.length; i++) {
            cards[i].style.opacity = on ? '0.4' : '1';
        }
    }

    function escapeHtml(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }

    // ------------------------------------------------- staff searchable dropdown
    // Mirrors the Issuer dropdown pattern on view.php (js/view.js buildIssuerDropdown).
    // Options are narrowed to the selected department, if any.
    function buildStaffDropdown() {
        var listEl   = document.getElementById('dash-staff-list');
        var searchEl = document.getElementById('dash-staff-search');
        var btnEl    = document.getElementById('dash-staff-btn');
        var dropEl   = document.getElementById('dash-staff-dropdown');
        var valEl    = document.getElementById('dash-staff-value');
        if (!listEl || !btnEl || !dropEl) { return; }

        // Sync dimensions, border and font-size to a form-select-sm exactly,
        // same as the Issuer dropdown on view.php — otherwise the custom div
        // falls back to its own CSS box model and looks mismatched. Year is
        // used as the reference (rather than Department) because the
        // Department field is hidden entirely for grade-1 users.
        var refEl = document.getElementById('dash-filter-year');
        if (refEl && btnEl) {
            var refStyle = window.getComputedStyle(refEl);
            btnEl.style.height       = refEl.offsetHeight + 'px';
            btnEl.style.border       = refStyle.border;
            btnEl.style.borderRadius = refStyle.borderRadius;
            btnEl.style.fontSize     = refStyle.fontSize;
            btnEl.style.color        = refStyle.color;
        }

        function currentDeptId() {
            var deptEl = document.getElementById('dash-filter-dept');
            return deptEl ? (parseInt(deptEl.value, 10) || 0) : 0;
        }

        function visibleStaff() {
            var all = CFG.staff || [];
            var deptId = currentDeptId();
            if (!deptId) { return all; }
            return all.filter(function (s) {
                return s.dept_ids && s.dept_ids.indexOf(deptId) !== -1;
            });
        }

        function renderList() {
            var staff = visibleStaff();
            var html = '<li class="vf-s2-list-item" data-id="0">All staff</li>';
            for (var i = 0; i < staff.length; i++) {
                html += '<li class="vf-s2-list-item" data-id="' + staff[i].id + '">' + escapeHtml(staff[i].name) + '</li>';
            }
            listEl.innerHTML = html;
        }

        function openDropdown() {
            renderList();
            dropEl.classList.add('open');
            if (searchEl) { searchEl.value = ''; filterList(''); searchEl.focus(); }
        }
        function closeDropdown() { dropEl.classList.remove('open'); }

        function filterList(term) {
            var items = listEl.querySelectorAll('li');
            var lower = term.toLowerCase();
            for (var j = 0; j < items.length; j++) {
                var text = items[j].textContent || '';
                items[j].classList.toggle('hidden', !(!lower || text.toLowerCase().indexOf(lower) >= 0));
            }
        }

        function selectStaff(id, name) {
            if (valEl) { valEl.value = id; }
            if (btnEl) { btnEl.textContent = name; }
            closeDropdown();
            loadDashboard(buildPayload());
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
            selectStaff(parseInt(li.getAttribute('data-id'), 10) || 0, li.textContent);
        });
        document.addEventListener('click', function (e) {
            var wrap = document.getElementById('dash-staff-wrap');
            if (wrap && !wrap.contains(e.target)) { closeDropdown(); }
        });

        // If the selected staff member falls outside the newly chosen department,
        // clear the selection rather than keep a mismatched filter applied.
        var deptEl = document.getElementById('dash-filter-dept');
        if (deptEl) {
            deptEl.addEventListener('change', function () {
                var deptId = currentDeptId();
                var selectedId = valEl ? (parseInt(valEl.value, 10) || 0) : 0;
                if (deptId && selectedId) {
                    var match = (CFG.staff || []).some(function (s) {
                        return s.id === selectedId && s.dept_ids && s.dept_ids.indexOf(deptId) !== -1;
                    });
                    if (!match) { resetStaffDropdown(); }
                }
            });
        }
    }

    function resetStaffDropdown() {
        var valEl = document.getElementById('dash-staff-value');
        var btnEl = document.getElementById('dash-staff-btn');
        var dropEl = document.getElementById('dash-staff-dropdown');
        if (valEl)  { valEl.value = '0'; }
        if (btnEl)  { btnEl.textContent = 'All staff'; }
        if (dropEl) { dropEl.classList.remove('open'); }
    }

    // ------------------------------------------- generic searchable dropdown
    // Copies height/border/font-size from a plain form-select-sm in the same
    // filter bar so the custom dropdown button lines up with it exactly. Only
    // works while the reference element is actually visible (offsetHeight reads
    // 0 on a hidden Bootstrap tab-pane) - re-run this once the Outlet dashboard
    // tab is actually shown, see the 'shown.bs.tab' listener in DOMContentLoaded.
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

    function resetSearchDropdown(baseId, allLabel) {
        var valEl  = document.getElementById(baseId + '-value');
        var btnEl  = document.getElementById(baseId + '-btn');
        var dropEl = document.getElementById(baseId + '-dropdown');
        if (valEl)  { valEl.value = '0'; }
        if (btnEl)  { btnEl.textContent = allLabel; }
        if (dropEl) { dropEl.classList.remove('open'); }
    }

    // Chain: outlet_regional -> outlet.regional_id -> staff.status_rym = 134.
    // Region narrows Outlet (buildOutletDropdownOutlet), which in turn narrows
    // Staff (buildStaffDropdownOutlet) - each dropdown re-filters at open time
    // based on the upstream selection(s), same approach throughout.
    var OUTLET_STAFF_STATUS_RYM = 134;

    function outletStaffById(id) {
        return (CFG.staff || []).filter(function (s) { return s.id === id; })[0];
    }

    // ---------------------------------------- Outlet tab outlet searchable dropdown
    function buildOutletDropdownOutlet() {
        var baseId   = 'dasho-outlet';
        var listEl   = document.getElementById(baseId + '-list');
        var searchEl = document.getElementById(baseId + '-search');
        var btnEl    = document.getElementById(baseId + '-btn');
        var dropEl   = document.getElementById(baseId + '-dropdown');
        var valEl    = document.getElementById(baseId + '-value');
        var wrapEl   = document.getElementById(baseId + '-wrap');
        if (!listEl || !btnEl || !dropEl) { return; }

        syncSearchDropdownSize(baseId, 'dasho-filter-year');

        function currentRegionId() {
            var regionEl = document.getElementById('dasho-filter-region');
            return regionEl ? (parseInt(regionEl.value, 10) || 0) : 0;
        }

        function visibleOutlets() {
            var all = CFG.outlets || [];
            var regionId = currentRegionId();
            if (!regionId) { return all; }
            return all.filter(function (o) { return o.region_id === regionId; });
        }

        function renderList() {
            var outlets = visibleOutlets();
            var html = '<li class="vf-s2-list-item" data-id="0">All outlets</li>';
            for (var i = 0; i < outlets.length; i++) {
                html += '<li class="vf-s2-list-item" data-id="' + outlets[i].id + '">' + escapeHtml(outlets[i].code) + '</li>';
            }
            listEl.innerHTML = html;
        }
        renderList();

        function openDropdown() {
            renderList();
            dropEl.classList.add('open');
            if (searchEl) { searchEl.value = ''; filterList(''); searchEl.focus(); }
        }
        function closeDropdown() { dropEl.classList.remove('open'); }

        function filterList(term) {
            var items = listEl.querySelectorAll('li');
            var lower = term.toLowerCase();
            for (var j = 0; j < items.length; j++) {
                var text = items[j].textContent || '';
                items[j].classList.toggle('hidden', !(!lower || text.toLowerCase().indexOf(lower) >= 0));
            }
        }

        function selectOutlet(id, name) {
            if (valEl) { valEl.value = id; }
            if (btnEl) { btnEl.textContent = name; }
            closeDropdown();

            // Staff dropdown is narrowed by outlet - clear a mismatched
            // selection, same as the Department -> Staff narrowing on the HQ tab.
            var staffValEl = document.getElementById('dasho-staff-value');
            var selectedStaffId = staffValEl ? (parseInt(staffValEl.value, 10) || 0) : 0;
            if (id && selectedStaffId) {
                var staff = outletStaffById(selectedStaffId);
                var match = staff && staff.outlet_ids && staff.outlet_ids.indexOf(id) !== -1;
                if (!match) { resetSearchDropdown('dasho-staff', 'All Area Managers'); }
            }

            loadDashboardOutlet(buildPayloadOutlet());
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
            selectOutlet(parseInt(li.getAttribute('data-id'), 10) || 0, li.textContent);
        });
        document.addEventListener('click', function (e) {
            if (wrapEl && !wrapEl.contains(e.target)) { closeDropdown(); }
        });

        // If the selected outlet falls outside the newly chosen region, clear
        // it (and any staff member who no longer serves an outlet in region).
        var regionEl = document.getElementById('dasho-filter-region');
        if (regionEl) {
            regionEl.addEventListener('change', function () {
                var regionId = currentRegionId();
                var selectedOutletId = valEl ? (parseInt(valEl.value, 10) || 0) : 0;
                var regionOutletIds = visibleOutlets().map(function (o) { return o.id; });

                if (regionId && selectedOutletId && regionOutletIds.indexOf(selectedOutletId) === -1) {
                    resetSearchDropdown('dasho-outlet', 'All outlets');
                }

                var staffValEl = document.getElementById('dasho-staff-value');
                var selectedStaffId = staffValEl ? (parseInt(staffValEl.value, 10) || 0) : 0;
                if (regionId && selectedStaffId) {
                    var staff = outletStaffById(selectedStaffId);
                    var staffMatch = staff && staff.outlet_ids && staff.outlet_ids.some(function (oid) {
                        return regionOutletIds.indexOf(oid) !== -1;
                    });
                    if (!staffMatch) { resetSearchDropdown('dasho-staff', 'All Area Managers'); }
                }

                loadDashboardOutlet(buildPayloadOutlet());
            });
        }
    }

    // ---------------------------------------- Outlet tab staff searchable dropdown
    // Scoped to Area Managers (staff.status_rym = 134), narrowed further to the
    // selected dasho-outlet, if any.
    function buildStaffDropdownOutlet() {
        var baseId   = 'dasho-staff';
        var listEl   = document.getElementById(baseId + '-list');
        var searchEl = document.getElementById(baseId + '-search');
        var btnEl    = document.getElementById(baseId + '-btn');
        var dropEl   = document.getElementById(baseId + '-dropdown');
        var valEl    = document.getElementById(baseId + '-value');
        var wrapEl   = document.getElementById(baseId + '-wrap');
        if (!listEl || !btnEl || !dropEl) { return; }

        syncSearchDropdownSize(baseId, 'dasho-filter-year');

        function currentOutletId() {
            var outletEl = document.getElementById('dasho-outlet-value');
            return outletEl ? (parseInt(outletEl.value, 10) || 0) : 0;
        }

        function visibleStaff() {
            var all = CFG.staff || [];
            var outletId = currentOutletId();
            return all.filter(function (s) {
                if (s.status_rym !== OUTLET_STAFF_STATUS_RYM) { return false; }
                if (outletId && (!s.outlet_ids || s.outlet_ids.indexOf(outletId) === -1)) { return false; }
                return true;
            });
        }

        function renderList() {
            var staff = visibleStaff();
            var html = '<li class="vf-s2-list-item" data-id="0">All Area Managers</li>';
            for (var i = 0; i < staff.length; i++) {
                html += '<li class="vf-s2-list-item" data-id="' + staff[i].id + '">' + escapeHtml(staff[i].name) + '</li>';
            }
            listEl.innerHTML = html;
        }
        renderList();

        function openDropdown() {
            renderList();
            dropEl.classList.add('open');
            if (searchEl) { searchEl.value = ''; filterList(''); searchEl.focus(); }
        }
        function closeDropdown() { dropEl.classList.remove('open'); }

        function filterList(term) {
            var items = listEl.querySelectorAll('li');
            var lower = term.toLowerCase();
            for (var j = 0; j < items.length; j++) {
                var text = items[j].textContent || '';
                items[j].classList.toggle('hidden', !(!lower || text.toLowerCase().indexOf(lower) >= 0));
            }
        }

        function selectStaff(id, name) {
            if (valEl) { valEl.value = id; }
            if (btnEl) { btnEl.textContent = name; }
            closeDropdown();
            loadDashboardOutlet(buildPayloadOutlet());
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
            selectStaff(parseInt(li.getAttribute('data-id'), 10) || 0, li.textContent);
        });
        document.addEventListener('click', function (e) {
            if (wrapEl && !wrapEl.contains(e.target)) { closeDropdown(); }
        });
    }

    var QUARTER_RANGES = {
        '1': ['01-01', '03-31'],
        '2': ['04-01', '06-30'],
        '3': ['07-01', '09-30'],
        '4': ['10-01', '12-31']
    };

    // ------------------------------------------------- quarter checkbox dropdown
    // Mirrors the Status checkbox-dropdown pattern (view.js/staff_performance) -
    // baseId is 'dash-quarter' (HQ tab) or 'dasho-quarter' (Outlet tab), each
    // with its own static 4-checkbox list (class baseId + '-cb') already in the
    // markup - no dynamic option-building needed, unlike the Status pattern.
    function allQuarterCheckboxes(baseId) {
        return document.querySelectorAll('.' + baseId + '-cb');
    }

    function getSelectedQuarters(baseId) {
        var boxes = document.querySelectorAll('.' + baseId + '-cb:checked');
        var out = [];
        for (var i = 0; i < boxes.length; i++) { out.push(parseInt(boxes[i].value, 10)); }
        return out;
    }

    function updateQuarterButtonLabel(baseId) {
        var btn = document.getElementById(baseId + '-btn');
        if (!btn) { return; }
        var selected = getSelectedQuarters(baseId);
        var all = allQuarterCheckboxes(baseId);
        if (selected.length === 0 || selected.length === all.length) {
            btn.textContent = 'All Quarters';
        } else {
            var labels = [];
            for (var i = 0; i < selected.length; i++) { labels.push('Q' + selected[i]); }
            btn.textContent = labels.join(', ');
        }
    }

    function resetQuarterDropdown(baseId) {
        var boxes = allQuarterCheckboxes(baseId);
        for (var i = 0; i < boxes.length; i++) { boxes[i].checked = false; }
        updateQuarterButtonLabel(baseId);
        var dropEl = document.getElementById(baseId + '-dropdown');
        if (dropEl) { dropEl.classList.remove('open'); }
    }

    function buildQuarterDropdown(baseId, sizeRefId, onChange) {
        var btnEl  = document.getElementById(baseId + '-btn');
        var dropEl = document.getElementById(baseId + '-dropdown');
        var wrapEl = document.getElementById(baseId + '-wrap');
        if (!btnEl || !dropEl) { return; }

        syncSearchDropdownSize(baseId, sizeRefId);
        updateQuarterButtonLabel(baseId);

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
                updateQuarterButtonLabel(baseId);
                if (onChange) { onChange(); }
            }
        });
    }

    // Envelope date range (earliest start, latest end) spanning every selected
    // quarter - view.php has no quarter concept of its own, only a single
    // contiguous from/to range, so a multi-quarter deep link widens to the
    // smallest range that covers all of them (exact when only one is selected,
    // same as before).
    function quarterEnvelope(quarters) {
        if (!quarters || !quarters.length) { return null; }
        var from = null, to = null;
        for (var i = 0; i < quarters.length; i++) {
            var range = QUARTER_RANGES[quarters[i]];
            if (!range) { continue; }
            if (from === null || range[0] < from) { from = range[0]; }
            if (to === null || range[1] > to) { to = range[1]; }
        }
        return (from !== null) ? [from, to] : null;
    }

    function buildViewUrl(statusOverride, levelIdOverride, deptOverride, statusesOverride, overdueOnly, incentiveOnly, roleOverride, issuerOverride, mineOverride, issuerIdOverride) {
        var yearEl    = document.getElementById('dash-filter-year');
        var monthEl   = document.getElementById('dash-filter-month');
        var deptEl    = document.getElementById('dash-filter-dept');

        var year    = yearEl    ? yearEl.value    : '';
        var month   = monthEl   ? monthEl.value   : '';
        var quarters = getSelectedQuarters('dash-quarter');
        var deptName = deptOverride || ((deptEl && deptEl.selectedIndex > 0)
                       ? deptEl.options[deptEl.selectedIndex].text : '');

        var params = [];
        if (statusOverride)  { params.push('status='      + encodeURIComponent(statusOverride)); }
        if (levelIdOverride) { params.push('level_id='    + encodeURIComponent(levelIdOverride)); }
        if (statusesOverride && statusesOverride.length) { params.push('statuses=' + encodeURIComponent(statusesOverride.join(','))); }
        if (overdueOnly)     { params.push('overdue=1'); }
        if (incentiveOnly)   { params.push('min_level_id=2'); }
        if (roleOverride)    { params.push('role='       + encodeURIComponent(roleOverride)); }
        if (issuerOverride)  { params.push('issuer='     + encodeURIComponent(issuerOverride)); }
        if (issuerIdOverride) { params.push('issuer_id=' + encodeURIComponent(issuerIdOverride)); }
        if (mineOverride)    { params.push('mine='       + encodeURIComponent(mineOverride)); }
        if (year)   { params.push('year='  + encodeURIComponent(year)); }
        if (month)  { params.push('month=' + encodeURIComponent(month)); }
        if (!month && year) {
            var qRange = quarterEnvelope(quarters);
            if (qRange) {
                params.push('from=' + encodeURIComponent(year + '-' + qRange[0]));
                params.push('to='   + encodeURIComponent(year + '-' + qRange[1]));
            }
        }
        if (deptName) { params.push('dept=' + encodeURIComponent(deptName)); }
        // Suspended/Force Terminated cards are soft-deleted like genuinely-Deleted
        // ones - no_deleted=1 would hide the very rows this link is pointing at, so
        // only suppress soft-deleted rows when navigating to some other status(es).
        var targetsSoftDeleted = statusOverride === 'Suspended' || statusOverride === 'Force Terminated'
            || (statusesOverride && (statusesOverride.indexOf('Suspended') !== -1 || statusesOverride.indexOf('Force Terminated') !== -1));
        if (!targetsSoftDeleted) {
            params.push('no_deleted=1');
        }

        return (window.ATEM_MODULE_BASE || 'atem/') + 'view.php' + (params.length ? '?' + params.join('&') : '');
    }

    // Outlet dashboard's equivalent of buildViewUrl - always lands on view.php's
    // Outlet tab (tab=outlet), has no level/dept concept, and adds region (matched
    // by name, same convention as vfo-region) and outlet_id params for the
    // Region/Outlet Breakdown tables' row clicks.
    function buildViewUrlOutlet(statusOverride, regionOverride, outletIdOverride, statusesOverride, overdueOnly, roleOverride, issuerOverride, mineOverride, issuerIdOverride) {
        var yearEl    = document.getElementById('dasho-filter-year');
        var monthEl   = document.getElementById('dasho-filter-month');

        var year    = yearEl    ? yearEl.value    : '';
        var month   = monthEl   ? monthEl.value   : '';
        var quarters = getSelectedQuarters('dasho-quarter');

        var params = ['tab=outlet'];
        if (statusOverride)   { params.push('status=' + encodeURIComponent(statusOverride)); }
        if (regionOverride)   { params.push('region=' + encodeURIComponent(regionOverride)); }
        if (outletIdOverride) { params.push('outlet_id=' + encodeURIComponent(outletIdOverride)); }
        if (statusesOverride && statusesOverride.length) { params.push('statuses=' + encodeURIComponent(statusesOverride.join(','))); }
        if (overdueOnly)     { params.push('overdue=1'); }
        if (roleOverride)    { params.push('role='   + encodeURIComponent(roleOverride)); }
        if (issuerOverride)  { params.push('issuer=' + encodeURIComponent(issuerOverride)); }
        if (issuerIdOverride) { params.push('issuer_id=' + encodeURIComponent(issuerIdOverride)); }
        if (mineOverride)    { params.push('mine='   + encodeURIComponent(mineOverride)); }
        if (year)   { params.push('year='  + encodeURIComponent(year)); }
        if (month)  { params.push('month=' + encodeURIComponent(month)); }
        if (!month && year) {
            var qRangeO = quarterEnvelope(quarters);
            if (qRangeO) {
                params.push('from=' + encodeURIComponent(year + '-' + qRangeO[0]));
                params.push('to='   + encodeURIComponent(year + '-' + qRangeO[1]));
            }
        }
        var targetsSoftDeletedOutlet = statusOverride === 'Suspended' || statusOverride === 'Force Terminated'
            || (statusesOverride && (statusesOverride.indexOf('Suspended') !== -1 || statusesOverride.indexOf('Force Terminated') !== -1));
        if (!targetsSoftDeletedOutlet) {
            params.push('no_deleted=1');
        }

        return (window.ATEM_MODULE_BASE || 'atem/') + 'view.php?' + params.join('&');
    }

    // Scope-aware heading + click-through for the My Involvement card. Deep
    // links (role=, issuer=me, mine=1) on view.php only know how to filter by
    // the *currently logged-in viewer's* roles, so click-through only stays
    // accurate when the breakdown is showing "me". For a Staff or Department
    // scope, the numbers are still correct but the tiles are shown inert.
    function applyInvolvementScopeUI(scope) {
        currentInvolvementScope = scope;
        var titleEl = document.getElementById('dash-myroles-title');
        var subEl   = document.getElementById('dash-myroles-subtitle');
        var label   = 'you are';
        var heading = 'My Involvement';

        if (scope === 'staff') {
            var staffBtn = document.getElementById('dash-staff-btn');
            var name = staffBtn ? staffBtn.textContent : 'Selected staff';
            heading = name + ' Involvement';
            label = name + ' is';
        } else if (scope === 'dept') {
            var deptEl = document.getElementById('dash-filter-dept');
            var deptName = (deptEl && deptEl.selectedIndex > 0) ? deptEl.options[deptEl.selectedIndex].text : 'Department';
            heading = deptName + ' Involvement';
            label = deptName + ' staff are';
        }

        if (titleEl) { titleEl.textContent = heading; }
        if (subEl)   { subEl.textContent = 'Cards where ' + label + ' the Issuer or an ARCI member, by role'; }

        var row = document.getElementById('dash-myroles-row');
        if (row) {
            var tiles = row.querySelectorAll('[data-myrole], [data-issuer], [data-mine]');
            for (var i = 0; i < tiles.length; i++) {
                tiles[i].style.cursor = (scope === 'me') ? 'pointer' : 'default';
            }
        }
    }

    var currentInvolvementScopeOutlet = 'me';

    function applyInvolvementScopeUIOutlet(scope) {
        currentInvolvementScopeOutlet = scope;
        var titleEl = document.getElementById('dasho-myroles-title');
        var subEl   = document.getElementById('dasho-myroles-subtitle');
        var label   = 'you are';
        var heading = 'My Involvement';

        if (scope === 'staff') {
            var staffBtn = document.getElementById('dasho-staff-btn');
            var name = staffBtn ? staffBtn.textContent : 'Selected staff';
            heading = name + ' Involvement';
            label = name + ' is';
        }
        // No 'dept' scope for Outlet - the Department filter is replaced by
        // Outlet/Pillar, neither of which maps to the involvement breakdown.

        if (titleEl) { titleEl.textContent = heading; }
        if (subEl)   { subEl.textContent = 'Cards where ' + label + ' the Issuer or an ARCI member, by role'; }

        var row = document.getElementById('dasho-myroles-row');
        if (row) {
            var tiles = row.querySelectorAll('[data-myrole], [data-issuer], [data-mine]');
            for (var i = 0; i < tiles.length; i++) {
                tiles[i].style.cursor = (scope === 'me') ? 'pointer' : 'default';
            }
        }
    }

    function renderDashboard(data) {
        var s = data.by_status;
        var total  = data.total || 0;
        var active = (s.active || 0) + (s.draft || 0);
        var closed = (s.complete || 0) + (s.excellence || 0);
        var failed = s.failed || 0;
        var failRate = total > 0 ? (failed / total * 100).toFixed(1) + '% failure rate' : '0.0% failure rate';

        var extended = s.extended || 0;
        var extLabelEl = document.getElementById('dash-extended-label');
        if (extLabelEl) {
            var extStatusCount = s.extended_status || 0;
            if (extStatusCount > 0) {
                extLabelEl.textContent = 'with ' + formatNumber(extStatusCount) + ' extended';
                extLabelEl.style.display = '';
            } else {
                extLabelEl.style.display = 'none';
            }
        }

        setText('dash-total',    formatNumber(total));
        setText('dash-active',   formatNumber(active));
        setText('dash-closed',   formatNumber(closed));
        setText('dash-failed',   formatNumber(failed));
        setText('dash-fail-rate',  failRate);
        setText('dash-incentive',  formatRM(data.incentive_total));
        setText('dash-overdue',    formatNumber(data.overdue_count || 0));
        setText('dash-suspended',  formatNumber(data.suspended_count || 0));

        var myRoles = data.my_roles || {};
        setText('dash-myrole-issuer',   formatNumber(myRoles.issuer));
        setText('dash-myrole-a',        formatNumber(myRoles.A));
        setText('dash-myrole-r',        formatNumber(myRoles.R));
        setText('dash-myrole-c',        formatNumber(myRoles.C));
        setText('dash-myrole-i',        formatNumber(myRoles.I));
        setText('dash-myrole-involved', formatNumber(myRoles.involved));
        applyInvolvementScopeUI(myRoles.scope || 'me');

        var tbody = document.getElementById('dash-level-body');
        if (tbody && data.by_level) {
            var html = '';
            for (var i = 0; i < data.by_level.length; i++) {
                var l = data.by_level[i];
                var forecast = l.level_id === 1 ? 'RM0' : formatRM(l.forecast);
                html += '<tr>' +
                    '<td style="font-size:12px;font-weight:600;">' + l.label + '</td>' +
                    '<td style="font-size:12px;cursor:pointer;text-decoration:underline;" data-nav-level-id="' + l.level_id + '" data-nav-status="">' + l.cards + '</td>' +
                    '<td style="font-size:12px;color:#0d6efd;cursor:pointer;text-decoration:underline;" data-nav-level-id="' + l.level_id + '" data-nav-status="Completed">' + l.complete + '</td>' +
                    '<td style="font-size:12px;color:#198754;cursor:pointer;text-decoration:underline;" data-nav-level-id="' + l.level_id + '" data-nav-status="Completed with Excellence">' + l.excellence + '</td>' +
                    '<td style="font-size:12px;color:#dc3545;cursor:pointer;text-decoration:underline;" data-nav-level-id="' + l.level_id + '" data-nav-status="Failed">' + l.fail + '</td>' +
                    '<td style="font-size:12px;">' + forecast + '</td>' +
                    '</tr>';
            }
            tbody.innerHTML = html;
            tbody.onclick = function (e) {
                var td = e.target;
                while (td && td !== tbody) {
                    if (td.tagName === 'TD' && td.hasAttribute('data-nav-level-id')) {
                        window.location.href = buildViewUrl(td.getAttribute('data-nav-status'), td.getAttribute('data-nav-level-id'), '');
                        return;
                    }
                    td = td.parentNode;
                }
            };
        }

        var suspendedN = data.suspended_count || 0;
        var forceTerminatedN = data.force_terminated_count || 0;
        var barScale = total + suspendedN + forceTerminatedN;
        setWidth('bar-complete',   barScale > 0 ? Math.round((s.complete   || 0) / barScale * 100) : 0);
        setWidth('bar-excellence', barScale > 0 ? Math.round((s.excellence || 0) / barScale * 100) : 0);
        setWidth('bar-extended',   barScale > 0 ? Math.round((s.extended   || 0) / barScale * 100) : 0);
        setWidth('bar-failed',     barScale > 0 ? Math.round(failed              / barScale * 100) : 0);
        setWidth('bar-suspended',        barScale > 0 ? Math.round(suspendedN       / barScale * 100) : 0);
        setWidth('bar-force-terminated', barScale > 0 ? Math.round(forceTerminatedN / barScale * 100) : 0);
        setText('bar-complete-n',   s.complete   || 0);
        setText('bar-excellence-n', s.excellence || 0);
        setText('bar-extended-n',   s.extended   || 0);
        setText('bar-failed-n',     failed);
        setText('bar-suspended-n',        suspendedN);
        setText('bar-force-terminated-n', forceTerminatedN);

        var sftTbody = document.getElementById('dash-sft-body');
        if (sftTbody) {
            var sftRows = data.by_suspend_force_terminate || [];
            if (sftRows.length > 0) {
                var sftHtml = '';
                for (var si = 0; si < sftRows.length; si++) {
                    var sftRow = sftRows[si];
                    sftHtml += '<tr style="cursor:pointer;" data-nav-issuer-id="' + sftRow.issuer_staff_id + '">' +
                        '<td style="font-size:12px;font-weight:600;">' + escapeHtml(sftRow.issuer_name) + '</td>' +
                        '<td style="font-size:12px;">' + escapeHtml(sftRow.dept_name) + '</td>' +
                        '<td style="font-size:12px;color:#e11d48;">' + (sftRow.suspended || 0) + '</td>' +
                        '<td style="font-size:12px;color:#7c3aed;">' + (sftRow.force_terminated || 0) + '</td>' +
                        '</tr>';
                }
                sftTbody.innerHTML = sftHtml;
                sftTbody.onclick = function (e) {
                    var tr = e.target;
                    while (tr && tr !== sftTbody) {
                        if (tr.tagName === 'TR' && tr.hasAttribute('data-nav-issuer-id')) {
                            window.location.href = buildViewUrl('', '', '', ['Suspended', 'Force Terminated'], false, false, '', '', '', tr.getAttribute('data-nav-issuer-id'));
                            return;
                        }
                        tr = tr.parentNode;
                    }
                };
            } else {
                sftTbody.innerHTML = '<tr><td colspan="4" class="text-muted" style="font-size:12px;">No suspended or force terminated cards for the selected period.</td></tr>';
            }
        }

        var deptTbody = document.getElementById('dash-dept-body');
        if (deptTbody) {
            if (data.by_department && data.by_department.length > 0) {
                var dHtml = '';
                for (var d = 0; d < data.by_department.length; d++) {
                    var dept      = data.by_department[d];
                    var dFail     = dept.fail || 0;
                    var dCards    = dept.cards || 0;
                    var dFailRate = dCards > 0 ? (dFail / dCards * 100).toFixed(1) + '%' : '0%';
                    var dForecast = dept.forecast > 0 ? formatRM(dept.forecast) : 'RM0';
                    dHtml += '<tr>' +
                        '<td style="font-size:12px;font-weight:600;">' + dept.dept_name + '</td>' +
                        '<td style="font-size:12px;cursor:pointer;text-decoration:underline;" data-nav-dept="' + dept.dept_name + '" data-nav-status="">' + dCards + '</td>' +
                        '<td style="font-size:12px;color:#0d6efd;cursor:pointer;text-decoration:underline;" data-nav-dept="' + dept.dept_name + '" data-nav-status="Completed">' + (dept.complete || 0) + '</td>' +
                        '<td style="font-size:12px;color:#198754;cursor:pointer;text-decoration:underline;" data-nav-dept="' + dept.dept_name + '" data-nav-status="Completed with Excellence">' + (dept.excellence || 0) + '</td>' +
                        '<td style="font-size:12px;color:#dc3545;cursor:pointer;text-decoration:underline;" data-nav-dept="' + dept.dept_name + '" data-nav-status="Failed">' + dFail + '</td>' +
                        '<td style="font-size:12px;">' + dFailRate + '</td>' +
                        '<td style="font-size:12px;">' + dForecast + '</td>' +
                        '</tr>';
                }
                deptTbody.innerHTML = dHtml;
                deptTbody.onclick = function (e) {
                    var td = e.target;
                    while (td && td !== deptTbody) {
                        if (td.tagName === 'TD' && td.hasAttribute('data-nav-dept')) {
                            window.location.href = buildViewUrl(td.getAttribute('data-nav-status'), '', td.getAttribute('data-nav-dept'));
                            return;
                        }
                        td = td.parentNode;
                    }
                };
            } else {
                deptTbody.innerHTML = '<tr><td colspan="7" class="text-muted" style="font-size:12px;">No data for the selected period.</td></tr>';
            }
        }

        setLoading(false, 'dash-tab-hq');
    }

    function renderDashboardOutlet(data) {
        var s = data.by_status;
        var total  = data.total || 0;
        var active = (s.active || 0) + (s.draft || 0);
        var closed = (s.complete || 0) + (s.excellence || 0);
        var failed = s.failed || 0;
        var failRate = total > 0 ? (failed / total * 100).toFixed(1) + '% failure rate' : '0.0% failure rate';

        var extLabelEl = document.getElementById('dasho-extended-label');
        if (extLabelEl) {
            var extStatusCount = s.extended_status || 0;
            if (extStatusCount > 0) {
                extLabelEl.textContent = 'with ' + formatNumber(extStatusCount) + ' extended';
                extLabelEl.style.display = '';
            } else {
                extLabelEl.style.display = 'none';
            }
        }

        setText('dasho-total',    formatNumber(total));
        setText('dasho-active',   formatNumber(active));
        setText('dasho-closed',   formatNumber(closed));
        setText('dasho-failed',   formatNumber(failed));
        setText('dasho-fail-rate',  failRate);
        setText('dasho-incentive',  formatRM(data.incentive_total));
        setText('dasho-overdue',    formatNumber(data.overdue_count || 0));
        setText('dasho-suspended',  formatNumber(data.suspended_count || 0));

        var myRoles = data.my_roles || {};
        setText('dasho-myrole-issuer',   formatNumber(myRoles.issuer));
        setText('dasho-myrole-a',        formatNumber(myRoles.A));
        setText('dasho-myrole-r',        formatNumber(myRoles.R));
        setText('dasho-myrole-c',        formatNumber(myRoles.C));
        setText('dasho-myrole-i',        formatNumber(myRoles.I));
        setText('dasho-myrole-involved', formatNumber(myRoles.involved));
        applyInvolvementScopeUIOutlet(myRoles.scope || 'me');

        var tbody = document.getElementById('dasho-pillar-body');
        if (tbody) {
            if (data.by_pillar && data.by_pillar.length > 0) {
                var html = '';
                for (var i = 0; i < data.by_pillar.length; i++) {
                    var p = data.by_pillar[i];
                    html += '<tr>' +
                        '<td style="font-size:12px;font-weight:600;">' + escapeHtml(p.label) + '</td>' +
                        '<td style="font-size:12px;cursor:pointer;text-decoration:underline;" data-nav-status="">' + p.cards + '</td>' +
                        '<td style="font-size:12px;color:#0d6efd;cursor:pointer;text-decoration:underline;" data-nav-status="Completed">' + p.complete + '</td>' +
                        '<td style="font-size:12px;color:#198754;cursor:pointer;text-decoration:underline;" data-nav-status="Completed with Excellence">' + p.excellence + '</td>' +
                        '<td style="font-size:12px;color:#dc3545;cursor:pointer;text-decoration:underline;" data-nav-status="Failed">' + p.fail + '</td>' +
                        '<td style="font-size:12px;">' + formatRM(p.forecast) + '</td>' +
                        '</tr>';
                }
                tbody.innerHTML = html;
                // view.php no longer has a Pillar filter (replaced by Region), so
                // this can only navigate by status now, not scoped to the pillar.
                tbody.onclick = function (e) {
                    var td = e.target;
                    while (td && td !== tbody) {
                        if (td.tagName === 'TD' && td.hasAttribute('data-nav-status')) {
                            window.location.href = buildViewUrlOutlet(td.getAttribute('data-nav-status'));
                            return;
                        }
                        td = td.parentNode;
                    }
                };
            } else {
                tbody.innerHTML = '<tr><td colspan="6" class="text-muted" style="font-size:12px;">No data for the selected period.</td></tr>';
            }
        }

        var regionTbody = document.getElementById('dasho-region-body');
        if (regionTbody) {
            if (data.by_region && data.by_region.length > 0) {
                var rHtml = '';
                for (var ri = 0; ri < data.by_region.length; ri++) {
                    var rg      = data.by_region[ri];
                    var rgFail  = rg.fail || 0;
                    var rgCards = rg.cards || 0;
                    var rgFailRate = rgCards > 0 ? (rgFail / rgCards * 100).toFixed(1) + '%' : '0%';
                    rHtml += '<tr>' +
                        '<td style="font-size:12px;font-weight:600;">' + escapeHtml(rg.label) + '</td>' +
                        '<td style="font-size:12px;cursor:pointer;text-decoration:underline;" data-nav-region="' + escapeHtml(rg.label) + '" data-nav-status="">' + rgCards + '</td>' +
                        '<td style="font-size:12px;color:#0d6efd;cursor:pointer;text-decoration:underline;" data-nav-region="' + escapeHtml(rg.label) + '" data-nav-status="Completed">' + (rg.complete || 0) + '</td>' +
                        '<td style="font-size:12px;color:#198754;cursor:pointer;text-decoration:underline;" data-nav-region="' + escapeHtml(rg.label) + '" data-nav-status="Completed with Excellence">' + (rg.excellence || 0) + '</td>' +
                        '<td style="font-size:12px;color:#dc3545;cursor:pointer;text-decoration:underline;" data-nav-region="' + escapeHtml(rg.label) + '" data-nav-status="Failed">' + rgFail + '</td>' +
                        '<td style="font-size:12px;">' + rgFailRate + '</td>' +
                        '<td style="font-size:12px;">' + formatRM(rg.forecast) + '</td>' +
                        '</tr>';
                }
                regionTbody.innerHTML = rHtml;
                regionTbody.onclick = function (e) {
                    var td = e.target;
                    while (td && td !== regionTbody) {
                        if (td.tagName === 'TD' && td.hasAttribute('data-nav-region')) {
                            window.location.href = buildViewUrlOutlet(td.getAttribute('data-nav-status'), td.getAttribute('data-nav-region'));
                            return;
                        }
                        td = td.parentNode;
                    }
                };
            } else {
                regionTbody.innerHTML = '<tr><td colspan="7" class="text-muted" style="font-size:12px;">No data for the selected period.</td></tr>';
            }
        }

        var outletBreakdownTbody = document.getElementById('dasho-outlet-breakdown-body');
        if (outletBreakdownTbody) {
            if (data.by_outlet && data.by_outlet.length > 0) {
                var oHtml = '';
                for (var oi = 0; oi < data.by_outlet.length; oi++) {
                    var ob      = data.by_outlet[oi];
                    var obFail  = ob.fail || 0;
                    var obCards = ob.cards || 0;
                    var obFailRate = obCards > 0 ? (obFail / obCards * 100).toFixed(1) + '%' : '0%';
                    oHtml += '<tr>' +
                        '<td style="font-size:12px;font-weight:600;">' + escapeHtml(ob.label) + '</td>' +
                        '<td style="font-size:12px;cursor:pointer;text-decoration:underline;" data-nav-outlet-id="' + ob.outlet_id + '" data-nav-status="">' + obCards + '</td>' +
                        '<td style="font-size:12px;color:#0d6efd;cursor:pointer;text-decoration:underline;" data-nav-outlet-id="' + ob.outlet_id + '" data-nav-status="Completed">' + (ob.complete || 0) + '</td>' +
                        '<td style="font-size:12px;color:#198754;cursor:pointer;text-decoration:underline;" data-nav-outlet-id="' + ob.outlet_id + '" data-nav-status="Completed with Excellence">' + (ob.excellence || 0) + '</td>' +
                        '<td style="font-size:12px;color:#dc3545;cursor:pointer;text-decoration:underline;" data-nav-outlet-id="' + ob.outlet_id + '" data-nav-status="Failed">' + obFail + '</td>' +
                        '<td style="font-size:12px;">' + obFailRate + '</td>' +
                        '<td style="font-size:12px;">' + formatRM(ob.forecast) + '</td>' +
                        '</tr>';
                }
                outletBreakdownTbody.innerHTML = oHtml;
                outletBreakdownTbody.onclick = function (e) {
                    var td = e.target;
                    while (td && td !== outletBreakdownTbody) {
                        if (td.tagName === 'TD' && td.hasAttribute('data-nav-outlet-id')) {
                            window.location.href = buildViewUrlOutlet(td.getAttribute('data-nav-status'), '', td.getAttribute('data-nav-outlet-id'));
                            return;
                        }
                        td = td.parentNode;
                    }
                };
            } else {
                outletBreakdownTbody.innerHTML = '<tr><td colspan="7" class="text-muted" style="font-size:12px;">No data for the selected period.</td></tr>';
            }
        }

        var suspendedNO = data.suspended_count || 0;
        var forceTerminatedNO = data.force_terminated_count || 0;
        var barScaleO = total + suspendedNO + forceTerminatedNO;
        setWidth('bar-o-complete',   barScaleO > 0 ? Math.round((s.complete   || 0) / barScaleO * 100) : 0);
        setWidth('bar-o-excellence', barScaleO > 0 ? Math.round((s.excellence || 0) / barScaleO * 100) : 0);
        setWidth('bar-o-extended',   barScaleO > 0 ? Math.round((s.extended   || 0) / barScaleO * 100) : 0);
        setWidth('bar-o-failed',     barScaleO > 0 ? Math.round(failed              / barScaleO * 100) : 0);
        setWidth('bar-o-suspended',        barScaleO > 0 ? Math.round(suspendedNO       / barScaleO * 100) : 0);
        setWidth('bar-o-force-terminated', barScaleO > 0 ? Math.round(forceTerminatedNO / barScaleO * 100) : 0);
        setText('bar-o-complete-n',   s.complete   || 0);
        setText('bar-o-excellence-n', s.excellence || 0);
        setText('bar-o-extended-n',   s.extended   || 0);
        setText('bar-o-failed-n',     failed);
        setText('bar-o-suspended-n',        suspendedNO);
        setText('bar-o-force-terminated-n', forceTerminatedNO);

        var sftTbodyO = document.getElementById('dasho-sft-body');
        if (sftTbodyO) {
            var sftRowsO = data.by_suspend_force_terminate || [];
            if (sftRowsO.length > 0) {
                var sftHtmlO = '';
                for (var soi = 0; soi < sftRowsO.length; soi++) {
                    var sftRowO = sftRowsO[soi];
                    sftHtmlO += '<tr style="cursor:pointer;" data-nav-issuer-id="' + sftRowO.issuer_staff_id + '">' +
                        '<td style="font-size:12px;font-weight:600;">' + escapeHtml(sftRowO.issuer_name) + '</td>' +
                        '<td style="font-size:12px;">' + escapeHtml(sftRowO.dept_name) + '</td>' +
                        '<td style="font-size:12px;color:#e11d48;">' + (sftRowO.suspended || 0) + '</td>' +
                        '<td style="font-size:12px;color:#7c3aed;">' + (sftRowO.force_terminated || 0) + '</td>' +
                        '</tr>';
                }
                sftTbodyO.innerHTML = sftHtmlO;
                sftTbodyO.onclick = function (e) {
                    var tr = e.target;
                    while (tr && tr !== sftTbodyO) {
                        if (tr.tagName === 'TR' && tr.hasAttribute('data-nav-issuer-id')) {
                            window.location.href = buildViewUrlOutlet('', '', '', ['Suspended', 'Force Terminated'], false, '', '', '', tr.getAttribute('data-nav-issuer-id'));
                            return;
                        }
                        tr = tr.parentNode;
                    }
                };
            } else {
                sftTbodyO.innerHTML = '<tr><td colspan="4" class="text-muted" style="font-size:12px;">No suspended or force terminated cards for the selected period.</td></tr>';
            }
        }

        setLoading(false, 'dash-tab-outlet');
    }

    function showError(msg) {
        setLoading(false, 'dash-tab-hq');
        setText('dash-total',           'err');
        setText('dash-active',          'err');
        setText('dash-closed',          'err');
        setText('dash-failed',          'err');
        setText('dash-incentive',       'err');
        setText('dash-overdue',         'err');
        setText('dash-suspended',       'err');
        setText('dash-myrole-issuer',   'err');
        setText('dash-myrole-a',        'err');
        setText('dash-myrole-r',        'err');
        setText('dash-myrole-c',        'err');
        setText('dash-myrole-i',        'err');
        setText('dash-myrole-involved', 'err');
        var tbody = document.getElementById('dash-level-body');
        if (tbody) {
            tbody.innerHTML = '<tr><td colspan="6" class="text-danger" style="font-size:12px;">' + msg + '</td></tr>';
        }
        var deptTbody = document.getElementById('dash-dept-body');
        if (deptTbody) {
            deptTbody.innerHTML = '<tr><td colspan="7" class="text-danger" style="font-size:12px;">' + msg + '</td></tr>';
        }
        var sftTbody = document.getElementById('dash-sft-body');
        if (sftTbody) {
            sftTbody.innerHTML = '<tr><td colspan="4" class="text-danger" style="font-size:12px;">' + msg + '</td></tr>';
        }
    }

    function showErrorOutlet(msg) {
        setLoading(false, 'dash-tab-outlet');
        setText('dasho-total',           'err');
        setText('dasho-active',          'err');
        setText('dasho-closed',          'err');
        setText('dasho-failed',          'err');
        setText('dasho-incentive',       'err');
        setText('dasho-overdue',         'err');
        setText('dasho-suspended',       'err');
        setText('dasho-myrole-issuer',   'err');
        setText('dasho-myrole-a',        'err');
        setText('dasho-myrole-r',        'err');
        setText('dasho-myrole-c',        'err');
        setText('dasho-myrole-i',        'err');
        setText('dasho-myrole-involved', 'err');
        var tbody = document.getElementById('dasho-pillar-body');
        if (tbody) {
            tbody.innerHTML = '<tr><td colspan="6" class="text-danger" style="font-size:12px;">' + msg + '</td></tr>';
        }
        var regionTbodyErr = document.getElementById('dasho-region-body');
        if (regionTbodyErr) {
            regionTbodyErr.innerHTML = '<tr><td colspan="7" class="text-danger" style="font-size:12px;">' + msg + '</td></tr>';
        }
        var outletBreakdownTbodyErr = document.getElementById('dasho-outlet-breakdown-body');
        if (outletBreakdownTbodyErr) {
            outletBreakdownTbodyErr.innerHTML = '<tr><td colspan="7" class="text-danger" style="font-size:12px;">' + msg + '</td></tr>';
        }
        var sftTbodyErr = document.getElementById('dasho-sft-body');
        if (sftTbodyErr) {
            sftTbodyErr.innerHTML = '<tr><td colspan="4" class="text-danger" style="font-size:12px;">' + msg + '</td></tr>';
        }
    }

    function buildPayload() {
        var payload = {};
        var yearEl    = document.getElementById('dash-filter-year');
        var monthEl   = document.getElementById('dash-filter-month');
        var deptEl    = document.getElementById('dash-filter-dept');
        var staffEl   = document.getElementById('dash-staff-value');

        var year     = yearEl    ? parseInt(yearEl.value,    10) : 0;
        var month    = monthEl   ? parseInt(monthEl.value,   10) : 0;
        var quarters = getSelectedQuarters('dash-quarter');
        var deptId   = deptEl    ? parseInt(deptEl.value,    10) : 0;
        var staffId  = staffEl   ? parseInt(staffEl.value,   10) : 0;

        if (year    > 0) { payload.filter_year     = year;    }
        if (month   > 0) { payload.filter_month    = month;   }
        payload.filter_quarter = quarters;
        if (deptId  > 0) { payload.filter_dept_id  = deptId;  }
        if (staffId > 0) { payload.filter_staff_id = staffId; }
        payload.filter_atem_type = 1;

        return payload;
    }

    function buildPayloadOutlet() {
        var payload = {};
        var yearEl    = document.getElementById('dasho-filter-year');
        var monthEl   = document.getElementById('dasho-filter-month');
        var regionEl  = document.getElementById('dasho-filter-region');
        var outletEl  = document.getElementById('dasho-outlet-value');
        var staffEl   = document.getElementById('dasho-staff-value');

        var year     = yearEl    ? parseInt(yearEl.value,    10) : 0;
        var month    = monthEl   ? parseInt(monthEl.value,   10) : 0;
        var quarters = getSelectedQuarters('dasho-quarter');
        var regionId = regionEl  ? parseInt(regionEl.value,  10) : 0;
        var outletId = outletEl  ? parseInt(outletEl.value,  10) : 0;
        var staffId  = staffEl   ? parseInt(staffEl.value,   10) : 0;

        if (year     > 0) { payload.filter_year        = year;     }
        if (month    > 0) { payload.filter_month       = month;    }
        payload.filter_quarter = quarters;
        if (regionId > 0) { payload.filter_region_id    = regionId; }
        if (outletId > 0) { payload.filter_outlet_id    = outletId; }
        if (staffId  > 0) { payload.filter_staff_id     = staffId;  }
        payload.filter_atem_type = 2;

        return payload;
    }

    var Q_LABELS = { 1: 'Q1 (Jan-Mar)', 2: 'Q2 (Apr-Jun)', 3: 'Q3 (Jul-Sep)', 4: 'Q4 (Oct-Dec)' };

    function quarterLabelText(quarters) {
        if (!quarters || !quarters.length) { return ''; }
        var labels = [];
        for (var i = 0; i < quarters.length; i++) { labels.push(Q_LABELS[quarters[i]] || ('Q' + quarters[i])); }
        return labels.join(' + ');
    }

    function buildLabel() {
        var yearEl    = document.getElementById('dash-filter-year');
        var monthEl   = document.getElementById('dash-filter-month');
        var deptEl    = document.getElementById('dash-filter-dept');
        var staffBtn  = document.getElementById('dash-staff-btn');
        var staffVal  = document.getElementById('dash-staff-value');

        var parts = [];
        var yearVal    = yearEl    ? yearEl.value    : '';
        var monthVal   = monthEl   ? monthEl.value   : '';
        var quarters   = getSelectedQuarters('dash-quarter');
        var deptVal    = deptEl    ? deptEl.value    : '';
        var staffIdVal = staffVal  ? (parseInt(staffVal.value, 10) || 0) : 0;

        if (!yearVal && !monthVal && !quarters.length && !deptVal && !staffIdVal) { return 'Showing all records'; }

        if (yearVal) { parts.push(yearVal); }

        if (monthVal) {
            var months = ['', 'January', 'February', 'March', 'April', 'May', 'June',
                              'July', 'August', 'September', 'October', 'November', 'December'];
            parts.push(months[parseInt(monthVal, 10)] || monthVal);
        }

        if (quarters.length) { parts.push(quarterLabelText(quarters)); }

        if (deptEl && deptEl.selectedIndex > 0) {
            parts.push(deptEl.options[deptEl.selectedIndex].text);
        }

        if (staffIdVal && staffBtn) { parts.push(staffBtn.textContent); }

        return 'Showing: ' + parts.join(', ');
    }

    function buildLabelOutlet() {
        var yearEl    = document.getElementById('dasho-filter-year');
        var monthEl   = document.getElementById('dasho-filter-month');
        var regionEl  = document.getElementById('dasho-filter-region');
        var outletBtn = document.getElementById('dasho-outlet-btn');
        var outletVal = document.getElementById('dasho-outlet-value');
        var staffBtn  = document.getElementById('dasho-staff-btn');
        var staffVal  = document.getElementById('dasho-staff-value');

        var parts = [];
        var yearVal    = yearEl    ? yearEl.value    : '';
        var monthVal   = monthEl   ? monthEl.value   : '';
        var quarters   = getSelectedQuarters('dasho-quarter');
        var regionVal  = regionEl  ? regionEl.value   : '';
        var outletIdVal = outletVal ? (parseInt(outletVal.value, 10) || 0) : 0;
        var staffIdVal  = staffVal  ? (parseInt(staffVal.value, 10) || 0) : 0;

        if (!yearVal && !monthVal && !quarters.length && !regionVal && !outletIdVal && !staffIdVal) { return 'Showing all records'; }

        if (yearVal) { parts.push(yearVal); }

        if (monthVal) {
            var months = ['', 'January', 'February', 'March', 'April', 'May', 'June',
                              'July', 'August', 'September', 'October', 'November', 'December'];
            parts.push(months[parseInt(monthVal, 10)] || monthVal);
        }

        if (quarters.length) { parts.push(quarterLabelText(quarters)); }

        if (regionEl && regionEl.selectedIndex > 0) {
            parts.push(regionEl.options[regionEl.selectedIndex].text);
        }
        if (outletIdVal && outletBtn) { parts.push(outletBtn.textContent); }
        if (staffIdVal && staffBtn) { parts.push(staffBtn.textContent); }

        return 'Showing: ' + parts.join(', ');
    }

    function loadDashboard(payload) {
        setLoading(true, 'dash-tab-hq');
        setText('dash-total',           '---');
        setText('dash-active',          '---');
        setText('dash-closed',          '---');
        setText('dash-failed',          '---');
        setText('dash-incentive',       '---');
        setText('dash-overdue',         '---');
        setText('dash-suspended',       '---');
        setText('dash-myrole-issuer',   '---');
        setText('dash-myrole-a',        '---');
        setText('dash-myrole-r',        '---');
        setText('dash-myrole-c',        '---');
        setText('dash-myrole-i',        '---');
        setText('dash-myrole-involved', '---');

        var deptTbody = document.getElementById('dash-dept-body');
        if (deptTbody) { deptTbody.innerHTML = '<tr><td colspan="7" class="text-muted" style="font-size:12px;">Loading...</td></tr>'; }

        var lbl = document.getElementById('dash-filter-label');
        if (lbl) { lbl.textContent = buildLabel(); }

        apiCall('dashboard-stats', payload || {}).then(function (res) {
            if (res && res.success && res.data) {
                renderDashboard(res.data);
            } else {
                showError(res && res.message ? res.message : 'Failed to load dashboard data.');
            }
        }).catch(function () {
            showError('Could not reach the ATEM service. Please ensure the service is running.');
        });
    }

    function loadDashboardOutlet(payload) {
        setLoading(true, 'dash-tab-outlet');
        setText('dasho-total',           '---');
        setText('dasho-active',          '---');
        setText('dasho-closed',          '---');
        setText('dasho-failed',          '---');
        setText('dasho-incentive',       '---');
        setText('dasho-overdue',         '---');
        setText('dasho-suspended',       '---');
        setText('dasho-myrole-issuer',   '---');
        setText('dasho-myrole-a',        '---');
        setText('dasho-myrole-r',        '---');
        setText('dasho-myrole-c',        '---');
        setText('dasho-myrole-i',        '---');
        setText('dasho-myrole-involved', '---');

        var tbody = document.getElementById('dasho-pillar-body');
        if (tbody) { tbody.innerHTML = '<tr><td colspan="6" class="text-muted" style="font-size:12px;">Loading...</td></tr>'; }
        var regionTbodyLoad = document.getElementById('dasho-region-body');
        if (regionTbodyLoad) { regionTbodyLoad.innerHTML = '<tr><td colspan="7" class="text-muted" style="font-size:12px;">Loading...</td></tr>'; }
        var outletBreakdownTbodyLoad = document.getElementById('dasho-outlet-breakdown-body');
        if (outletBreakdownTbodyLoad) { outletBreakdownTbodyLoad.innerHTML = '<tr><td colspan="7" class="text-muted" style="font-size:12px;">Loading...</td></tr>'; }

        var lbl = document.getElementById('dasho-filter-label');
        if (lbl) { lbl.textContent = buildLabelOutlet(); }

        apiCall('dashboard-stats', payload || {}).then(function (res) {
            if (res && res.success && res.data) {
                renderDashboardOutlet(res.data);
            } else {
                showErrorOutlet(res && res.message ? res.message : 'Failed to load dashboard data.');
            }
        }).catch(function () {
            showErrorOutlet('Could not reach the ATEM service. Please ensure the service is running.');
        });
    }

    function populateDeptSelect() {
        var deptEl = document.getElementById('dash-filter-dept');
        if (!deptEl || !CFG.departments || !CFG.departments.length) { return; }
        for (var i = 0; i < CFG.departments.length; i++) {
            var opt = document.createElement('option');
            opt.value = CFG.departments[i].id;
            opt.textContent = CFG.departments[i].name;
            deptEl.appendChild(opt);
        }
    }

    function populateRegionSelect() {
        var regionEl = document.getElementById('dasho-filter-region');
        if (!regionEl || !CFG.regions || !CFG.regions.length) { return; }
        for (var i = 0; i < CFG.regions.length; i++) {
            var opt = document.createElement('option');
            opt.value = CFG.regions[i].id;
            opt.textContent = CFG.regions[i].name;
            regionEl.appendChild(opt);
        }
    }

    document.addEventListener('DOMContentLoaded', function () {
        populateDeptSelect();
        buildStaffDropdown();

        var resetBtn  = document.getElementById('dash-reset-filter');
        var yearEl    = document.getElementById('dash-filter-year');
        var monthEl   = document.getElementById('dash-filter-month');
        var deptEl    = document.getElementById('dash-filter-dept');

        function applyFiltersNow() { loadDashboard(buildPayload()); }

        // Month and quarter are mutually exclusive
        if (monthEl) {
            monthEl.addEventListener('change', function () {
                if (this.value) { resetQuarterDropdown('dash-quarter'); }
                applyFiltersNow();
            });
        }
        buildQuarterDropdown('dash-quarter', 'dash-filter-year', function () {
            if (getSelectedQuarters('dash-quarter').length && monthEl) { monthEl.value = ''; }
            applyFiltersNow();
        });
        if (yearEl)  { yearEl.addEventListener('change', applyFiltersNow); }
        if (deptEl)  { deptEl.addEventListener('change', applyFiltersNow); }

        if (resetBtn) {
            resetBtn.addEventListener('click', function () {
                if (yearEl)    { yearEl.value    = '2026'; }
                if (monthEl)   { monthEl.value   = ''; }
                resetQuarterDropdown('dash-quarter');
                if (deptEl)    { deptEl.value    = ''; }
                resetStaffDropdown();
                loadDashboard({ filter_year: 2026 });
            });
        }

        // Default load: 2026 data
        if (CFG.tabSingleView !== 'outlet') { loadDashboard({ filter_year: 2026 }); }

        // Stat card click navigation
        var dashStats = document.querySelectorAll('.atem-dash-stat');
        for (var si = 0; si < dashStats.length; si++) {
            (function (card) {
                card.addEventListener('click', function () {
                    var role       = card.getAttribute('data-myrole') || '';
                    var issuerMine = card.getAttribute('data-issuer') || '';
                    var mine       = card.getAttribute('data-mine')   || '';
                    // These three deep-link params only resolve correctly for the
                    // logged-in viewer's own roles (see applyInvolvementScopeUI) —
                    // ignore clicks on them while showing a Staff/Department scope.
                    if ((role || issuerMine || mine) && currentInvolvementScope !== 'me') { return; }

                    var status       = card.getAttribute('data-status')    || '';
                    var statusesAttr = card.getAttribute('data-statuses')  || '';
                    var statuses     = statusesAttr ? statusesAttr.split(',') : [];
                    var isOverdue    = card.getAttribute('data-overdue')   === '1';
                    var isIncentive  = card.getAttribute('data-incentive') === '1';
                    window.location.href = buildViewUrl(status, '', '', statuses, isOverdue, isIncentive, role, issuerMine, mine);
                });
            }(dashStats[si]));
        }

        // ----------------------------------------------------- Outlet dashboard
        populateRegionSelect();
        buildOutletDropdownOutlet();
        buildStaffDropdownOutlet();

        // The Outlet dashboard pane is hidden (display:none) until its tab is
        // shown, so the dropdown-button size sync above read 0 - redo it now
        // that the pane is actually visible.
        var dashOutletTabBtn = document.getElementById('dash-tab-outlet-btn');
        if (dashOutletTabBtn) {
            dashOutletTabBtn.addEventListener('shown.bs.tab', function () {
                syncSearchDropdownSize('dasho-staff', 'dasho-filter-year');
                syncSearchDropdownSize('dasho-outlet', 'dasho-filter-year');
                syncSearchDropdownSize('dasho-quarter', 'dasho-filter-year');
            });
        }

        var resetBtnO  = document.getElementById('dasho-reset-filter');
        var yearElO    = document.getElementById('dasho-filter-year');
        var monthElO   = document.getElementById('dasho-filter-month');

        function applyFiltersNowOutlet() { loadDashboardOutlet(buildPayloadOutlet()); }

        if (monthElO) {
            monthElO.addEventListener('change', function () {
                if (this.value) { resetQuarterDropdown('dasho-quarter'); }
                applyFiltersNowOutlet();
            });
        }
        buildQuarterDropdown('dasho-quarter', 'dasho-filter-year', function () {
            if (getSelectedQuarters('dasho-quarter').length && monthElO) { monthElO.value = ''; }
            applyFiltersNowOutlet();
        });
        if (yearElO)   { yearElO.addEventListener('change', applyFiltersNowOutlet); }

        if (resetBtnO) {
            resetBtnO.addEventListener('click', function () {
                if (yearElO)    { yearElO.value    = '2026'; }
                if (monthElO)   { monthElO.value   = ''; }
                resetQuarterDropdown('dasho-quarter');
                var regionElO = document.getElementById('dasho-filter-region');
                if (regionElO) { regionElO.value = ''; }
                resetSearchDropdown('dasho-outlet', 'All outlets');
                resetSearchDropdown('dasho-staff', 'All Area Managers');
                loadDashboardOutlet({ filter_year: 2026, filter_atem_type: 2 });
            });
        }

        // Default load: 2026 data
        if (CFG.tabSingleView !== 'hq') { loadDashboardOutlet({ filter_year: 2026, filter_atem_type: 2 }); }

        // Stat card click navigation (Outlet)
        var dashStatsOutlet = document.querySelectorAll('.atem-dash-stat-outlet');
        for (var soi = 0; soi < dashStatsOutlet.length; soi++) {
            (function (card) {
                card.addEventListener('click', function () {
                    var role       = card.getAttribute('data-myrole') || '';
                    var issuerMine = card.getAttribute('data-issuer') || '';
                    var mine       = card.getAttribute('data-mine')   || '';
                    if ((role || issuerMine || mine) && currentInvolvementScopeOutlet !== 'me') { return; }

                    var status       = card.getAttribute('data-status')    || '';
                    var statusesAttr = card.getAttribute('data-statuses')  || '';
                    var statuses     = statusesAttr ? statusesAttr.split(',') : [];
                    var isOverdue    = card.getAttribute('data-overdue')   === '1';
                    window.location.href = buildViewUrlOutlet(status, '', '', statuses, isOverdue, role, issuerMine, mine);
                });
            }(dashStatsOutlet[soi]));
        }
    });
}());
