/* ATEM edit/view: loads an existing card, renders read-only (mode=read) or
   editable (mode=edit). In edit mode, children (ARCI, links, attachments) are
   persisted immediately against the real id; the main fields + timeline save via
   update-atem (PUT). Talks to the JWT proxy at atem/api.php. */
(function () {
    'use strict';

    var CFG = window.ATEM_CONFIG || {};
    var READ = (CFG.mode !== 'edit');
    var REC = CFG.record || {};
    var IS_ISSUER = !!(CFG.staffId && REC.issuer_staff_id && CFG.staffId == REC.issuer_staff_id);
    var IS_A_ARCI = (function () {
        var arci = REC.arci || [];
        for (var i = 0; i < arci.length; i++) {
            if (arci[i].role === 'A' && String(arci[i].staff_id) === String(CFG.staffId)) {
                return true;
            }
        }
        return false;
    }());
    var TERMINAL_STATUSES = ['Failed', 'Completed', 'Completed with Excellence'];
    var quillEditor = null;
    var arciState = { A: [], R: [], C: [], I: [] };
    var reflinks = [];
    var attachments = [];
    var progressUpdates = [];

    function $(id) { return document.getElementById(id); }
    function money(n) { return 'RM' + (Math.round((Number(n) || 0) * 100) / 100).toFixed(2); }
    function dateOnly(v) { return v ? String(v).substring(0, 10) : ''; }

    function escapeHtml(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }

    function apiCall(action, payload) {
        var body = { action: action };
        if (payload) { for (var k in payload) { if (payload.hasOwnProperty(k)) { body[k] = payload[k]; } } }
        return fetch(CFG.apiUrl, {
            method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body)
        }).then(function (r) { return r.json(); });
    }

    function uploadCall(formData) {
        return fetch(CFG.apiUrl, { method: 'POST', body: formData }).then(function (r) { return r.json(); });
    }

    function setError(id, msg) { var el = $(id); if (el) { el.textContent = msg || ''; } }

    var _confirmModal = null, _confirmCb = null;
    function getConfirmModal() {
        if (!_confirmModal && typeof bootstrap !== 'undefined') {
            _confirmModal = new bootstrap.Modal($('atem-confirm-modal'));
            $('atem-confirm-ok').addEventListener('click', function () {
                var cb = _confirmCb; _confirmCb = null;
                if (_confirmModal) { _confirmModal.hide(); }
                if (cb) { cb(); }
            });
        }
        return _confirmModal;
    }
    function confirmAction(message, onConfirm) {
        $('atem-confirm-message').textContent = message;
        _confirmCb = onConfirm;
        var m = getConfirmModal();
        if (m) { m.show(); } else { onConfirm(); }
    }

    function armOrConfirm(el, onConfirm) {
        if (el.getAttribute('data-confirming') === '1') {
            if (el._t) { clearTimeout(el._t); el._t = null; }
            onConfirm();
            return;
        }
        el.setAttribute('data-confirming', '1');
        el._orig = el.innerHTML;
        el.innerHTML = 'confirm?';
        el.classList.add('atem-confirm-x');
        el._t = setTimeout(function () {
            el.setAttribute('data-confirming', '0');
            el.innerHTML = el._orig;
            el.classList.remove('atem-confirm-x');
            el._t = null;
        }, 3000);
    }

    // --------------------------------------------------------------- Quill RTE
    function initEditor() {
        if (typeof Quill === 'undefined') { return; }
        quillEditor = new Quill('#atem-description-editor', {
            theme: 'snow',
            modules: {
                toolbar: {
                    container: [
                        [{ 'header': [1, 2, 3, false] }],
                        ['bold', 'italic', 'underline', 'strike'],
                        [{ 'color': [] }, { 'background': [] }],
                        [{ 'list': 'ordered' }, { 'list': 'bullet' }],
                        [{ 'indent': '-1' }, { 'indent': '+1' }],
                        [{ 'align': [] }],
                        ['link', 'image'],
                        ['clean']
                    ],
                    handlers: {
                        'link': function (value) {
                            if (value) { var href = prompt('Enter the URL:'); if (href) { this.quill.format('link', href); } }
                            else { this.quill.format('link', false); }
                        },
                        'image': function () {
                            var input = document.createElement('input');
                            input.setAttribute('type', 'file'); input.setAttribute('accept', 'image/*'); input.click();
                            var q = this.quill;
                            input.onchange = function () {
                                var file = input.files[0];
                                if (file) {
                                    var reader = new FileReader();
                                    reader.onload = function (e) {
                                        var range = q.getSelection(true);
                                        q.insertEmbed(range.index, 'image', e.target.result, 'user');
                                        q.setSelection(range.index + 1);
                                    };
                                    reader.readAsDataURL(file);
                                }
                            };
                        }
                    }
                }
            },
            placeholder: READ ? '' : 'Write the ATEM description in details here....'
        });
    }

    // --------------------------------------------------------------- dropdowns
    function fillSelect(select, items, valueKey, labelFn, placeholder) {
        if (!select) { return; }
        select.innerHTML = '';
        var opt = document.createElement('option');
        opt.value = ''; opt.textContent = placeholder;
        select.appendChild(opt);
        for (var i = 0; i < items.length; i++) {
            var o = document.createElement('option');
            o.value = items[i][valueKey]; o.textContent = labelFn(items[i]);
            select.appendChild(o);
        }
    }

    function populateLookups() {
        fillSelect($('atem-level'), CFG.levels || [], 'id', function (l) {
            return l.level + ' - ' + l.system_name + ' (RM' + Number(l.incentive_value).toFixed(0) + ')';
        }, 'Select level');
        fillSelect($('atem-rule'), CFG.rules || [], 'id', function (r) { return r.code + ' - ' + r.system_label; }, 'Select rule');
        fillSelect($('tl-status'), CFG.statuses || [], 'id', function (s) { return s.value; }, 'Select status');
    }

    function selectedLevel() {
        var id = $('atem-level').value, levels = CFG.levels || [];
        for (var i = 0; i < levels.length; i++) { if (String(levels[i].id) === String(id)) { return levels[i]; } }
        return null;
    }
    function selectedRule() {
        var id = $('atem-rule').value, rules = CFG.rules || [];
        for (var i = 0; i < rules.length; i++) { if (String(rules[i].id) === String(id)) { return rules[i]; } }
        return null;
    }

    function recalcIncentive() {
        var level = selectedLevel(), rule = selectedRule();
        var base = level ? Number(level.incentive_value) : 0;
        var ruleSelect = $('atem-rule'), note = $('inc-note');
        if (!READ) {
            if (level && base === 0) { ruleSelect.value = ''; ruleSelect.setAttribute('disabled', 'disabled'); }
            else { ruleSelect.removeAttribute('disabled'); }
        }
        var ruleStar = $('rule-req-star');
        if (ruleStar) { ruleStar.style.display = (base > 0) ? '' : 'none'; }
        rule = selectedRule();
        var rCount = (arciState.R) ? arciState.R.length : 0;
        var a = 0, r = 0;
        if (base > 0 && rule) { a = base; r = (String(rule.code).toLowerCase() === 'rule 2') ? base * 0.5 * rCount : 0; }
        $('inc-base').textContent = money(base);
        $('inc-a').textContent = money(a);
        $('inc-r').textContent = money(r);
        $('inc-total').textContent = money(a + r);
        var rLabel = $('inc-r-label');
        if (rLabel) { rLabel.textContent = rCount > 1 ? 'R · Responsible ×' + rCount : 'R · Responsible'; }
        if (!level) { note.textContent = 'Select an ATEM level and rule to calculate incentive.'; }
        else if (base === 0) { note.textContent = 'Level 1 carries no incentive payout.'; }
        else if (!rule) { note.textContent = 'Select an incentive rule (required for Level 2-4).'; }
        else { note.textContent = 'Projected amounts. Claimable only on a completed closure.'; }
    }

    // --------------------------------------------------------------- timeline
    function recalcFinalDue() {
        if (!$('tl-final-due')) { return; }
        var v = $('tl-end').value;
        if ($('tl-ext1') && $('tl-ext1').value) { v = $('tl-ext1').value; }
        if ($('tl-ext2') && $('tl-ext2').value) { v = $('tl-ext2').value; }
        $('tl-final-due').value = v || '';
    }
    function recalcClosureDate() {
        var closureEl = $('tl-closure');
        if (!closureEl) { return; }
        var selId = $('tl-status') ? $('tl-status').value : '';
        var selVal = '';
        (CFG.statuses || []).forEach(function (s) {
            if (String(s.id) === String(selId)) { selVal = s.value; }
        });
        if (TERMINAL_STATUSES.indexOf(selVal) >= 0) {
            if (!closureEl.value) {
                closureEl.value = new Date().toISOString().substring(0, 10);
            }
        } else {
            closureEl.value = '';
        }
    }
    function syncExtensionFields() {
        var on = $('tl-extended').checked;
        var w1 = $('tl-ext1-wrap'), w2 = $('tl-ext2-wrap');
        if (!on) {
            w1.style.display = 'none'; w2.style.display = 'none';
            $('tl-ext1').value = ''; $('tl-ext2').value = '';
        } else {
            w1.style.display = '';
            var ext1Val = $('tl-ext1').value;
            var today = new Date(); today.setHours(0, 0, 0, 0);
            var showExt2 = ext1Val && (today > new Date(ext1Val + 'T00:00:00'));
            w2.style.display = showExt2 ? '' : 'none';
            if (!showExt2) { $('tl-ext2').value = ''; }
        }
        recalcFinalDue();
    }

    // ------------------------------------------------------------------- ARCI
    function assignedStaffIds() {
        var ids = [];
        ['A', 'R', 'C', 'I'].forEach(function (role) {
            (arciState[role] || []).forEach(function (m) { ids.push(parseInt(m.staff_id, 10)); });
        });
        return ids;
    }
    function setArciState(grouped) {
        if (!grouped) { return; }
        arciState = { A: grouped.A || [], R: grouped.R || [], C: grouped.C || [], I: grouped.I || [] };
        renderArci();
    }
    function deptName(deptId) {
        var d = CFG.departments || [];
        for (var i = 0; i < d.length; i++) { if (String(d[i].id) === String(deptId)) { return d[i].name; } }
        return '';
    }
    function staffNameIn(deptId, staffId) {
        var list = (CFG.staffByDept && CFG.staffByDept[deptId]) ? CFG.staffByDept[deptId] : [];
        for (var i = 0; i < list.length; i++) { if (parseInt(list[i].id, 10) === parseInt(staffId, 10)) { return list[i].name; } }
        return '';
    }
    function renderArci() {
        var cols = document.querySelectorAll('.atem-arci-members');
        for (var i = 0; i < cols.length; i++) {
            var role = cols[i].getAttribute('data-role');
            var members = arciState[role] || [];
            if (members.length === 0) { cols[i].innerHTML = '<div class="atem-arci-empty">No members assigned</div>'; continue; }
            var html = '';
            for (var m = 0; m < members.length; m++) {
                var mem = members[m];
                var nm = mem.staff_name || staffNameIn(mem.department_id, mem.staff_id) || ('Staff #' + mem.staff_id);
                var dn = mem.department_name || deptName(mem.department_id);
                html += '<div class="atem-arci-member"><div class="atem-arci-member-info">'
                    + '<div class="atem-arci-member-dept">(' + escapeHtml(dn) + ')</div>'
                    + '<div class="atem-arci-member-name">' + escapeHtml(nm) + '</div></div>'
                    + (READ ? '' : '<span class="atem-arci-remove" data-staff="' + parseInt(mem.staff_id, 10) + '" data-role="' + role + '" title="Remove">&times;</span>')
                    + '</div>';
            }
            cols[i].innerHTML = html;
        }
        if (!READ) { renderStaffList(); }
        recalcIncentive();
    }
    function populateDepartments() {
        var sel = $('arci-dept-select'); if (!sel) { return; }
        var depts = CFG.departments || [];
        sel.innerHTML = '<option value="">Select department</option>';
        for (var i = 0; i < depts.length; i++) {
            var o = document.createElement('option'); o.value = depts[i].id; o.textContent = depts[i].name; sel.appendChild(o);
        }
    }
    function filterDepartments() {
        var term = $('arci-dept-search').value.toLowerCase(), opts = $('arci-dept-select').options;
        for (var i = 0; i < opts.length; i++) { if (opts[i].value === '') { continue; } opts[i].hidden = opts[i].textContent.toLowerCase().indexOf(term) < 0; }
    }
    function renderStaffList() {
        var listDiv = $('arci-staff-list'); if (!listDiv) { return; }
        var deptId = $('arci-dept-select').value;
        if (!deptId) { listDiv.innerHTML = '<div class="text-muted" style="font-size:13px;">Select a department to load staff</div>'; return; }
        var staff = (CFG.staffByDept && CFG.staffByDept[deptId]) ? CFG.staffByDept[deptId] : [];
        var assigned = assignedStaffIds(), term = $('arci-staff-search').value.toLowerCase(), html = '';
        for (var i = 0; i < staff.length; i++) {
            if (assigned.indexOf(parseInt(staff[i].id, 10)) >= 0) { continue; }
            if (term && staff[i].name.toLowerCase().indexOf(term) < 0) { continue; }
            html += '<label class="atem-arci-staff-item"><input type="checkbox" value="' + parseInt(staff[i].id, 10) + '" data-name="' + escapeHtml(staff[i].name) + '"> <span>' + escapeHtml(staff[i].name) + '</span></label>';
        }
        listDiv.innerHTML = html || '<div class="text-muted" style="font-size:13px;">No staff available</div>';
    }
    function addSelectedMembers() {
        setError('arci-error', '');
        var role = $('arci-role').value;
        if (!role) { setError('arci-error', 'Please select a role first.'); return; }
        var deptId = $('arci-dept-select').value;
        var checks = $('arci-staff-list').querySelectorAll('input[type="checkbox"]:checked');
        if (checks.length === 0) { setError('arci-error', 'Please select at least one staff member.'); return; }
        if (role === 'A' && (checks.length > 1 || (arciState.A && arciState.A.length > 0))) { setError('arci-error', 'Role A (Accountable) can only have one person.'); return; }
        var queue = [];
        for (var i = 0; i < checks.length; i++) { queue.push(parseInt(checks[i].value, 10)); }
        function next() {
            if (queue.length === 0) { $('arci-role').value = ''; $('arci-dept-select').value = ''; $('arci-staff-search').value = ''; renderStaffList(); return; }
            var sid = queue.shift();
            apiCall('arci-add', { id: CFG.atemId, data: { staff_id: sid, department_id: deptId ? parseInt(deptId, 10) : null, role: role } }).then(function (res) {
                if (res && res.success) { setArciState(res.data); } else { setError('arci-error', res && res.message ? res.message : 'Failed to add member.'); }
                next();
            }).catch(function () { setError('arci-error', 'Network error while adding member.'); next(); });
        }
        next();
    }
    function removeMember(staffId, role) {
        apiCall('arci-remove', { id: CFG.atemId, staff_id: staffId, role: role }).then(function (res) {
            if (res && res.success) { setArciState(res.data); } else { setError('arci-error', res && res.message ? res.message : 'Failed to remove member.'); }
        });
    }
    var _pendingClear = {};
    function clearRole(role, btn) {
        if (_pendingClear[role]) {
            clearTimeout(_pendingClear[role]); delete _pendingClear[role];
            if (btn) { btn.textContent = 'Delete All'; btn.classList.remove('atem-arci-clear-confirm'); }
            apiCall('arci-remove-role', { id: CFG.atemId, role: role }).then(function (res) {
                if (res && res.success) { setArciState(res.data); } else { setError('arci-error', res && res.message ? res.message : 'Failed to clear role.'); }
            });
            return;
        }
        setError('arci-error', '');
        if (btn) { btn.textContent = 'Click again to confirm'; btn.classList.add('atem-arci-clear-confirm'); }
        _pendingClear[role] = setTimeout(function () {
            delete _pendingClear[role];
            if (btn) { btn.textContent = 'Delete All'; btn.classList.remove('atem-arci-clear-confirm'); }
        }, 3000);
    }

    // ------------------------------------------------------- reference links
    var _reflinkModal = null;
    function getReflinkModal() {
        if (!_reflinkModal && typeof bootstrap !== 'undefined') { _reflinkModal = new bootstrap.Modal($('atem-reflink-modal')); }
        return _reflinkModal;
    }
    function renderReferenceLinks() {
        var wrap = $('atem-reflink-list');
        if (!reflinks.length) { wrap.innerHTML = '<div class="atem-empty-state">No Reference Link added.</div>'; return; }
        var html = '<ol class="atem-reflink-ol">';
        for (var i = 0; i < reflinks.length; i++) {
            html += '<li><div class="atem-reflink-row"><a href="' + escapeHtml(reflinks[i].url) + '" target="_blank" rel="noopener">' + escapeHtml(reflinks[i].name) + '</a>'
                + (READ ? '' : '<span class="atem-reflink-remove" data-id="' + parseInt(reflinks[i].id, 10) + '" title="Remove">&times;</span>') + '</div></li>';
        }
        html += '</ol>';
        wrap.innerHTML = html;
    }
    function openReflinkModal() {
        $('reflink-name').value = ''; $('reflink-url').value = ''; setError('reflink-error', '');
        var m = getReflinkModal(); if (m) { m.show(); }
    }
    function saveReferenceLink() {
        var name = $('reflink-name').value.trim(), url = $('reflink-url').value.trim();
        if (!name || !url) { setError('reflink-error', 'Please fill in both Name and URL.'); return; }
        try { new URL(url); } catch (e) { setError('reflink-error', 'Please enter a valid URL.'); return; }
        apiCall('reflink-add', { id: CFG.atemId, data: { name: name, url: url } }).then(function (res) {
            if (res && res.success) { reflinks = res.data || []; renderReferenceLinks(); var m = getReflinkModal(); if (m) { m.hide(); } }
            else { setError('reflink-error', (res && res.message) ? res.message : 'Failed to add reference link.'); }
        }).catch(function () { setError('reflink-error', 'Network error while adding reference link.'); });
    }
    function removeReferenceLink(linkId) {
        apiCall('reflink-remove', { id: CFG.atemId, link_id: linkId }).then(function (res) {
            if (res && res.success) { reflinks = res.data || []; renderReferenceLinks(); }
        });
    }

    // ------------------------------------------------------------ attachments
    var ALLOWED_EXT = ['jpg', 'jpeg', 'png', 'gif', 'pdf', 'doc', 'docx', 'xls', 'xlsx', 'txt'];
    var MAX_BYTES = 10 * 1024 * 1024;
    function formatFileSize(bytes) {
        if (!bytes) { return '0 Bytes'; }
        var k = 1024, sizes = ['Bytes', 'KB', 'MB', 'GB'], i = Math.floor(Math.log(bytes) / Math.log(k));
        return Math.round(bytes / Math.pow(k, i) * 100) / 100 + ' ' + sizes[i];
    }
    function fileExt(name) { var i = name.lastIndexOf('.'); return i >= 0 ? name.substr(i + 1).toLowerCase() : ''; }
    function renderAttachments() {
        var wrap = $('atem-attachment-list');
        if (!attachments.length) { wrap.innerHTML = '<div class="atem-empty-state">No attachments.</div>'; return; }
        var html = '';
        for (var i = 0; i < attachments.length; i++) {
            var a = attachments[i];
            var dl = CFG.apiUrl + '?action=attachment-download&id=' + parseInt(CFG.atemId, 10) + '&att=' + parseInt(a.id, 10);
            html += '<div class="atem-attachment-row"><a class="atem-file-name" href="' + dl + '" target="_blank" rel="noopener">' + escapeHtml(a.name) + '</a> '
                + '<span class="atem-file-size">(' + formatFileSize(a.size) + ')</span>'
                + (READ ? '' : '<span class="atem-file-remove" data-att="' + parseInt(a.id, 10) + '" title="Remove">&times;</span>') + '</div>';
        }
        wrap.innerHTML = html;
    }
    function uploadFiles(fileList) {
        setError('atem-file-error', '');
        var queue = [];
        for (var i = 0; i < fileList.length; i++) {
            var f = fileList[i];
            if (ALLOWED_EXT.indexOf(fileExt(f.name)) < 0) { setError('atem-file-error', f.name + ': file type not allowed.'); continue; }
            if (f.size > MAX_BYTES) { setError('atem-file-error', f.name + ': exceeds 10MB.'); continue; }
            queue.push(f);
        }
        function next() {
            if (queue.length === 0) { return; }
            var f = queue.shift();
            var fd = new FormData(); fd.append('action', 'attachment-upload'); fd.append('id', CFG.atemId); fd.append('file', f);
            uploadCall(fd).then(function (res) {
                if (res && res.success) { attachments = res.data || []; renderAttachments(); }
                else { setError('atem-file-error', (res && res.message) ? res.message : 'Upload failed.'); }
                next();
            }).catch(function () { setError('atem-file-error', 'Network error during upload.'); next(); });
        }
        next();
    }
    function deleteAttachment(attId) {
        apiCall('attachment-remove', { id: CFG.atemId, att_id: attId }).then(function (res) {
            if (res && res.success) { attachments = res.data || []; renderAttachments(); }
        });
    }
    function bindAttachmentZone() {
        var dz = $('atem-dropzone'), fi = $('atem-file-input');
        if (dz && fi) {
            $('atem-file-pick').addEventListener('click', function (e) { e.preventDefault(); e.stopPropagation(); fi.click(); });
            dz.addEventListener('click', function () { fi.click(); });
            fi.addEventListener('change', function () { uploadFiles(fi.files); fi.value = ''; });
            ['dragenter', 'dragover'].forEach(function (ev) { dz.addEventListener(ev, function (e) { e.preventDefault(); e.stopPropagation(); dz.classList.add('atem-dropzone-active'); }); });
            ['dragleave', 'drop'].forEach(function (ev) { dz.addEventListener(ev, function (e) { e.preventDefault(); e.stopPropagation(); dz.classList.remove('atem-dropzone-active'); }); });
            dz.addEventListener('drop', function (e) { if (e.dataTransfer && e.dataTransfer.files) { uploadFiles(e.dataTransfer.files); } });
        }
        var alist = $('atem-attachment-list');
        if (alist) {
            alist.addEventListener('click', function (e) {
                if (e.target.classList.contains('atem-file-remove')) {
                    var attId = parseInt(e.target.getAttribute('data-att'), 10);
                    confirmAction('Remove this attachment?', function () { deleteAttachment(attId); });
                }
            });
        }
    }

    // ----------------------------------------------------------- progress updates
    var _progressEditing = false;

    var PROGRESS_STATUS_LABELS = { red: 'Red', yellow: 'Yellow', green: 'Green' };

    function progressDateMax() {
        var finalDue = dateOnly(REC.final_due_date);
        return finalDue || '';
    }

    function progressDateMin() {
        return dateOnly(REC.start_date) || '';
    }

    function validateProgressRow(startVal, endVal) {
        var min = progressDateMin(), max = progressDateMax();
        if (!startVal) { return 'Start Date is required.'; }
        if (!endVal)   { return 'End Date is required.'; }
        if (min && startVal < min) { return 'Start Date cannot be earlier than the ATEM start date (' + min + ').'; }
        if (max && endVal > max)   { return 'End Date cannot be later than the final due date (' + max + ').'; }
        if (endVal < startVal)     { return 'End Date cannot be earlier than Start Date.'; }
        return '';
    }

    function formatDate(v) {
        if (!v) { return ''; }
        var d = new Date(v + 'T00:00:00');
        if (isNaN(d.getTime())) { return v; }
        var months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
        return d.getDate() + ' ' + months[d.getMonth()] + ' ' + d.getFullYear();
    }

    function progressStatusBtnsHtml(containerId, selectedVal) {
        var statuses = ['red', 'yellow', 'green'];
        var html = '<div class="atem-status-btn-group" id="' + containerId + '-btns">';
        for (var i = 0; i < statuses.length; i++) {
            var s = statuses[i];
            var onCls = (s === selectedVal) ? ' atem-status-btn-on' : '';
            html += '<button type="button" class="atem-status-btn atem-status-btn-' + s + onCls + '" data-status="' + s + '">'
                + PROGRESS_STATUS_LABELS[s] + '</button>';
        }
        html += '</div>';
        html += '<input type="hidden" id="' + containerId + '-status" value="' + (selectedVal || '') + '">';
        return html;
    }

    function progressInputFormHtml(rowId, p) {
        var start  = p ? p.start_date    : '';
        var end    = p ? p.end_date      : '';
        var status = p ? p.status        : '';
        var remark = p ? escapeHtml(p.remark || '') : '';
        var min = progressDateMin(), max = progressDateMax();
        var minAttr = min ? ' min="' + min + '"' : '';
        var maxAttr = max ? ' max="' + max + '"' : '';
        return '<div class="atem-progress-form-grid">'
            + '<div><label class="form-label" style="font-size:12px;font-weight:600;margin-bottom:4px;">Start Date</label>'
            + '<input type="date" class="form-control form-control-sm" id="' + rowId + '-start" value="' + start + '"' + minAttr + maxAttr + '></div>'
            + '<div><label class="form-label" style="font-size:12px;font-weight:600;margin-bottom:4px;">End Date</label>'
            + '<input type="date" class="form-control form-control-sm" id="' + rowId + '-end" value="' + end + '"' + minAttr + maxAttr + '></div>'
            + '<div><label class="form-label" style="font-size:12px;font-weight:600;margin-bottom:4px;">Status</label>'
            + progressStatusBtnsHtml(rowId, status) + '</div>'
            + '</div>'
            + '<div class="mt-2"><label class="form-label" style="font-size:12px;font-weight:600;margin-bottom:4px;">Task</label>'
            + '<textarea class="form-control form-control-sm" id="' + rowId + '-remark" rows="2" style="resize:vertical;">' + remark + '</textarea></div>'
            + '<div class="atem-progress-form-actions">'
            + '<button type="button" class="btn btn-primary btn-sm atem-progress-save" data-row="' + rowId + '" data-id="' + (p ? p.id : '') + '">Save</button>'
            + '<button type="button" class="btn btn-outline-secondary btn-sm atem-progress-cancel" data-row="' + rowId + '">Cancel</button>'
            + '</div>';
    }

    function renderProgress() {
        var wrap = $('atem-progress-wrap');
        if (!wrap) { return; }
        if (!progressUpdates.length) {
            wrap.innerHTML = '<div class="atem-empty-state">No progress updates recorded.</div>';
            return;
        }
        var html = '<div class="atem-progress-grid">';
        for (var i = 0; i < progressUpdates.length; i++) {
            var p = progressUpdates[i];
            var pillClass = 'atem-pill atem-pill-' + p.status;
            var actionsHtml = READ ? '' : '<div class="atem-progress-item-actions">'
                + '<button type="button" class="btn btn-outline-secondary btn-sm atem-progress-edit" data-id="' + p.id + '">Edit</button>'
                + '<button type="button" class="btn btn-outline-danger btn-sm atem-progress-delete" data-id="' + p.id + '">Delete</button>'
                + '</div>';
            var remarkHtml = p.remark
                ? '<div class="atem-progress-item-remark">' + escapeHtml(p.remark) + '</div>'
                : '';
            var creatorHtml = p.created_by_name
                ? '<div class="atem-progress-item-creator">Added by: ' + escapeHtml(p.created_by_name) + '</div>'
                : '';
            html += '<div class="atem-progress-item atem-progress-item-' + p.status + '" data-pid="' + p.id + '">'
                + '<div class="atem-progress-item-header">'
                + '<span class="atem-progress-item-num">' + (i + 1) + '</span>'
                + '<span class="atem-progress-item-dates">' + escapeHtml(formatDate(p.start_date)) + ' &rarr; ' + escapeHtml(formatDate(p.end_date)) + '</span>'
                + '<span class="' + pillClass + '">' + PROGRESS_STATUS_LABELS[p.status] + '</span>'
                + actionsHtml
                + '</div>'
                + remarkHtml
                + creatorHtml
                + '</div>';
        }
        html += '</div>';
        wrap.innerHTML = html;
    }

    function startAddProgressRow() {
        if (_progressEditing) { return; }
        _progressEditing = true;
        var addBtn = $('atem-add-progress-btn');
        if (addBtn) { addBtn.disabled = true; }
        setError('progress-error', '');

        var wrap = $('atem-progress-wrap');
        var rowId = 'progress-new';

        var grid = wrap.querySelector('.atem-progress-grid');
        if (!grid) {
            wrap.innerHTML = '<div class="atem-progress-grid"></div>';
            grid = wrap.querySelector('.atem-progress-grid');
        }

        var card = document.createElement('div');
        card.id = rowId + '-row';
        card.className = 'atem-progress-item atem-progress-form-item';
        card.innerHTML = progressInputFormHtml(rowId, null);
        grid.appendChild(card);

        var startEl = document.getElementById(rowId + '-start');
        if (startEl) { startEl.focus(); }
    }

    function cancelProgressRow(rowId) {
        var row = document.getElementById(rowId + '-row');
        if (row) { row.parentNode.removeChild(row); }
        _progressEditing = false;
        var addBtn = $('atem-add-progress-btn');
        if (addBtn) { addBtn.disabled = false; }
        renderProgress();
    }

    function saveProgressRow(rowId, progressId) {
        var startVal  = (document.getElementById(rowId + '-start')  || {}).value || '';
        var endVal    = (document.getElementById(rowId + '-end')    || {}).value || '';
        var remarkVal = (document.getElementById(rowId + '-remark') || {}).value || '';
        var statusEl  = document.getElementById(rowId + '-status');
        var statusVal = statusEl ? statusEl.value : '';

        setError('progress-error', '');
        var err = validateProgressRow(startVal, endVal);
        if (err) { setError('progress-error', err); return; }
        if (!statusVal) { setError('progress-error', 'Please select a status.'); return; }

        var payload = { start_date: startVal, end_date: endVal, status: statusVal, remark: remarkVal };

        var saveBtn = document.querySelector('.atem-progress-save[data-row="' + rowId + '"]');
        if (saveBtn) { saveBtn.disabled = true; saveBtn.textContent = 'Saving...'; }

        var action = progressId ? 'progress-update' : 'progress-add';
        var body = { id: CFG.atemId, data: payload };
        if (progressId) { body.progress_id = progressId; }

        apiCall(action, body).then(function (res) {
            if (res && res.success) {
                progressUpdates = res.data || [];
                _progressEditing = false;
                var addBtn = $('atem-add-progress-btn');
                if (addBtn) { addBtn.disabled = false; }
                renderProgress();
            } else {
                setError('progress-error', (res && res.message) ? res.message : 'Failed to save progress update.');
                if (saveBtn) { saveBtn.disabled = false; saveBtn.textContent = 'Save'; }
            }
        }).catch(function () {
            setError('progress-error', 'Network error while saving progress update.');
            if (saveBtn) { saveBtn.disabled = false; saveBtn.textContent = 'Save'; }
        });
    }

    function startEditProgressRow(progressId) {
        if (_progressEditing) { return; }
        _progressEditing = true;
        var addBtn = $('atem-add-progress-btn');
        if (addBtn) { addBtn.disabled = true; }
        setError('progress-error', '');

        var p = null;
        for (var i = 0; i < progressUpdates.length; i++) {
            if (parseInt(progressUpdates[i].id, 10) === progressId) { p = progressUpdates[i]; break; }
        }
        if (!p) { _progressEditing = false; return; }

        var rowId = 'progress-edit-' + progressId;
        var card = document.querySelector('[data-pid="' + progressId + '"]');
        if (!card) { _progressEditing = false; return; }
        card.id = rowId + '-row';
        card.className = 'atem-progress-item atem-progress-form-item';
        card.innerHTML = progressInputFormHtml(rowId, p);
    }

    function deleteProgressRow(progressId) {
        confirmAction('Remove this progress update?', function () {
            apiCall('progress-remove', { id: CFG.atemId, progress_id: progressId }).then(function (res) {
                if (res && res.success) {
                    progressUpdates = res.data || [];
                    renderProgress();
                } else {
                    setError('progress-error', (res && res.message) ? res.message : 'Failed to remove progress update.');
                }
            }).catch(function () {
                setError('progress-error', 'Network error while removing progress update.');
            });
        });
    }

    function bindProgressWrap() {
        var wrap = $('atem-progress-wrap');
        if (!wrap) { return; }
        wrap.addEventListener('click', function (e) {
            var t = e.target;
            if (t.classList.contains('atem-status-btn')) {
                var btnGroup = t.closest('.atem-status-btn-group');
                if (btnGroup) {
                    var btns = btnGroup.querySelectorAll('.atem-status-btn');
                    for (var bi = 0; bi < btns.length; bi++) { btns[bi].classList.remove('atem-status-btn-on'); }
                    t.classList.add('atem-status-btn-on');
                    var hid = document.getElementById(btnGroup.id.replace('-btns', '-status'));
                    if (hid) { hid.value = t.getAttribute('data-status'); }
                    var formCard = t.closest('.atem-progress-form-item');
                    if (formCard) {
                        formCard.classList.remove('atem-progress-item-red', 'atem-progress-item-yellow', 'atem-progress-item-green');
                        formCard.classList.add('atem-progress-item-' + t.getAttribute('data-status'));
                    }
                }
            } else if (t.classList.contains('atem-progress-save')) {
                var rowId = t.getAttribute('data-row');
                var pid = t.getAttribute('data-id');
                saveProgressRow(rowId, pid ? parseInt(pid, 10) : null);
            } else if (t.classList.contains('atem-progress-cancel')) {
                cancelProgressRow(t.getAttribute('data-row'));
            } else if (t.classList.contains('atem-progress-edit')) {
                startEditProgressRow(parseInt(t.getAttribute('data-id'), 10));
            } else if (t.classList.contains('atem-progress-delete')) {
                deleteProgressRow(parseInt(t.getAttribute('data-id'), 10));
            }
        });
    }

    // ------------------------------------------------------------------- save
    function validateFinal() {
        setError('atem-title-error', ''); setError('atem-level-error', ''); setError('atem-rule-error', '');
        setError('tl-start-error', ''); setError('tl-end-error', ''); setError('tl-status-error', '');
        setError('atem-save-error', '');
        if (!$('atem-title').value.trim()) { setError('atem-title-error', 'ATEM Title is required.'); return false; }
        if (!$('atem-level').value) { setError('atem-level-error', 'ATEM Level is required.'); return false; }
        if (!$('tl-start').value) { setError('tl-start-error', 'Start Date is required.'); return false; }
        if (!$('tl-end').value) { setError('tl-end-error', 'End Date is required.'); return false; }
        if (!$('tl-status').value) {
            setError('tl-status-error', 'Status is required. Please select a status before saving.');
            return false;
        }
        var originalStatusValue = (REC.status && REC.status.value) ? REC.status.value : '';
        var MUST_CHANGE = ['Draft'];
        if (IS_ISSUER && MUST_CHANGE.indexOf(originalStatusValue) >= 0 && String($('tl-status').value) === String(REC.atem_status_id)) {
            setError('tl-status-error', 'The current status is "' + originalStatusValue + '". Please change the status before saving.');
            return false;
        }
        if ($('tl-extended').checked && $('tl-ext1').value) {
            var selStatusId = $('tl-status').value;
            var selStatusValue = '';
            (CFG.statuses || []).forEach(function (s) {
                if (String(s.id) === String(selStatusId)) { selStatusValue = s.value; }
            });
            if (selStatusValue !== 'Extended') {
                setError('tl-status-error', 'Status must be changed to "Extended" when an extension date is applied.');
                return false;
            }
        }
        var level = selectedLevel();
        if (level && Number(level.incentive_value) > 0 && !$('atem-rule').value) {
            setError('atem-rule-error', 'Incentive Rule is required for Level 2-4.');
            return false;
        }
        return true;
    }
    function saveAtem() {
        if (!validateFinal()) { return; }
        var levelId = $('atem-level').value, ruleId = $('atem-rule').value;
        var description = quillEditor ? ((quillEditor.getText().trim() === '') ? '' : quillEditor.root.innerHTML) : '';
        var data = {
            title: $('atem-title').value.trim(),
            description: description,
            level_structure_id: levelId ? parseInt(levelId, 10) : null,
            incentive_rule_id: ruleId ? parseInt(ruleId, 10) : null,
            start_date: $('tl-start').value || null,
            end_date: $('tl-end').value || null,
            is_extended: $('tl-extended').checked,
            extended_date_1: $('tl-ext1').value || null,
            extended_date_2: $('tl-ext2').value || null,
            atem_status_id: $('tl-status').value ? parseInt($('tl-status').value, 10) : null,
            remarks: $('tl-remarks').value
        };
        var btn = $('atem-save-btn');
        if (btn) { btn.disabled = true; btn.textContent = 'Saving...'; }
        apiCall('update-atem', { id: CFG.atemId, data: data }).then(function (res) {
            if (res && res.success) { window.location.href = 'atem/view.php'; }
            else { setError('atem-save-error', res && res.message ? res.message : 'Failed to save ATEM.'); if (btn) { btn.disabled = false; btn.textContent = 'Save ATEM'; } }
        }).catch(function () { setError('atem-save-error', 'Network error while saving.'); if (btn) { btn.disabled = false; btn.textContent = 'Save ATEM'; } });
    }

    // ----------------------------------------------------------- audit log
    var AUDIT_ICONS = {
        'created':          'bi-star',
        'updated':          'bi-pencil',
        'status_changed':   'bi-tag',
        'arci_added':       'bi-person-plus',
        'arci_removed':     'bi-person-dash',
        'arci_role_cleared':'bi-people',
        'attachment_added': 'bi-paperclip',
        'attachment_removed':'bi-trash',
        'reflink_added':    'bi-link-45deg',
        'reflink_removed':  'bi-link',
        'progress_added':   'bi-bar-chart-steps',
        'progress_updated': 'bi-bar-chart-steps',
        'progress_removed': 'bi-trash'
    };

    function formatDateTime(v) {
        if (!v) { return ''; }
        var d = new Date(v.replace(' ', 'T'));
        if (isNaN(d.getTime())) { return v; }
        var months = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
        var hh = String(d.getHours()).padStart(2, '0');
        var mm = String(d.getMinutes()).padStart(2, '0');
        return d.getDate() + ' ' + months[d.getMonth()] + ' ' + d.getFullYear() + ', ' + hh + ':' + mm;
    }

    function renderAuditLog() {
        var metaEl = $('atem-audit-meta');
        var logEl  = $('atem-audit-log');
        if (!metaEl || !logEl) { return; }

        // Meta summary
        var createdName  = '';
        var updatedName  = '';
        var logs = REC.audit_logs || [];
        // Walk logs to find first created entry for creator name
        for (var li = logs.length - 1; li >= 0; li--) {
            if (logs[li].event === 'created') { createdName = logs[li].actor_name || ''; break; }
        }
        // Last updated: first entry in the list (newest first)
        if (logs.length > 0) { updatedName = logs[0].actor_name || ''; }

        metaEl.innerHTML =
            '<div class="atem-audit-meta-item">'
            + '<strong>Created by</strong> ' + escapeHtml(createdName || 'System')
            + ' &mdash; ' + escapeHtml(formatDateTime(REC.created_at))
            + '</div>'
            + '<div class="atem-audit-meta-item">'
            + '<strong>Last updated by</strong> ' + escapeHtml(updatedName || 'System')
            + ' &mdash; ' + escapeHtml(formatDateTime(REC.updated_at))
            + '</div>';

        // Timeline
        if (!logs.length) {
            logEl.innerHTML = '<div class="atem-empty-state">No activity recorded yet.</div>';
            return;
        }

        var html = '';
        for (var i = 0; i < logs.length; i++) {
            var entry = logs[i];
            var icon = AUDIT_ICONS[entry.event] || 'bi-circle';
            var eventClass = 'atem-audit-' + (entry.event || 'updated').replace(/_/g, '_');
            var actor = escapeHtml(entry.actor_name || 'System');
            var ts    = escapeHtml(formatDateTime(entry.created_at));

            var bodyHtml = '';
            if (entry.changes && entry.changes.length) {
                bodyHtml = '<ul class="atem-audit-changes">';
                for (var c = 0; c < entry.changes.length; c++) {
                    var ch = entry.changes[c];
                    bodyHtml += '<li><strong>' + escapeHtml(ch.label) + '</strong>: '
                        + (ch.from ? '"' + escapeHtml(ch.from) + '" &rarr; ' : '')
                        + '"' + escapeHtml(ch.to) + '"</li>';
                }
                bodyHtml += '</ul>';
            } else if (entry.summary) {
                bodyHtml = '<div class="atem-audit-summary">' + escapeHtml(entry.summary) + '</div>';
            }

            html += '<div class="atem-audit-entry ' + eventClass + '">'
                + '<div class="atem-audit-icon"><i class="bi ' + icon + '"></i></div>'
                + '<div class="atem-audit-body">'
                + '<div class="atem-audit-header"><span style="font-size:12px;">' + ts + '</span> &mdash; <strong>' + actor + '</strong></div>'
                + bodyHtml
                + '</div>'
                + '</div>';
        }
        logEl.innerHTML = html;
    }

    // ------------------------------------------------------------- hydrate
    function hydrate() {
        $('atem-title').value = REC.title || '';
        if (REC.level_structure_id) { $('atem-level').value = REC.level_structure_id; }
        if (REC.incentive_rule_id) { $('atem-rule').value = REC.incentive_rule_id; }
        $('tl-start').value = dateOnly(REC.start_date);
        $('tl-end').value = dateOnly(REC.end_date);
        if ($('tl-status') && REC.atem_status_id) { $('tl-status').value = REC.atem_status_id; }
        if ($('tl-final-due')) { $('tl-final-due').value = dateOnly(REC.final_due_date); }
        if ($('tl-closure')) { $('tl-closure').value = dateOnly(REC.closure_date); }
        if ($('tl-remarks')) { $('tl-remarks').value = REC.remarks || ''; }
        if ($('tl-extended')) {
            $('tl-extended').checked = !!REC.is_extended;
            if (REC.is_extended) {
                $('tl-ext1-wrap').style.display = '';
                $('tl-ext1').value = dateOnly(REC.extended_date_1);
                if (READ) {
                    // Read mode: show ext2 whenever the record has a value for it.
                    if (REC.extended_date_2) { $('tl-ext2-wrap').style.display = ''; $('tl-ext2').value = dateOnly(REC.extended_date_2); }
                } else {
                    // Edit mode: pre-fill ext2 then let syncExtensionFields decide visibility based on date.
                    if (REC.extended_date_2) { $('tl-ext2').value = dateOnly(REC.extended_date_2); }
                    syncExtensionFields();
                }
            }
        }
        if (quillEditor && REC.description) { quillEditor.clipboard.dangerouslyPasteHTML(REC.description); }

        var grouped = { A: [], R: [], C: [], I: [] };
        (REC.arci || []).forEach(function (m) { if (grouped[m.role]) { grouped[m.role].push(m); } });
        arciState = grouped;
        reflinks = REC.reference_links || [];
        attachments = REC.attachments || [];
        progressUpdates = REC.progress || [];

        renderArci();
        renderReferenceLinks();
        renderAttachments();
        renderProgress();
        renderAuditLog();
        recalcIncentive();
    }

    function injectBadge() {
        // Only a Draft-status card gets a badge beside the title; others show none.
        var statusValue = REC.status ? REC.status.value : null;
        if (statusValue !== 'Draft') { return; }
        var title = document.querySelector('.atem-page-title');
        if (title) {
            var span = document.createElement('span');
            span.className = 'atem-pill atem-pill-draft';
            span.textContent = 'Draft';
            title.appendChild(document.createTextNode(' '));
            title.appendChild(span);
        }
    }

    function lockDateFields() {
        if (READ) { return; }
        if ($('tl-start') && $('tl-start').value) { $('tl-start').setAttribute('disabled', 'disabled'); }
        if ($('tl-end') && $('tl-end').value) { $('tl-end').setAttribute('disabled', 'disabled'); }
        var ext1El = $('tl-ext1');
        if (ext1El && ext1El.value) {
            var today = new Date(); today.setHours(0, 0, 0, 0);
            if (today > new Date(ext1El.value + 'T00:00:00')) {
                ext1El.setAttribute('disabled', 'disabled');
            }
        }
    }

    function applyReadMode() {
        if (!READ) { return; }
        if (quillEditor) { quillEditor.disable(); }
        ['atem-title', 'atem-issuer', 'atem-department', 'atem-level', 'atem-rule', 'tl-start', 'tl-end',
            'tl-status', 'tl-final-due', 'tl-closure', 'tl-remarks', 'tl-extended', 'tl-ext1', 'tl-ext2'].forEach(function (id) {
            var el = $(id);
            if (el) { el.setAttribute('disabled', 'disabled'); }
        });
    }

    // --------------------------------------------------------------- wiring
    function bind() {
        $('atem-level').addEventListener('change', recalcIncentive);
        $('atem-rule').addEventListener('change', recalcIncentive);
        if ($('tl-end')) { $('tl-end').addEventListener('change', recalcFinalDue); }
        if ($('tl-extended')) { $('tl-extended').addEventListener('change', syncExtensionFields); }
        if ($('tl-ext1')) { $('tl-ext1').addEventListener('change', function () { syncExtensionFields(); }); }
        if ($('tl-ext2')) { $('tl-ext2').addEventListener('change', recalcFinalDue); }
        if ($('tl-status')) { $('tl-status').addEventListener('change', recalcClosureDate); }

        if ($('arci-dept-search')) { $('arci-dept-search').addEventListener('keyup', filterDepartments); }
        if ($('arci-dept-select')) { $('arci-dept-select').addEventListener('change', renderStaffList); }
        if ($('arci-staff-search')) { $('arci-staff-search').addEventListener('keyup', renderStaffList); }
        if ($('arci-add-btn')) { $('arci-add-btn').addEventListener('click', addSelectedMembers); }

        var grid = $('arci-grid');
        if (grid) {
            grid.addEventListener('click', function (e) {
                var t = e.target;
                if (t.classList.contains('atem-arci-remove')) {
                    var sId = parseInt(t.getAttribute('data-staff'), 10), sRole = t.getAttribute('data-role');
                    armOrConfirm(t, function () { removeMember(sId, sRole); });
                } else if (t.classList.contains('atem-arci-clear')) {
                    clearRole(t.getAttribute('data-role'), t);
                }
            });
        }

        if ($('atem-add-reflink-btn')) { $('atem-add-reflink-btn').addEventListener('click', openReflinkModal); }
        if ($('reflink-save-btn')) { $('reflink-save-btn').addEventListener('click', saveReferenceLink); }
        var rl = $('atem-reflink-list');
        if (rl) {
            rl.addEventListener('click', function (e) {
                if (e.target.classList.contains('atem-reflink-remove')) {
                    var linkId = parseInt(e.target.getAttribute('data-id'), 10);
                    confirmAction('Remove this reference link?', function () { removeReferenceLink(linkId); });
                }
            });
        }

        bindAttachmentZone();
        bindProgressWrap();
        if ($('atem-add-progress-btn')) { $('atem-add-progress-btn').addEventListener('click', startAddProgressRow); }

        if ($('atem-save-btn')) {
            $('atem-save-btn').addEventListener('click', function () {
                var selId = $('tl-status') ? $('tl-status').value : '';
                var selVal = '';
                (CFG.statuses || []).forEach(function (s) {
                    if (String(s.id) === String(selId)) { selVal = s.value; }
                });
                if (TERMINAL_STATUSES.indexOf(selVal) >= 0) {
                    var msgEl = $('atem-terminal-warn-msg');
                    if (msgEl) {
                        msgEl.textContent = 'You are about to set this ATEM to "' + selVal + '". Once saved, the ATEM will be locked and cannot be edited further. Do you want to proceed?';
                    }
                    var warnModal = new bootstrap.Modal($('atem-terminal-warn-modal'));
                    var okBtn = $('atem-terminal-warn-ok');
                    if (okBtn) {
                        var handler = function () {
                            okBtn.removeEventListener('click', handler);
                            warnModal.hide();
                            saveAtem();
                        };
                        okBtn.addEventListener('click', handler);
                    }
                    warnModal.show();
                } else {
                    saveAtem();
                }
            });
        }

        if ($('atem-delete-btn')) { $('atem-delete-btn').addEventListener('click', deleteAtem); }
    }

    function deleteAtem() {
        var okBtn = $('atem-confirm-ok');
        if (okBtn) { okBtn.textContent = 'Delete'; }
        confirmAction(
            'Delete this ATEM permanently? All associated data will be removed and this action cannot be undone.',
            function () {
                if (okBtn) { okBtn.textContent = 'Remove'; }
                apiCall('delete-atem', { id: CFG.atemId }).then(function (res) {
                    if (res && res.success) {
                        window.location.href = 'atem/view.php';
                    } else {
                        setError('atem-save-error', res && res.message ? res.message : 'Failed to delete ATEM.');
                    }
                }).catch(function () {
                    setError('atem-save-error', 'Network error while deleting.');
                });
            }
        );
        var dismissBtns = document.querySelectorAll('#atem-confirm-modal [data-bs-dismiss="modal"]');
        for (var i = 0; i < dismissBtns.length; i++) {
            dismissBtns[i].addEventListener('click', function () {
                if (okBtn) { okBtn.textContent = 'Remove'; }
            }, { once: true });
        }
    }

    document.addEventListener('DOMContentLoaded', function () {
        populateLookups();
        populateDepartments();
        initEditor();
        bind();
        hydrate();
        lockDateFields();
        injectBadge();
        applyReadMode();
        if (!READ && !IS_ISSUER && !IS_A_ARCI) {
            ['tl-status', 'tl-extended', 'tl-ext1', 'tl-ext2', 'tl-remarks'].forEach(function (id) {
                var el = document.getElementById(id);
                if (el) { el.disabled = true; }
            });
        }
        recalcClosureDate();
    });
})();
