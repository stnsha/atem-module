/* ATEM listing: renders the table from server-injected rows with client-side
   filtering, title search, sort and pagination (15/page). Filters/search always
   run over the full dataset, then the result is paginated. */
(function () {
    'use strict';

    var CFG = window.ATEM_VIEW || { rows: [], levels: [], statuses: [] };
    var rows = CFG.rows || [];
    var PER_PAGE = 15;
    var page = 1;
    var sortCol = 'id';
    var sortDir = -1; // newest first by id

    var LEVEL_COLOR = { 'Level 1': '#6c757d', 'Level 2': '#0d6efd', 'Level 3': '#6610f2', 'Level 4': '#003B73' };
    var STATUS_COLOR = {
        'Draft': '#6c757d', 'On Hold': '#7a5cff', 'Pending': '#e0a800', 'In Progress': '#0d6efd',
        'Completed': '#198754', 'Completed with Excellence': '#0dcaf0', 'Extended': '#fd7e14', 'Failed': '#dc3545'
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
        var depts = distinct('department_name');
        var dh = '<option value="">All departments</option>';
        for (var d = 0; d < depts.length; d++) { dh += '<option value="' + escapeHtml(depts[d]) + '">' + escapeHtml(depts[d]) + '</option>'; }
        dep.innerHTML = dh;

        var st = $('vf-status');
        var statuses = CFG.statuses || [];
        var sh = '<option value="">All statuses</option>';
        for (var s = 0; s < statuses.length; s++) { sh += '<option value="' + escapeHtml(statuses[s].value) + '">' + escapeHtml(statuses[s].value) + '</option>'; }
        st.innerHTML = sh;
    }

    // --------------------------------------------------------------- filtering
    function applyFilters() {
        var level = $('vf-level').value;
        var dept = $('vf-dept').value;
        var status = $('vf-status').value;
        var from = $('vf-from').value;
        var to = $('vf-to').value;
        var term = $('vf-search').value.toLowerCase().trim();

        return rows.filter(function (r) {
            if (level && r.level_label !== level) { return false; }
            if (dept && r.department_name !== dept) { return false; }
            if (status && r.status !== status) { return false; }
            if (from && (!r.start_date || r.start_date.substring(0, 10) < from)) { return false; }
            if (to && (!r.start_date || r.start_date.substring(0, 10) > to)) { return false; }
            if (term && String(r.title).toLowerCase().indexOf(term) < 0) { return false; }
            return true;
        });
    }

    function sortRows(list) {
        return list.slice().sort(function (a, b) {
            var av = a[sortCol], bv = b[sortCol];
            if (sortCol === 'id') { av = Number(av) || 0; bv = Number(bv) || 0; }
            else { av = String(av == null ? '' : av).toLowerCase(); bv = String(bv == null ? '' : bv).toLowerCase(); }
            if (av < bv) { return -1 * sortDir; }
            if (av > bv) { return 1 * sortDir; }
            return 0;
        });
    }

    // --------------------------------------------------------------- rendering
    function render() {
        var list = sortRows(applyFilters());
        var total = list.length;
        var pages = Math.max(1, Math.ceil(total / PER_PAGE));
        if (page > pages) { page = pages; }
        var startIdx = (page - 1) * PER_PAGE;
        var pageRows = list.slice(startIdx, startIdx + PER_PAGE);

        var body = $('atem-view-body');
        if (total === 0) {
            body.innerHTML = '<tr><td colspan="9" class="atem-empty-state" style="text-align:center;padding:24px;">No ATEM cards match the current filters.</td></tr>';
        } else {
            var html = '';
            for (var i = 0; i < pageRows.length; i++) {
                var r = pageRows[i];
                var levelCell = r.level_label ? pill(r.level_label, LEVEL_COLOR[r.level_label] || '#6c757d', r.system_name) : '-';
                var statusCell = r.status ? pill(r.status, STATUS_COLOR[r.status] || '#6c757d') : '-';
                html += '<tr>'
                    + '<td><span class="atem-id">#AT' + r.id + '</span></td>'
                    + '<td>' + escapeHtml(r.title) + '</td>'
                    + '<td>' + escapeHtml(r.issuer_name) + '</td>'
                    + '<td>' + escapeHtml(r.department_name) + '</td>'
                    + '<td>' + levelCell + '</td>'
                    + '<td>' + fmtDate(r.start_date) + '</td>'
                    + '<td>' + fmtDate(r.end_date) + '</td>'
                    + '<td>' + statusCell + '</td>'
                    + '<td class="atem-view-actions">'
                    + '<a href="atem/edit.php?id=' + r.id + '&mode=read" class="btn btn-sm btn-outline-primary" title="View"><i class="bi bi-eye"></i></a> '
                    + '<a href="atem/edit.php?id=' + r.id + '&mode=edit" class="btn btn-sm btn-outline-secondary" title="Edit"><i class="bi bi-pencil"></i></a>'
                    + '</td></tr>';
            }
            body.innerHTML = html;
        }
        renderPager(total, pages, startIdx, pageRows.length);
    }

    function renderPager(total, pages, startIdx, shown) {
        var pager = $('atem-pager');
        if (total === 0) { pager.innerHTML = ''; return; }
        var info = '<span class="atem-pager-info">Showing ' + (startIdx + 1) + '-' + (startIdx + shown) + ' of ' + total + '</span>';
        var btns = '<button type="button" class="atem-pager-btn" data-page="' + (page - 1) + '"' + (page <= 1 ? ' disabled' : '') + '>Prev</button>';

        var win = 2, from = Math.max(1, page - win), to = Math.min(pages, page + win);
        if (from > 1) { btns += pageBtn(1) + (from > 2 ? '<span class="atem-pager-gap">...</span>' : ''); }
        for (var p = from; p <= to; p++) { btns += pageBtn(p); }
        if (to < pages) { btns += (to < pages - 1 ? '<span class="atem-pager-gap">...</span>' : '') + pageBtn(pages); }

        btns += '<button type="button" class="atem-pager-btn" data-page="' + (page + 1) + '"' + (page >= pages ? ' disabled' : '') + '>Next</button>';
        pager.innerHTML = info + '<div class="atem-pager-bar">' + btns + '</div>';
    }

    function pageBtn(p) {
        return '<button type="button" class="atem-pager-btn' + (p === page ? ' active' : '') + '" data-page="' + p + '">' + p + '</button>';
    }

    // --------------------------------------------------------------- wiring
    function bind() {
        ['vf-level', 'vf-dept', 'vf-status', 'vf-from', 'vf-to'].forEach(function (id) {
            $(id).addEventListener('change', function () { page = 1; render(); });
        });
        $('vf-search').addEventListener('keyup', function () { page = 1; render(); });
        $('vf-reset').addEventListener('click', function () {
            ['vf-level', 'vf-dept', 'vf-status', 'vf-from', 'vf-to', 'vf-search'].forEach(function (id) { $(id).value = ''; });
            page = 1; render();
        });

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
    }

    document.addEventListener('DOMContentLoaded', function () {
        buildFilters();
        bind();
        render();
    });
})();
