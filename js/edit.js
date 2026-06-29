/* ATEM edit/view: loads an existing card, renders read-only (mode=read) or
   editable (mode=edit). In edit mode, children (ARCI, links, attachments) are
   persisted immediately against the real id; the main fields + timeline save via
   update-atem (PUT). Talks to the JWT proxy at atem/api.php. */
(function () {
    'use strict';

    var CFG = window.ATEM_CONFIG || {};
    var READ = (CFG.mode !== 'edit');
    var PROGRESS_MODE = (CFG.mode === 'progress');
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
    // Issuer and all ARCI members (A/R/C/I) see all progress entries.
    var CAN_VIEW_ALL_PROGRESS = IS_TAGGED_ON_CARD;
    // True for any user tagged on the card (Issuer or any ARCI role A/R/C/I).
    var IS_TAGGED_ON_CARD = IS_ISSUER || (function () {
        var arci = REC.arci || [];
        for (var i = 0; i < arci.length; i++) {
            if (String(arci[i].staff_id) === String(CFG.staffId)) { return true; }
        }
        return false;
    }());
    var TERMINAL_STATUSES = ['Failed', 'Completed', 'Completed with Excellence', 'Completed with Extension'];
    var quillEditor = null;
    var arciState = { A: [], R: [], C: [], I: [] };
    var reflinks = [];
    var attachments = [];
    var progressUpdates = [];
    var _inlineSaveTimer = null;
    var _lastCalcIncentive = 'RM0.00';

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

    function scrollToFirstError() {
        var ids = ['atem-title-error', 'atem-level-error', 'atem-rule-error', 'tl-start-error',
                   'tl-end-error', 'tl-status-error', 'arci-error', 'reflink-section-error', 'atem-save-error'];
        for (var i = 0; i < ids.length; i++) {
            var el = $(ids[i]);
            if (el && el.textContent.trim() !== '') {
                el.scrollIntoView({ behavior: 'smooth', block: 'center' });
                return;
            }
        }
    }

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

    function getRuleLimits(rule) {
        var map = {
            'rule 1': { maxA: 2, maxR: 0 },
            'rule 2': { maxA: 1, maxR: 0 },
            'rule 3': { maxA: 1, maxR: 2 },
            'rule 4': { maxA: 2, maxR: 2 },
            'rule 5': { maxA: 1, maxR: 1 },
            'rule 6': { maxA: 2, maxR: 1 }
        };
        if (!rule) { return { maxA: 2, maxR: 2 }; }
        var code = String(rule.code).toLowerCase().trim();
        return map[code] || { maxA: 2, maxR: 2 };
    }

    function validateArciIncentive() {
        var level = selectedLevel();
        if (!level || Number(level.incentive_value) === 0) { return null; }
        var rule = selectedRule();
        if (!rule) { return null; }
        var limits = getRuleLimits(rule);
        var incA = countIncentivised('A');
        var incR = countIncentivised('R');
        if (incA !== limits.maxA) { return 'This rule requires exactly ' + limits.maxA + ' Accountable (A) member(s) to be incentivised.'; }
        if (limits.maxR > 0 && incR !== limits.maxR) { return 'This rule requires exactly ' + limits.maxR + ' Responsible (R) member(s) to be incentivised.'; }
        return null;
    }

    function recalcIncentive() {
        if (REC && REC.status && REC.status.value === 'Suspended') {
            $('inc-base').textContent  = money(0);
            $('inc-a').textContent     = money(0);
            $('inc-r').textContent     = money(0);
            $('inc-total').textContent = money(0);
            if ($('inc-note')) { $('inc-note').textContent = 'Incentive has been reset to zero — this card is suspended.'; }
            return;
        }
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
        var incentivisedA = countIncentivised('A');
        var incentivisedR = countIncentivised('R');
        var code = rule ? String(rule.code).toLowerCase() : '';
        var a = 0, r = 0, rDisplay = 0;
        if (base > 0 && rule) {
            if (code === 'rule 1') {
                a = base * 0.5 * incentivisedA;
                rDisplay = incentivisedA > 0 ? base * 0.5 : 0;
                r = 0;
            } else if (code === 'rule 2') {
                a = base * incentivisedA;
                r = 0;
            } else if (code === 'rule 3') {
                a = base * incentivisedA;
                r = incentivisedR > 0 ? base * 0.5 : 0;
                rDisplay = r;
            } else if (code === 'rule 4') {
                a = base * 0.5 * incentivisedA;
                r = incentivisedR > 0 ? base * 0.5 : 0;
            } else if (code === 'rule 5') {
                a = base * incentivisedA;
                r = base * 0.5 * incentivisedR;
            } else if (code === 'rule 6') {
                a = base * 0.5 * incentivisedA;
                r = base * 0.5 * incentivisedR;
            }
        }
        _lastCalcIncentive = money(a + r);
        var _isExtended = !!($('tl-extended') && $('tl-extended').checked && $('tl-ext1') && $('tl-ext1').value);
        var _noIncentive = (_isExtended && !READ && IS_ISSUER) && !!($('tl-incentive-approve-no') && $('tl-incentive-approve-no').checked);
        $('inc-base').textContent  = _noIncentive ? money(0) : money(base);
        $('inc-a').textContent     = _noIncentive ? money(0) : money(a);
        $('inc-r').textContent     = _noIncentive ? money(0) : money(code === 'rule 1' ? rDisplay : r);
        $('inc-total').textContent = _noIncentive ? money(0) : money(a + r);
        var rLabel = $('inc-r-label');
        if (rLabel) {
            if (code === 'rule 1') {
                rLabel.textContent = 'A · Accountable (50% each)';
            } else if ((code === 'rule 3' || code === 'rule 4') && incentivisedR > 1) {
                rLabel.textContent = 'R · Responsible ×' + incentivisedR + ' (pooled 50%)';
            } else if (code === 'rule 5' || code === 'rule 6') {
                rLabel.textContent = 'R · Responsible (50%)';
            } else {
                rLabel.textContent = 'R · Responsible';
            }
        }
        if (!level) { note.textContent = 'Select an ATEM Complexity Leveland rule to calculate incentive.'; }
        else if (base === 0) { note.textContent = 'Level 1 carries no incentive payout.'; }
        else if (!rule) { note.textContent = 'Select an incentive rule (required for Level 2-4).'; }
        else { note.textContent = 'Projected amounts. Claimable only on a completed closure.'; }
        syncIncentiveApproval();
    }

    // --------------------------------------------------------------- timeline
    function recalcFinalDue() {
        if (!$('tl-final-due')) { return; }
        var v = $('tl-end').value;
        if ($('tl-ext1') && $('tl-ext1').value) { v = $('tl-ext1').value; }
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
        if (selVal === 'Extended') {
            closureEl.value = ($('tl-ext1') && $('tl-ext1').value) ? $('tl-ext1').value : '';
        } else if (TERMINAL_STATUSES.indexOf(selVal) >= 0) {
            var _recStatusVal = '';
            (CFG.statuses || []).forEach(function (s) {
                if (String(s.id) === String(REC.atem_status_id)) { _recStatusVal = s.value; }
            });
            if (!closureEl.value || _recStatusVal === 'Extended') {
                var _cd = new Date();
                closureEl.value = _cd.getFullYear() + '-' + (_cd.getMonth() + 1 < 10 ? '0' + (_cd.getMonth() + 1) : '' + (_cd.getMonth() + 1)) + '-' + (_cd.getDate() < 10 ? '0' + _cd.getDate() : '' + _cd.getDate());
            }
        } else {
            closureEl.value = '';
        }
    }
    function syncExtensionFields() {
        var on = $('tl-extended').checked;
        var reqEl = $('tl-ext1-req');
        if (reqEl) { reqEl.style.display = (!READ && on) ? '' : 'none'; }
        var w1 = $('tl-ext1-wrap');
        if (!on) {
            w1.style.display = 'none';
            if ($('tl-ext1')) { $('tl-ext1').value = ''; }
        } else {
            w1.style.display = '';
        }
        applyExtMins();
        syncEndDateLock();
        syncStatusOptions();
        // Lock status to Extended while the checkbox is checked but the extension has not yet been saved.
        // Saved extensions (REC.extended_date_1 set) keep the dropdown enabled so the issuer can
        // still close the card as Completed or Failed.
        var statusEl = $('tl-status');
        if (statusEl && !READ) {
            if (on && !(REC && REC.extended_date_1)) {
                statusEl.setAttribute('disabled', 'disabled');
            } else {
                statusEl.removeAttribute('disabled');
            }
        }
        syncIncentiveApproval();
        recalcClosureDate();
        recalcFinalDue();
    }

    function syncEndDateLock() {
        if (READ || !IS_ISSUER) { return; }
        var endEl = $('tl-end');
        if (!endEl || !endEl.value) { return; }
        var selId  = $('tl-status') ? $('tl-status').value : '';
        var selVal = '';
        (CFG.statuses || []).forEach(function (s) {
            if (String(s.id) === String(selId)) { selVal = s.value; }
        });
        var isActive = selVal === 'Active';
        var extChecked = !!($('tl-extended') && $('tl-extended').checked);
        if (isActive && !extChecked) {
            endEl.removeAttribute('disabled');
        } else {
            endEl.setAttribute('disabled', 'disabled');
        }
    }

    function syncStatusOptions() {
        var statusEl = $('tl-status');
        if (!statusEl) { return; }
        var current = statusEl.value;
        statusEl.innerHTML = '<option value="">Select status</option>';
        var recStatusVal = '';
        (CFG.statuses || []).forEach(function (s) {
            if (String(s.id) === String(REC.atem_status_id)) { recStatusVal = s.value; }
        });
        var extendedAllowed = ['Extended', 'Completed with Extension', 'Failed'];
        var canSeeDeleted = (CFG.userGrade >= 4 || CFG.isSuperAdmin);
        (CFG.statuses || []).forEach(function (s) {
            if (CFG.superadminTerminalEdit) {
                // SuperAdmin editing a terminal card: only offer Draft or the current status.
                if (s.value !== 'Draft' && String(s.id) !== String(REC.atem_status_id)) { return; }
            } else if (CFG.issuerCompletedEdit) {
                if (recStatusVal === 'Completed with Extension') {
                    // Can only revert to Extended.
                    if (s.value !== 'Extended' && String(s.id) !== String(REC.atem_status_id)) { return; }
                } else {
                    // Issuer reverting a Completed/Excellence card.
                    var isExtCard = !!(REC.is_extended && REC.extended_date_1);
                    if (isExtCard) {
                        var extRevertAllowed = ['Extended', 'Failed'];
                        if (extRevertAllowed.indexOf(s.value) === -1 && String(s.id) !== String(REC.atem_status_id)) { return; }
                    } else {
                        // Non-extended card: allow all statuses except Suspended and Deleted (grade 1-3).
                        if (s.value === 'Suspended' && recStatusVal !== 'Suspended') { return; }
                        if (s.value === 'Deleted' && !canSeeDeleted) { return; }
                    }
                }
            } else {
                if (recStatusVal === 'Extended' && extendedAllowed.indexOf(s.value) === -1) { return; }
                if (s.value === 'Suspended' && recStatusVal !== 'Suspended') { return; }
                if (s.value === 'Deleted' && !canSeeDeleted) { return; }
            }
            var opt = document.createElement('option');
            opt.value = s.id;
            opt.textContent = s.value;
            statusEl.appendChild(opt);
        });
        statusEl.value = current;
        recalcClosureDate();
    }

    function showTimelineReminder() {
        var el = $('tl-save-reminder');
        if (el && !READ && IS_ISSUER) { el.style.display = ''; }
    }

    function syncIncentiveApproval() {
        var wrap = $('tl-incentive-approval-wrap');
        if (!wrap) { return; }
        wrap.style.display = 'none'; // hidden until further notice
        var amountEl = $('tl-approval-amount');
        if (amountEl) {
            var incTotal = _lastCalcIncentive;
            amountEl.textContent = incTotal;
        }
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
    function countIncentivised(role) {
        var n = 0;
        (arciState[role] || []).forEach(function (m) { if (m.is_incentivised) { n++; } });
        return n;
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
                var nm = mem.staff_name || staffNameIn(mem.staff_dept_id, mem.staff_id) || ('Staff #' + mem.staff_id);
                var dn = mem.department_name || deptName(mem.staff_dept_id);
                var incentivisedHtml = '';
                var _arciRule = selectedRule();
                var _arciLimits = getRuleLimits(_arciRule);
                var _lvl = selectedLevel();
                var _isLevel1 = _lvl && Number(_lvl.incentive_value) === 0;
                var showChk = !_isLevel1 && ((role === 'A') || (role === 'R' && _arciLimits.maxR > 0));
                if (showChk) {
                    if (READ) {
                        if (mem.is_incentivised) {
                            incentivisedHtml = '<span class="atem-arci-incentivised-badge">Incentivised</span>';
                        }
                    } else {
                        var maxForRole = (role === 'A') ? _arciLimits.maxA : _arciLimits.maxR;
                        var atMax = !mem.is_incentivised && countIncentivised(role) >= maxForRole;
                        incentivisedHtml = '<label class="atem-arci-incentivised">'
                            + '<input type="checkbox" class="atem-arci-incentivised-chk"'
                            + ' data-staff="' + parseInt(mem.staff_id, 10) + '" data-role="' + role + '"'
                            + (mem.is_incentivised ? ' checked' : '')
                            + (atMax ? ' disabled' : '') + '>'
                            + ' Incentivised</label>';
                    }
                }
                html += '<div class="atem-arci-member"><div class="atem-arci-member-info">'
                    + '<div class="atem-arci-member-dept">(' + escapeHtml(dn) + ')</div>'
                    + '<div class="atem-arci-member-name">' + escapeHtml(nm) + '</div>'
                    + '</div>'
                    + incentivisedHtml
                    + (READ || CFG.issuerCompletedEdit ? '' : '<span class="atem-arci-remove" data-staff="' + parseInt(mem.staff_id, 10) + '" data-role="' + role + '" title="Remove">&times;</span>')
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
        var queue = [];
        for (var i = 0; i < checks.length; i++) { queue.push(parseInt(checks[i].value, 10)); }
        function next() {
            if (queue.length === 0) { $('arci-role').value = ''; $('arci-dept-select').value = ''; $('arci-staff-search').value = ''; renderStaffList(); return; }
            var sid = queue.shift();
            apiCall('arci-add', { id: CFG.atemId, data: { staff_id: sid, staff_dept_id: deptId ? parseInt(deptId, 10) : null, role: role } }).then(function (res) {
                if (res && res.success) { setArciState(res.data); saveInline(); } else { setError('arci-error', res && res.message ? res.message : 'Failed to add member.'); }
                next();
            }).catch(function () { setError('arci-error', 'Network error while adding member.'); next(); });
        }
        next();
    }
    function removeMember(staffId, role) {
        apiCall('arci-remove', { id: CFG.atemId, staff_id: staffId, role: role }).then(function (res) {
            if (res && res.success) { setArciState(res.data); saveInline(); } else { setError('arci-error', res && res.message ? res.message : 'Failed to remove member.'); }
        });
    }
    var _pendingClear = {};
    function clearRole(role, btn) {
        if (_pendingClear[role]) {
            clearTimeout(_pendingClear[role]); delete _pendingClear[role];
            if (btn) { btn.textContent = 'Delete All'; btn.classList.remove('atem-arci-clear-confirm'); }
            apiCall('arci-remove-role', { id: CFG.atemId, role: role }).then(function (res) {
                if (res && res.success) { setArciState(res.data); saveInline(); } else { setError('arci-error', res && res.message ? res.message : 'Failed to clear role.'); }
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

        // R/C/I see only their own cards; Issuer and A see all.
        var visible = CAN_VIEW_ALL_PROGRESS
            ? progressUpdates
            : progressUpdates.filter(function (p) {
                return String(p.created_by) === String(CFG.staffId);
            });

        if (!visible.length) {
            wrap.innerHTML = '<div class="atem-empty-state">No progress updates recorded.</div>';
            return;
        }
        var sorted = visible.slice().sort(function (a, b) {
            return a.start_date < b.start_date ? -1 : a.start_date > b.start_date ? 1 : 0;
        });
        var html = '<div class="atem-progress-grid">';
        for (var i = 0; i < sorted.length; i++) {
            var p = sorted[i];
            var pillClass = 'atem-pill atem-pill-' + p.status;
            var isOwn = String(p.created_by) === String(CFG.staffId);
            var actionsHtml = isOwn ? '<div class="atem-progress-item-actions">'
                + '<button type="button" class="btn btn-outline-secondary btn-sm atem-progress-edit" data-id="' + p.id + '">Edit</button>'
                + '<button type="button" class="btn btn-outline-danger btn-sm atem-progress-delete" data-id="' + p.id + '">Delete</button>'
                + '</div>' : '';
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
        if (!IS_TAGGED_ON_CARD) { return; }
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
        setError('reflink-section-error', ''); setError('atem-save-error', '');
        if (!$('atem-title').value.trim()) { setError('atem-title-error', 'ATEM Title is required.'); return false; }
        if (!$('atem-level').value) { setError('atem-level-error', 'ATEM Complexity Levelis required.'); return false; }
        if (!$('tl-start').value) { setError('tl-start-error', 'Start Date is required.'); return false; }
        if (!$('tl-end').value) { setError('tl-end-error', 'End Date is required.'); return false; }
        if (!$('tl-status').value) {
            setError('tl-status-error', 'Status is required. Please select a status before saving.');
            return false;
        }
        var _tlStatusVal = '';
        (CFG.statuses || []).forEach(function (s) { if (String(s.id) === String($('tl-status').value)) { _tlStatusVal = s.value; } });
        if (_tlStatusVal === 'Extended' && !($('tl-ext1') && $('tl-ext1').value)) {
            setError('tl-status-error', 'Extended status requires an extended date. Please enter the extended date below.');
            return false;
        }
        var originalStatusValue = (REC.status && REC.status.value) ? REC.status.value : '';
        var MUST_CHANGE = ['Draft'];
        if (IS_ISSUER && MUST_CHANGE.indexOf(originalStatusValue) >= 0 && String($('tl-status').value) === String(REC.atem_status_id)) {
            setError('tl-status-error', 'The current status is "' + originalStatusValue + '". Please change the status before saving.');
            return false;
        }
        if ($('tl-extended').checked && !$('tl-ext1').value) {
            setError('atem-save-error', 'Extended Date 1 is required when the extended option is checked.');
            return false;
        }
        var level = selectedLevel();
        if (level && Number(level.incentive_value) > 0 && !$('atem-rule').value) {
            setError('atem-rule-error', 'Incentive Rule is required for Level 2-4.');
            return false;
        }
        if (!arciState.A || arciState.A.length === 0) {
            setError('arci-error', 'An Accountable (A) member is mandatory.');
            return false;
        }
        if (level && Number(level.incentive_value) > 0 && $('atem-rule').value) {
            var _arciErr = validateArciIncentive();
            if (_arciErr) { setError('arci-error', _arciErr); return false; }
        }
        if (!reflinks || reflinks.length === 0) {
            setError('reflink-section-error', 'At least one Reference Link is required.');
            return false;
        }
        return true;
    }
    function saveInline() {
        setError('atem-title-error', ''); setError('atem-level-error', ''); setError('atem-rule-error', ''); setError('atem-save-error', ''); setError('arci-error', '');
        if (!$('atem-title').value.trim()) { setError('atem-title-error', 'ATEM Title is required.'); return; }
        var level = selectedLevel();
        if (level && Number(level.incentive_value) > 0 && !$('atem-rule').value) {
            setError('atem-rule-error', 'Incentive Rule is required for Level 2-4.');
            return;
        }
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
            incentive_approved: !!(document.getElementById('tl-incentive-approve-yes') && document.getElementById('tl-incentive-approve-yes').checked),
            atem_status_id: $('tl-status').value ? parseInt($('tl-status').value, 10) : null,
            remarks: $('tl-remarks').value
        };
        apiCall('update-atem', { id: CFG.atemId, data: data }).then(function (res) {
            if (!res || !res.success) {
                setError('atem-save-error', res && res.message ? res.message : 'Failed to save.');
            }
        }).catch(function () { setError('atem-save-error', 'Network error while saving.'); });
    }
    function saveAtem() {
        if (!validateFinal()) { scrollToFirstError(); return; }
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
            incentive_approved: !!(document.getElementById('tl-incentive-approve-yes') && document.getElementById('tl-incentive-approve-yes').checked),
            atem_status_id: $('tl-status').value ? parseInt($('tl-status').value, 10) : null,
            remarks: $('tl-remarks').value
        };
        var btn = $('atem-save-btn');
        if (btn) { btn.disabled = true; btn.textContent = 'Saving...'; }
        apiCall('update-atem', { id: CFG.atemId, data: data }).then(function (res) {
            if (res && res.success) { window.location.href = 'atem/view.php'; }
            else { setError('atem-save-error', res && res.message ? res.message : 'Failed to save ATEM.'); scrollToFirstError(); if (btn) { btn.disabled = false; btn.textContent = 'Save ATEM'; } }
        }).catch(function () { setError('atem-save-error', 'Network error while saving.'); scrollToFirstError(); if (btn) { btn.disabled = false; btn.textContent = 'Save ATEM'; } });
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
        'progress_removed': 'bi-trash',
        'suspended':        'bi-slash-circle',
        'unsuspended':      'bi-check-circle'
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
                + '<div class="atem-audit-header"><span>' + ts + '</span> &mdash; <strong>' + actor + '</strong></div>'
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
                if ($('tl-ext1')) { $('tl-ext1').value = dateOnly(REC.extended_date_1); }
                if (!READ) { syncExtensionFields(); }
            }
        }
        var yesEl = document.getElementById('tl-incentive-approve-yes');
        var noEl  = document.getElementById('tl-incentive-approve-no');
        if (yesEl && noEl) {
            yesEl.checked = !!REC.incentive_approved;
            noEl.checked  = !REC.incentive_approved;
        }
        syncStatusOptions();
        syncIncentiveApproval();
        syncEndDateLock();
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

    function applyExtMins() {
        var ext1El = $('tl-ext1');
        if (!ext1El) { return; }
        if (CFG.backdate && CFG.backdate.enabled) { return; }
        var _d = new Date();
        var todayStr = _d.getFullYear() + '-' + (_d.getMonth() + 1 < 10 ? '0' + (_d.getMonth() + 1) : '' + (_d.getMonth() + 1)) + '-' + (_d.getDate() < 10 ? '0' + _d.getDate() : '' + _d.getDate());
        var endVal = $('tl-end') ? ($('tl-end').value || '') : '';
        var ext1Min = todayStr;
        if (endVal) {
            var endNext = new Date(endVal + 'T00:00:00');
            endNext.setDate(endNext.getDate() + 1);
            var endNextStr = endNext.getFullYear() + '-' + (endNext.getMonth() + 1 < 10 ? '0' + (endNext.getMonth() + 1) : '' + (endNext.getMonth() + 1)) + '-' + (endNext.getDate() < 10 ? '0' + endNext.getDate() : '' + endNext.getDate());
            if (endNextStr > todayStr) { ext1Min = endNextStr; }
        }
        ext1El.setAttribute('min', ext1Min);
    }

    function syncExtendedByStatus() {
        var extEl = $('tl-extended');
        if (!extEl || READ) { return; }
        var selId = $('tl-status') ? $('tl-status').value : '';
        var selVal = '';
        (CFG.statuses || []).forEach(function (s) { if (String(s.id) === String(selId)) { selVal = s.value; } });
        if (selVal === 'Draft') {
            extEl.setAttribute('disabled', 'disabled');
        } else {
            var ext1El = $('tl-ext1');
            var extLocked = ext1El && ext1El.disabled && ext1El.value;
            if (!extLocked) { extEl.removeAttribute('disabled'); }
        }
        if (selVal === 'Extended') {
            if (!extEl.checked && !extEl.disabled) {
                extEl.checked = true;
                syncExtensionFields();
            }
            if (!($('tl-ext1') && $('tl-ext1').value)) {
                setError('tl-status-error', 'Extended status requires an extended date. Please enter the extended date below.');
            }
        } else {
            setError('tl-status-error', '');
        }
    }

    function restrictDraftStatus() {
        if (CFG.superadminTerminalEdit) { return; }
        var sel = $('tl-status');
        if (!sel) { return; }
        var currentLabel = '';
        var selId = REC.atem_status_id ? String(REC.atem_status_id) : '';
        (CFG.statuses || []).forEach(function (s) { if (String(s.id) === selId) { currentLabel = s.value; } });
        if (!currentLabel || currentLabel === 'Draft') { return; }
        for (var i = sel.options.length - 1; i >= 0; i--) {
            if (sel.options[i].text === 'Draft') { sel.remove(i); break; }
        }
    }

    function lockDateFields() {
        if (READ) { return; }
        if ($('tl-start') && $('tl-start').value) { $('tl-start').setAttribute('disabled', 'disabled'); }
        syncEndDateLock();
        var ext1El = $('tl-ext1');
        if (ext1El && REC.extended_date_1) {
            ext1El.setAttribute('disabled', 'disabled');
            // Lock the checkbox too — a saved extension cannot be undone.
            var extEl = $('tl-extended');
            if (extEl) { extEl.setAttribute('disabled', 'disabled'); }
        }
    }

    function applyReadMode() {
        if (!READ) { return; }
        if (quillEditor) { quillEditor.disable(); }
        ['atem-title', 'atem-issuer', 'atem-department', 'atem-level', 'atem-rule', 'tl-start', 'tl-end',
            'tl-status', 'tl-final-due', 'tl-closure', 'tl-remarks', 'tl-extended', 'tl-ext1',
            'tl-incentive-approve-yes', 'tl-incentive-approve-no'].forEach(function (id) {
            var el = $(id);
            if (el) { el.setAttribute('disabled', 'disabled'); }
        });
    }

    // Locks all fields except Level, Rule, and Status for SuperAdmin editing a terminal card.
    function applyTerminalEditRestrictions() {
        if (quillEditor) { quillEditor.disable(); }
        ['atem-title', 'atem-issuer', 'atem-department',
         'tl-start', 'tl-end', 'tl-extended', 'tl-ext1',
         'tl-remarks', 'tl-incentive-approve-yes', 'tl-incentive-approve-no'].forEach(function (id) {
            var el = $(id);
            if (el) { el.setAttribute('disabled', 'disabled'); }
        });
        // tl-final-due and tl-closure are already disabled in HTML.
    }

    // Locks all editable fields except the status dropdown while the issuer's
    // Completed card still shows the original Completed/Excellence status.
    var ICE_LOCK_FIELDS = ['atem-title', 'atem-level', 'atem-rule',
        'tl-start', 'tl-end', 'tl-extended', 'tl-ext1',
        'tl-remarks', 'tl-incentive-approve-yes', 'tl-incentive-approve-no'];

    function applyIssuerCompletedLock() {
        if (quillEditor) { quillEditor.disable(); }
        ICE_LOCK_FIELDS.forEach(function (id) {
            var el = $(id);
            if (el) { el.setAttribute('disabled', 'disabled'); }
        });
    }

    function releaseIssuerCompletedLock() {
        ICE_LOCK_FIELDS.forEach(function (id) {
            var el = $(id);
            if (el) { el.removeAttribute('disabled'); }
        });
    }

    // --------------------------------------------------------------- wiring
    function bind() {
        $('atem-level').addEventListener('change', function () { recalcIncentive(); saveInline(); });
        $('atem-rule').addEventListener('change', function () {
            var _newLimits = getRuleLimits(selectedRule());
            var aInc = 0;
            (arciState['A'] || []).forEach(function (m) {
                if (m.is_incentivised) {
                    aInc++;
                    if (aInc > _newLimits.maxA) {
                        m.is_incentivised = false;
                        apiCall('arci-set-incentivised', { id: CFG.atemId, arci_id: m.id, is_incentivised: false });
                    }
                }
            });
            var rInc = 0;
            (arciState['R'] || []).forEach(function (m) {
                if (m.is_incentivised) {
                    if (_newLimits.maxR === 0) {
                        m.is_incentivised = false;
                        apiCall('arci-set-incentivised', { id: CFG.atemId, arci_id: m.id, is_incentivised: false });
                        return;
                    }
                    rInc++;
                    if (rInc > _newLimits.maxR) {
                        m.is_incentivised = false;
                        apiCall('arci-set-incentivised', { id: CFG.atemId, arci_id: m.id, is_incentivised: false });
                    }
                }
            });
            renderArci();
            saveInline();
        });
        if ($('atem-title')) { $('atem-title').addEventListener('blur', saveInline); }
        if (quillEditor) { quillEditor.on('text-change', function () { if (READ) { return; } clearTimeout(_inlineSaveTimer); _inlineSaveTimer = setTimeout(saveInline, 1500); }); }
        if ($('tl-end')) { $('tl-end').addEventListener('change', function () { recalcFinalDue(); syncEndDateLock(); showTimelineReminder(); }); }
        if ($('tl-extended')) {
            $('tl-extended').addEventListener('change', function () {
                var statusEl = $('tl-status');
                if (statusEl) {
                    if (this.checked) {
                        var extStatusId = '';
                        (CFG.statuses || []).forEach(function (s) { if (s.value === 'Extended') { extStatusId = String(s.id); } });
                        if (extStatusId) { statusEl.value = extStatusId; }
                    } else {
                        var activeStatusId = '';
                        (CFG.statuses || []).forEach(function (s) { if (s.value === 'Active') { activeStatusId = String(s.id); } });
                        if (activeStatusId) { statusEl.value = activeStatusId; }
                        setError('tl-status-error', '');
                    }
                }
                syncExtensionFields();
                recalcClosureDate();
                syncEndDateLock();
                applyExtMins();
                showTimelineReminder();
            });
        }
        if ($('tl-ext1')) {
            $('tl-ext1').addEventListener('change', function () {
                syncExtensionFields();
                syncEndDateLock();
                syncStatusOptions();
                syncIncentiveApproval();
                recalcClosureDate();
                showTimelineReminder();
            });
        }
        if ($('tl-status')) {
            $('tl-status').addEventListener('change', function () {
                recalcClosureDate(); syncExtendedByStatus(); syncEndDateLock(); applyExtMins();
                showTimelineReminder();
                if (CFG.issuerCompletedEdit) {
                    if (String(this.value) === String(REC.atem_status_id)) {
                        applyIssuerCompletedLock();
                    } else {
                        releaseIssuerCompletedLock();
                    }
                }
            });
        }
        if ($('tl-ext1')) {
            $('tl-ext1').addEventListener('change', function () {
                if (this.value) { setError('tl-status-error', ''); }
            });
        }
        var approvalRadios = document.querySelectorAll('input[name="tl-incentive-approval"]');
        for (var _r = 0; _r < approvalRadios.length; _r++) {
            approvalRadios[_r].addEventListener('change', function () {
                recalcIncentive();
                showTimelineReminder();
            });
        }

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
                } else if (t.classList.contains('atem-arci-incentivised-chk')) {
                    var chkStaff = parseInt(t.getAttribute('data-staff'), 10);
                    var chkRole = t.getAttribute('data-role');
                    var chkVal = t.checked;
                    if (chkVal) {
                        var chkLimits = getRuleLimits(selectedRule());
                        var chkMax = (chkRole === 'A') ? chkLimits.maxA : chkLimits.maxR;
                        if (countIncentivised(chkRole) >= chkMax) {
                            t.checked = false;
                            setError('arci-error', 'Maximum incentivised ' + chkRole + ' members (' + chkMax + ') already reached for this rule.');
                            return;
                        }
                    }
                    setError('arci-error', '');
                    (arciState[chkRole] || []).forEach(function (m) {
                        if (parseInt(m.staff_id, 10) === chkStaff) {
                            m.is_incentivised = chkVal;
                            apiCall('arci-set-incentivised', { id: CFG.atemId, arci_id: m.id, is_incentivised: chkVal }).then(function () {
                                saveInline();
                            });
                        }
                    });
                    recalcIncentive();
                    renderArci();
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
                if (CFG.superadminTerminalEdit) {
                    saveTerminalEdit();
                    return;
                }
                var selId = $('tl-status') ? $('tl-status').value : '';
                var selVal = '';
                (CFG.statuses || []).forEach(function (s) {
                    if (String(s.id) === String(selId)) { selVal = s.value; }
                });
                if (CFG.issuerCompletedEdit
                        && selId
                        && String(selId) !== String(REC.atem_status_id)
                        && TERMINAL_STATUSES.indexOf(selVal) === -1) {
                    var msgEl = $('atem-terminal-warn-msg');
                    if (msgEl) {
                        msgEl.textContent = 'You are reverting this ATEM from "' + (REC.status && REC.status.value ? REC.status.value : 'Completed') + '" to "' + selVal + '". The closure date will be cleared and the ATEM will be open for editing again. Proceed?';
                    }
                    var revertModal = new bootstrap.Modal($('atem-terminal-warn-modal'));
                    var okBtn = $('atem-terminal-warn-ok');
                    if (okBtn) {
                        var revertHandler = function () {
                            okBtn.removeEventListener('click', revertHandler);
                            revertModal.hide();
                            saveAtem();
                        };
                        okBtn.addEventListener('click', revertHandler);
                    }
                    revertModal.show();
                    return;
                }
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

        (function () {
            var suspendBtn = $('atem-suspend-btn');
            if (!suspendBtn) { return; }
            var _modal = null;
            function getSuspendModal() {
                if (!_modal && typeof bootstrap !== 'undefined') {
                    _modal = new bootstrap.Modal($('atem-suspend-modal'));
                    $('atem-suspend-confirm-btn').addEventListener('click', function () {
                        var remarks = $('suspend-remarks') ? $('suspend-remarks').value.trim() : '';
                        if (!remarks) {
                            setError('suspend-remarks-error', 'Reason is required.');
                            return;
                        }
                        setError('suspend-remarks-error', '');
                        apiCall('suspend-atem', { id: CFG.atemId, remarks: remarks }).then(function (res) {
                            if (res && res.success) {
                                window.location.reload();
                            } else {
                                if (_modal) { _modal.hide(); }
                                setError('atem-save-error', res && res.message ? res.message : 'Failed to suspend ATEM.');
                            }
                        }).catch(function () {
                            if (_modal) { _modal.hide(); }
                            setError('atem-save-error', 'Network error while suspending.');
                        });
                    });
                }
                return _modal;
            }
            suspendBtn.addEventListener('click', function () {
                if ($('suspend-remarks')) { $('suspend-remarks').value = ''; }
                setError('suspend-remarks-error', '');
                var m = getSuspendModal();
                if (m) { m.show(); }
            });
        }());

        (function () {
            var unsuspendBtn = $('atem-unsuspend-btn');
            if (!unsuspendBtn) { return; }
            var _modal = null;
            function getUnsuspendModal() {
                if (!_modal && typeof bootstrap !== 'undefined') {
                    _modal = new bootstrap.Modal($('atem-unsuspend-modal'));
                    $('atem-unsuspend-confirm-btn').addEventListener('click', function () {
                        apiCall('unsuspend-atem', { id: CFG.atemId }).then(function (res) {
                            if (res && res.success) {
                                window.location.reload();
                            } else {
                                if (_modal) { _modal.hide(); }
                                setError('atem-save-error', res && res.message ? res.message : 'Failed to unsuspend ATEM.');
                            }
                        }).catch(function () {
                            if (_modal) { _modal.hide(); }
                            setError('atem-save-error', 'Network error while unsuspending.');
                        });
                    });
                }
                return _modal;
            }
            unsuspendBtn.addEventListener('click', function () {
                var m = getUnsuspendModal();
                if (m) { m.show(); }
            });
        }());
    }

    function saveTerminalEdit() {
        var levelVal  = parseInt(($('atem-level') || {}).value, 10) || null;
        var ruleVal   = parseInt(($('atem-rule')  || {}).value, 10) || null;
        var statusVal = parseInt(($('tl-status')  || {}).value, 10) || null;

        if (!levelVal)  { setError('atem-save-error', 'Please select a complexity level.'); return; }
        if (!statusVal) { setError('atem-save-error', 'Please select a status.'); return; }
        setError('atem-save-error', '');

        var btn = $('atem-save-btn');
        if (btn) { btn.disabled = true; btn.textContent = 'Saving...'; }

        apiCall('update-atem', {
            id: CFG.atemId,
            data: {
                title:              REC.title,
                description:        REC.description        || null,
                level_structure_id: levelVal,
                incentive_rule_id:  ruleVal,
                start_date:         REC.start_date          ? dateOnly(REC.start_date)      : null,
                end_date:           REC.end_date             ? dateOnly(REC.end_date)         : null,
                is_extended:        REC.is_extended          || false,
                extended_date_1:    REC.extended_date_1      ? dateOnly(REC.extended_date_1) : null,
                atem_status_id:     statusVal,
                remarks:            REC.remarks             || null,
                incentive_approved: false
            }
        }).then(function (res) {
            if (btn) { btn.disabled = false; btn.textContent = 'Save ATEM'; }
            if (res && res.success) {
                window.location.href = 'atem/view.php';
            } else {
                setError('atem-save-error', res && res.message ? res.message : 'Failed to save.');
            }
        }).catch(function () {
            if (btn) { btn.disabled = false; btn.textContent = 'Save ATEM'; }
            setError('atem-save-error', 'Network error. Please try again.');
        });
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
        restrictDraftStatus();
        syncExtendedByStatus();
        applyExtMins();
        lockDateFields();
        injectBadge();
        applyReadMode();
        if (CFG.superadminTerminalEdit) { applyTerminalEditRestrictions(); }
        if (CFG.issuerCompletedEdit) { applyIssuerCompletedLock(); }
        if (!READ && !IS_ISSUER && !CFG.superadminTerminalEdit) {
            ['tl-start', 'tl-end', 'tl-status', 'tl-extended', 'tl-ext1', 'tl-remarks',
             'tl-incentive-approve-yes', 'tl-incentive-approve-no'].forEach(function (id) {
                var el = document.getElementById(id);
                if (el) { el.disabled = true; }
            });
        }
        recalcClosureDate();
    });
})();
