/* ATEM create form behaviour: session-first draft lifecycle.
   Nothing is written to the DB until the user saves. The in-progress card lives
   in the PHP session (text/ARCI/links) and the browser (staged files). Talks to
   the JWT proxy at atem/api.php. */
(function () {
    'use strict';

    var CFG = window.ATEM_CONFIG || {};
    var quillEditor = null;

    // In-progress state (the source of truth in the browser).
    var arciState = { A: [], R: [], C: [], I: [] };
    var reflinks = [];      // [{ name, url }]
    var stagedFiles = [];   // File objects (client-side only, not session-synced)

    // Leave/save bookkeeping.
    var dirty = false;
    var leaving = false;
    var pendingNavUrl = 'atem/view.php';
    var _syncTimer = null;

    // ----------------------------------------------------------------- helpers
    function $(id) { return document.getElementById(id); }

    function money(n) {
        return 'RM' + (Math.round((Number(n) || 0) * 100) / 100).toFixed(2);
    }

    function escapeHtml(s) {
        return String(s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }

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

    function readFileAsBase64(file) {
        return new Promise(function (resolve, reject) {
            var reader = new FileReader();
            reader.onload = function (e) {
                var dataUrl = String(e.target.result);
                var comma = dataUrl.indexOf(',');
                resolve(comma >= 0 ? dataUrl.substring(comma + 1) : dataUrl);
            };
            reader.onerror = function () { reject(new Error('read failed')); };
            reader.readAsDataURL(file);
        });
    }

    // Staged files are kept as {name, type, size, content(base64)} objects and
    // persisted to the session under their own key so they survive a refresh and
    // are always present at save time. Synced only when files change.
    function syncAttachments() {
        apiCall('draft-files-save', { data: stagedFiles });
    }

    function setError(id, msg) {
        var el = $(id);
        if (el) { el.textContent = msg || ''; }
    }

    function clearFormErrors() {
        ['atem-title-error', 'atem-level-error', 'atem-rule-error', 'tl-start-error',
            'tl-end-error', 'arci-error', 'atem-save-error', 'atem-file-error'].forEach(function (id) {
            setError(id, '');
        });
    }

    // Shared confirmation modal for Attachment / Reference Link removals.
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

    // Inline two-click confirm on a remove control (no JS dialog): first click
    // arms it (shows a red "confirm?"), a second click runs onConfirm.
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

    // --------------------------------------------------------- dirty / session
    function markChanged() {
        dirty = true;
        scheduleSync();
    }

    function buildDraft() {
        return {
            title: $('atem-title').value,
            description: quillEditor ? quillEditor.root.innerHTML : '',
            level_structure_id: $('atem-level').value || null,
            incentive_rule_id: $('atem-rule').value || null,
            start_date: $('tl-start').value || null,
            end_date: $('tl-end').value || null,
            arci: arciState,
            reflinks: reflinks
        };
    }

    function scheduleSync() {
        if (_syncTimer) { clearTimeout(_syncTimer); }
        _syncTimer = setTimeout(function () {
            apiCall('draft-save', { data: buildDraft() });
        }, 500);
    }

    function hydrate(draft) {
        if (!draft) { return; }
        if (typeof draft.title === 'string') { $('atem-title').value = draft.title; }
        if (draft.level_structure_id) { $('atem-level').value = draft.level_structure_id; }
        if (draft.incentive_rule_id) { $('atem-rule').value = draft.incentive_rule_id; }
        if (draft.start_date) { $('tl-start').value = draft.start_date; }
        if (draft.end_date) { $('tl-end').value = draft.end_date; }
        if (quillEditor && draft.description) { quillEditor.root.innerHTML = draft.description; }
        if (draft.arci) {
            arciState = {
                A: draft.arci.A || [], R: draft.arci.R || [],
                C: draft.arci.C || [], I: draft.arci.I || []
            };
        }
        if (draft.reflinks) { reflinks = draft.reflinks; }
        if (draft.attachments) { stagedFiles = draft.attachments; }
        // Restored content is unsaved (no DB row), so leaving should still warn.
        dirty = true;
    }

    // --------------------------------------------------------------- Quill RTE
    // Matches the iidas rich text editor (common/rich_text_editor.php): Quill
    // 1.3.6, full toolbar with custom link (prompt) and image (base64) handlers.
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
                            if (value) {
                                var href = prompt('Enter the URL:');
                                if (href) { this.quill.format('link', href); }
                            } else {
                                this.quill.format('link', false);
                            }
                        },
                        'image': function () {
                            var input = document.createElement('input');
                            input.setAttribute('type', 'file');
                            input.setAttribute('accept', 'image/*');
                            input.click();

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
            placeholder: 'Write the ATEM description in details here....'
        });
        quillEditor.on('text-change', function (delta, old, source) {
            if (source === 'user') { markChanged(); }
        });
    }

    // --------------------------------------------------------------- dropdowns
    function fillSelect(select, items, valueKey, labelFn, placeholder) {
        if (!select) { return; }
        select.innerHTML = '';
        var opt = document.createElement('option');
        opt.value = '';
        opt.textContent = placeholder;
        select.appendChild(opt);
        for (var i = 0; i < items.length; i++) {
            var o = document.createElement('option');
            o.value = items[i][valueKey];
            o.textContent = labelFn(items[i]);
            select.appendChild(o);
        }
    }

    function populateLookups() {
        var levels = CFG.levels || [];
        var rules = CFG.rules || [];

        fillSelect($('atem-level'), levels, 'id', function (l) {
            return l.level + ' - ' + l.system_name + ' (RM' + Number(l.incentive_value).toFixed(0) + ')';
        }, 'Select level');

        fillSelect($('atem-rule'), rules, 'id', function (r) {
            return r.code + ' - ' + r.system_label;
        }, 'Select rule');
    }

    function selectedLevel() {
        var id = $('atem-level').value;
        var levels = CFG.levels || [];
        for (var i = 0; i < levels.length; i++) {
            if (String(levels[i].id) === String(id)) { return levels[i]; }
        }
        return null;
    }

    function selectedRule() {
        var id = $('atem-rule').value;
        var rules = CFG.rules || [];
        for (var i = 0; i < rules.length; i++) {
            if (String(rules[i].id) === String(id)) { return rules[i]; }
        }
        return null;
    }

    // --------------------------------------------------------- incentive calc
    function recalcIncentive() {
        var level = selectedLevel();
        var rule = selectedRule();
        var base = level ? Number(level.incentive_value) : 0;

        var ruleSelect = $('atem-rule');
        var note = $('inc-note');

        // Level 1 (zero base) carries no incentive and needs no rule.
        if (level && base === 0) {
            ruleSelect.value = '';
            ruleSelect.setAttribute('disabled', 'disabled');
        } else {
            ruleSelect.removeAttribute('disabled');
        }
        var ruleStar = $('rule-req-star');
        if (ruleStar) { ruleStar.style.display = (base > 0) ? '' : 'none'; }
        rule = selectedRule();

        var incentivisedA = countIncentivised('A');
        var incentivisedR = countIncentivised('R');
        var code = rule ? String(rule.code).toLowerCase() : '';
        var a = 0, r = 0, rDisplay = 0;
        if (base > 0 && rule) {
            if (code === 'rule 1') {
                // Each incentivised A gets 50% of base (up to 2)
                a = base * 0.5 * incentivisedA;
                rDisplay = incentivisedA > 0 ? base * 0.5 : 0;
                r = 0;
            } else if (code === 'rule 2') {
                // Each incentivised A gets 100%; no R payout
                a = base * incentivisedA;
                r = 0;
            } else if (code === 'rule 3') {
                // Each incentivised A gets 100%; incentivised R members share a 50% pool
                a = base * incentivisedA;
                r = incentivisedR > 0 ? base * 0.5 : 0;
                rDisplay = r;
            }
        }
        var total = a + r;

        $('inc-base').textContent = money(base);
        $('inc-a').textContent = money(a);
        $('inc-r').textContent = money(code === 'rule 1' ? rDisplay : r);
        $('inc-total').textContent = money(total);
        var rLabel = $('inc-r-label');
        if (rLabel) {
            if (code === 'rule 1') {
                rLabel.textContent = 'A · Accountable (50% each)';
            } else if (code === 'rule 3' && incentivisedR > 1) {
                rLabel.textContent = 'R · Responsible ×' + incentivisedR + ' (pooled 50%)';
            } else {
                rLabel.textContent = 'R · Responsible';
            }
        }

        if (!level) {
            note.textContent = 'Select an ATEM level and rule to calculate incentive. C and I are not incentivised.';
        } else if (base === 0) {
            note.textContent = 'Level 1 carries no incentive payout.';
        } else if (!rule) {
            note.textContent = 'Select an incentive rule (required for Level 2-4).';
        } else {
            note.textContent = 'Projected amounts. Claimable only when closed as Complete or Complete with Excellence.';
        }
    }

    // ------------------------------------------------------------------- ARCI
    function countIncentivised(role) {
        var n = 0;
        (arciState[role] || []).forEach(function (m) { if (m.is_incentivised) { n++; } });
        return n;
    }

    function assignedStaffIds() {
        var ids = [];
        ['A', 'R', 'C', 'I'].forEach(function (role) {
            (arciState[role] || []).forEach(function (m) { ids.push(parseInt(m.staff_id, 10)); });
        });
        return ids;
    }

    function renderArci() {
        var cols = document.querySelectorAll('.atem-arci-members');
        for (var i = 0; i < cols.length; i++) {
            var role = cols[i].getAttribute('data-role');
            var members = arciState[role] || [];
            if (members.length === 0) {
                cols[i].innerHTML = '<div class="atem-arci-empty">No members assigned</div>';
                continue;
            }
            var html = '';
            for (var m = 0; m < members.length; m++) {
                var mem = members[m];
                var incentivisedHtml = '';
                if (role === 'A' || role === 'R') {
                    incentivisedHtml = '<label class="atem-arci-incentivised">'
                        + '<input type="checkbox" class="atem-arci-incentivised-chk"'
                        + ' data-staff="' + parseInt(mem.staff_id, 10) + '" data-role="' + role + '"'
                        + (mem.is_incentivised ? ' checked' : '') + '>'
                        + ' Incentivised</label>';
                }
                html += '<div class="atem-arci-member">'
                    + '<div class="atem-arci-member-info">'
                    + '<div class="atem-arci-member-dept">(' + escapeHtml(mem.department_name || '') + ')</div>'
                    + '<div class="atem-arci-member-name">' + escapeHtml(mem.staff_name || '') + '</div>'
                    + '</div>'
                    + incentivisedHtml
                    + '<span class="atem-arci-remove" data-staff="' + parseInt(mem.staff_id, 10) + '" data-role="' + role + '" title="Remove">&times;</span>'
                    + '</div>';
            }
            cols[i].innerHTML = html;
        }
        // refresh staff picker so already-assigned people drop off
        renderStaffList();
        recalcIncentive();
    }

    function populateDepartments() {
        var sel = $('arci-dept-select');
        var depts = CFG.departments || [];
        sel.innerHTML = '<option value="">Select department</option>';
        for (var i = 0; i < depts.length; i++) {
            var o = document.createElement('option');
            o.value = depts[i].id;
            o.textContent = depts[i].name;
            sel.appendChild(o);
        }
    }

    function filterDepartments() {
        var term = $('arci-dept-search').value.toLowerCase();
        var opts = $('arci-dept-select').options;
        for (var i = 0; i < opts.length; i++) {
            if (opts[i].value === '') { continue; }
            opts[i].hidden = opts[i].textContent.toLowerCase().indexOf(term) < 0;
        }
    }

    function renderStaffList() {
        var listDiv = $('arci-staff-list');
        var deptId = $('arci-dept-select').value;
        if (!deptId) {
            listDiv.innerHTML = '<div class="text-muted" style="font-size:13px;">Select a department to load staff</div>';
            return;
        }
        var staff = (CFG.staffByDept && CFG.staffByDept[deptId]) ? CFG.staffByDept[deptId] : [];
        var assigned = assignedStaffIds();
        var term = $('arci-staff-search').value.toLowerCase();

        var html = '';
        for (var i = 0; i < staff.length; i++) {
            if (assigned.indexOf(parseInt(staff[i].id, 10)) >= 0) { continue; }
            if (term && staff[i].name.toLowerCase().indexOf(term) < 0) { continue; }
            html += '<label class="atem-arci-staff-item">'
                + '<input type="checkbox" value="' + parseInt(staff[i].id, 10) + '" data-name="' + escapeHtml(staff[i].name) + '"> '
                + '<span>' + escapeHtml(staff[i].name) + '</span>'
                + '</label>';
        }
        listDiv.innerHTML = html || '<div class="text-muted" style="font-size:13px;">No staff available</div>';
    }

    function departmentName(deptId) {
        var depts = CFG.departments || [];
        for (var i = 0; i < depts.length; i++) {
            if (String(depts[i].id) === String(deptId)) { return depts[i].name; }
        }
        return '';
    }

    function addSelectedMembers() {
        setError('arci-error', '');
        var role = $('arci-role').value;
        if (!role) { setError('arci-error', 'Please select a role first.'); return; }

        var deptId = $('arci-dept-select').value;
        var checks = $('arci-staff-list').querySelectorAll('input[type="checkbox"]:checked');
        if (checks.length === 0) { setError('arci-error', 'Please select at least one staff member.'); return; }
        if (role === 'A' && (arciState.A.length + checks.length > 2)) {
            setError('arci-error', 'Role A (Accountable) is limited to 2 members.');
            return;
        }
        if (role === 'R' && (arciState.R.length + checks.length > 2)) {
            setError('arci-error', 'Role R (Responsible) is limited to 2 members.');
            return;
        }

        var deptName = departmentName(deptId);
        for (var i = 0; i < checks.length; i++) {
            arciState[role].push({
                staff_id: parseInt(checks[i].value, 10),
                staff_name: checks[i].getAttribute('data-name'),
                staff_dept_id: deptId ? parseInt(deptId, 10) : null,
                department_name: deptName,
                role: role,
                is_incentivised: false
            });
        }

        $('arci-role').value = '';
        $('arci-dept-select').value = '';
        $('arci-staff-search').value = '';
        renderArci();
        markChanged();
    }

    function removeMember(staffId, role) {
        var list = arciState[role] || [];
        for (var i = 0; i < list.length; i++) {
            if (parseInt(list[i].staff_id, 10) === parseInt(staffId, 10)) {
                list.splice(i, 1);
                break;
            }
        }
        renderArci();
        markChanged();
    }

    // Two-click inline confirm for clearing a role.
    var _pendingClear = {};
    function resetClearBtn(role, btn) {
        if (_pendingClear[role]) { clearTimeout(_pendingClear[role]); }
        delete _pendingClear[role];
        if (btn) {
            btn.textContent = 'Delete All';
            btn.classList.remove('atem-arci-clear-confirm');
        }
    }

    function clearRole(role, btn) {
        if (_pendingClear[role]) {
            resetClearBtn(role, btn);
            arciState[role] = [];
            renderArci();
            markChanged();
            return;
        }
        setError('arci-error', '');
        if (btn) {
            btn.textContent = 'Click again to confirm';
            btn.classList.add('atem-arci-clear-confirm');
        }
        _pendingClear[role] = setTimeout(function () { resetClearBtn(role, btn); }, 3000);
    }

    // ------------------------------------------------------- reference links
    var _reflinkModal = null;
    function getReflinkModal() {
        if (!_reflinkModal && typeof bootstrap !== 'undefined') {
            _reflinkModal = new bootstrap.Modal($('atem-reflink-modal'));
        }
        return _reflinkModal;
    }

    function renderReferenceLinks() {
        var wrap = $('atem-reflink-list');
        if (!reflinks.length) {
            wrap.innerHTML = '<div class="atem-empty-state">No Reference Link added.</div>';
            return;
        }
        var html = '<ol class="atem-reflink-ol">';
        for (var i = 0; i < reflinks.length; i++) {
            html += '<li><div class="atem-reflink-row">'
                + '<a href="' + escapeHtml(reflinks[i].url) + '" target="_blank" rel="noopener">' + escapeHtml(reflinks[i].name) + '</a>'
                + '<span class="atem-reflink-remove" data-index="' + i + '" title="Remove">&times;</span>'
                + '</div></li>';
        }
        html += '</ol>';
        wrap.innerHTML = html;
    }

    function openReflinkModal() {
        $('reflink-name').value = '';
        $('reflink-url').value = '';
        setError('reflink-error', '');
        var m = getReflinkModal();
        if (m) { m.show(); }
    }

    function saveReferenceLink() {
        var name = $('reflink-name').value.trim();
        var url = $('reflink-url').value.trim();
        if (!name || !url) { setError('reflink-error', 'Please fill in both Name and URL.'); return; }
        try { new URL(url); } catch (e) { setError('reflink-error', 'Please enter a valid URL (e.g. https://example.com).'); return; }
        reflinks.push({ name: name, url: url });
        renderReferenceLinks();
        markChanged();
        var m = getReflinkModal();
        if (m) { m.hide(); }
    }

    function removeReferenceLink(index) {
        reflinks.splice(index, 1);
        renderReferenceLinks();
        markChanged();
    }

    // ------------------------------------------------------------ attachments
    var ALLOWED_EXT = ['jpg', 'jpeg', 'png', 'gif', 'pdf', 'doc', 'docx', 'xls', 'xlsx', 'txt'];
    var MAX_BYTES = 10 * 1024 * 1024;

    function formatFileSize(bytes) {
        if (!bytes) { return '0 Bytes'; }
        var k = 1024;
        var sizes = ['Bytes', 'KB', 'MB', 'GB'];
        var i = Math.floor(Math.log(bytes) / Math.log(k));
        return Math.round(bytes / Math.pow(k, i) * 100) / 100 + ' ' + sizes[i];
    }

    function fileExt(name) {
        var idx = name.lastIndexOf('.');
        return idx >= 0 ? name.substr(idx + 1).toLowerCase() : '';
    }

    function renderStagedFiles() {
        var wrap = $('atem-file-list');
        if (!stagedFiles.length) {
            wrap.innerHTML = '<div class="atem-empty-state">No files attached.</div>';
            return;
        }
        var html = '';
        for (var i = 0; i < stagedFiles.length; i++) {
            html += '<div class="atem-file-row">'
                + '<span class="atem-file-name">' + escapeHtml(stagedFiles[i].name) + ' (' + formatFileSize(stagedFiles[i].size) + ')</span>'
                + '<span class="atem-file-remove" data-index="' + i + '" title="Remove">&times;</span>'
                + '</div>';
        }
        wrap.innerHTML = html;
    }

    function addStagedFiles(fileList) {
        setError('atem-file-error', '');
        var toRead = [];
        for (var i = 0; i < fileList.length; i++) {
            var f = fileList[i];
            if (ALLOWED_EXT.indexOf(fileExt(f.name)) < 0) {
                setError('atem-file-error', f.name + ': file type not allowed.');
                continue;
            }
            if (f.size > MAX_BYTES) {
                setError('atem-file-error', f.name + ': exceeds 10MB.');
                continue;
            }
            var dup = false;
            for (var j = 0; j < stagedFiles.length; j++) {
                if (stagedFiles[j].name === f.name && stagedFiles[j].size === f.size) { dup = true; break; }
            }
            if (!dup) { toRead.push(f); }
        }
        if (!toRead.length) { return; }

        // Capture the bytes as base64 now (not lazily at save) so the file is
        // guaranteed to be in the payload and is persisted to the session.
        Promise.all(toRead.map(function (file) {
            return readFileAsBase64(file).then(function (b64) {
                return { name: file.name, type: file.type, size: file.size, content: b64 };
            });
        })).then(function (objs) {
            for (var k = 0; k < objs.length; k++) { stagedFiles.push(objs[k]); }
            renderStagedFiles();
            dirty = true;
            syncAttachments();
        }).catch(function () {
            setError('atem-file-error', 'Could not read the selected file(s).');
        });
    }

    function removeStagedFile(index) {
        stagedFiles.splice(index, 1);
        renderStagedFiles();
        dirty = true;
        syncAttachments();
    }

    function bindAttachmentZone() {
        var dz = $('atem-dropzone');
        var fi = $('atem-file-input');
        if (!dz || !fi) { return; }

        $('atem-file-pick').addEventListener('click', function (e) {
            e.preventDefault();
            e.stopPropagation();
            fi.click();
        });
        dz.addEventListener('click', function () { fi.click(); });
        fi.addEventListener('change', function () { addStagedFiles(fi.files); fi.value = ''; });

        ['dragenter', 'dragover'].forEach(function (ev) {
            dz.addEventListener(ev, function (e) {
                e.preventDefault();
                e.stopPropagation();
                dz.classList.add('atem-dropzone-active');
            });
        });
        ['dragleave', 'drop'].forEach(function (ev) {
            dz.addEventListener(ev, function (e) {
                e.preventDefault();
                e.stopPropagation();
                dz.classList.remove('atem-dropzone-active');
            });
        });
        dz.addEventListener('drop', function (e) {
            if (e.dataTransfer && e.dataTransfer.files) { addStagedFiles(e.dataTransfer.files); }
        });

        $('atem-file-list').addEventListener('click', function (e) {
            if (e.target.classList.contains('atem-file-remove')) {
                var idx = parseInt(e.target.getAttribute('data-index'), 10);
                confirmAction('Remove this attachment?', function () { removeStagedFile(idx); });
            }
        });
    }

    // ------------------------------------------------------------------- save
    function flattenArci() {
        return arciState.A.concat(arciState.R, arciState.C, arciState.I);
    }

    function validateFinal() {
        clearFormErrors();
        var title = $('atem-title').value.trim();
        var levelId = $('atem-level').value;
        var ruleId = $('atem-rule').value;
        var startDate = $('tl-start').value;
        var endDate = $('tl-end').value;
        var level = selectedLevel();

        if (!title) { setError('atem-title-error', 'ATEM Title is required.'); $('atem-title').focus(); return false; }
        if (!levelId) { setError('atem-level-error', 'ATEM Level is required.'); $('atem-level').focus(); return false; }
        if (!startDate) { setError('tl-start-error', 'Start Date is required.'); $('tl-start').focus(); return false; }
        if (!endDate) { setError('tl-end-error', 'End Date is required.'); $('tl-end').focus(); return false; }
        if (level && Number(level.incentive_value) > 0 && !ruleId) {
            setError('atem-rule-error', 'Incentive Rule is required for Level 2-4.');
            $('atem-rule').focus();
            return false;
        }
        if (!arciState.A || arciState.A.length === 0) {
            setError('arci-error', 'An Accountable (A) member is mandatory.');
            return false;
        }
        var rule = selectedRule();
        if (level && Number(level.incentive_value) > 0 && rule) {
            var ruleCode = rule.code.toLowerCase();
            if (countIncentivised('A') === 0) {
                setError('arci-error', 'Please mark at least one Accountable (A) member as incentivised.');
                return false;
            }
            if (ruleCode === 'rule 3' && countIncentivised('R') === 0) {
                setError('arci-error', 'Rule 3 requires at least one Responsible (R) member marked as incentivised.');
                return false;
            }
        }
        return true;
    }

    function collectPayload(mode) {
        var levelId = $('atem-level').value;
        var ruleId = $('atem-rule').value;
        var description = '';
        if (quillEditor) {
            description = (quillEditor.getText().trim() === '') ? '' : quillEditor.root.innerHTML;
        }
        return {
            title: $('atem-title').value.trim(),
            description: description,
            level_structure_id: levelId ? parseInt(levelId, 10) : null,
            incentive_rule_id: ruleId ? parseInt(ruleId, 10) : null,
            start_date: $('tl-start').value || null,
            end_date: $('tl-end').value || null,
            arci: flattenArci(),
            reference_links: reflinks,
            mode: mode
        };
    }

    // Upload the staged files (multipart) against a saved ATEM id, then continue.
    function saveAtem(mode, navUrl) {
        if (mode === 'final' && !validateFinal()) { return; }
        setError('atem-save-error', '');

        var btn = $('atem-save-btn');
        if (btn) { btn.disabled = true; btn.textContent = 'Saving...'; }

        // Staged files already hold their base64 content, so the card and its
        // attachments are persisted together in one atomic call.
        var payload = collectPayload(mode);
        payload.attachments = stagedFiles;
        apiCall('save-atem', { data: payload }).then(function (res) {
            if (res && res.success && res.data && res.data.id) {
                apiCall('draft-clear').then(function () {
                    leaving = true;
                    window.location.href = navUrl || 'atem/view.php';
                });
            } else {
                setError('atem-save-error', res && res.message ? res.message : 'Failed to save ATEM.');
                if (btn) { btn.disabled = false; btn.textContent = 'Save ATEM'; }
            }
        }).catch(function () {
            setError('atem-save-error', 'Network error while saving.');
            if (btn) { btn.disabled = false; btn.textContent = 'Save ATEM'; }
        });
    }

    function cancelAtem(navUrl) {
        apiCall('draft-clear').then(function () {
            leaving = true;
            window.location.href = navUrl || 'atem/view.php';
        });
    }

    // ------------------------------------------------------------ leave guard
    var _leaveModal = null;
    function getLeaveModal() {
        if (!_leaveModal && typeof bootstrap !== 'undefined') {
            _leaveModal = new bootstrap.Modal($('atem-leave-modal'));
        }
        return _leaveModal;
    }

    function showLeaveModal(navUrl) {
        pendingNavUrl = navUrl || 'atem/view.php';
        var m = getLeaveModal();
        if (m) { m.show(); }
    }

    function bindLeaveGuard() {
        var cancelBtn = $('atem-cancel-btn');
        if (cancelBtn) {
            cancelBtn.addEventListener('click', function () { showLeaveModal('atem/view.php'); });
        }
        var leaveCancel = $('atem-leave-cancel');
        if (leaveCancel) {
            leaveCancel.addEventListener('click', function () {
                var m = getLeaveModal(); if (m) { m.hide(); }
                cancelAtem(pendingNavUrl);
            });
        }
        var leaveDraft = $('atem-leave-draft');
        if (leaveDraft) {
            leaveDraft.addEventListener('click', function () {
                var m = getLeaveModal(); if (m) { m.hide(); }
                saveAtem('draft', pendingNavUrl);
            });
        }

        // Intercept in-app navigation links while there are unsaved changes.
        document.addEventListener('click', function (e) {
            if (!dirty || leaving) { return; }
            var a = e.target.closest ? e.target.closest('a[href]') : null;
            if (!a) { return; }
            var href = a.getAttribute('href');
            if (!href || href.charAt(0) === '#' || href.indexOf('javascript:') === 0) { return; }
            if (a.getAttribute('target') === '_blank') { return; }
            e.preventDefault();
            showLeaveModal(a.href);
        });

        // Tab close / refresh: only a generic browser prompt is possible.
        window.addEventListener('beforeunload', function (e) {
            if (dirty && !leaving) {
                e.preventDefault();
                e.returnValue = '';
                return '';
            }
        });
    }

    // --------------------------------------------------------------- wiring
    function bind() {
        $('atem-title').addEventListener('input', markChanged);
        $('atem-level').addEventListener('change', function () { recalcIncentive(); markChanged(); });
        $('atem-rule').addEventListener('change', function () { recalcIncentive(); markChanged(); });
        $('tl-start').addEventListener('change', markChanged);
        $('tl-end').addEventListener('change', markChanged);

        $('arci-dept-search').addEventListener('keyup', filterDepartments);
        $('arci-dept-select').addEventListener('change', renderStaffList);
        $('arci-staff-search').addEventListener('keyup', renderStaffList);
        $('arci-add-btn').addEventListener('click', addSelectedMembers);

        $('arci-grid').addEventListener('click', function (e) {
            var t = e.target;
            if (t.classList.contains('atem-arci-remove')) {
                var sId = parseInt(t.getAttribute('data-staff'), 10);
                var sRole = t.getAttribute('data-role');
                armOrConfirm(t, function () { removeMember(sId, sRole); });
            } else if (t.classList.contains('atem-arci-clear')) {
                clearRole(t.getAttribute('data-role'), t);
            } else if (t.classList.contains('atem-arci-incentivised-chk')) {
                var chkStaff = parseInt(t.getAttribute('data-staff'), 10);
                var chkRole = t.getAttribute('data-role');
                var rule = selectedRule();
                var code = rule ? String(rule.code).toLowerCase() : '';
                (arciState[chkRole] || []).forEach(function (m) {
                    if (parseInt(m.staff_id, 10) === chkStaff) { m.is_incentivised = t.checked; }
                });
                recalcIncentive();
                markChanged();
                renderArci();
            }
        });

        $('atem-add-reflink-btn').addEventListener('click', openReflinkModal);
        $('reflink-save-btn').addEventListener('click', saveReferenceLink);
        $('atem-reflink-list').addEventListener('click', function (e) {
            if (e.target.classList.contains('atem-reflink-remove')) {
                var idx = parseInt(e.target.getAttribute('data-index'), 10);
                confirmAction('Remove this reference link?', function () { removeReferenceLink(idx); });
            }
        });

        bindAttachmentZone();

        var saveBtn = $('atem-save-btn');
        if (saveBtn) { saveBtn.addEventListener('click', function () { saveAtem('final', 'atem/view.php'); }); }

        bindLeaveGuard();
    }

    document.addEventListener('DOMContentLoaded', function () {
        populateLookups();
        populateDepartments();
        initEditor();
        hydrate(CFG.draft);
        bind();
        recalcIncentive();
        renderArci();
        renderReferenceLinks();
        renderStagedFiles();
        var today = new Date().toISOString().substring(0, 10);
        if ($('tl-start')) { $('tl-start').setAttribute('min', today); }
        if ($('tl-end')) { $('tl-end').setAttribute('min', today); }
    });
})();
