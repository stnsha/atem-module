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
2. Sets `$atem_permission` with this priority:
   - `$_SESSION['atem_dev_role_override']` (localhost only, dev testing)
   - `(int)$atem === 1` → forces `$atem_permission = 6` (SuperAdmin, ignores real grade)
   - `(int)$grade` (default)

### `staff.atem` SuperAdmin Flag

`staff.atem TINYINT(1)` — `0` = normal user, `1` = superadmin.

A user with `atem = 1` is treated as grade 6 (SuperAdmin) throughout the ATEM module regardless of their actual `staff.grade`. This is the correct way to grant SuperAdmin access in production where the user's real grade reflects their job position, not their ATEM role.

**Where the override is applied:**
- `header.php` — sets `$atem_permission = 6`
- `navbar.php` — sets `$_navbar_realRole = 6` (enables dev toolbar)
- `admin/backend.php` — sets `$requester_grade = 6` after reading `staff.atem`

### Grade Levels

| Value | Label | ATEM Access |
|---|---|---|
| 0 | Non-Graded | None |
| 1 | Frontline / Operational Staff | Basic |
| 2 | Middle Management | Basic |
| 3 | Senior Management | Admin pages |
| 4 | C Suite Executive | Admin pages |
| 5 | CEO/Board | Admin pages |
| 6 | SuperAdmin | All + Library |

Page-level guards use `$atem_permission` (not `$grade` directly):
- `$atem_permission < 3` → redirect to dashboard (admin pages, performance page, library)
- `$atem_permission === 6` → Library nav link visible
- `$atem_role >= 3` → Admin nav link visible (navbar resolves `$atem_role` from `$atem_permission`)

### API Bridge

All frontend AJAX calls go through `api.php`. It uses a shared service account JWT (`atem-service@local`) cached in session. The file is included via `define('API_JWT_INCLUDED', 1); include 'api.php';` when used as a library, or accessed directly as a POST endpoint.

Key functions in `api.php`:
- `getAuthToken($staff_id)` — returns cached or fresh JWT
- `getApiDataWithJWT($endpoint, $data, $method, $staff_id)` — wraps all REST calls
- `getStaffAuthData($staff_id)` — queries ODB `staff` table for issuer identity fields

### Admin Backend

`admin/backend.php` is a standalone JSON endpoint (not part of `api.php`). It:
- Reads `staff.grade` AND `staff.atem` from the ODB database directly
- Overrides `$requester_grade = 6` when `staff.atem = 1`
- Requires `$requester_grade >= 3` to proceed
- Handles: `getActiveStaff`, `searchStaff`, `updateAccess`, `getLibrary`, `updateLibrary`, `addLibrary`, `deleteLibrary`

## Key Files

| File | Role |
|---|---|
| `header.php` | Base layout, auth gate, sets `$atem_permission` |
| `navbar.php` | Nav + dev grade switcher toolbar |
| `api.php` | JWT bridge to atem-api, all AJAX actions |
| `admin/backend.php` | Admin AJAX handler, direct ODB DB queries |
| `lock_adv.php` (parent) | ODB session auth, sets `$grade`, `$struct`, `$atem` |
| `sql/add_staff_atem_column.sql` | One-time DDL to add `staff.atem` column |

## ODB Database Tables

| Table | Purpose |
|---|---|
| `staff` | Auth, grade, atem flag, name resolution |
| `staff_department` | Department names |
| `staff_grade` | Grade label definitions (editable) |
| `staff_struct` | Structure label definitions (editable) |

## Error Handling

- Display errors inline as small red text — never use `alert()` or system dialogs
- All API responses use `{'success': true/false, 'message': '...'}` shape
- Log JWT operations to `logs/jwt_operations.log` via `logJWTOperation()`

## Dev Tooling

- `dev-switch-role.php` — writes `$_SESSION['atem_dev_role_override']` to simulate any grade
- Dev toolbar in `navbar.php` — visible on localhost for users where `$_navbar_realRole === 6` (real grade 6 or `atem = 1`)
- Cache-busting: `style.css?v=<?php echo time(); ?>` on every load

## Important Constraints

- Staff names and department names are resolved from ODB on the frontend — never store snapshots in `atem-api`
- All FK ids (issuer_staff_id, staff_dept_id) stored in atem-api; names resolved here by id
- Do not create new SQL migration files — run `add_staff_atem_column.sql` once manually
- Do not bypass `lock_adv.php` session checks
