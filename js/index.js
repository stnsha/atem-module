(function () {
    'use strict';

    var CFG = window.ATEM_DASH || {};

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

    function renderDashboard(data) {
        var s = data.by_status;
        var total  = data.total || 0;
        var active = (s.active || 0) + (s.draft || 0);
        var closed = (s.complete || 0) + (s.excellence || 0);
        var failed = s.failed || 0;
        var failRate = total > 0 ? (failed / total * 100).toFixed(1) + '% failure rate' : '0.0% failure rate';
        var exRate   = closed > 0 ? ((s.excellence || 0) / closed * 100).toFixed(1) + '%' : '0.0%';

        setText('dash-total',           formatNumber(total));
        setText('dash-active',          formatNumber(active));
        setText('dash-closed',          formatNumber(closed));
        setText('dash-failed',          formatNumber(failed));
        setText('dash-fail-rate',       failRate);
        setText('dash-incentive',       formatRM(data.incentive_total));
        setText('dash-excellence-rate', exRate);
        setText('dash-overdue',         formatNumber(data.overdue_count || 0));

        var tbody = document.getElementById('dash-level-body');
        if (tbody && data.by_level) {
            var html = '';
            for (var i = 0; i < data.by_level.length; i++) {
                var l = data.by_level[i];
                var forecast = l.level_id === 1 ? 'RM0' : formatRM(l.forecast);
                html += '<tr>' +
                    '<td style="font-size:12px;font-weight:600;">' + l.label + '</td>' +
                    '<td style="font-size:12px;">' + l.cards + '</td>' +
                    '<td style="font-size:12px;"><span style="color:#0d6efd;">' + l.complete + '</span></td>' +
                    '<td style="font-size:12px;"><span style="color:#198754;">' + l.excellence + '</span></td>' +
                    '<td style="font-size:12px;"><span style="color:#dc3545;">' + l.fail + '</span></td>' +
                    '<td style="font-size:12px;">' + forecast + '</td>' +
                    '</tr>';
            }
            tbody.innerHTML = html;
        }

        if (total > 0) {
            setWidth('bar-complete',   Math.round((s.complete   || 0) / total * 100));
            setWidth('bar-excellence', Math.round((s.excellence || 0) / total * 100));
            setWidth('bar-extended',   Math.round((s.extended   || 0) / total * 100));
            setWidth('bar-failed',     Math.round(failed              / total * 100));
        }
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
                        '<td style="font-size:12px;">' + dCards + '</td>' +
                        '<td style="font-size:12px;"><span style="color:#0d6efd;">' + (dept.complete || 0) + '</span></td>' +
                        '<td style="font-size:12px;"><span style="color:#198754;">' + (dept.excellence || 0) + '</span></td>' +
                        '<td style="font-size:12px;"><span style="color:#dc3545;">' + dFail + '</span></td>' +
                        '<td style="font-size:12px;">' + dFailRate + '</td>' +
                        '<td style="font-size:12px;">' + dForecast + '</td>' +
                        '</tr>';
                }
                deptTbody.innerHTML = dHtml;
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
        setText('dash-excellence-rate', '--%');
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

        var year    = yearEl    ? parseInt(yearEl.value,    10) : 0;
        var month   = monthEl   ? parseInt(monthEl.value,   10) : 0;
        var quarter = quarterEl ? parseInt(quarterEl.value, 10) : 0;
        var deptId  = deptEl    ? parseInt(deptEl.value,    10) : 0;

        if (year    > 0) { payload.filter_year    = year;    }
        if (month   > 0) { payload.filter_month   = month;   }
        if (quarter > 0) { payload.filter_quarter = quarter; }
        if (deptId  > 0) { payload.filter_dept_id = deptId;  }

        return payload;
    }

    function buildLabel() {
        var yearEl    = document.getElementById('dash-filter-year');
        var monthEl   = document.getElementById('dash-filter-month');
        var quarterEl = document.getElementById('dash-filter-quarter');
        var deptEl    = document.getElementById('dash-filter-dept');

        var parts = [];
        var yearVal    = yearEl    ? yearEl.value    : '';
        var monthVal   = monthEl   ? monthEl.value   : '';
        var quarterVal = quarterEl ? quarterEl.value  : '';
        var deptVal    = deptEl    ? deptEl.value    : '';

        if (!yearVal && !monthVal && !quarterVal && !deptVal) { return 'Showing all records'; }

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
        setText('dash-excellence-rate', '--%');

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

        var applyBtn  = document.getElementById('dash-apply-filter');
        var resetBtn  = document.getElementById('dash-reset-filter');
        var monthEl   = document.getElementById('dash-filter-month');
        var quarterEl = document.getElementById('dash-filter-quarter');

        // Month and quarter are mutually exclusive
        if (monthEl) {
            monthEl.addEventListener('change', function () {
                if (this.value && quarterEl) { quarterEl.value = ''; }
            });
        }
        if (quarterEl) {
            quarterEl.addEventListener('change', function () {
                if (this.value && monthEl) { monthEl.value = ''; }
            });
        }

        if (applyBtn) {
            applyBtn.addEventListener('click', function () {
                loadDashboard(buildPayload());
            });
        }

        if (resetBtn) {
            resetBtn.addEventListener('click', function () {
                var yearEl    = document.getElementById('dash-filter-year');
                var deptEl    = document.getElementById('dash-filter-dept');
                if (yearEl)    { yearEl.value    = '2026'; }
                if (monthEl)   { monthEl.value   = ''; }
                if (quarterEl) { quarterEl.value = ''; }
                if (deptEl)    { deptEl.value    = ''; }
                loadDashboard({ filter_year: 2026 });
            });
        }

        // Default load: 2026 data
        loadDashboard({ filter_year: 2026 });
    });
}());
