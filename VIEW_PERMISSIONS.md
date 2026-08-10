# ATEM View & Permission Matrix (HQ vs Outlet)

Scope: who can see which ATEM cards in the list/dashboard, open a single card,
edit a card, and delete a card — split by `atem_type` (1 = HQ, 2 = Outlet) and
`staff.grade` (0-5) plus the `staff.atem` SuperAdmin flag.

Enforced server-side in `view.php` (card list), `api.php` (`dashboard-stats`,
`list-atems-scoped`), and `edit.php` (single-card open/edit gate). See project
`CLAUDE.md` for the full architecture writeup this table summarizes.

## Card list / dashboard visibility

| Grade | Label | HQ ATEM scope | Outlet ATEM scope |
|---|---|---|---|
| 0 | Non-Graded | No ATEM access | No ATEM access |
| 1 | Frontline / Operational | Own cards only (issuer or ARCI member) | Own cards only (issuer or ARCI member) |
| 2 | Middle Management | Own department(s) — issuer/ARCI dept overlap | Own outlet(s) only — `staff.outlet` overlap with card's linked outlets (narrower than dept, since `department=1` alone is shared by every outlet company-wide) |
| 3 | Senior Management | Own department(s) — issuer/ARCI dept overlap | **All outlets company-wide** — no department/outlet restriction |
| 4 | C Suite Executive | All departments | All outlets |
| 5 | CEO/Board | All departments | All outlets |
| SuperAdmin (`staff.atem=1`) | — | All departments | All outlets |

Grade 1/2 also get a collapsed single-tab view (HQ or Outlet, matching their
own department) instead of both tabs — grade 3+/SA keep both tabs.

Deleted (soft-deleted) cards: grades 1-3 never see them; grade 4+/SA see them
with a red "Deleted" badge (`view.php` passes `include_deleted`). Everyone can
still see their own **Suspended** cards regardless of grade — Suspended is a
soft-delete status but stays visible to its issuer.

## Open a single card (read-only)

| Grade | HQ ATEM | Outlet ATEM |
|---|---|---|
| 1 | Own cards only (issuer or ARCI member) | Own cards only (issuer or ARCI member) |
| 2-5, SuperAdmin | Any card | Any card |

This is a separate, looser gate than the list/dashboard scope above — grades
2-5 can open any single card by URL even though their list only shows their
own department(s)/outlet(s). Enforced in `edit.php` (`$can_view`).

## Edit a card (`mode=edit`)

Same rule for every grade and both types — no grade-based widening:

- Issuer, OR
- Accountable ARCI member (role `A`), OR
- Real SuperAdmin (`staff.atem = 1`, not dev-override)

Enforced in `edit.php` (`$can_edit`); everyone else is downgraded to a
read-only render server-side, regardless of what the frontend link shows.

## Delete a card

Grade-independent, same rule for HQ and Outlet:

- Issuer can delete their own Draft/Active/Extended/Suspended cards.
- Real SuperAdmin can delete any card of any status, including terminal ones
  (Completed, Completed with Excellence, Completed with Extension, Failed).
- Non-SuperAdmins can never delete terminal-status or already-deleted cards.

Enforced client-side in `js/view.js` (`canDelete()` / `canDeleteSuspended()`)
and backstopped server-side in `AtemController::destroy`.

## Dashboard stats (`api.php` `dashboard-stats`)

Mirrors the card list/dashboard visibility table above exactly (same grade
branches, same HQ-dept vs Outlet-outlet split for grades 2-3).

## Notes

- `atem_type`: `1` = HQ, `2` = Outlet. Outlet-type cards show staff **position**
  instead of department for Issuer/Accountable columns.
- Outlet ATEM has no incentive/payout concept — Est. Reward stays HQ-only
  (Outlet dashboard has no "Est. Reward Forecast" card; Lock Payout is
  HQ-only in Staff Performance).
- Dev role override (`$_SESSION['atem_dev_role_override']`) simulates any
  grade 0-5 for testing; it is never treated as SuperAdmin.
