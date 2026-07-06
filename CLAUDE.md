# ATEM Frontend — Claude Code Guide

## Environment

- **PHP version:** 5.6 (XAMPP, `C:\xampp\htdocs\odb\`)
- **Runtime:** Apache via XAMPP on Windows
- **Database:** MySQL via `$conn` (MySQLi), connection provided by `common/index_adv.php`
- **Backend API:** Laravel `atem-api` at `C:\laragon\www\atem-api` (local: `http://127.0.0.1:8000/api/`)

## PHP Syntax Rules

- Always use `array()` — never short `[]` syntax
- No namespaces, traits, scalar type hints, return types, null coalescing (`??`), or short ternary
- Use `mysqli_*` functions directly — no PDO
- PHP 5.6 is available: `CURLFile` is safe to use for multipart uploads

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
- `navbar.php` — uses `$_is_superadmin` for Performance nav link; uses `$atem === 1` for Masterlist nav link and dev toolbar
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
| 2 | Middle Management | Basic | Own department | View department cards; edit staff limited to overlapping departments |
| 3 | Senior Management | Admin | Own department (cards) | Access Control page; view and edit all staff grades and structs |
| 4 | C Suite Executive | Admin | All departments | + Staff Performance page; Masterlist page accessible (page-guard only) |
| 5 | CEO/Board | Admin | All departments | Same as grade 4 |

`staff.grade` only holds values 0–5. Grade 6 does not exist in the database. SuperAdmin is a separate flag (`staff.atem = 1`) — see the SuperAdmin Flag section for capabilities.

**ATEM card / dashboard statistics visibility** (distinct from Access Control staff management):

| Grade | Card / statistics scope |
|---|---|
| 1 | Own cards only — issuer or ARCI member |
| 2, 3 | Own department(s) — issuer dept or any ARCI member dept overlaps the user's departments |
| 4, 5, SuperAdmin | All departments |

This scoping is enforced server-side in `view.php` (card list) and the `dashboard-stats` handler in `api.php` (dashboard), and reflected in their department filter dropdowns (`view.php`, `index.php`). It is independent of the Access Control page, where grades 3–5 manage staff across all departments.

The browse/statistics scope above is the **list** layer. Two finer layers gate an individual card (both enforced in `edit.php`, dev-override aware via `$atem_permission` / `$_is_superadmin`):

| Layer | Rule |
|---|---|
| Open a single card (read-only view) | Grade 1: own cards only (issuer or ARCI member). Grades 2–5 and real SuperAdmin: any card. Gate at `edit.php` (`$can_view`). |
| Edit a card (`mode=edit`) | Issuer, Accountable ARCI member (`role 'A'`), or real SuperAdmin only — for every grade. Enforced by the edit backstop in `edit.php` (`$can_edit`) and mirrored by `canEdit()` in `js/view.js`. A grade 2–5 viewer is downgraded to read on unrelated cards. |

So grades 2–5 can open and read any card even though their list/dashboard only shows their own department(s); editing remains issuer/ARCI-only regardless of grade.

**Page-level guards** use `$atem_permission` (not `$grade` directly):

| Page | Guard | Redirect Target |
|---|---|---|
| All pages | `$atem_permission === 0` | `/odb/index.php` (login) |
| `access_control/index.php` | `$atem_permission < 1` | `/odb/atem/index.php` |
| `staff_performance/index.php` | `$atem_permission < 4` | `/odb/atem/index.php` |
| `staff_performance/edit.php` | `$atem_permission < 4` | `/odb/atem/index.php` |
| `access_control/masterlist.php` | `$atem_permission < 4` | `/odb/atem/index.php` |

SuperAdmin (`$atem === 1`) passes all grade-based page guards above via `$_is_superadmin` (set by `header.php`). SuperAdmin-only features (Masterlist nav, struct window toggle, library writes) are gated on `$_is_superadmin` or `$atem === 1` directly.

**Navbar visibility** (navbar resolves `$atem_role` from `$atem_permission`):

| Nav Item | Condition | Notes |
|---|---|---|
| Dashboard | Always | All graded users |
| ATEM | Always | All graded users |
| Performance | `$atem_role >= 4` | Grades 4, 5, SuperAdmin |
| Access Control | `$atem_role >= 1` | All graded users |
| Masterlist | `isset($atem) && (int)$atem === 1` | SuperAdmin flag check — not a grade 6 check |

### Department Assignment

`staff.department` stores comma-separated department IDs (e.g., `"3,7"`), not a single FK. A staff member can belong to multiple departments.

- `access_control/backend.php` parses this with `explode(',', $auth_row['department'])` into an array of int IDs (`$requester_dept_ids`)
- Grades 2–3: can only view or edit target staff whose `department` IDs overlap with their own assigned departments
- Grade 4+ and SuperAdmin: can edit all staff regardless of department assignment
- `canEditStaff()` in `js/admin_access.js` enforces this on the frontend by comparing `REQUESTER_DEPT_IDS` against the target staff member's dept IDs

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
- Log JWT operations to `logs/jwt_operations.log` via `logJWTOperation()`

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
