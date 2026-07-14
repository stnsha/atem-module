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
    var staffType = null;   // 'outlet' | 'hq'
    var outletTags = [];         // [{ id, code }] - derived from areaManagerTags, read-only
    var areaManagerTags = [];    // [{ id, name, position, outlet_ids }] - outlet staff flow only

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
            'tl-end-error', 'arci-error', 'atem-save-error', 'atem-file-error',
            'reflink-section-error', 'atem-am-error', 'atem-reward-amount-error',
            'atem-deduction-amount-error'].forEach(function (id) {
            setError(id, '');
        });
    }

    function scrollToFirstError() {
        var ids = ['atem-title-error', 'atem-level-error', 'atem-rule-error', 'tl-start-error',
                   'tl-end-error', 'arci-error', 'reflink-section-error', 'atem-file-error', 'atem-save-error',
                   'atem-am-error', 'atem-reward-amount-error', 'atem-deduction-amount-error'];
        for (var i = 0; i < ids.length; i++) {
            var el = $(ids[i]);
            if (el && el.textContent.trim() !== '') {
                el.scrollIntoView({ behavior: 'smooth', block: 'center' });
                return;
            }
        }
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
            pillar_id: $('atem-pillars') ? ($('atem-pillars').value || null) : null,
            reward_amount: $('atem-reward-amount') ? ($('atem-reward-amount').value || null) : null,
            deduction_amount: $('atem-deduction-amount') ? ($('atem-deduction-amount').value || null) : null,
            outlet_ids: outletTags.map(function (o) { return o.id; }),
            area_manager_ids: areaManagerTags.map(function (m) { return m.id; }),
            start_date: $('tl-start').value || null,
            end_date: $('tl-end').value || null,
            arci: arciState,
            reflinks: reflinks,
            staff_type: staffType
        };
    }

    // HQ staff see the Complexity Level / Incentive Rule fields and the
    // Estimated Incentive breakdown. Outlet staff see 5 Pillars, Outlet
    // tagging, Reward Amount / Deduction Amount, and a simple Estimated
    // Reward total instead.
    function setStaffType(type) {
        staffType = type;
        var outletBtn = $('staff-type-outlet');
        var hqBtn = $('staff-type-hq');
        if (outletBtn) { outletBtn.classList.toggle('active', type === 'outlet'); }
        if (hqBtn) { hqBtn.classList.toggle('active', type === 'hq'); }

        var isHq = (type === 'hq');
        var hqOnly = document.querySelectorAll('.atem-hq-only');
        var outletOnly = document.querySelectorAll('.atem-outlet-only');
        for (var i = 0; i < hqOnly.length; i++) { hqOnly[i].classList.toggle('atem-hidden', !isHq); }
        for (var j = 0; j < outletOnly.length; j++) { outletOnly[j].classList.toggle('atem-hidden', isHq); }
        if (!isHq) { recalcReward(); }

        var incentiveSection = $('atem-incentive-section');
        var rewardSection = $('atem-reward-section');
        if (incentiveSection) { incentiveSection.classList.toggle('atem-hidden', !isHq); }
        if (rewardSection) { rewardSection.classList.toggle('atem-hidden', isHq); }

        if (!isHq) {
            arciScope = 'outlet';
            if ($('arci-scope-outlet')) { $('arci-scope-outlet').checked = true; }
        }
        populateDepartments();
        renderStaffList();
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
        if (draft.pillar_id && $('atem-pillars')) { $('atem-pillars').value = draft.pillar_id; }
        if (draft.reward_amount && $('atem-reward-amount')) { $('atem-reward-amount').value = draft.reward_amount; }
        if (draft.deduction_amount && $('atem-deduction-amount')) { $('atem-deduction-amount').value = draft.deduction_amount; }
        if (draft.area_manager_ids && draft.area_manager_ids.length) {
            var amById = {};
            (CFG.areaManagers || []).forEach(function (a) { amById[a.id] = a; });
            areaManagerTags = draft.area_manager_ids
                .filter(function (id) { return amById.hasOwnProperty(id); })
                .map(function (id) {
                    return { id: id, name: amById[id].name, position: amById[id].position, outlet_ids: amById[id].outlet_ids };
                });
            renderAreaManagerTags();
            syncAreaManagerPickerSelection();
            recomputeDerivedOutlets();
        }
        if (draft.staff_type) { setStaffType(draft.staff_type); }
        // Restored content is unsaved (no DB row), so leaving should still warn.
        dirty = true;
    }

    // --------------------------------------------------- area manager tagging
    function renderAreaManagerTags() {
        var wrap = $('atem-am-tags');
        if (!wrap) { return; }
        if (!areaManagerTags.length) {
            wrap.innerHTML = '<span class="atem-empty-state">No area manager tagged.</span>';
            return;
        }
        var html = '';
        for (var i = 0; i < areaManagerTags.length; i++) {
            var label = areaManagerTags[i].name + ' (' + areaManagerTags[i].position + ')';
            html += '<span class="atem-outlet-tag">' + escapeHtml(label)
                + '<span class="atem-outlet-tag-remove" data-id="' + areaManagerTags[i].id + '">&times;</span></span>';
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
        markChanged();
    }

    // Area Managers are automatically tagged as Accountable (A) in the
    // Project Team, since they own the outlet(s) in scope. Both staff_dept_id
    // and outlet_id are left null - they aren't scoped to a single
    // outlet/department like other members. Skipped if the staff is already
    // assigned to any ARCI role (atem_arci is unique per staff per card).
    function autoAddAreaManagerToArci(am) {
        if (assignedStaffIds().indexOf(am.id) >= 0) { return; }
        arciState.A.push({
            staff_id: am.id,
            staff_name: am.name + ' (' + am.position + ')',
            staff_dept_id: null,
            outlet_id: null,
            department_name: 'All Outlets',
            role: 'A',
            is_incentivised: false
        });
        renderArci();
    }

    function removeAreaManagerTag(id) {
        areaManagerTags = areaManagerTags.filter(function (m) { return m.id !== id; });
        renderAreaManagerTags();
        syncAreaManagerPickerSelection();
        recomputeDerivedOutlets();
        markChanged();
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
            var label = managers[i].name + ' (' + managers[i].position + ')';
            html += '<li data-id="' + managers[i].id + '">' + escapeHtml(label) + '</li>';
        }
        listEl.innerHTML = html || '<div class="atem-outlet-picker-empty">No area managers available</div>';
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
        if (staffType !== 'outlet') { warnEl.classList.add('atem-hidden'); return; }

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
            textEl.textContent = 'The following Project Team member(s) are tagged to an outlet no longer covered by the selected Area Manager(s) - please recheck: ' + orphanNames.join(', ');
            warnEl.classList.remove('atem-hidden');
        } else {
            warnEl.classList.add('atem-hidden');
        }
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
        var pillars = CFG.pillars || [];

        fillSelect($('atem-level'), levels, 'id', function (l) {
            return l.level + ' - ' + l.system_name + ' (RM' + Number(l.incentive_value).toFixed(0) + ')';
        }, 'Select level');

        fillSelect($('atem-rule'), rules, 'id', function (r) {
            return r.code + ' - ' + r.system_label;
        }, 'Select rule');

        fillSelect($('atem-pillars'), pillars, 'id', function (p) {
            return p.name;
        }, 'Select pillar');
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

    function updateArciWarning() {
        var level = selectedLevel();
        var rule = selectedRule();

        if (!level || Number(level.incentive_value) === 0 || !rule) {
            setError('arci-error', '');
            return;
        }

        var limits = getRuleLimits(rule);
        var incA = countIncentivised('A');
        var incR = countIncentivised('R');
        var msg = '';

        if (!arciState.A || arciState.A.length === 0) {
            msg = 'An Accountable (A) member is mandatory.';
        } else if (incA !== limits.maxA) {
            msg = 'This rule requires exactly ' + limits.maxA + ' Accountable (A) member(s) to be incentivised.';
        } else if (limits.maxR > 0 && incR !== limits.maxR) {
            msg = 'This rule requires exactly ' + limits.maxR + ' Responsible (R) member(s) to be incentivised.';
        }

        setError('arci-error', msg);
    }

    function enforceRuleLimitsOnState() {
        var limits = getRuleLimits(selectedRule());
        var aInc = 0;
        (arciState['A'] || []).forEach(function (m) {
            if (m.is_incentivised) {
                aInc++;
                if (aInc > limits.maxA) { m.is_incentivised = false; }
            }
        });
        var rInc = 0;
        (arciState['R'] || []).forEach(function (m) {
            if (m.is_incentivised) {
                if (limits.maxR === 0) { m.is_incentivised = false; return; }
                rInc++;
                if (rInc > limits.maxR) { m.is_incentivised = false; }
            }
        });
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
        var total = a + r;

        $('inc-base').textContent = money(base);
        $('inc-a').textContent = money(a);
        $('inc-r').textContent = money(code === 'rule 1' ? rDisplay : r);
        $('inc-total').textContent = money(total);
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

        if (!level) {
            note.textContent = 'Select an ATEM Complexity Leveland rule to calculate incentive. C and I are not incentivised.';
        } else if (base === 0) {
            note.textContent = 'Level 1 carries no incentive payout.';
        } else if (!rule) {
            note.textContent = 'Select an incentive rule (required for Level 2-4).';
        } else {
            note.textContent = 'Projected amounts. Claimable only when closed as Complete or Complete with Excellence.';
        }
    }

    // Outlet flow: the "Total Reward" card just mirrors the selected Reward
    // Amount (the upside scenario). The actual signed final_amount is decided
    // server-side once the card reaches a closing status.
    function recalcReward() {
        var rewardEl = $('atem-reward-amount');
        var totalEl = $('reward-total');
        if (!rewardEl || !totalEl) { return; }
        totalEl.textContent = money(rewardEl.value ? Number(rewardEl.value) : 0);
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
            var _arciRule = selectedRule();
            var _arciLimits = getRuleLimits(_arciRule);
            for (var m = 0; m < members.length; m++) {
                var mem = members[m];
                var incentivisedHtml = '';
                var _lvl = selectedLevel();
                var _isLevel1 = _lvl && Number(_lvl.incentive_value) === 0;
                var showChk = !_isLevel1 && staffType !== 'outlet' && ((role === 'A') || (role === 'R' && _arciLimits.maxR > 0));
                if (showChk) {
                    var maxForRole = (role === 'A') ? _arciLimits.maxA : _arciLimits.maxR;
                    var atMax = !mem.is_incentivised && countIncentivised(role) >= maxForRole;
                    incentivisedHtml = '<label class="atem-arci-incentivised">'
                        + '<input type="checkbox" class="atem-arci-incentivised-chk"'
                        + ' data-staff="' + parseInt(mem.staff_id, 10) + '" data-role="' + role + '"'
                        + (mem.is_incentivised ? ' checked' : '')
                        + (atMax ? ' disabled' : '') + '>'
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
        updateArciWarning();
    }

    // On Outlet-type cards, a radio toggle (#arci-scope-outlet /
    // #arci-scope-department) lets the issuer add either outlet-scoped staff
    // or HQ department staff (e.g. for a C/I role) to the Project Team.
    // arciScope is only meaningful when staffType === 'outlet'; HQ-type cards
    // always use department scope.
    var arciScope = 'outlet';

    function currentArciScope() {
        return (staffType === 'outlet') ? arciScope : 'department';
    }

    function populateDepartments() {
        var sel = $('arci-dept-select');
        var labelEl = $('arci-dept-label');
        var searchEl = $('arci-dept-search');
        var scopeToggle = $('arci-scope-toggle');
        var isOutlet = (staffType === 'outlet');
        if (scopeToggle) { scopeToggle.classList.toggle('atem-hidden', !isOutlet); }
        if (labelEl) { labelEl.textContent = isOutlet ? 'Scope' : 'Department'; }

        if (isOutlet && currentArciScope() === 'outlet') {
            if (searchEl) { searchEl.placeholder = 'Search outlet...'; }
            sel.innerHTML = '<option value="">Select outlet</option>';
            for (var j = 0; j < outletTags.length; j++) {
                var oo = document.createElement('option');
                oo.value = outletTags[j].id;
                oo.textContent = outletTags[j].code;
                sel.appendChild(oo);
            }
            return;
        }

        if (searchEl) { searchEl.placeholder = 'Search department...'; }
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
        var isOutletScope = (currentArciScope() === 'outlet');
        if (!deptId) {
            listDiv.innerHTML = '<div class="text-muted" style="font-size:13px;">Select ' + (isOutletScope ? 'an outlet' : 'a department') + ' to load staff</div>';
            return;
        }
        var staff = isOutletScope
            ? ((CFG.staffByOutlet && CFG.staffByOutlet[deptId]) ? CFG.staffByOutlet[deptId] : [])
            : ((CFG.staffByDept && CFG.staffByDept[deptId]) ? CFG.staffByDept[deptId] : []);
        var assigned = assignedStaffIds();
        var term = $('arci-staff-search').value.toLowerCase();

        var html = '';
        for (var i = 0; i < staff.length; i++) {
            if (assigned.indexOf(parseInt(staff[i].id, 10)) >= 0) { continue; }
            if (term && staff[i].name.toLowerCase().indexOf(term) < 0) { continue; }
            var displayName = (isOutletScope && staff[i].position) ? (staff[i].name + ' (' + staff[i].position + ')') : staff[i].name;
            html += '<label class="atem-arci-staff-item">'
                + '<input type="checkbox" value="' + parseInt(staff[i].id, 10) + '" data-name="' + escapeHtml(displayName) + '"> '
                + '<span>' + escapeHtml(displayName) + '</span>'
                + '</label>';
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
        var scopeName = '';
        if (isOutletScope) {
            for (var j = 0; j < outletTags.length; j++) {
                if (String(outletTags[j].id) === String(scopeId)) { scopeName = outletTags[j].code; break; }
            }
        } else {
            var depts = CFG.departments || [];
            for (var k = 0; k < depts.length; k++) {
                if (String(depts[k].id) === String(scopeId)) { scopeName = depts[k].name; break; }
            }
        }

        for (var i = 0; i < checks.length; i++) {
            arciState[role].push({
                staff_id: parseInt(checks[i].value, 10),
                staff_name: checks[i].getAttribute('data-name'),
                staff_dept_id: (!isOutletScope && scopeId) ? parseInt(scopeId, 10) : null,
                outlet_id: (isOutletScope && scopeId) ? parseInt(scopeId, 10) : null,
                department_name: scopeName,
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
        var startDate = $('tl-start').value;
        var endDate = $('tl-end').value;

        if (!title) { setError('atem-title-error', 'ATEM Title is required.'); $('atem-title').focus(); return false; }

        if (staffType === 'outlet') {
            if (!areaManagerTags.length) {
                setError('atem-am-error', 'At least one Area Manager is required.');
                return false;
            }
        } else {
            var levelId = $('atem-level').value;
            var ruleId = $('atem-rule').value;
            var level = selectedLevel();
            if (!levelId) { setError('atem-level-error', 'ATEM Complexity Levelis required.'); $('atem-level').focus(); return false; }
            if (level && Number(level.incentive_value) > 0 && !ruleId) {
                setError('atem-rule-error', 'Incentive Rule is required for Level 2-4.');
                $('atem-rule').focus();
                return false;
            }
        }

        if (!startDate) { setError('tl-start-error', 'Start Date is required.'); $('tl-start').focus(); return false; }
        if (!endDate) { setError('tl-end-error', 'End Date is required.'); $('tl-end').focus(); return false; }
        if (!arciState.A || arciState.A.length === 0) {
            setError('arci-error', 'An Accountable (A) member is mandatory.');
            return false;
        }
        if (staffType !== 'outlet') {
            var rule = selectedRule();
            var lvl = selectedLevel();
            if (lvl && Number(lvl.incentive_value) > 0 && rule) {
                var limits = getRuleLimits(rule);
                var incA = countIncentivised('A');
                var incR = countIncentivised('R');
                if (incA !== limits.maxA) {
                    setError('arci-error', 'This rule requires exactly ' + limits.maxA + ' Accountable (A) member(s) to be incentivised.');
                    return false;
                }
                if (limits.maxR > 0 && incR !== limits.maxR) {
                    setError('arci-error', 'This rule requires exactly ' + limits.maxR + ' Responsible (R) member(s) to be incentivised.');
                    return false;
                }
            }
        }
        if (!reflinks || reflinks.length === 0) {
            setError('reflink-section-error', 'At least one Reference Link is required.');
            return false;
        }
        return true;
    }

    function collectPayload(mode) {
        var levelId = $('atem-level').value;
        var ruleId = $('atem-rule').value;
        var pillarId = $('atem-pillars') ? $('atem-pillars').value : '';
        var rewardAmount = $('atem-reward-amount') ? $('atem-reward-amount').value : '';
        var deductionAmount = $('atem-deduction-amount') ? $('atem-deduction-amount').value : '';
        var description = '';
        if (quillEditor) {
            description = (quillEditor.getText().trim() === '') ? '' : quillEditor.root.innerHTML;
        }
        return {
            title: $('atem-title').value.trim(),
            description: description,
            atem_type: (staffType === 'outlet') ? 2 : 1,
            level_structure_id: levelId ? parseInt(levelId, 10) : null,
            incentive_rule_id: ruleId ? parseInt(ruleId, 10) : null,
            pillar_id: pillarId ? parseInt(pillarId, 10) : null,
            reward_amount: rewardAmount ? parseInt(rewardAmount, 10) : null,
            deduction_amount: deductionAmount ? parseInt(deductionAmount, 10) : null,
            outlet_ids: outletTags.map(function (o) { return o.id; }),
            area_manager_ids: areaManagerTags.map(function (m) { return m.id; }),
            start_date: $('tl-start').value || null,
            end_date: $('tl-end').value || null,
            arci: flattenArci(),
            reference_links: reflinks,
            mode: mode
        };
    }

    // Upload the staged files (multipart) against a saved ATEM id, then continue.
    function saveAtem(mode, navUrl) {
        if (mode === 'final' && !validateFinal()) { scrollToFirstError(); return; }
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
                scrollToFirstError();
                if (btn) { btn.disabled = false; btn.textContent = 'Save ATEM'; }
            }
        }).catch(function () {
            setError('atem-save-error', 'Network error while saving.');
            scrollToFirstError();
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

    // Distinguishes "user actually entered something" from the generic dirty
    // flag (which also flips true from the type buttons themselves), so
    // switching ATEM Type before any real data exists doesn't trigger the
    // reset warning.
    function hasMeaningfulFormData() {
        if ($('atem-title').value.trim() !== '') { return true; }
        if (quillEditor && quillEditor.getText().trim() !== '') { return true; }
        if ($('tl-start').value || $('tl-end').value) { return true; }
        if ($('atem-level') && $('atem-level').value) { return true; }
        if ($('atem-rule') && $('atem-rule').value) { return true; }
        if ($('atem-pillars') && $('atem-pillars').value) { return true; }
        if (outletTags.length || areaManagerTags.length) { return true; }
        if (arciState.A.length || arciState.R.length || arciState.C.length || arciState.I.length) { return true; }
        if (reflinks.length) { return true; }
        if (stagedFiles.length) { return true; }
        return false;
    }

    function requestStaffTypeChange(type) {
        if (type === staffType) { return; }
        if (hasMeaningfulFormData()) {
            var modalEl = $('atem-type-switch-modal');
            if (modalEl && typeof bootstrap !== 'undefined') {
                bootstrap.Modal.getOrCreateInstance(modalEl).show();
            }
            return;
        }
        setStaffType(type);
        markChanged();
    }

    // --------------------------------------------------------------- wiring
    function bind() {
        $('staff-type-outlet').addEventListener('click', function () { requestStaffTypeChange('outlet'); });
        $('staff-type-hq').addEventListener('click', function () { requestStaffTypeChange('hq'); });
        var typeSwitchResetBtn = $('atem-type-switch-reset-btn');
        if (typeSwitchResetBtn) {
            typeSwitchResetBtn.addEventListener('click', function () {
                typeSwitchResetBtn.disabled = true;
                apiCall('draft-clear').then(function () {
                    window.location.reload();
                }).catch(function () {
                    typeSwitchResetBtn.disabled = false;
                    setError('atem-save-error', 'Failed to reset the form. Please try again.');
                });
            });
        }

        $('atem-title').addEventListener('input', markChanged);
        $('atem-level').addEventListener('change', function () { recalcIncentive(); renderArci(); updateArciWarning(); markChanged(); });
        $('atem-rule').addEventListener('change', function () {
            enforceRuleLimitsOnState();
            renderArci();
            recalcIncentive();
            updateArciWarning();
            markChanged();
        });
        $('tl-start').addEventListener('change', markChanged);
        $('tl-end').addEventListener('change', markChanged);
        if ($('atem-reward-amount')) { $('atem-reward-amount').addEventListener('change', function () { recalcReward(); markChanged(); }); }
        if ($('atem-deduction-amount')) { $('atem-deduction-amount').addEventListener('change', markChanged); }

        $('arci-dept-search').addEventListener('keyup', filterDepartments);
        $('arci-dept-select').addEventListener('change', renderStaffList);
        $('arci-staff-search').addEventListener('keyup', renderStaffList);
        $('arci-add-btn').addEventListener('click', addSelectedMembers);
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
                    if (parseInt(m.staff_id, 10) === chkStaff) { m.is_incentivised = chkVal; }
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
        buildAreaManagerPicker();
        var orphanCloseBtn = $('atem-arci-orphan-warning-close');
        if (orphanCloseBtn) {
            orphanCloseBtn.addEventListener('click', function () {
                $('atem-arci-orphan-warning').classList.add('atem-hidden');
            });
        }
        initEditor();
        var _urlParams = new URLSearchParams(window.location.search);
        setStaffType(_urlParams.get('type') === 'outlet' ? 'outlet' : 'hq');
        hydrate(CFG.draft);
        bind();
        recalcIncentive();
        recalcReward();
        renderArci();
        updateArciWarning();
        renderReferenceLinks();
        renderStagedFiles();
        if (!(CFG.backdate && CFG.backdate.enabled)) {
            var _d = new Date();
            var today = _d.getFullYear() + '-' + (_d.getMonth() + 1 < 10 ? '0' + (_d.getMonth() + 1) : '' + (_d.getMonth() + 1)) + '-' + (_d.getDate() < 10 ? '0' + _d.getDate() : '' + _d.getDate());
            if ($('tl-start')) { $('tl-start').setAttribute('min', today); }
            if ($('tl-end')) { $('tl-end').setAttribute('min', today); }
        }
    });
})();
