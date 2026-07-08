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

    function setLoading(on) {
        var cards = document.querySelectorAll('.atem-stat-value');
        for (var i = 0; i < cards.length; i++) {
            cards[i].style.opacity = on ? '0.4' : '1';
        }
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

        function escapeHtml(s) {
            return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
                return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
            });
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

    var QUARTER_RANGES = {
        '1': ['01-01', '03-31'],
        '2': ['04-01', '06-30'],
        '3': ['07-01', '09-30'],
        '4': ['10-01', '12-31']
    };

    function buildViewUrl(statusOverride, levelIdOverride, deptOverride, presetOverride, overdueOnly, incentiveOnly, roleOverride, issuerOverride, mineOverride) {
        var yearEl    = document.getElementById('dash-filter-year');
        var monthEl   = document.getElementById('dash-filter-month');
        var quarterEl = document.getElementById('dash-filter-quarter');
        var deptEl    = document.getElementById('dash-filter-dept');

        var year    = yearEl    ? yearEl.value    : '';
        var month   = monthEl   ? monthEl.value   : '';
        var quarter = quarterEl ? quarterEl.value  : '';
        var deptName = deptOverride || ((deptEl && deptEl.selectedIndex > 0)
                       ? deptEl.options[deptEl.selectedIndex].text : '');

        var params = [];
        if (statusOverride)  { params.push('status='      + encodeURIComponent(statusOverride)); }
        if (levelIdOverride) { params.push('level_id='    + encodeURIComponent(levelIdOverride)); }
        if (presetOverride)  { params.push('preset='      + encodeURIComponent(presetOverride)); }
        if (overdueOnly)     { params.push('overdue=1'); }
        if (incentiveOnly)   { params.push('min_level_id=2'); }
        if (roleOverride)    { params.push('role='       + encodeURIComponent(roleOverride)); }
        if (issuerOverride)  { params.push('issuer='     + encodeURIComponent(issuerOverride)); }
        if (mineOverride)    { params.push('mine='       + encodeURIComponent(mineOverride)); }
        if (year)   { params.push('year='  + encodeURIComponent(year)); }
        if (month)  { params.push('month=' + encodeURIComponent(month)); }
        if (!month && quarter && year && QUARTER_RANGES[quarter]) {
            var range = QUARTER_RANGES[quarter];
            params.push('from=' + encodeURIComponent(year + '-' + range[0]));
            params.push('to='   + encodeURIComponent(year + '-' + range[1]));
        }
        if (deptName) { params.push('dept=' + encodeURIComponent(deptName)); }
        params.push('no_deleted=1');

        return 'atem/view.php' + (params.length ? '?' + params.join('&') : '');
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

        setWidth('bar-complete',   total > 0 ? Math.round((s.complete   || 0) / total * 100) : 0);
        setWidth('bar-excellence', total > 0 ? Math.round((s.excellence || 0) / total * 100) : 0);
        setWidth('bar-extended',   total > 0 ? Math.round((s.extended   || 0) / total * 100) : 0);
        setWidth('bar-failed',     total > 0 ? Math.round(failed              / total * 100) : 0);
        setText('bar-complete-n',   s.complete   || 0);
        setText('bar-excellence-n', s.excellence || 0);
        setText('bar-extended-n',   s.extended   || 0);
        setText('bar-failed-n',     failed);

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

        setLoading(false);
    }

    function showError(msg) {
        setLoading(false);
        setText('dash-total',           'err');
        setText('dash-active',          'err');
        setText('dash-closed',          'err');
        setText('dash-failed',          'err');
        setText('dash-incentive',       'err');
        setText('dash-overdue',         'err');
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
    }

    function buildPayload() {
        var payload = {};
        var yearEl    = document.getElementById('dash-filter-year');
        var monthEl   = document.getElementById('dash-filter-month');
        var quarterEl = document.getElementById('dash-filter-quarter');
        var deptEl    = document.getElementById('dash-filter-dept');
        var staffEl   = document.getElementById('dash-staff-value');

        var year    = yearEl    ? parseInt(yearEl.value,    10) : 0;
        var month   = monthEl   ? parseInt(monthEl.value,   10) : 0;
        var quarter = quarterEl ? parseInt(quarterEl.value, 10) : 0;
        var deptId  = deptEl    ? parseInt(deptEl.value,    10) : 0;
        var staffId = staffEl   ? parseInt(staffEl.value,   10) : 0;

        if (year    > 0) { payload.filter_year     = year;    }
        if (month   > 0) { payload.filter_month    = month;   }
        if (quarter > 0) { payload.filter_quarter  = quarter; }
        if (deptId  > 0) { payload.filter_dept_id  = deptId;  }
        if (staffId > 0) { payload.filter_staff_id = staffId; }

        return payload;
    }

    function buildLabel() {
        var yearEl    = document.getElementById('dash-filter-year');
        var monthEl   = document.getElementById('dash-filter-month');
        var quarterEl = document.getElementById('dash-filter-quarter');
        var deptEl    = document.getElementById('dash-filter-dept');
        var staffBtn  = document.getElementById('dash-staff-btn');
        var staffVal  = document.getElementById('dash-staff-value');

        var parts = [];
        var yearVal    = yearEl    ? yearEl.value    : '';
        var monthVal   = monthEl   ? monthEl.value   : '';
        var quarterVal = quarterEl ? quarterEl.value  : '';
        var deptVal    = deptEl    ? deptEl.value    : '';
        var staffIdVal = staffVal  ? (parseInt(staffVal.value, 10) || 0) : 0;

        if (!yearVal && !monthVal && !quarterVal && !deptVal && !staffIdVal) { return 'Showing all records'; }

        if (yearVal) { parts.push(yearVal); }

        if (monthVal) {
            var months = ['', 'January', 'February', 'March', 'April', 'May', 'June',
                              'July', 'August', 'September', 'October', 'November', 'December'];
            parts.push(months[parseInt(monthVal, 10)] || monthVal);
        }

        if (quarterVal) {
            var qLabels = { 1: 'Q1 (Jan-Mar)', 2: 'Q2 (Apr-Jun)', 3: 'Q3 (Jul-Sep)', 4: 'Q4 (Oct-Dec)' };
            parts.push(qLabels[parseInt(quarterVal, 10)] || ('Q' + quarterVal));
        }

        if (deptEl && deptEl.selectedIndex > 0) {
            parts.push(deptEl.options[deptEl.selectedIndex].text);
        }

        if (staffIdVal && staffBtn) { parts.push(staffBtn.textContent); }

        return 'Showing: ' + parts.join(', ');
    }

    function loadDashboard(payload) {
        setLoading(true);
        setText('dash-total',           '---');
        setText('dash-active',          '---');
        setText('dash-closed',          '---');
        setText('dash-failed',          '---');
        setText('dash-incentive',       '---');
        setText('dash-overdue',         '---');
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

    document.addEventListener('DOMContentLoaded', function () {
        populateDeptSelect();
        buildStaffDropdown();

        var resetBtn  = document.getElementById('dash-reset-filter');
        var yearEl    = document.getElementById('dash-filter-year');
        var monthEl   = document.getElementById('dash-filter-month');
        var quarterEl = document.getElementById('dash-filter-quarter');
        var deptEl    = document.getElementById('dash-filter-dept');

        function applyFiltersNow() { loadDashboard(buildPayload()); }

        // Month and quarter are mutually exclusive
        if (monthEl) {
            monthEl.addEventListener('change', function () {
                if (this.value && quarterEl) { quarterEl.value = ''; }
                applyFiltersNow();
            });
        }
        if (quarterEl) {
            quarterEl.addEventListener('change', function () {
                if (this.value && monthEl) { monthEl.value = ''; }
                applyFiltersNow();
            });
        }
        if (yearEl)  { yearEl.addEventListener('change', applyFiltersNow); }
        if (deptEl)  { deptEl.addEventListener('change', applyFiltersNow); }

        if (resetBtn) {
            resetBtn.addEventListener('click', function () {
                if (yearEl)    { yearEl.value    = '2026'; }
                if (monthEl)   { monthEl.value   = ''; }
                if (quarterEl) { quarterEl.value = ''; }
                if (deptEl)    { deptEl.value    = ''; }
                resetStaffDropdown();
                loadDashboard({ filter_year: 2026 });
            });
        }

        // Default load: 2026 data
        loadDashboard({ filter_year: 2026 });

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

                    var status      = card.getAttribute('data-status')    || '';
                    var preset      = card.getAttribute('data-preset')    || '';
                    var isOverdue   = card.getAttribute('data-overdue')   === '1';
                    var isIncentive = card.getAttribute('data-incentive') === '1';
                    window.location.href = buildViewUrl(status, '', '', preset, isOverdue, isIncentive, role, issuerMine, mine);
                });
            }(dashStats[si]));
        }
    });
}());
