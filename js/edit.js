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
    // True for any user tagged on the card (Issuer or any ARCI role A/R/C/I).
    var IS_TAGGED_ON_CARD = IS_ISSUER || (function () {
        var arci = REC.arci || [];
        for (var i = 0; i < arci.length; i++) {
            if (String(arci[i].staff_id) === String(CFG.staffId)) { return true; }
        }
        return false;
    }());
    // Issuer, all ARCI members (A/R/C/I), and SuperAdmin see all progress entries.
    var CAN_VIEW_ALL_PROGRESS = IS_TAGGED_ON_CARD || !!CFG.isSuperAdmin;
    var TERMINAL_STATUSES = ['Failed', 'Completed', 'Completed with Excellence', 'Completed with Extension'];
    var COMPLETION_STATUSES = ['Completed', 'Completed with Excellence', 'Completed with Extension'];
    var quillEditor = null;
    var arciState = { A: [], R: [], C: [], I: [] };
    var reflinks = [];
    var attachments = [];
    var progressUpdates = [];
    var chatMessages = [];
    var _chatPollTimer = null;
    var outletTags = []; // [{ id, code }] - derived from areaManagerTags, read-only
    var areaManagerTags = []; // [{ id, name, position, outlet_ids }] - outlet-type ATEMs only
    var _inlineSaveTimer = null;
    var _lastCalcIncentive = 'RM0.00';

    function $(id) { return document.getElementById(id); }
    function money(n) { return 'RM' + (Math.round((Number(n) || 0) * 100) / 100).toFixed(2); }
    function dateOnly(v) { return v ? String(v).substring(0, 10) : ''; }

    // Today as a local-timezone YYYY-MM-DD string (toISOString() would shift
    // the date across midnight UTC).
    function localTodayStr() {
        var d = new Date();
        var m = d.getMonth() + 1, day = d.getDate();
        return d.getFullYear() + '-' + (m < 10 ? '0' + m : '' + m) + '-' + (day < 10 ? '0' + day : '' + day);
    }

    function escapeHtml(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }

    // A saved status change here is pushed onto the linked OKR Key Result/
    // Subtask (okr_key_results.atem_id) too, only when the current user is
    // literally the Issuer of this ATEM - okr/backend.php independently
    // re-verifies OKR-side issuership from its own DB before applying
    // anything, this is just the trigger. Same-origin as okr/backend.php
    // (both live under odb/), so no extra auth bridging is needed.
    function syncOkrKeyResultStatus(atemData) {
        if (!atemData || !atemData.okr_id || !CFG.okrBackendUrl) { return; }
        if (!IS_ISSUER) { return; }
        var statusValue = atemData.status && atemData.status.value;
        if (!statusValue) { return; }

        var body = new URLSearchParams();
        body.set('action', 'syncKeyResultStatusFromAtem');
        body.set('atem_id', atemData.id);
        body.set('status_value', statusValue);
        body.set('atem_issuer_staff_id', atemData.issuer_staff_id);
        fetch(CFG.okrBackendUrl, { method: 'POST', body: body }).catch(function () {});
    }

    function apiCall(action, payload) {
        var body = { action: action };
        if (payload) { for (var k in payload) { if (payload.hasOwnProperty(k)) { body[k] = payload[k]; } } }
        return fetch(CFG.apiUrl, {
            method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body)
        }).then(function (r) { return r.json(); }).then(function (res) {
            if (action === 'update-atem' && res && res.success && res.data) {
                syncOkrKeyResultStatus(res.data);
            }
            return res;
        });
    }

    function uploadCall(formData) {
        return fetch(CFG.apiUrl, { method: 'POST', body: formData }).then(function (r) { return r.json(); });
    }

    function setError(id, msg) { var el = $(id); if (el) { el.textContent = msg || ''; } }

    function scrollToFirstError() {
        var ids = ['atem-title-error', 'atem-level-error', 'atem-rule-error', 'tl-start-error',
                   'tl-end-error', 'tl-status-error', 'tl-closure-error', 'tl-remarks-error', 'arci-error', 'reflink-section-error', 'atem-save-error'];
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
        fillSelect($('atem-pillars'), CFG.pillars || [], 'id', function (p) { return p.name; }, 'Select pillar');
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
        // Force Terminated also closes the card (mirrors AtemController::update()'s
        // $closesCard) but is deliberately kept out of the shared TERMINAL_STATUSES
        // list itself, since that list also gates the Reward Decision UI elsewhere.
        var closesCard = TERMINAL_STATUSES.indexOf(selVal) >= 0 || selVal === 'Force Terminated';
        if (closesCard) {
            // Post-unsuspend deferred closure date (needsClosureDate) and the
            // CEO/SuperAdmin direct picker (canPickClosureDate): never
            // auto-fill today's date here - the field must stay blank until a
            // date is consciously picked, so validateFinal()'s required check
            // can catch an ignored field (issuer flow) and the field mirrors
            // the real stored value rather than faking one (CEO/SA flow).
            if ((CFG.needsClosureDate || CFG.canPickClosureDate) && !closureEl.value) {
                return;
            }
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

    // Force Terminated must always carry a remark explaining why - mirrors the
    // Extended Date required-asterisk toggle pattern (tl-ext1-req above).
    // Enforced for real in validateFinal(); this only keeps the label in sync.
    function syncRemarksRequired() {
        var reqEl = $('tl-remarks-req');
        if (!reqEl) { return; }
        var statusEl = $('tl-status');
        var selVal = '';
        if (statusEl) {
            (CFG.statuses || []).forEach(function (s) { if (String(s.id) === String(statusEl.value)) { selVal = s.value; } });
        }
        reqEl.style.display = (!READ && selVal === 'Force Terminated') ? '' : 'none';
    }

    // Start Date and End Date cannot be changed once the card has been created
    // (i.e. once a value exists) - matches lockDateFields()'s Start Date rule.
    function syncEndDateLock() {
        var endEl = $('tl-end');
        if (!endEl || !endEl.value) { return; }
        endEl.setAttribute('disabled', 'disabled');
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
                if (recStatusVal === 'Suspended') {
                    // SuperAdmin editing a suspended card: only Active, Force
                    // Terminate, or the current status (not the usual "-> Draft").
                    if (['Active', 'Force Terminated'].indexOf(s.value) === -1 && String(s.id) !== String(REC.atem_status_id)) { return; }
                } else if (s.value !== 'Draft' && String(s.id) !== String(REC.atem_status_id)) {
                    // SuperAdmin editing another terminal card: only offer Draft or the current status.
                    return;
                }
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

    function syncRewardDecision() {
        var wrap = $('tl-reward-decision-wrap');
        if (!wrap) { return; }
        var statusEl = $('tl-status');
        var selVal = '';
        if (statusEl) {
            (CFG.statuses || []).forEach(function (s) { if (String(s.id) === String(statusEl.value)) { selVal = s.value; } });
        }
        var atemType = (REC && REC.atem_type) ? parseInt(REC.atem_type, 10) : 1;
        var show = (atemType === 1) && TERMINAL_STATUSES.indexOf(selVal) >= 0;
        wrap.style.display = show ? '' : 'none';
    }

    // HQ cards show Complexity Level / Incentive Rule and the Estimated
    // Incentive breakdown. Outlet cards show 5 Pillars / Reward (a descriptive
    // label, not a monetary breakdown - see js/create.js). The type is fixed
    // at creation, so this is a one-time toggle from REC, not a live user
    // choice like on create.php.
    function applyAtemTypeView() {
        var isHq = (parseInt(REC.atem_type, 10) || 1) === 1;
        var hqOnly = document.querySelectorAll('.atem-hq-only');
        var outletOnly = document.querySelectorAll('.atem-outlet-only');
        for (var i = 0; i < hqOnly.length; i++) { hqOnly[i].classList.toggle('atem-hidden', !isHq); }
        for (var j = 0; j < outletOnly.length; j++) { outletOnly[j].classList.toggle('atem-hidden', isHq); }

        var incentiveSection = $('atem-incentive-section');
        if (incentiveSection) { incentiveSection.classList.toggle('atem-hidden', !isHq); }
    }

    // --------------------------------------------------- area manager tagging
    // Outlet Staff(s) are sourced by department/grade, not a single fixed
    // position, so a match may have no position_rymnet row - omit the
    // parenthetical entirely rather than showing "(...)" empty.
    function amLabel(am) {
        return am.position ? (am.name + ' (' + am.position + ')') : am.name;
    }

    function renderAreaManagerTags() {
        var wrap = $('atem-am-tags');
        if (!wrap) { return; }
        if (!areaManagerTags.length) {
            wrap.innerHTML = '<span class="atem-empty-state">No outlet staff tagged.</span>';
            return;
        }
        var html = '';
        for (var i = 0; i < areaManagerTags.length; i++) {
            var label = amLabel(areaManagerTags[i]);
            html += '<span class="atem-outlet-tag">' + escapeHtml(label)
                + (READ ? '' : '<span class="atem-outlet-tag-remove" data-id="' + areaManagerTags[i].id + '">&times;</span>')
                + '</span>';
        }
        wrap.innerHTML = html;
    }

    function syncAreaManagerPickerSelection() {
        var listEl = $('atem-am-picker-list');
        if (!listEl) { return; }
        var items = listEl.querySelectorAll('li');
        for (var i = 0; i < items.length; i++) {
            var id = parseInt(items[i].getAttribute('data-id'), 10) || 0;
            items[i].classList.toggle('selected', areaManagerTags.some(function (m) { return m.id === id; }));
        }
    }

    function addAreaManagerTag(id) {
        if (areaManagerTags.some(function (m) { return m.id === id; })) { return; }
        var am = (CFG.areaManagers || []).filter(function (a) { return a.id === id; })[0];
        if (!am) { return; }
        areaManagerTags.push({ id: am.id, name: am.name, position: am.position, outlet_ids: am.outlet_ids });
        renderAreaManagerTags();
        syncAreaManagerPickerSelection();
        recomputeDerivedOutlets();
        autoAddAreaManagerToArci(am);
        if (!READ) { saveInline(); }
    }

    // Area Managers are automatically tagged as Accountable (A) in the
    // Project Team, since they own the outlet(s) in scope. Both
    // staff_dept_id and outlet_id are left null - they aren't scoped to a
    // single outlet/department like other members. Skipped if the staff is
    // already assigned to any ARCI role (atem_arci is unique per staff per
    // card).
    function autoAddAreaManagerToArci(am) {
        if (READ) { return; }
        if (assignedStaffIds().indexOf(am.id) >= 0) { return; }
        apiCall('arci-add', { id: CFG.atemId, data: { staff_id: am.id, staff_dept_id: null, outlet_id: null, role: 'A' } }).then(function (res) {
            if (res && res.success) {
                setArciState(res.data);
            } else {
                setError('arci-error', res && res.message ? res.message : 'Failed to auto-add Outlet Staff to Accountable.');
            }
        }).catch(function () {
            setError('arci-error', 'Network error while adding Outlet Staff to Accountable.');
        });
    }

    function removeAreaManagerTag(id) {
        areaManagerTags = areaManagerTags.filter(function (m) { return m.id !== id; });
        renderAreaManagerTags();
        syncAreaManagerPickerSelection();
        recomputeDerivedOutlets();
        if (!READ) { saveInline(); }
    }

    function buildAreaManagerPicker() {
        var listEl   = $('atem-am-picker-list');
        var searchEl = $('atem-am-picker-search');
        var btnEl    = $('atem-am-picker-btn');
        var dropEl   = $('atem-am-picker-dropdown');
        var wrapEl   = $('atem-am-picker-wrap');
        if (!listEl || !btnEl || !dropEl) { return; }

        var managers = CFG.areaManagers || [];
        var html = '';
        for (var i = 0; i < managers.length; i++) {
            var label = amLabel(managers[i]);
            html += '<li data-id="' + managers[i].id + '">' + escapeHtml(label) + '</li>';
        }
        listEl.innerHTML = html || '<div class="atem-outlet-picker-empty">No outlet staff available</div>';
        syncAreaManagerPickerSelection();

        function openDropdown() {
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
            addAreaManagerTag(parseInt(li.getAttribute('data-id'), 10) || 0);
        });
        document.addEventListener('click', function (e) {
            if (wrapEl && !wrapEl.contains(e.target)) { closeDropdown(); }
        });

        var tagsWrap = $('atem-am-tags');
        if (tagsWrap) {
            tagsWrap.addEventListener('click', function (e) {
                if (e.target.classList.contains('atem-outlet-tag-remove')) {
                    removeAreaManagerTag(parseInt(e.target.getAttribute('data-id'), 10) || 0);
                }
            });
        }
    }

    // Unions outlet_ids across all selected Area Managers, keeps the derived
    // outlet id/code set used by the outlet-mode ARCI "Outlet" select/staff
    // list, and warns (inline banner, never a
    // native dialog) if any existing ARCI member now references an outlet
    // that dropped out of the derived set.
    function recomputeDerivedOutlets() {
        var outletsById = {};
        (CFG.outlets || []).forEach(function (o) { outletsById[o.id] = o.code; });

        var seen = {};
        var unionIds = [];
        areaManagerTags.forEach(function (m) {
            (m.outlet_ids || []).forEach(function (oid) {
                if (!seen[oid]) { seen[oid] = true; unionIds.push(oid); }
            });
        });

        outletTags = unionIds
            .filter(function (id) { return outletsById.hasOwnProperty(id); })
            .map(function (id) { return { id: id, code: outletsById[id] }; });

        populateDepartments();
        renderStaffList();
        checkArciOutletOrphans();
    }

    // Compares each currently-tagged ARCI member's outlet_id against the
    // current derived outlet set. Members referencing an outlet no longer in
    // scope are NOT removed - just flagged via the inline warning banner so
    // the issuer can recheck them. Department-scoped members (HQ staff
    // tagged C/I on an Outlet ATEM) have no outlet_id and are never flagged.
    function checkArciOutletOrphans() {
        var warnEl = $('atem-arci-orphan-warning');
        var textEl = $('atem-arci-orphan-warning-text');
        if (!warnEl || !textEl) { return; }
        var isOutletCard = (parseInt(REC.atem_type, 10) || 1) === 2;
        if (!isOutletCard) { warnEl.classList.add('atem-hidden'); return; }

        var validOutletIds = {};
        outletTags.forEach(function (o) { validOutletIds[o.id] = true; });

        var orphanNames = [];
        ['A', 'R', 'C', 'I'].forEach(function (role) {
            (arciState[role] || []).forEach(function (m) {
                var oid = parseInt(m.outlet_id, 10) || 0;
                if (oid && !validOutletIds[oid]) { orphanNames.push(m.staff_name); }
            });
        });

        if (orphanNames.length) {
            textEl.textContent = 'The following Project Team member(s) are tagged to an outlet no longer covered by the selected Outlet Staff(s) - please recheck: ' + orphanNames.join(', ');
            warnEl.classList.remove('atem-hidden');
        } else {
            warnEl.classList.add('atem-hidden');
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
    function outletCodeForArci(outletId) {
        var o = CFG.outlets || [];
        for (var i = 0; i < o.length; i++) { if (String(o[i].id) === String(outletId)) { return o[i].code; } }
        return '';
    }
    function staffNameIn(deptId, staffId) {
        var list = (CFG.staffByDept && CFG.staffByDept[deptId]) ? CFG.staffByDept[deptId] : [];
        for (var i = 0; i < list.length; i++) { if (parseInt(list[i].id, 10) === parseInt(staffId, 10)) { return list[i].name; } }
        return '';
    }
    function staffNameInOutlet(outletId, staffId) {
        var list = (CFG.staffByOutlet && CFG.staffByOutlet[outletId]) ? CFG.staffByOutlet[outletId] : [];
        for (var i = 0; i < list.length; i++) { if (parseInt(list[i].id, 10) === parseInt(staffId, 10)) { return list[i].name; } }
        return '';
    }
    // Resolves display name/scope-label for a freshly-added ARCI member on an
    // Outlet ATEM (the arci-add response has no resolved names). A member can
    // be outlet-scoped, department-scoped (HQ staff on C/I), or neither (an
    // auto-added Area Manager, who spans every outlet on the card).
    function resolveOutletArciDisplay(mem) {
        if (mem.outlet_id) {
            return { name: staffNameInOutlet(mem.outlet_id, mem.staff_id), dept: outletCodeForArci(mem.outlet_id) };
        }
        if (mem.staff_dept_id) {
            return { name: staffNameIn(mem.staff_dept_id, mem.staff_id), dept: deptName(mem.staff_dept_id) };
        }
        var am = (CFG.areaManagers || []).filter(function (a) { return parseInt(a.id, 10) === parseInt(mem.staff_id, 10); })[0];
        if (am) { return { name: am.name + ' (' + am.position + ')', dept: 'All Outlets' }; }
        return { name: '', dept: '' };
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
                var _isOutletMem = (parseInt(REC.atem_type, 10) || 1) === 2;
                var _resolved = _isOutletMem
                    ? resolveOutletArciDisplay(mem)
                    : { name: staffNameIn(mem.staff_dept_id, mem.staff_id), dept: deptName(mem.staff_dept_id) };
                var nm = mem.staff_name || _resolved.name || ('Staff #' + mem.staff_id);
                var dn = mem.department_name || _resolved.dept;
                var incentivisedHtml = '';
                var _arciRule = selectedRule();
                var _arciLimits = getRuleLimits(_arciRule);
                var _lvl = selectedLevel();
                var _isLevel1 = _lvl && Number(_lvl.incentive_value) === 0;
                var isOutletCard = (parseInt(REC.atem_type, 10) || 1) === 2;
                var showChk = !_isLevel1 && !isOutletCard && ((role === 'A') || (role === 'R' && _arciLimits.maxR > 0));
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
    // On Outlet-type cards, a radio toggle (#arci-scope-outlet /
    // #arci-scope-department) lets the issuer add either outlet-scoped staff
    // or HQ department staff (e.g. for a C/I role) to the Project Team.
    // arciScope is only meaningful when the card is Outlet-type; HQ-type
    // cards always use department scope.
    var arciScope = 'outlet';

    function currentArciScope() {
        var isOutletCard = (parseInt(REC.atem_type, 10) || 1) === 2;
        return isOutletCard ? arciScope : 'department';
    }

    function populateDepartments() {
        var sel = $('arci-dept-select'); if (!sel) { return; }
        var labelEl = $('arci-dept-label');
        var searchEl = $('arci-dept-search');
        var scopeToggle = $('arci-scope-toggle');
        var isOutletCard = (parseInt(REC.atem_type, 10) || 1) === 2;
        if (scopeToggle) { scopeToggle.classList.toggle('atem-hidden', !isOutletCard); }
        if (labelEl) { labelEl.textContent = isOutletCard ? 'Scope' : 'Department'; }

        if (isOutletCard && currentArciScope() === 'outlet') {
            if (searchEl) { searchEl.placeholder = 'Search outlet...'; }
            sel.innerHTML = '<option value="">Select outlet</option>';
            for (var j = 0; j < outletTags.length; j++) {
                var oo = document.createElement('option'); oo.value = outletTags[j].id; oo.textContent = outletTags[j].code; sel.appendChild(oo);
            }
            return;
        }

        if (searchEl) { searchEl.placeholder = 'Search department...'; }
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
        var isOutletScope = (currentArciScope() === 'outlet');
        if (!deptId) { listDiv.innerHTML = '<div class="text-muted" style="font-size:13px;">Select ' + (isOutletScope ? 'an outlet' : 'a department') + ' to load staff</div>'; return; }
        var staff = isOutletScope
            ? ((CFG.staffByOutlet && CFG.staffByOutlet[deptId]) ? CFG.staffByOutlet[deptId] : [])
            : ((CFG.staffByDept && CFG.staffByDept[deptId]) ? CFG.staffByDept[deptId] : []);
        var assigned = assignedStaffIds(), term = $('arci-staff-search').value.toLowerCase(), html = '';
        for (var i = 0; i < staff.length; i++) {
            if (assigned.indexOf(parseInt(staff[i].id, 10)) >= 0) { continue; }
            if (term && staff[i].name.toLowerCase().indexOf(term) < 0) { continue; }
            var displayName = (isOutletScope && staff[i].position) ? (staff[i].name + ' (' + staff[i].position + ')') : staff[i].name;
            html += '<label class="atem-arci-staff-item"><input type="checkbox" value="' + parseInt(staff[i].id, 10) + '" data-name="' + escapeHtml(displayName) + '"> <span>' + escapeHtml(displayName) + '</span></label>';
        }
        listDiv.innerHTML = html || '<div class="text-muted" style="font-size:13px;">No staff available</div>';
    }
    function addSelectedMembers() {
        setError('arci-error', '');
        var role = $('arci-role').value;
        if (!role) { setError('arci-error', 'Please select a role first.'); return; }
        var scopeId = $('arci-dept-select').value;
        var checks = $('arci-staff-list').querySelectorAll('input[type="checkbox"]:checked');
        if (checks.length === 0) { setError('arci-error', 'Please select at least one staff member.'); return; }
        var isOutletScope = (currentArciScope() === 'outlet');
        var queue = [];
        for (var i = 0; i < checks.length; i++) { queue.push(parseInt(checks[i].value, 10)); }
        function next() {
            if (queue.length === 0) { $('arci-role').value = ''; $('arci-dept-select').value = ''; $('arci-staff-search').value = ''; renderStaffList(); return; }
            var sid = queue.shift();
            var payload = {
                staff_id: sid,
                staff_dept_id: (!isOutletScope && scopeId) ? parseInt(scopeId, 10) : null,
                outlet_id: (isOutletScope && scopeId) ? parseInt(scopeId, 10) : null,
                role: role
            };
            apiCall('arci-add', { id: CFG.atemId, data: payload }).then(function (res) {
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
                + ((READ && !CFG.suspendedIssuerEdit) ? '' : '<span class="atem-reflink-remove" data-id="' + parseInt(reflinks[i].id, 10) + '" title="Remove">&times;</span>') + '</div></li>';
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
                + ((READ && !CFG.suspendedIssuerEdit) ? '' : '<span class="atem-file-remove" data-att="' + parseInt(a.id, 10) + '" title="Remove">&times;</span>') + '</div>';
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
        setError('tl-closure-error', '');
        setError('tl-remarks-error', '');
        setError('reflink-section-error', ''); setError('atem-save-error', ''); setError('tl-reward-decision-error', '');
        setError('atem-am-error', ''); setError('atem-reward-label-error', '');
        var isOutletType = (parseInt(REC.atem_type, 10) || 1) === 2;
        if (!$('atem-title').value.trim()) { setError('atem-title-error', 'ATEM Title is required.'); return false; }
        if (isOutletType) {
            if (!areaManagerTags.length) { setError('atem-am-error', 'At least one Outlet Staff is required.'); return false; }
        } else if (!$('atem-level').value) {
            setError('atem-level-error', 'ATEM Complexity Levelis required.'); return false;
        }
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
        if (_tlStatusVal === 'Force Terminated' && !$('tl-remarks').value.trim()) {
            setError('tl-remarks-error', 'A remark is required when force terminating an ATEM.');
            return false;
        }
        var COMPLETED_LIKE_STATUSES = ['Completed', 'Completed with Excellence'];
        if (CFG.needsClosureDate && COMPLETED_LIKE_STATUSES.indexOf(_tlStatusVal) >= 0) {
            var _closureVal = $('tl-closure').value;
            if (!_closureVal) {
                setError('tl-closure-error', 'Please set the closure date before saving.');
                return false;
            }
            // YYYY-MM-DD strings compare correctly as plain strings.
            var _closureMin = dateOnly(REC.start_date);
            if ((_closureMin && _closureVal < _closureMin) || _closureVal > localTodayStr()) {
                setError('tl-closure-error', 'Closure date must be between the start date and today.');
                return false;
            }
        }
        var originalStatusValue = (REC.status && REC.status.value) ? REC.status.value : '';
        var MUST_CHANGE = ['Draft'];
        if (IS_ISSUER && MUST_CHANGE.indexOf(originalStatusValue) >= 0 && String($('tl-status').value) === String(REC.atem_status_id)) {
            setError('tl-status-error', 'The current status is "' + originalStatusValue + '". Please change the status before saving.');
            return false;
        }
        // Non-issuer SuperAdmin changing status on a non-terminal card must explain why.
        // Terminal-original-status cards go through applyTerminalEditRestrictions() instead,
        // which locks tl-remarks entirely, so they're excluded here.
        var SA_TERMINAL_STATUSES = ['Completed', 'Failed', 'Completed with Extension'];
        var isNonIssuerSuperAdminStatusEdit = !!CFG.isSuperAdmin && !IS_ISSUER
            && SA_TERMINAL_STATUSES.indexOf(originalStatusValue) < 0
            && String($('tl-status').value) !== String(REC.atem_status_id);
        if (isNonIssuerSuperAdminStatusEdit && !$('tl-remarks').value.trim()) {
            setError('tl-remarks-error', 'A remark is required when changing the status of an ATEM you did not issue.');
            return false;
        }
        if ($('tl-extended').checked && !$('tl-ext1').value) {
            setError('atem-save-error', 'Extended Date 1 is required when the extended option is checked.');
            return false;
        }
        var level = selectedLevel();
        if (!isOutletType && level && Number(level.incentive_value) > 0 && !$('atem-rule').value) {
            setError('atem-rule-error', 'Incentive Rule is required for Level 2-4.');
            return false;
        }
        if (!arciState.A || arciState.A.length === 0) {
            setError('arci-error', 'An Accountable (A) member is mandatory.');
            return false;
        }
        if (!isOutletType && level && Number(level.incentive_value) > 0 && $('atem-rule').value) {
            var _arciErr = validateArciIncentive();
            if (_arciErr) { setError('arci-error', _arciErr); return false; }
        }
        if (!reflinks || reflinks.length === 0) {
            setError('reflink-section-error', 'At least one Reference Link is required.');
            return false;
        }
        var rewardWrap = $('tl-reward-decision-wrap');
        if (rewardWrap && rewardWrap.style.display !== 'none' && !document.querySelector('input[name="tl-reward-decision"]:checked')) {
            setError('tl-reward-decision-error', 'Please choose whether this ATEM is Rewarded or Deducted.');
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
        var pillarId = $('atem-pillars') ? $('atem-pillars').value : '';
        var rewardLabel = $('atem-reward-label') ? $('atem-reward-label').value : '';
        var description = quillEditor ? ((quillEditor.getText().trim() === '') ? '' : quillEditor.root.innerHTML) : '';
        var data = {
            title: $('atem-title').value.trim(),
            description: description,
            level_structure_id: levelId ? parseInt(levelId, 10) : null,
            incentive_rule_id: ruleId ? parseInt(ruleId, 10) : null,
            pillar_id: pillarId ? parseInt(pillarId, 10) : null,
            reward_label: rewardLabel || null,
            outlet_ids: outletTags.map(function (o) { return o.id; }),
            area_manager_ids: areaManagerTags.map(function (m) { return m.id; }),
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
    function getSelectedStatusValue() {
        var selId = $('tl-status') ? $('tl-status').value : '';
        var selVal = '';
        (CFG.statuses || []).forEach(function (s) { if (String(s.id) === String(selId)) { selVal = s.value; } });
        return selVal;
    }
    function hasOutcomeAttachmentLink() {
        return (reflinks || []).some(function (r) { return (r.name || '').trim().toLowerCase() === 'outcome attachment'; });
    }
    var _outcomeAttachmentModal = null;
    function getOutcomeAttachmentModal() {
        if (!_outcomeAttachmentModal && typeof bootstrap !== 'undefined') { _outcomeAttachmentModal = new bootstrap.Modal($('atem-outcome-attachment-modal')); }
        return _outcomeAttachmentModal;
    }
    function openOutcomeAttachmentModal() {
        setError('outcome-attachment-error', '');
        if ($('outcome-attachment-url')) { $('outcome-attachment-url').value = ''; }
        var m = getOutcomeAttachmentModal();
        if (m) { m.show(); } else { performSaveAtem(); }
    }
    function attachOutcomeAndSave() {
        setError('outcome-attachment-error', '');
        var url = $('outcome-attachment-url') ? $('outcome-attachment-url').value.trim() : '';
        if (!url) { setError('outcome-attachment-error', 'Please enter the outcome link.'); return; }
        try { new URL(url); } catch (e) { setError('outcome-attachment-error', 'Please enter a valid URL.'); return; }
        var btn = $('outcome-attachment-save-btn');
        if (btn) { btn.disabled = true; btn.textContent = 'Attaching...'; }
        apiCall('reflink-add', { id: CFG.atemId, data: { name: 'Outcome Attachment', url: url } }).then(function (res) {
            if (btn) { btn.disabled = false; btn.textContent = 'Attach & Save'; }
            if (res && res.success) {
                reflinks = res.data || [];
                renderReferenceLinks();
                var m = getOutcomeAttachmentModal(); if (m) { m.hide(); }
                performSaveAtem();
            } else {
                setError('outcome-attachment-error', (res && res.message) ? res.message : 'Failed to attach outcome link.');
            }
        }).catch(function () {
            if (btn) { btn.disabled = false; btn.textContent = 'Attach & Save'; }
            setError('outcome-attachment-error', 'Network error while attaching outcome link.');
        });
    }
    function saveAtem() {
        if (!validateFinal()) { scrollToFirstError(); return; }
        if (COMPLETION_STATUSES.indexOf(getSelectedStatusValue()) >= 0 && !hasOutcomeAttachmentLink()) {
            openOutcomeAttachmentModal();
            return;
        }
        performSaveAtem();
    }
    function performSaveAtem() {
        var levelId = $('atem-level').value, ruleId = $('atem-rule').value;
        var pillarId = $('atem-pillars') ? $('atem-pillars').value : '';
        var rewardLabel = $('atem-reward-label') ? $('atem-reward-label').value : '';
        var description = quillEditor ? ((quillEditor.getText().trim() === '') ? '' : quillEditor.root.innerHTML) : '';
        var data = {
            title: $('atem-title').value.trim(),
            description: description,
            level_structure_id: levelId ? parseInt(levelId, 10) : null,
            incentive_rule_id: ruleId ? parseInt(ruleId, 10) : null,
            pillar_id: pillarId ? parseInt(pillarId, 10) : null,
            reward_label: rewardLabel || null,
            outlet_ids: outletTags.map(function (o) { return o.id; }),
            area_manager_ids: areaManagerTags.map(function (m) { return m.id; }),
            start_date: $('tl-start').value || null,
            end_date: $('tl-end').value || null,
            is_extended: $('tl-extended').checked,
            extended_date_1: $('tl-ext1').value || null,
            incentive_approved: !!(document.getElementById('tl-incentive-approve-yes') && document.getElementById('tl-incentive-approve-yes').checked),
            atem_status_id: $('tl-status').value ? parseInt($('tl-status').value, 10) : null,
            remarks: $('tl-remarks').value,
            closure_date: CFG.needsClosureDate ? ($('tl-closure').value || null) : undefined,
            is_deducted: (function () {
                var checked = document.querySelector('input[name="tl-reward-decision"]:checked');
                return !!(checked && checked.value === 'deducted');
            })()
        };
        var btn = $('atem-save-btn');
        if (btn) { btn.disabled = true; btn.textContent = 'Saving...'; }
        apiCall('update-atem', { id: CFG.atemId, data: data }).then(function (res) {
            if (res && res.success) { window.location.href = (window.ATEM_MODULE_BASE || 'atem/') + 'view.php'; }
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

    var CHAT_EDIT_WINDOW_MS = 60000;

    function chatWithinEditWindow(createdAt) {
        var t = new Date(String(createdAt).replace(' ', 'T')).getTime();
        if (isNaN(t)) { return false; }
        return (Date.now() - t) < CHAT_EDIT_WINDOW_MS;
    }

    function findChatMessage(id) {
        for (var i = 0; i < chatMessages.length; i++) {
            if (String(chatMessages[i].id) === String(id)) { return chatMessages[i]; }
        }
        return null;
    }

    function renderChat() {
        var wrap = $('atem-chat-wrap');
        if (!wrap) { return; }
        if (!chatMessages.length) {
            wrap.innerHTML = '<div class="atem-empty-state">No messages yet.</div>';
            return;
        }
        var html = '';
        for (var i = 0; i < chatMessages.length; i++) {
            var m = chatMessages[i];
            var mine = String(m.sender_staff_id) === String(CFG.staffId);
            var canManage = mine && chatWithinEditWindow(m.created_at);
            var actionsHtml = canManage
                ? '<div class="atem-chat-bubble-actions">'
                    + '<button type="button" class="btn btn-link btn-sm p-0 atem-chat-edit-btn" data-id="' + m.id + '">Edit</button>'
                    + '<button type="button" class="btn btn-link btn-sm p-0 text-danger atem-chat-unsend-btn" data-id="' + m.id + '">Unsend</button>'
                    + '</div>'
                : '';
            html += '<div class="atem-chat-bubble' + (mine ? ' atem-chat-bubble-mine' : '') + '" data-message-id="' + m.id + '" data-created-at="' + escapeHtml(m.created_at) + '">'
                + '<div class="atem-chat-bubble-header"><strong>' + escapeHtml(m.sender_name || ('Staff #' + m.sender_staff_id)) + '</strong>'
                + ' <span class="atem-chat-bubble-time">' + escapeHtml(formatDateTime(m.created_at)) + '</span></div>'
                + '<div class="atem-chat-bubble-body" id="atem-chat-body-' + m.id + '">' + escapeHtml(m.message).replace(/\n/g, '<br>') + '</div>'
                + actionsHtml
                + '</div>';
        }
        wrap.innerHTML = html;
        wrap.scrollTop = wrap.scrollHeight;
    }

    // Hides expired Edit/Unsend buttons without a full re-render (which would
    // reset scroll position); safe to run on a timer independent of polling.
    function refreshChatActionVisibility() {
        var wrap = $('atem-chat-wrap');
        if (!wrap) { return; }
        var bubbles = wrap.querySelectorAll('.atem-chat-bubble[data-created-at]');
        for (var i = 0; i < bubbles.length; i++) {
            var b = bubbles[i];
            var actions = b.querySelector('.atem-chat-bubble-actions');
            if (actions && !chatWithinEditWindow(b.getAttribute('data-created-at'))) {
                actions.parentNode.removeChild(actions);
            }
        }
    }

    function startEditChatMessage(id) {
        var body = $('atem-chat-body-' + id);
        var msg = findChatMessage(id);
        if (!body || !msg) { return; }
        body.innerHTML = '<textarea class="form-control atem-chat-edit-input" rows="2" maxlength="4000">' + escapeHtml(msg.message) + '</textarea>'
            + '<div class="atem-form-error" id="atem-chat-edit-error-' + id + '"></div>'
            + '<div class="atem-chat-edit-actions">'
            + '<button type="button" class="btn btn-primary btn-sm atem-chat-edit-save" data-id="' + id + '">Save</button>'
            + '<button type="button" class="btn btn-outline-secondary btn-sm atem-chat-edit-cancel" data-id="' + id + '">Cancel</button>'
            + '</div>';
        var ta = body.querySelector('textarea');
        if (ta) { ta.focus(); }
    }

    function cancelEditChatMessage() {
        renderChat();
    }

    function saveEditChatMessage(id) {
        var body = $('atem-chat-body-' + id);
        var ta = body ? body.querySelector('textarea') : null;
        var text = ta ? ta.value.trim() : '';
        var errId = 'atem-chat-edit-error-' + id;
        setError(errId, '');
        if (!text) { setError(errId, 'Message cannot be empty.'); return; }
        apiCall('chat-edit', { id: CFG.atemId, message_id: id, message: text }).then(function (res) {
            if (res && res.success) {
                for (var i = 0; i < chatMessages.length; i++) {
                    if (String(chatMessages[i].id) === String(id)) { chatMessages[i] = res.data; break; }
                }
                renderChat();
            } else {
                setError(errId, (res && res.message) ? res.message : 'Failed to save message.');
            }
        }).catch(function () {
            setError(errId, 'Network error while saving message.');
        });
    }

    function unsendChatMessage(id) {
        confirmAction('Unsend this message?', function () {
            apiCall('chat-unsend', { id: CFG.atemId, message_id: id }).then(function (res) {
                if (res && res.success) {
                    chatMessages = chatMessages.filter(function (m) { return String(m.id) !== String(id); });
                    renderChat();
                } else {
                    setError('atem-chat-error', (res && res.message) ? res.message : 'Failed to unsend message.');
                }
            }).catch(function () {
                setError('atem-chat-error', 'Network error while unsending message.');
            });
        });
    }

    function bindChatWrap() {
        var wrap = $('atem-chat-wrap');
        if (!wrap) { return; }
        wrap.addEventListener('click', function (e) {
            var t = e.target;
            if (t.classList.contains('atem-chat-edit-btn')) {
                startEditChatMessage(parseInt(t.getAttribute('data-id'), 10));
            } else if (t.classList.contains('atem-chat-unsend-btn')) {
                unsendChatMessage(parseInt(t.getAttribute('data-id'), 10));
            } else if (t.classList.contains('atem-chat-edit-save')) {
                saveEditChatMessage(parseInt(t.getAttribute('data-id'), 10));
            } else if (t.classList.contains('atem-chat-edit-cancel')) {
                cancelEditChatMessage();
            }
        });
    }

    function sendChatMessage() {
        var input = $('atem-chat-input');
        var text = input ? input.value.trim() : '';
        setError('atem-chat-error', '');
        if (!text) { setError('atem-chat-error', 'Message cannot be empty.'); return; }
        var btn = $('atem-chat-send-btn');
        if (btn) { btn.disabled = true; }
        apiCall('chat-send', { id: CFG.atemId, message: text }).then(function (res) {
            if (btn) { btn.disabled = false; }
            if (res && res.success) {
                chatMessages.push(res.data);
                if (input) { input.value = ''; }
                renderChat();
            } else {
                setError('atem-chat-error', (res && res.message) ? res.message : 'Failed to send message.');
            }
        }).catch(function () {
            if (btn) { btn.disabled = false; }
            setError('atem-chat-error', 'Network error while sending message.');
        });
    }

    function pollChatMessages() {
        if (!CFG.atemId) { return; }
        // Full resync (not incremental by id) so edits and unsends on existing
        // messages - which don't create a new id - reach other viewers too.
        apiCall('chat-list', { id: CFG.atemId }).then(function (res) {
            if (res && res.success && res.data) {
                if (JSON.stringify(res.data) !== JSON.stringify(chatMessages)) {
                    chatMessages = res.data;
                    renderChat();
                }
            }
        }).catch(function () {});
    }

    function bindChat() {
        var btn = $('atem-chat-send-btn');
        if (btn) { btn.addEventListener('click', sendChatMessage); }
        var input = $('atem-chat-input');
        if (input) {
            input.addEventListener('keydown', function (e) {
                if (e.key === 'Enter' && (e.ctrlKey || e.metaKey)) { sendChatMessage(); }
            });
        }
        bindChatWrap();
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
        var rewardedEl = document.getElementById('tl-reward-decision-rewarded');
        var deductedEl = document.getElementById('tl-reward-decision-deducted');
        if (rewardedEl && deductedEl) {
            // No separate column tracks this decision - infer from claimable: a
            // terminal HQ card with claimable already false was Deducted.
            var wasDeducted = TERMINAL_STATUSES.indexOf(REC.status ? REC.status.value : '') >= 0 && !REC.claimable;
            deductedEl.checked = wasDeducted;
            rewardedEl.checked = !wasDeducted;
        }
        if (REC.pillar_id && $('atem-pillars')) { $('atem-pillars').value = REC.pillar_id; }
        if ($('atem-reward-label')) { $('atem-reward-label').value = REC.reward_label || ''; }
        var amById = {};
        (CFG.areaManagers || []).forEach(function (a) { amById[a.id] = a; });
        areaManagerTags = (REC.area_managers || [])
            .map(function (a) { return parseInt(a.staff_id, 10) || 0; })
            .filter(function (id) { return amById.hasOwnProperty(id); })
            .map(function (id) {
                return { id: id, name: amById[id].name, position: amById[id].position, outlet_ids: amById[id].outlet_ids };
            });
        renderAreaManagerTags();
        syncAreaManagerPickerSelection();
        recomputeDerivedOutlets();
        applyAtemTypeView();

        syncStatusOptions();
        syncIncentiveApproval();
        syncRewardDecision();
        syncEndDateLock();
        syncRemarksRequired();
        if (quillEditor && REC.description) { quillEditor.clipboard.dangerouslyPasteHTML(REC.description); }

        var grouped = { A: [], R: [], C: [], I: [] };
        (REC.arci || []).forEach(function (m) { if (grouped[m.role]) { grouped[m.role].push(m); } });
        arciState = grouped;
        reflinks = REC.reference_links || [];
        attachments = REC.attachments || [];
        progressUpdates = REC.progress || [];
        chatMessages = REC.messages || [];

        renderArci();
        renderReferenceLinks();
        renderAttachments();
        renderProgress();
        renderAuditLog();
        renderChat();
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
        ['atem-title', 'atem-issuer', 'atem-department', 'atem-level', 'atem-rule',
            'atem-pillars', 'atem-reward-label', 'tl-start', 'tl-end',
            'tl-status', 'tl-final-due', 'tl-closure', 'tl-remarks', 'tl-extended', 'tl-ext1',
            'tl-incentive-approve-yes', 'tl-incentive-approve-no',
            'tl-reward-decision-rewarded', 'tl-reward-decision-deducted'].forEach(function (id) {
            var el = $(id);
            if (el) { el.setAttribute('disabled', 'disabled'); }
        });
    }

    // While suspended, the Issuer may still edit Title and Description — everything
    // else stays locked by applyReadMode() above.
    function applySuspendedIssuerUnlock() {
        if (quillEditor) { quillEditor.enable(); }
        var titleEl = $('atem-title');
        if (titleEl) { titleEl.removeAttribute('disabled'); }
    }

    function saveSuspendedFields() {
        var titleEl = $('atem-title');
        var title = titleEl ? titleEl.value.trim() : '';
        setError('atem-suspended-save-error', '');
        if (!title) { setError('atem-suspended-save-error', 'ATEM Title is required.'); return; }
        var description = quillEditor ? ((quillEditor.getText().trim() === '') ? '' : quillEditor.root.innerHTML) : '';
        var btn = $('atem-suspended-save-btn');
        if (btn) { btn.disabled = true; btn.textContent = 'Saving...'; }
        apiCall('update-atem-suspended', { id: CFG.atemId, data: { title: title, description: description } }).then(function (res) {
            if (btn) { btn.disabled = false; btn.textContent = 'Save Title & Description'; }
            if (res && res.success) {
                REC.title = title;
                REC.description = description;
            } else {
                setError('atem-suspended-save-error', res && res.message ? res.message : 'Failed to save.');
            }
        }).catch(function () {
            if (btn) { btn.disabled = false; btn.textContent = 'Save Title & Description'; }
            setError('atem-suspended-save-error', 'Network error while saving.');
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

    // Card was restored from suspension back into Completed/Completed with
    // Excellence with closure_date left blank (see AtemController::unsuspend()).
    // Unlike every other locked field, Closure Date stays editable here. The
    // replacement date must fall between the card's Start Date and today -
    // enforced both by the picker's min/max and by validateFinal(), and
    // re-checked server-side in AtemController::update().
    function applyClosureDateUnlock() {
        var el = $('tl-closure');
        if (!el) { return; }
        el.removeAttribute('disabled');
        if (REC.start_date) { el.setAttribute('min', dateOnly(REC.start_date)); }
        el.setAttribute('max', localTodayStr());
    }

    // CEO (grade 5) / SuperAdmin direct closure-date editing (CFG.canPickClosureDate):
    // available on any status except Draft/Active/Failed/Deleted, saved through its
    // own endpoint since the page is usually read-only for these viewers. Same
    // start_date..today range as the post-unsuspend flow.
    function bindClosureDatePicker() {
        applyClosureDateUnlock();
        var btn = $('tl-closure-save-btn');
        if (!btn) { return; }
        btn.addEventListener('click', function () {
            setError('tl-closure-error', '');
            var v = $('tl-closure').value;
            if (!v) { setError('tl-closure-error', 'Please select a closure date.'); return; }
            var minD = dateOnly(REC.start_date);
            if ((minD && v < minD) || v > localTodayStr()) {
                setError('tl-closure-error', 'Closure date must be between the start date and today.');
                return;
            }
            btn.disabled = true; btn.textContent = 'Saving...';
            apiCall('update-closure-date', { id: CFG.atemId, closure_date: v }).then(function (res) {
                btn.disabled = false; btn.textContent = 'Save Closure Date';
                if (res && res.success) {
                    REC.closure_date = v;
                } else {
                    setError('tl-closure-error', res && res.message ? res.message : 'Failed to update closure date.');
                }
            }).catch(function () {
                btn.disabled = false; btn.textContent = 'Save Closure Date';
                setError('tl-closure-error', 'Network error while updating closure date.');
            });
        });
    }

    // --------------------------------------------------------------- wiring
    function bind() {
        $('atem-level').addEventListener('change', function () { recalcIncentive(); saveInline(); });
        if ($('atem-reward-label')) { $('atem-reward-label').addEventListener('change', function () { saveInline(); }); }
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
        if ($('atem-title')) { $('atem-title').addEventListener('blur', function () { if (READ) { return; } saveInline(); }); }
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
                syncRewardDecision();
                syncRemarksRequired();
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
        if ($('arci-scope-outlet')) {
            $('arci-scope-outlet').addEventListener('change', function () {
                arciScope = 'outlet';
                populateDepartments();
                renderStaffList();
            });
        }
        if ($('arci-scope-department')) {
            $('arci-scope-department').addEventListener('change', function () {
                arciScope = 'department';
                populateDepartments();
                renderStaffList();
            });
        }

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

        if ($('atem-suspended-save-btn')) { $('atem-suspended-save-btn').addEventListener('click', saveSuspendedFields); }
        if ($('atem-add-reflink-btn')) { $('atem-add-reflink-btn').addEventListener('click', openReflinkModal); }
        if ($('reflink-save-btn')) { $('reflink-save-btn').addEventListener('click', saveReferenceLink); }
        if ($('outcome-attachment-save-btn')) { $('outcome-attachment-save-btn').addEventListener('click', attachOutcomeAndSave); }
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
                        var confirmBtn = $('atem-suspend-confirm-btn');
                        var originalHtml = confirmBtn ? confirmBtn.innerHTML : '';
                        if (confirmBtn) {
                            confirmBtn.disabled = true;
                            confirmBtn.innerHTML = '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Suspending...';
                        }
                        function resetBtn() {
                            if (confirmBtn) { confirmBtn.disabled = false; confirmBtn.innerHTML = originalHtml; }
                        }
                        apiCall('suspend-atem', { id: CFG.atemId, remarks: remarks }).then(function (res) {
                            if (res && res.success) {
                                window.location.reload();
                            } else {
                                resetBtn();
                                if (_modal) { _modal.hide(); }
                                setError('atem-save-error', res && res.message ? res.message : 'Failed to suspend ATEM.');
                            }
                        }).catch(function () {
                            resetBtn();
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

        (function () {
            var appealBtn = $('atem-appeal-btn');
            if (!appealBtn) { return; }
            var _modal = null;
            function getAppealModal() {
                if (!_modal && typeof bootstrap !== 'undefined') {
                    _modal = new bootstrap.Modal($('atem-appeal-modal'));
                    $('atem-appeal-confirm-btn').addEventListener('click', function () {
                        var remarks = $('appeal-remarks') ? $('appeal-remarks').value.trim() : '';
                        if (!remarks) {
                            setError('appeal-remarks-error', 'Reason is required.');
                            return;
                        }
                        setError('appeal-remarks-error', '');
                        var confirmBtn = $('atem-appeal-confirm-btn');
                        var originalHtml = confirmBtn ? confirmBtn.innerHTML : '';
                        if (confirmBtn) {
                            confirmBtn.disabled = true;
                            confirmBtn.innerHTML = '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Submitting...';
                        }
                        function resetBtn() {
                            if (confirmBtn) { confirmBtn.disabled = false; confirmBtn.innerHTML = originalHtml; }
                        }
                        apiCall('appeal-atem', { id: CFG.atemId, remarks: remarks }).then(function (res) {
                            if (res && res.success) {
                                window.location.reload();
                            } else {
                                resetBtn();
                                if (_modal) { _modal.hide(); }
                                setError('atem-save-error', res && res.message ? res.message : 'Failed to submit appeal.');
                            }
                        }).catch(function () {
                            resetBtn();
                            if (_modal) { _modal.hide(); }
                            setError('atem-save-error', 'Network error while submitting appeal.');
                        });
                    });
                }
                return _modal;
            }
            appealBtn.addEventListener('click', function () {
                if ($('appeal-remarks')) { $('appeal-remarks').value = ''; }
                setError('appeal-remarks-error', '');
                var m = getAppealModal();
                if (m) { m.show(); }
            });
        }());

        (function () {
            var payoutSelect = $('payout-status');
            if (!payoutSelect || payoutSelect.disabled) { return; }
            var originalValue = payoutSelect.value;
            var pendingValue = null;
            var _modal = null;

            function getPayoutModal() {
                if (!_modal && typeof bootstrap !== 'undefined') {
                    _modal = new bootstrap.Modal($('atem-payout-modal'));
                    $('atem-payout-confirm-btn').addEventListener('click', function () {
                        var remarks = $('payout-remarks') ? $('payout-remarks').value.trim() : '';
                        if (!remarks) {
                            setError('payout-remarks-error', 'Remark is required.');
                            return;
                        }
                        setError('payout-remarks-error', '');
                        apiCall('update-payout-status', { id: CFG.atemId, payout_status: pendingValue, remarks: remarks }).then(function (res) {
                            if (res && res.success) {
                                window.location.reload();
                            } else {
                                if (_modal) { _modal.hide(); }
                                payoutSelect.value = originalValue;
                                setError('payout-status-error', res && res.message ? res.message : 'Failed to update payout status.');
                            }
                        }).catch(function () {
                            if (_modal) { _modal.hide(); }
                            payoutSelect.value = originalValue;
                            setError('payout-status-error', 'Network error while updating payout status.');
                        });
                    });
                }
                return _modal;
            }

            payoutSelect.addEventListener('change', function () {
                var newValue = payoutSelect.value;
                if (!newValue) { return; }
                pendingValue = newValue;
                if ($('payout-remarks')) { $('payout-remarks').value = ''; }
                setError('payout-remarks-error', '');
                setError('payout-status-error', '');
                var msgEl = $('atem-payout-modal-msg');
                if (msgEl) {
                    msgEl.textContent = 'You are about to set the payout status to "' + newValue + '"' +
                        (newValue === 'Closed' ? '. Once closed, this section becomes permanently read-only and cannot be changed again.' : '.') +
                        ' Do you want to proceed?';
                }
                var m = getPayoutModal();
                if (m) { m.show(); }
            });

            var payoutModalEl = $('atem-payout-modal');
            if (payoutModalEl) {
                payoutModalEl.addEventListener('hidden.bs.modal', function () {
                    if (pendingValue !== null && payoutSelect.value === pendingValue) {
                        payoutSelect.value = originalValue;
                    }
                });
            }
        }());
    }

    function saveTerminalEdit() {
        var levelVal  = parseInt(($('atem-level') || {}).value, 10) || null;
        var ruleVal   = parseInt(($('atem-rule')  || {}).value, 10) || null;
        var statusVal = parseInt(($('tl-status')  || {}).value, 10) || null;

        if (!levelVal)  { setError('atem-save-error', 'Please select a complexity level.'); return; }
        if (!statusVal) { setError('atem-save-error', 'Please select a status.'); return; }

        var statusStrVal = '';
        (CFG.statuses || []).forEach(function (s) { if (String(s.id) === String(statusVal)) { statusStrVal = s.value; } });
        var remarksVal = $('tl-remarks') ? $('tl-remarks').value.trim() : '';
        if (statusStrVal === 'Force Terminated' && !remarksVal) {
            setError('tl-remarks-error', 'A remark is required when force terminating an ATEM.');
            return;
        }
        setError('atem-save-error', '');
        setError('tl-remarks-error', '');

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
                // Was previously REC.remarks (the stale pre-edit value) - the
                // typed-in Remarks box was silently discarded on every save
                // through this SuperAdmin-terminal-edit path.
                remarks:            remarksVal || null,
                incentive_approved: false
            }
        }).then(function (res) {
            if (btn) { btn.disabled = false; btn.textContent = 'Save ATEM'; }
            if (res && res.success) {
                window.location.href = (window.ATEM_MODULE_BASE || 'atem/') + 'view.php';
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
                        window.location.href = (window.ATEM_MODULE_BASE || 'atem/') + 'view.php';
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
        buildAreaManagerPicker();
        var orphanCloseBtn = $('atem-arci-orphan-warning-close');
        if (orphanCloseBtn) {
            orphanCloseBtn.addEventListener('click', function () {
                $('atem-arci-orphan-warning').classList.add('atem-hidden');
            });
        }
        initEditor();
        bind();
        bindChat();
        hydrate();
        if (CFG.atemId) {
            _chatPollTimer = setInterval(pollChatMessages, 4000);
            setInterval(refreshChatActionVisibility, 5000);
        }
        restrictDraftStatus();
        syncExtendedByStatus();
        applyExtMins();
        lockDateFields();
        injectBadge();
        applyReadMode();
        if (CFG.superadminTerminalEdit) { applyTerminalEditRestrictions(); }
        if (CFG.issuerCompletedEdit) { applyIssuerCompletedLock(); }
        if (CFG.suspendedIssuerEdit) { applySuspendedIssuerUnlock(); }
        if (CFG.needsClosureDate) { applyClosureDateUnlock(); }
        if (CFG.canPickClosureDate) { bindClosureDatePicker(); }
        if (!READ && !IS_ISSUER && !CFG.superadminTerminalEdit) {
            // A real SuperAdmin (dev-override aware via CFG.isSuperAdmin) may still
            // change Status and add a Remark on a card they didn't issue; everything
            // else in this list stays locked for them.
            var SA_STATUS_UNLOCK = !!CFG.isSuperAdmin;
            ['tl-start', 'tl-end', 'tl-status', 'tl-extended', 'tl-ext1', 'tl-remarks',
             'tl-incentive-approve-yes', 'tl-incentive-approve-no'].forEach(function (id) {
                if (SA_STATUS_UNLOCK && (id === 'tl-status' || id === 'tl-remarks')) { return; }
                var el = document.getElementById(id);
                if (el) { el.disabled = true; }
            });
        }
        recalcClosureDate();
    });
})();
