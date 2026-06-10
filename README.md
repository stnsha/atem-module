# ATEM Frontend Module

ATEM (Accountability, Tracking, Engagement & Metrics) is a PHP module embedded within the ODB (OctopusDB) staff portal at `/odb/atem/`. It allows staff to create, track, and manage ATEM cards with incentive calculations, ARCI assignments, progress updates, and file attachments. Data is persisted in the Laravel `atem-api` backend and retrieved via JWT-authenticated API calls.

---

## Directory Structure

```
atem/
├── index.php              Dashboard — summary stats and charts
├── view.php               ATEM list — all cards the user can see
├── create.php             New ATEM card form
├── edit.php               Edit an existing card
├── staff_performance.php  Grade 3+ view of staff performance metrics
├── header.php             Base layout top — includes auth, sets $atem_permission
├── footer.php             Base layout bottom — loads page-specific JS
├── navbar.php             Navigation bar — role-gated links, dev grade switcher
├── api.php                Bridge to atem-api — JWT auth + all AJAX action handlers
├── dev-switch-role.php    Localhost-only grade override for testing
│
├── admin/
│   ├── index.php          Access Control — assign grades to staff (grade 3+)
│   ├── library.php        Library — edit grade/struct labels (SuperAdmin only)
│   ├── backend.php        Admin AJAX handler — role-gated, queries ODB staff table
│   └── access_control.php (support file for admin UI)
│
├── css/
│   ├── style.css          Module stylesheet
│   └── logo.svg           ATEM logo
│
├── js/
│   ├── index.js           Dashboard interactions
│   ├── view.js            ATEM list interactions
│   ├── create.js          Card creation form
│   ├── edit.js            Card editing form
│   └── admin_access.js    Access control page
│
├── sql/
│   └── add_staff_atem_column.sql   One-time migration: adds staff.atem column
│
└── logs/
    └── jwt_operations.log  JWT request/response log (auto-created)
```

---

## Authentication & Role System

### ODB Session Auth

Every ATEM page includes `header.php`, which calls `lock_adv.php`. That file:
1. Checks `$_SESSION['myusername']` and `$_SESSION['type'] == 'odb'`. Redirects to login if invalid.
2. Queries `SELECT * FROM staff WHERE username = ? AND recycle != 1`.
3. Sets PHP variables from the staff row, including `$grade`, `$struct`, and `$atem`.

### ATEM Permission Levels

`$atem_permission` is resolved in `header.php` with this priority:

| Priority | Source | Condition |
|---|---|---|
| 1 (highest) | `$_SESSION['atem_dev_role_override']` | Localhost only, dev testing |
| 2 | `staff.atem = 1` | SuperAdmin flag — overrides grade |
| 3 (default) | `staff.grade` | Actual DB grade value |

### Grade Levels

| Grade | Label | Access |
|---|---|---|
| 0 | Non-Graded | No ATEM access |
| 1 | Frontline / Operational Staff | Basic user |
| 2 | Middle Management | Basic user |
| 3 | Senior Management | Admin pages, Performance |
| 4 | C Suite Executive | Admin pages, Performance |
| 5 | CEO/Board | Admin pages, Performance |
| 6 | SuperAdmin | All of the above + Library |

### `staff.atem` Column (SuperAdmin Flag)

`staff.atem TINYINT(1)` — `0` = normal user, `1` = superadmin.

When `staff.atem = 1`, the user is granted grade 6 access in the ATEM module regardless of their actual `staff.grade`. This allows production SuperAdmins to follow their real position grade in other ODB modules while retaining full ATEM admin access.

SQL to add the column (run once):
```sql
-- atem/sql/add_staff_atem_column.sql
ALTER TABLE staff
    ADD COLUMN atem TINYINT(1) NOT NULL DEFAULT 0 COMMENT '0 = normal user, 1 = superadmin';
```

To grant SuperAdmin access to a user:
```sql
UPDATE staff SET atem = 1 WHERE id = <staff_id>;
```

### Dev Grade Switcher

On localhost, a toolbar appears at the top of every ATEM page for users with `$_navbar_realRole === 6` (or `staff.atem = 1`). It writes `$_SESSION['atem_dev_role_override']` via `dev-switch-role.php`, allowing simulation of any grade without changing the database.

---

## API Bridge (`api.php`)

`api.php` is the single AJAX endpoint for all frontend-to-backend communication. It:
1. Reads `$_SESSION['myusername']` and looks up `staff.id`, `staff.department`, `staff.nama_staff`.
2. Obtains a JWT from `atem-api` using a service account (`atem-service@local`).
3. Caches the JWT in session (`$_SESSION['jwt_token']`, `$_SESSION['jwt_expires']`).
4. Forwards requests to the appropriate `atem-api` REST endpoint.

### Supported Actions (POST `api.php?action=...` or JSON body `{"action":"..."}`)

| Action | Backend call |
|---|---|
| `lookups` | GET /atem/lookups |
| `list-atems` | GET /atem |
| `dashboard-stats` | GET /atem (aggregated client-side) |
| `get-atem` | GET /atem/{id} |
| `create-draft` | POST /atem |
| `save-atem` | POST /atem |
| `update-atem` | PUT /atem/{id} |
| `delete-atem` | DELETE /atem/{id} |
| `arci-add` | POST /atem/{id}/arci |
| `arci-remove` | DELETE /atem/{id}/arci |
| `arci-remove-role` | DELETE /atem/{id}/arci/role/{role} |
| `reflink-list` | GET /atem/{id}/reference-links |
| `reflink-add` | POST /atem/{id}/reference-links |
| `reflink-remove` | DELETE /atem/{id}/reference-links/{linkId} |
| `attachment-list` | GET /atem/{id}/attachments |
| `attachment-upload` | POST /atem/{id}/attachments (multipart) |
| `attachment-remove` | DELETE /atem/{id}/attachments/{attId} |
| `attachment-download` | GET /atem/{id}/attachments/{attId}/download |
| `progress-list` | GET /atem/{id}/progress |
| `progress-add` | POST /atem/{id}/progress |
| `progress-update` | PUT /atem/{id}/progress/{progressId} |
| `progress-remove` | DELETE /atem/{id}/progress/{progressId} |
| `draft-get` | Session read |
| `draft-save` | Session write |
| `draft-files-save` | Session write |
| `draft-clear` | Session clear |
| `bonus-update-remark` | PUT /bonus-eligibility/{id} |
| `bonus-trigger-calculate` | POST /bonus-eligibility/calculate |

### Environment Detection

| Environment | API Host |
|---|---|
| localhost / 127.0.0.1 | `http://127.0.0.1:8000/api/` |
| Production | `http://mytotalhealth.com.my/atem-api/public/api/` |

---

## Admin Module (`admin/`)

Access requires `$atem_permission >= 3`.

### `admin/backend.php` Actions

| Action | Method | Description | Min Grade |
|---|---|---|---|
| `getActiveStaff` | GET | Paginated staff list (15/page) | 3 |
| `searchStaff` | POST | Staff name search (max 20) | 3 |
| `updateAccess` | POST | Set `staff.grade` for a staff member | 3 |
| `getLibrary` | GET | Fetch `staff_grade` and `staff_struct` labels | 3 |
| `updateLibrary` | POST | Update a grade/struct label | 3 |
| `addLibrary` | POST | Add a new grade/struct entry | 3 |
| `deleteLibrary` | POST | Delete a grade/struct entry | 3 |

The Library actions (grade/struct label management) are visible in the UI only to grade 6 / `atem = 1` users via `admin/library.php`.

---

## ODB Database Tables Used

| Table | Usage |
|---|---|
| `staff` | Auth, grade, atem flag, name resolution |
| `staff_department` | Department name resolution |
| `staff_grade` | Grade label definitions (editable in Library) |
| `staff_struct` | Structure label definitions (editable in Library) |

---

## Setup

1. Run the SQL column migration (once per environment):
   ```bash
   mysql -u root odb < atem/sql/add_staff_atem_column.sql
   ```
2. Ensure `atem-api` is running (see backend README).
3. Access at `http://localhost/odb/atem/`.
