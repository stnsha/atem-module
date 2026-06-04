/* ATEM edit/view: loads an existing card, renders read-only (mode=read) or
   editable (mode=edit). In edit mode, children (ARCI, links, attachments) are
   persisted immediately against the real id; the main fields + timeline save via
   update-atem (PUT). Talks to the JWT proxy at atem/api.php. */
(function () {
    'use strict';

    var CFG = window.ATEM_CONFIG || {};
    var READ = (CFG.mode !== 'edit');
    var REC = CFG.record || {};
    var quillEditor = null;
    var arciState = { A: [], R: [], C: [], I: [] };
    var reflinks = [];
    var attachments = [];

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
        rule = selectedRule();
        var a = 0, r = 0;
        if (base > 0 && rule) { a = base; r = (String(rule.code).toLowerCase() === 'rule 2') ? base * 0.5 : 0; }
        $('inc-base').textContent = money(base);
        $('inc-a').textContent = money(a);
        $('inc-r').textContent = money(r);
        $('inc-total').textContent = money(a + r);
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
        // Closure date always follows the final due date.
        if ($('tl-closure')) { $('tl-closure').value = v || ''; }
    }
    function syncExtensionFields() {
        var on = $('tl-extended').checked;
        var w1 = $('tl-ext1-wrap'), w2 = $('tl-ext2-wrap');
        if (!on) {
            w1.style.display = 'none'; w2.style.display = 'none';
            $('tl-ext1').value = ''; $('tl-ext2').value = '';
        } else {
            w1.style.display = '';
            w2.style.display = $('tl-ext1').value ? '' : 'none';
            if (!$('tl-ext1').value) { $('tl-ext2').value = ''; }
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

    // ------------------------------------------------------------------- save
    function validateFinal() {
        setError('atem-title-error', ''); setError('atem-level-error', ''); setError('tl-start-error', ''); setError('tl-end-error', ''); setError('atem-save-error', '');
        if (!$('atem-title').value.trim()) { setError('atem-title-error', 'ATEM Title is required.'); return false; }
        if (!$('atem-level').value) { setError('atem-level-error', 'ATEM Level is required.'); return false; }
        if (!$('tl-start').value) { setError('tl-start-error', 'Start Date is required.'); return false; }
        if (!$('tl-end').value) { setError('tl-end-error', 'End Date is required.'); return false; }
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
                $('tl-ext1-wrap').style.display = ''; $('tl-ext1').value = dateOnly(REC.extended_date_1);
                if (REC.extended_date_2) { $('tl-ext2-wrap').style.display = ''; $('tl-ext2').value = dateOnly(REC.extended_date_2); }
            }
        }
        if (quillEditor && REC.description) { quillEditor.clipboard.dangerouslyPasteHTML(REC.description); }

        var grouped = { A: [], R: [], C: [], I: [] };
        (REC.arci || []).forEach(function (m) { if (grouped[m.role]) { grouped[m.role].push(m); } });
        arciState = grouped;
        reflinks = REC.reference_links || [];
        attachments = REC.attachments || [];

        renderArci();
        renderReferenceLinks();
        renderAttachments();
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

        if ($('atem-save-btn')) { $('atem-save-btn').addEventListener('click', saveAtem); }
    }

    document.addEventListener('DOMContentLoaded', function () {
        populateLookups();
        populateDepartments();
        initEditor();
        bind();
        hydrate();
        injectBadge();
        applyReadMode();
    });
})();
