# ATEM Frontend — Claude Code Guide

## Environment

- **PHP version:** 8.2 (Laragon, `C:\laragon\www\odb\`) — upgraded 2026-07-14 from the legacy PHP 5.6 copy at `C:\xampp\htdocs\odb\`
- **Runtime:** Apache via Laragon on Windows
- **Database:** MySQL via `$conn` (MySQLi), connection provided by `common/index_adv.php`
- **Backend API:** Laravel `atem-api` at `C:\laragon\www\atem-api` (local: `http://127.0.0.1:8000/api/`)

## PHP Syntax Rules

- Modern PHP 8.2 syntax is available: short array `[]` syntax, namespaces, traits, scalar type hints, return type declarations, null coalescing (`??`/`??=`), short ternary (`?:`), arrow functions, match expressions
- Existing `array()` usage does not need to be mass-refactored, but new code may use `[]` freely
- Use `mysqli_*` functions directly — no PDO
- `CURLFile` remains safe to use for multipart uploads

## Architecture

### Auth Flow

Every ATEM page starts with `header.php`, which:
1. Includes `lock_adv.php` — validates ODB session, sets `$grade`, `$struct`, `$atem`, and 70+ module permission vars from `staff` table
2. Sets `$atem_permission = (int)$grade`, then applies dev override if present
3. Sets `$_is_superadmin = (!isset($_SESSION['atem_dev_role_override']) && isset($atem) && (int)$atem === 1)`
4. Redirects to login if `$atem_permission === 0 && !$_is_superadmin`

`$_is_superadmin` is available to all files that include `header.php` (including `navbar.php`, which header.php includes). It is `false` when dev override is active, matching backend.php behavior. `$atem_permission` is never set to 6 — SuperAdmin is always identified via `$_is_superadmin` or `$atem === 1` directly.

### `staff.atem` SuperAdmin Flag

`staff.atem TINYINT(1)` — `0` = normal user, `1` = superadmin.

A user with `atem = 1` is treated as grade 6 (SuperAdmin) throughout the ATEM module regardless of their actual `staff.grade`. This is the correct way to grant SuperAdmin access in production where the user's real grade reflects their job position, not their ATEM role.

**Where the override is applied:**
- `header.php` — sets `$_is_superadmin`; available to all pages and to `navbar.php`
- `navbar.php` — uses grade 3+/`$_is_superadmin` for the Performance nav link; uses `$atem === 1` for Masterlist nav link and dev toolbar
- `access_control/backend.php` — sets `$requester_grade = 6` and `$requester_is_superadmin = true` after reading `staff.atem` directly from DB

All SuperAdmin feature gates check `$_is_superadmin` (set by `header.php`) or `$atem === 1` directly. `$atem_permission` is never set to 6 and never checked for SuperAdmin identity.

**Additional SuperAdmin capabilities:**
- Bypasses the struct update window and per-quarter quota entirely
- Can toggle the global struct window override via `atem_config` table (opens window for all users)
- Has exclusive write access to grade and struct library entries

**Dev override interaction:**
- In `header.php`: dev override (`$_SESSION['atem_dev_role_override']`) takes priority over the `$atem` flag, so a SuperAdmin can simulate lower grades
- In `access_control/backend.php`: when dev override is active on localhost, `$requester_is_superadmin` is explicitly set to `false` — SuperAdmin-only backend operations are suppressed for accurate testing of grade 2–5 behavior

### Grade Levels

| Value | Label | ATEM Access | Data Scope | Key Capabilities |
|---|---|---|---|---|
| 0 | Non-Graded | None | — | Redirected to dashboard; no ATEM access |
| 1 | Frontline / Operational Staff | Basic | Own cards only | View ATEM cards where issuer or ARCI member |
| 2 | Middle Management | Basic | Own department | View department cards; edit staff limited to overlapping departments; Staff Performance page (view/export/lock/unlock) |
| 3 | Senior Management | Admin | Own department (cards) | Access Control page; view and edit all staff grades and structs; Staff Performance page |
| 4 | C Suite Executive | Admin | All departments | Staff Performance page; Masterlist page accessible (page-guard only) |
| 5 | CEO/Board | Admin | All departments | Same as grade 4 |

`staff.grade` only holds values 0–5. Grade 6 does not exist in the database. SuperAdmin is a separate flag (`staff.atem = 1`) — see the SuperAdmin Flag section for capabilities.

**ATEM card / dashboard statistics visibility** (distinct from Access Control staff management):

| Grade | Card / statistics scope |
|---|---|
| 1 | Own cards only — issuer or ARCI member |
| 2, 3 | Own cards (issuer or ARCI member by `staff_id`, always) **plus** department scope: issuer dept or any ARCI member dept overlaps the user's departments. Grade 3 also sees every Outlet-type card. |
| 4, 5, SuperAdmin | All departments |

The "own cards" clause for grades 2–3 is checked by `staff_id` against `issuer_staff_id` / `arci[].staff_id` — it ignores the `atem_arci.staff_dept_id` snapshot and the user's current department, so an issuer/ARCI member still sees their card after changing departments (`_atem_viewer_is_own()` in `api.php`; inline in `view.php`). This mirrors `edit.php`'s `$can_view`.

This scoping is enforced server-side in `view.php` (card list), the `dashboard-stats` handler in `api.php` (dashboard), and `list-atems-scoped` in `api.php` (OKR Link-ATEM picker), and reflected in the department filter dropdowns (`view.php`, `index.php`). It is independent of the Access Control page, where grades 3–5 manage staff across all departments.

The browse/statistics scope above is the **list** layer. Two finer layers gate an individual card (both enforced in `edit.php`, dev-override aware via `$atem_permission` / `$_is_superadmin`):

| Layer | Rule |
|---|---|
| Open a single card (read-only view) | Issuer and any ARCI member: always, at any grade. Otherwise — grade 4–5 and real SuperAdmin: any card; grade 3: Outlet-type cards company-wide, HQ-type cards only when the issuer dept or an ARCI member dept overlaps the viewer's own dept(s); grade 2: Outlet-dept viewers only when a card outlet overlaps their own outlet(s), all other grade 2 on the same dept-overlap rule as grade 3; grade 1: issuer/ARCI only. Blocked users are redirected to `view.php` with the "no permission" warning. Gate at `edit.php` (`$can_view`); mirrors the list scoping in `api.php` (`list-atems-scoped`). |
| Edit a card (`mode=edit`) | Issuer, Accountable ARCI member (`role 'A'`), or real SuperAdmin only — for every grade. Enforced solely by the edit backstop in `edit.php` (`$can_edit`); everyone else is downgraded to read server-side. |

So the single-card view gate now matches the list/dashboard department scoping instead of letting any grade 2+ open any card; editing remains issuer/ARCI-only regardless of grade. `$can_view` reads `$record['staff_dept_id']` (issuer dept), each `arci[].staff_dept_id` / `arci[].outlet_id`, and any `record['outlets'][].outlet_id`; viewer scope comes from `$requester_dept_ids` and the comma-separated `$outlet` session var.

`view.php`'s Action column shows exactly two buttons per row — Edit and Delete. There is no separate View button: the Edit button always links to `edit.php?id=<id>&mode=edit` for every viewer, including those who cannot actually edit — `edit.php`'s `$can_edit` backstop (see above) downgrades unauthorized viewers to a read-only render server-side, so the single button doubles as "open" for read-only users. `js/view.js`'s `buildActionCell()` no longer gates the Edit link on any frontend permission check (the old `canEdit()`/`canUpdateProgress()` helpers were removed as dead code); it only gates the Delete button, via `canDelete()`/`canDeleteSuspended()`.

**Page-level guards** use `$atem_permission` (not `$grade` directly):

| Page | Guard | Redirect Target |
|---|---|---|
| All pages | `$atem_permission === 0` | `/odb/index.php` (login) |
| `access_control/index.php` | `$atem_permission < 1` | `/odb/atem/index.php` |
| `staff_performance/index.php` | `$atem_permission < 3` | `/odb/atem/index.php` |
| `staff_performance/edit.php` | `$atem_permission < 3` | `/odb/atem/index.php` |
| `access_control/masterlist.php` | `$atem_permission < 4` | `/odb/atem/index.php` |

SuperAdmin (`$atem === 1`) passes all grade-based page guards above via `$_is_superadmin` (set by `header.php`). SuperAdmin-only features (Masterlist nav, struct window toggle, library writes) are gated on `$_is_superadmin` or `$atem === 1` directly.

**`staff_performance/index.php` access tier** (grade 3+ or SuperAdmin — the People Management dept-17 carve-out was removed): this tier gates the page itself, the `get-performance-list`/`get-staff-atem-list` data endpoints, and the plain Export/Export Selected buttons (`staff_performance/export.php`). The list table shows three read-only Completed-count columns side by side — **HQ ATEM Completed**, **Outlet ATEM Completed**, **OKR Completed** — followed by two more read-only sum columns, **Total Completed** and **Failed**, which are simply HQ + Outlet + OKR added together (`complete_total_count`/`failed_total_count` in `get-performance-list`'s response; the Failed side is only ever nonzero when "Failed" is checked in the Status filter, same as every other bucket). **Est. Reward stays HQ ATEM only**; Outlet ATEM and OKR carry no incentive/payout concept at all (Lock Payout is likewise HQ-only, see below). `api.php`'s `get-performance-list` case builds each row by calling `getStaffPerformanceLive()` twice (`$filterAtemType` 1 then 2, for HQ and Outlet respectively — each a full `getAtemList()` HTTP round trip to `atem-api`) plus `getOkrPerformanceLive($conn, ...)` once (plain mysqli against `okr_cards`/`okr_statuses` in the same ODB database — OKR has no separate API, see `okr/CLAUDE.md`), then merges on the **union** of staff ids touched by any of the three, so a staff member with only Outlet ATEM or OKR activity and no HQ ATEM cards still appears. OKR's Completed and Failed counts only credit the card's owner(s) (`owner_staff_id`/`owner2_staff_id`) — not the issuer — matching OKR's own "my cards" scoping (`okrScopeWhere()` in `okr/lib.php`), not ATEM's issuer+ARCI model. `export.php`'s `performance` export type mirrors the same three-fetch/union approach (its own `getStaffPerformanceLive()` calls for HQ/Outlet plus `getOkrPerformanceLive()`) and emits one flat CSV: HQ ATEM rows keep the full computed `Est. Reward (RM)` (via `emit_atem_rows()`), while Outlet ATEM rows (`emit_atem_rows(..., $blank_reward = true)`) and OKR rows (`emit_okr_rows()`, always Owner/Owner 2 only) always leave `Est. Reward (RM)` and `Payout` blank — and are restricted to Completed-family statuses regardless of what else is checked in the Status filter, matching the on-screen Outlet ATEM Completed/OKR Completed columns. The single-staff `staff-atem` export type (used by `edit.php`, not the Staff Performance list) is unrelated and untouched — it dumps a target staff's full unfiltered ATEM history (HQ and Outlet mixed, each with its normal computed reward) via `emit_atem_rows()` with no `$blank_reward` override. The Lock Payout/Unlock family of buttons is a **stricter, separate carve-out**: `api.php`'s `bulk-lock-payout`/`bulk-unlock-payout` cases require real SuperAdmin (`staff.atem = 1`) regardless of grade — a grade 3-5 user who can see the page and export data cannot lock or unlock payout. `staff_performance/edit.php` (the per-staff/per-ATEM detail page reached via each row's "View" link) keeps its own separate `$atem_permission < 3` guard (grade 3+ or SuperAdmin) — same threshold as the list page now, so no grade sees a dead-end "View" button; it remains HQ-ATEM-only with no Outlet/OKR tab. Payout lock/unlock server-side logic lives in `api.php`'s `resolvePayoutTargetStaffIds()`/`resolvePayoutAtemIds()` (odb) and `AtemController::bulkLockPayout()`/`bulkUnlockPayout()` (atem-api) — the atem-api endpoints trust odb's authorization decision and do not re-check grade themselves.

**Navbar visibility** (navbar resolves `$atem_role` from `$atem_permission`):

| Nav Item | Condition | Notes |
|---|---|---|
| Dashboard | Always | All graded users |
| ATEM | Always | All graded users |
| Performance | `$atem_role >= 3 \|\| $_is_superadmin` | Grades 3+, SuperAdmin |
| Access Control | `$atem_role >= 1` | All graded users |
| Masterlist | `isset($atem) && (int)$atem === 1` | SuperAdmin flag check — not a grade 6 check |

### Department Assignment

`staff.department` stores comma-separated department IDs (e.g., `"3,7"`), not a single FK. A staff member can belong to multiple departments.

- `access_control/backend.php` parses this with `explode(',', $auth_row['department'])` into an array of int IDs (`$requester_dept_ids`)
- Grades 2–3: can only view or edit target staff whose `department` IDs overlap with their own assigned departments
- Grade 4+ and SuperAdmin: can edit all staff regardless of department assignment
- `canEditStaff()` in `js/admin_access.js` enforces this on the frontend by comparing `REQUESTER_DEPT_IDS` against the target staff member's dept IDs

### Outlet ATEM — Area Manager Picker

Outlet-type ATEMs (`create.php` / `edit.php`) carry a required **Area Manager(s)** field (`#atem-am-tag-group`, JS `CFG.areaManagers` / `areaManagerTags`, error id `atem-am-error`). It was previously labelled "Outlet Staff(s)".

**Visibility:** the group has classes `atem-outlet-only atem-hidden`. `setStaffType()` in `js/create.js` / `js/edit.js` unhides all `.atem-outlet-only` only when ATEM Type is **Outlet**; HQ ATEM keeps it hidden. The ATEM Type selector itself only renders when `$_can_choose_atem_type` (`$atem_permission >= 3 || $_is_superadmin`); lower grades are forced onto a type by `$_forced_atem_type` from their department.

**Who populates the picker** (`$area_managers_list` / `$am_sql` in both `create.php` and `edit.php`):

```sql
SELECT s.id, s.nama_staff, s.outlet, p.position_name
FROM staff s
LEFT JOIN position_rymnet p ON p.id = s.status_rym
WHERE s.status_rym = 134 AND s.grade >= 3 AND s.recycle != 1
ORDER BY s.nama_staff
```

`staff.status_rym = 134` is the Area Manager position (same id the Outlet dashboard tab uses in `index.php`). The list is strictly Area Managers — the old filter was `FIND_IN_SET('1', s.department) AND s.grade >= 3` (any grade-3+ Outlet-department staff), which let non-AM staff through. `position_rymnet` is `LEFT JOIN`ed for the display label only; it is not filtered on. `staff.grade` holds 0–5 only (grade 6 does not exist — see the SuperAdmin Flag section).

### Project Team (ARCI) — Staff Picker

The ARCI picker (`#arci-*` in `create.php` / `edit.php`, logic in `js/create.js` / `js/edit.js`) normally requires a Scope first: a radio toggle (`#arci-scope-outlet` / `#arci-scope-department`, Outlet cards only) then an outlet/department in `#arci-dept-select`, which loads `CFG.staffByOutlet[id]` or `CFG.staffByDept[id]` into `#arci-staff-list`. `#arci-staff-search` only filters within that chosen scope.

**Cross-scope name search:** when `#arci-dept-select` is empty and `#arci-staff-search` has text, `renderStaffList()` calls `renderCrossScopeMatches()` instead, listing name matches from **every** outlet and department out of `CFG.allStaff` (flat list, capped at 50 rows, each row shows the staff member's outlet code(s) / department name(s) as a muted hint). `CFG.allStaff` is built server-side in both PHP files (`$all_staff_flat` / `$asf_sql`): `id, name, position, dept_ids[], outlet_ids[]` parsed from the comma-separated `staff.department` / `staff.outlet` columns.

**Clicking a match** (`pickCrossScopeStaff()`):
- Uses the **first** id only — `outlet_ids[0]` on Outlet scope, `dept_ids[0]` otherwise (matches how `staffByOutlet` / `staffByDept` are keyed).
- Outlet scope: if `outlet_ids[0]` is not one of the card's tagged outlets (`outletTags`), it is **blocked** with inline red text (`atem-arci-error`) — "…is not in a tagged outlet — add outlet `<code>` to the card first." Nothing is added.
- Otherwise it sets the Scope radio + `#arci-dept-select`, re-renders the scoped list, and ticks that person's checkbox. The user still picks a **Role** and clicks **Add Selected** — the click never adds the member directly.

### API Bridge

All frontend AJAX calls go through `api.php`. It uses a shared service account JWT (`atem-service@local`) cached in session. The file is included via `define('API_JWT_INCLUDED', 1); include 'api.php';` when used as a library, or accessed directly as a POST endpoint.

Key functions in `api.php`:
- `getAuthToken($staff_id)` — returns cached or fresh JWT
- `getApiDataWithJWT($endpoint, $data, $method, $staff_id)` — wraps all REST calls
- `getStaffAuthData($staff_id)` — queries ODB `staff` table for issuer identity fields

### Admin Backend

`access_control/backend.php` is a standalone JSON endpoint (not part of `api.php`). The `admin/` directory no longer exists — all admin backend logic was consolidated here.

It:
- Reads `staff.grade` AND `staff.atem` from the ODB database directly
- Sets `$requester_is_superadmin = true` and `$requester_grade = 6` when `staff.atem = 1`
- On localhost with dev override active: sets `$requester_is_superadmin = false` (suppresses SuperAdmin for testing)
- Requires `$requester_grade >= 3` to proceed for most operations
- Parses `$requester_dept_ids` from comma-separated `staff.department`
- Handles: `getActiveStaff`, `searchStaff`, `updateAccess`, `getLibrary`, `updateLibrary`, `addLibrary`, `deleteLibrary`, `getStructHistory`, `updateStructWindowOverride`
- Library write operations (`addLibrary`, `updateLibrary`, `deleteLibrary`) are guarded by `$requester_is_superadmin`
- `updateStructWindowOverride` is guarded by `$requester_is_superadmin`

### Struct Update Window

Non-SuperAdmin users can only change their evaluation struct during the first 10 days of each quarter's opening month (Jan 1–10, Apr 1–10, Jul 1–10, Oct 1–10). One change per staff per quarter is enforced.

- `isInStructWindow()` in `access_control/backend.php` checks `date('j') >= 1 && date('j') <= 10`
- `getCurrentQuarter()` maps the current month to Q1–Q4
- `staff_struct_history` table enforces the quota: if a record already exists for `(staff_id, year, quarter)`, a second update is blocked for non-SuperAdmin users
- SuperAdmin always bypasses both the window check and the quota check
- SuperAdmin can enable a **global window override**: setting `atem_config.struct_window_override = '1'` opens the window for all users regardless of date
- The override toggle is a checkbox on `access_control/index.php`, visible only when `$atem === 1`
- `getStructHistory` returns the last 12 quarters of struct changes for display in `js/admin_access.js`; used to show lock reason when updating is blocked

### ATEM Card Deletion

Soft delete only — `atems.deleted_at` is set, the row remains in the DB.

**Who can delete:** Issuer can delete their own Draft, Active, Extended, or Suspended cards. A real SuperAdmin (`CFG.isSuperAdmin` / `$is_api_superadmin`) can delete an ATEM of **any** status — including terminal ones (Completed, Completed with Excellence, Completed with Extension, Failed) — regardless of who issued it. Non-SuperAdmins can never delete terminal-status cards. Already soft-deleted (`status === 'Deleted'`) cards are never delete-able again by anyone.

**Delete flow:**
1. Issuer (or SuperAdmin) clicks Delete in `view.php` → Bootstrap modal opens (same fade animation as edit.php modals)
2. User must enter a remark (required) before confirming
3. Frontend posts `{ action: 'delete-atem', id, remarks }` to `api.php`
4. `api.php` → `deleteAtem($id, $staff_id, $remarks, $is_superadmin)` → DELETE `/api/atem/{id}` with JSON body `{ actor_id, remarks, [superadmin_override: 1] }` (flag added when `$is_api_superadmin` is true)
5. Backend (`AtemController::destroy`): checks terminal status and issuer match, both bypassed when `superadmin_override` is set; sets `atem_status_id` to "Deleted" status, saves `remarks` and `closed_by` (actor), writes audit log (`event = 'deleted'`), then calls `$atem->delete()` (soft delete)

**"Deleted" status:** A real `atem_statuses` row (value = `'Deleted'`), added via migration `2026_06_22_105848_add_deleted_status_to_atem_statuses_table.php`. Always look this up via `DB::table('atem_statuses')->where('value', 'Deleted')->whereNull('deleted_at')->value('id')` in the backend — do NOT use `AtemStatus::where(...)` which may be affected by the SoftDeletes global scope.

**Visibility of deleted cards:**
- Grades 1–3: never see deleted cards (`getAtemList` does not pass `include_deleted`)
- Grades 4, 5, SuperAdmin: see deleted cards with a red "Deleted" badge on the title and dimmed row opacity; no action buttons except View
- `view.php` passes `include_deleted=true` to `getAtemList` for grade 4+/SA; backend uses `Atem::withTrashed()` when `?include_deleted=1`
- Status filter in `view.js` includes "Deleted" naturally since it is a real status from the lookups

**Viewing a deleted card (`edit.php`):**
- `AtemController::show` uses `withTrashed()` so deleted cards can be fetched by ID
- `edit.php` detects `$record['deleted_at']` and sets `$record_is_deleted`
- Grades 1–3 hitting a deleted card URL are redirected to `view.php` with a warning
- Grades 4+/SA: forced read-only, red alert banner shows who deleted it and when (resolved from `closed_by` + `deleted_at`)
- `'Deleted'` is in `$terminal_statuses` in `edit.php` — can never enter edit/progress mode

**Key columns written on delete:** `atems.deleted_at` (soft delete), `atems.atem_status_id` → Deleted, `atems.remarks` (deletion reason), `atems.closed_by` (actor staff_id)

**Audit log:** `atem_audit_logs` row with `event = 'deleted'`, `actor_staff_id`, `summary` containing the remark. The `cascadeOnDelete` FK on `atem_audit_logs.atem_id` only fires on hard delete — audit logs survive soft delete.

## Key Files

| File | Role |
|---|---|
| `header.php` | Base layout, auth gate, sets `$atem_permission` |
| `navbar.php` | Nav + dev grade switcher toolbar |
| `api.php` | JWT bridge to atem-api, all AJAX actions |
| `view.php` | ATEM card list; passes `include_deleted` for grade 4+/SA |
| `js/view.js` | Card list rendering; `canDelete()`, delete modal, deleted badge/dimming |
| `edit.php` | Single card view/edit; detects `$record_is_deleted`, shows deleted banner |
| `access_control/index.php` | Staff grade and struct management UI; struct window override toggle for SuperAdmin |
| `access_control/backend.php` | Admin AJAX handler, direct ODB DB queries (replaces removed `admin/backend.php`) |
| `access_control/masterlist.php` | Grade and struct library editor; accessible via SuperAdmin nav link only |
| `js/admin_access.js` | Frontend logic for Access Control page (dept-scoped edit gating, struct history display) |
| `lock_adv.php` (parent) | ODB session auth, sets `$grade`, `$struct`, `$atem` |
| `sql/add_staff_atem_column.sql` | One-time DDL to add `staff.atem` column |

## ODB Database Tables

| Table | Purpose |
|---|---|
| `staff` | Auth, grade, atem flag, name resolution; `department` is comma-separated dept IDs |
| `staff_department` | Department names |
| `staff_grade` | Grade label definitions (editable via Masterlist) |
| `staff_struct` | Evaluation structure label definitions (editable via Masterlist) |
| `staff_struct_history` | Quarterly struct change log per staff; fields: `staff_id`, `struct`, `year`, `quarter` |
| `atem_config` | Global ATEM settings; key `struct_window_override` (`'0'`/`'1'`) controls global struct window |

## Error Handling

- Display errors inline as small red text — never use `alert()` or system dialogs
- All API responses use `{'success': true/false, 'message': '...'}` shape
- Log JWT operations to `logs/jwt_operations-YYYY-MM-DD.log` (one file per day) via `logJWTOperation()`

## Dev Tooling

- `dev-switch-role.php` — writes `$_SESSION['atem_dev_role_override']` to simulate any grade
- Dev toolbar in `navbar.php` — visible on localhost for users where `$_navbar_realRole === 6` (`atem = 1` users only; navbar sets this from `$atem === 1` directly)
- When dev override is active, `access_control/backend.php` suppresses SuperAdmin privileges (`$requester_is_superadmin = false`) to allow realistic simulation of grade 2–5 behavior
- Cache-busting: `style.css?v=<?php echo time(); ?>` on every load

## Important Constraints

- Staff names and department names are resolved from ODB on the frontend — never store snapshots in `atem-api`
- All FK ids (issuer_staff_id, staff_dept_id) stored in atem-api; names resolved here by id
- Do not create new SQL migration files — run `add_staff_atem_column.sql` once manually
- Do not bypass `lock_adv.php` session checks
