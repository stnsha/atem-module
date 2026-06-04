/* ATEM create form behaviour: lookups, live incentive, ARCI (draft-first AJAX),
   timeline rules, and final save. Talks to the JWT proxy at atem/api.php. */
(function () {
    'use strict';

    var CFG = window.ATEM_CONFIG || {};
    var arciState = { A: [], R: [], C: [], I: [] };
    var CLOSING_STATUSES = ['Completed', 'Completed with Excellence', 'Failed'];

    // ----------------------------------------------------------------- helpers
    function $(id) { return document.getElementById(id); }

    function money(n) {
        return 'RM' + (Math.round((Number(n) || 0) * 100) / 100).toFixed(2);
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

    // ------------------------------------------------------------- TinyMCE RTE
    function initEditor() {
        if (typeof tinymce === 'undefined') { return; }
        tinymce.init({
            selector: 'textarea#atem-description',
            promotion: false,
            branding: false,
            menubar: false,
            height: 220,
            plugins: ['lists', 'link', 'searchreplace', 'wordcount', 'fullscreen'],
            toolbar: 'undo redo | blocks | bold italic underline | bullist numlist | link | removeformat | fullscreen',
            content_css: 'https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css'
        });
    }

    // --------------------------------------------------------------- dropdowns
    function fillSelect(select, items, valueKey, labelFn, placeholder) {
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
        var statuses = CFG.statuses || [];

        fillSelect($('atem-level'), levels, 'id', function (l) {
            return l.level + ' - ' + l.system_name + ' (RM' + Number(l.incentive_value).toFixed(0) + ')';
        }, 'Select level');

        fillSelect($('atem-rule'), rules, 'id', function (r) {
            return r.code + ' - ' + r.system_label;
        }, 'Select rule');

        fillSelect($('tl-status'), statuses, 'id', function (s) {
            return s.value;
        }, 'Select status');
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

    function selectedStatusValue() {
        var id = $('tl-status').value;
        var statuses = CFG.statuses || [];
        for (var i = 0; i < statuses.length; i++) {
            if (String(statuses[i].id) === String(id)) { return statuses[i].value; }
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
        rule = selectedRule();

        var a = 0, r = 0;
        if (base > 0 && rule) {
            a = base;
            r = (String(rule.code).toLowerCase() === 'rule 2') ? base * 0.5 : 0;
        }
        var total = a + r;

        $('inc-base').textContent = money(base);
        $('inc-a').textContent = money(a);
        $('inc-r').textContent = money(r);
        $('inc-total').textContent = money(total);

        var statusValue = selectedStatusValue();
        if (!level) {
            note.textContent = 'Select an ATEM level and rule to calculate incentive. C and I are not incentivised.';
        } else if (base === 0) {
            note.textContent = 'Level 1 carries no incentive payout.';
        } else if (!rule) {
            note.textContent = 'Select an incentive rule (required for Level 2-4).';
        } else if (statusValue === 'Completed' || statusValue === 'Completed with Excellence') {
            note.textContent = 'Claimable: incentive is payable on this closure status.';
        } else {
            note.textContent = 'Projected amounts. Claimable only when closed as Complete or Complete with Excellence.';
        }
    }

    // --------------------------------------------------------------- timeline
    function recalcFinalDue() {
        var end = $('tl-end').value;
        var ext1 = $('tl-ext1').value;
        var ext2 = $('tl-ext2').value;
        var finalDue = end;
        if (ext1) { finalDue = ext1; }
        if (ext2) { finalDue = ext2; }
        $('tl-final-due').value = finalDue || '';
    }

    function syncExtensionFields() {
        var on = $('tl-extended').checked;
        var w1 = $('tl-ext1-wrap');
        var w2 = $('tl-ext2-wrap');
        if (!on) {
            w1.style.display = 'none';
            w2.style.display = 'none';
            $('tl-ext1').value = '';
            $('tl-ext2').value = '';
        } else {
            w1.style.display = '';
            // Reveal the second extension only after the first is set.
            w2.style.display = $('tl-ext1').value ? '' : 'none';
            if (!$('tl-ext1').value) { $('tl-ext2').value = ''; }
        }
        recalcFinalDue();
    }

    function syncClosureDate() {
        var statusValue = selectedStatusValue();
        var closure = $('tl-closure');
        if (statusValue && CLOSING_STATUSES.indexOf(statusValue) >= 0) {
            if (!closure.value) {
                closure.value = new Date().toISOString().slice(0, 10);
            }
        } else {
            closure.value = '';
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
        arciState = {
            A: grouped.A || [],
            R: grouped.R || [],
            C: grouped.C || [],
            I: grouped.I || []
        };
        renderArci();
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
                html += '<div class="atem-arci-member">'
                    + '<div class="atem-arci-member-info">'
                    + '<div class="atem-arci-member-dept">(' + escapeHtml(mem.department_name || '') + ')</div>'
                    + '<div class="atem-arci-member-name">' + escapeHtml(mem.staff_name || '') + '</div>'
                    + '</div>'
                    + '<span class="atem-arci-remove" data-staff="' + parseInt(mem.staff_id, 10) + '" data-role="' + role + '" title="Remove">&times;</span>'
                    + '</div>';
            }
            cols[i].innerHTML = html;
        }
        // refresh staff picker so already-assigned people drop off
        renderStaffList();
    }

    function escapeHtml(s) {
        return String(s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
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
        var role = $('arci-role').value;
        if (!role) { alert('Please select a role first.'); return; }

        var deptId = $('arci-dept-select').value;
        var checks = $('arci-staff-list').querySelectorAll('input[type="checkbox"]:checked');
        if (checks.length === 0) { alert('Please select at least one staff member.'); return; }
        if (role === 'A' && (checks.length > 1 || (arciState.A && arciState.A.length > 0))) {
            alert('Role A (Accountable) can only have one person.');
            return;
        }

        var queue = [];
        for (var i = 0; i < checks.length; i++) {
            queue.push({ id: parseInt(checks[i].value, 10), name: checks[i].getAttribute('data-name') });
        }

        var deptName = departmentName(deptId);

        function next() {
            if (queue.length === 0) {
                $('arci-role').value = '';
                $('arci-dept-select').value = '';
                $('arci-staff-search').value = '';
                renderStaffList();
                return;
            }
            var member = queue.shift();
            apiCall('arci-add', {
                id: CFG.atemId,
                data: {
                    staff_id: member.id,
                    staff_name: member.name,
                    department_id: deptId ? parseInt(deptId, 10) : null,
                    department_name: deptName,
                    role: role
                }
            }).then(function (res) {
                if (res && res.success) {
                    setArciState(res.data);
                } else {
                    alert(res && res.message ? res.message : 'Failed to add member.');
                }
                next();
            }).catch(function () { alert('Network error while adding member.'); next(); });
        }
        next();
    }

    function removeMember(staffId, role) {
        apiCall('arci-remove', { id: CFG.atemId, staff_id: staffId, role: role }).then(function (res) {
            if (res && res.success) { setArciState(res.data); }
            else { alert(res && res.message ? res.message : 'Failed to remove member.'); }
        });
    }

    function clearRole(role) {
        if (!confirm('Remove all members from role ' + role + '?')) { return; }
        apiCall('arci-remove-role', { id: CFG.atemId, role: role }).then(function (res) {
            if (res && res.success) { setArciState(res.data); }
            else { alert(res && res.message ? res.message : 'Failed to clear role.'); }
        });
    }

    // ------------------------------------------------------------------- save
    function saveAtem() {
        var title = $('atem-title').value.trim();
        var googleLink = $('atem-google-link').value.trim();
        var levelId = $('atem-level').value;
        var ruleId = $('atem-rule').value;
        var startDate = $('tl-start').value;
        var endDate = $('tl-end').value;
        var level = selectedLevel();

        if (!title) { alert('ATEM Title is required.'); return; }
        if (!googleLink) { alert('ATEM Google Link is required.'); return; }
        if (!levelId) { alert('ATEM Level is required.'); return; }
        if (!startDate) { alert('Start Date is required.'); return; }
        if (!endDate) { alert('End Date is required.'); return; }
        if (level && Number(level.incentive_value) > 0 && !ruleId) {
            alert('Incentive Rule is required for Level 2-4.');
            return;
        }
        if (!arciState.A || arciState.A.length === 0) {
            alert('An Accountable (A) member is mandatory.');
            return;
        }

        var description = (typeof tinymce !== 'undefined' && tinymce.get('atem-description'))
            ? tinymce.get('atem-description').getContent()
            : $('atem-description').value;

        var data = {
            title: title,
            description: description,
            google_link: googleLink,
            level_structure_id: levelId ? parseInt(levelId, 10) : null,
            incentive_rule_id: ruleId ? parseInt(ruleId, 10) : null,
            start_date: startDate,
            end_date: endDate,
            is_extended: $('tl-extended').checked,
            extended_date_1: $('tl-ext1').value || null,
            extended_date_2: $('tl-ext2').value || null,
            atem_status_id: $('tl-status').value ? parseInt($('tl-status').value, 10) : null,
            remarks: $('tl-remarks').value,
            finalize: true
        };

        var btn = $('atem-save-btn');
        btn.disabled = true;
        btn.textContent = 'Saving...';

        apiCall('update-atem', { id: CFG.atemId, data: data }).then(function (res) {
            if (res && res.success) {
                window.location.href = 'atem/index.php';
            } else {
                alert(res && res.message ? res.message : 'Failed to save ATEM.');
                btn.disabled = false;
                btn.textContent = 'Save ATEM';
            }
        }).catch(function () {
            alert('Network error while saving.');
            btn.disabled = false;
            btn.textContent = 'Save ATEM';
        });
    }

    // --------------------------------------------------------------- wiring
    function bind() {
        $('atem-level').addEventListener('change', recalcIncentive);
        $('atem-rule').addEventListener('change', recalcIncentive);
        $('tl-status').addEventListener('change', function () { syncClosureDate(); recalcIncentive(); });

        $('tl-end').addEventListener('change', recalcFinalDue);
        $('tl-extended').addEventListener('change', syncExtensionFields);
        $('tl-ext1').addEventListener('change', function () { syncExtensionFields(); });
        $('tl-ext2').addEventListener('change', recalcFinalDue);

        $('arci-dept-search').addEventListener('keyup', filterDepartments);
        $('arci-dept-select').addEventListener('change', renderStaffList);
        $('arci-staff-search').addEventListener('keyup', renderStaffList);
        $('arci-add-btn').addEventListener('click', addSelectedMembers);

        $('arci-grid').addEventListener('click', function (e) {
            var t = e.target;
            if (t.classList.contains('atem-arci-remove')) {
                removeMember(parseInt(t.getAttribute('data-staff'), 10), t.getAttribute('data-role'));
            } else if (t.classList.contains('atem-arci-clear')) {
                clearRole(t.getAttribute('data-role'));
            }
        });

        var saveBtn = $('atem-save-btn');
        if (saveBtn) { saveBtn.addEventListener('click', saveAtem); }
    }

    document.addEventListener('DOMContentLoaded', function () {
        populateLookups();
        populateDepartments();
        initEditor();
        bind();
        recalcIncentive();
        renderArci();
    });
})();
