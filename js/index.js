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

        setText('dash-total',     formatNumber(total));
        setText('dash-active',    formatNumber(active));
        setText('dash-closed',    formatNumber(closed));
        setText('dash-failed',    formatNumber(failed));
        setText('dash-fail-rate', failRate);
        setText('dash-incentive', formatRM(data.incentive_total));

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

        setLoading(false);
    }

    function showError(msg) {
        setLoading(false);
        setText('dash-total',     'err');
        setText('dash-active',    'err');
        setText('dash-closed',    'err');
        setText('dash-failed',    'err');
        setText('dash-incentive', 'err');
        var tbody = document.getElementById('dash-level-body');
        if (tbody) {
            tbody.innerHTML = '<tr><td colspan="6" class="text-danger" style="font-size:12px;">' + msg + '</td></tr>';
        }
    }

    function buildPayload() {
        var payload = {};
        var yearEl   = document.getElementById('dash-filter-year');
        var periodEl = document.getElementById('dash-filter-period');
        var year     = yearEl   ? parseInt(yearEl.value,   10) : 0;
        var period   = periodEl ? periodEl.value : '';

        if (year > 0) { payload.filter_year = year; }

        if (period.indexOf('m:') === 0) {
            payload.filter_month = parseInt(period.slice(2), 10);
        } else if (period.indexOf('q:') === 0) {
            payload.filter_quarter = parseInt(period.slice(2), 10);
        }

        return payload;
    }

    function labelForFilter(year, period) {
        if (!year && !period) { return 'Showing all records'; }
        var parts = [];
        if (year) { parts.push(year); }
        if (period) {
            var months = ['', 'January', 'February', 'March', 'April', 'May', 'June',
                              'July', 'August', 'September', 'October', 'November', 'December'];
            if (period.indexOf('m:') === 0) {
                parts.push(months[parseInt(period.slice(2), 10)] || '');
            } else if (period.indexOf('q:') === 0) {
                var qLabels = { 1: 'Q1 (Jan-Mar)', 2: 'Q2 (Apr-Jun)', 3: 'Q3 (Jul-Sep)', 4: 'Q4 (Oct-Dec)' };
                parts.push(qLabels[parseInt(period.slice(2), 10)] || '');
            }
        }
        return 'Showing: ' + parts.join(' ');
    }

    function loadDashboard(payload) {
        setLoading(true);
        setText('dash-total',     '---');
        setText('dash-active',    '---');
        setText('dash-closed',    '---');
        setText('dash-failed',    '---');
        setText('dash-incentive', '---');

        var yearEl   = document.getElementById('dash-filter-year');
        var periodEl = document.getElementById('dash-filter-period');
        var lbl      = document.getElementById('dash-filter-label');
        if (lbl) {
            lbl.textContent = labelForFilter(
                yearEl   ? yearEl.value   : '',
                periodEl ? periodEl.value : ''
            );
        }

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

    document.addEventListener('DOMContentLoaded', function () {
        var applyBtn = document.getElementById('dash-apply-filter');
        var resetBtn = document.getElementById('dash-reset-filter');

        if (applyBtn) {
            applyBtn.addEventListener('click', function () {
                loadDashboard(buildPayload());
            });
        }

        if (resetBtn) {
            resetBtn.addEventListener('click', function () {
                var yearEl   = document.getElementById('dash-filter-year');
                var periodEl = document.getElementById('dash-filter-period');
                if (yearEl)   { yearEl.value   = '2026'; }
                if (periodEl) { periodEl.value = ''; }
                loadDashboard({ filter_year: 2026 });
            });
        }

        // Default load: 2026 data
        loadDashboard({ filter_year: 2026 });
    });
}());
