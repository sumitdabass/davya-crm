# Lead Access Rule — Design

**Date:** 2026-04-22
**Status:** Design approved, pending implementation plan
**Author:** Sumit + Claude (brainstorming session)
**Supersedes in part:** prior ad-hoc visibility rules in `app/Models/Student.php::scopeVisibleTo` and `app/Policies/StudentPolicy.php`

## Problem

Lead access rules (view / edit / delete / transfer) have evolved through several targeted fixes (team-unit scope, admin-owned lead_source routing, cross-head leak fix, Nikhil referrer visibility experiment that was later rolled back). The intent is now clear and needs to be locked in as an explicit, documented contract — both to prevent regressions and to close three real gaps between current behaviour and intent.

## Roles

| User | Role(s) | Team label |
|---|---|---|
| Sumit | Admin (+ nominal head) | — (admin bypasses all rules) |
| Nikhil | Head | Nikhil's team: T1, T2, T3 |
| Sonam | Head | Sonam's team: ST1, ST2, ST3 |
| Counsellors | Member | Linked via `users.team_head_id` |

## Lead fields that drive access

- `owner_id` — FK to `users`. The person responsible for the lead.
- `referrer_id` — FK to `users`. The "Reference" field — an explicit additional grant.
- `lead_source` — string (length 60). May encode a team via patterns `"{name}"` or `"Sheet:{name}"` where `{name}` is a team member's or head's name.

## Access matrix (the contract)

For a user `u` acting on a lead `L`, with `u`'s team defined as:

- If `u` has role `head` → team = `{u}` ∪ `{users where team_head_id = u.id}`
- If `u` has role `member` → team = `{u.team_head}` ∪ `{users where team_head_id = u.team_head_id}` (i.e. u's head and all members under that head, including u)

| Capability | Admin | Head | Member | Other |
|---|---|---|---|---|
| View   | all | own / referenced / team-lead | own / referenced / team-lead | ❌ |
| Edit   | all | own / referenced / team-lead | own / referenced **only** | ❌ |
| Delete | all | ❌ | ❌ | ❌ |
| Transfer | all | own / referenced / team-lead | ❌ | ❌ |

**"Team-lead"** (belongs to `u`'s team) means ANY of:

- `L.owner_id` ∈ team member ids
- `L.referrer_id` ∈ team member ids
- `L.lead_source` ∈ `{name}` ∪ `{"Sheet:"+name}` for each team member name (including head)

## Gaps closed by this spec

1. **Delete restriction.** Current policy allows heads to delete any team lead. New: admin only.
2. **Edit split.** Current policy's `update()` equals `view()`, so any counsellor can edit a teammate's lead. New: counsellors can only edit own or referenced; heads keep team-wide edit.
3. **Lead-source routing generality.** Current scope only routes a lead via `lead_source` when `owner_id = admin AND referrer_id = admin`. New: any lead whose `lead_source` names a team routes to that team, regardless of owner.

## Code changes

Three files change. No migration, no schema change.

### 1. `app/Models/Student.php` — `scopeVisibleTo`

Drop the admin-owned-AND-admin-referred gate on the `lead_source` branch. The head/member branch's inner `where` becomes:

```php
return $query->where(fn ($q) => $q
    ->whereIn('owner_id', $teamIds)
    ->orWhereIn('referrer_id', $teamIds)
    ->orWhereIn('lead_source', $teamLeadSources));
```

The existing `$teamLeadSources` construction (`[...teamNames, 'Sheet:'+each]`) is kept unchanged.

### 2. `app/Policies/StudentPolicy.php`

- `view()` — mirror the new scope (drop the admin-owned gate on the `lead_source` branch).
- `update()` — new shape:
  ```php
  if ($user->hasRole('admin')) return true;
  if ($student->owner_id === $user->id || $student->referrer_id === $user->id) return true;
  if ($user->hasRole('head')) return $this->view($user, $student);
  return false;
  ```
- `delete()` — admin only:
  ```php
  return $user->hasRole('admin');
  ```
- `transfer()` — unchanged (admin OR head with view).

### 3. `app/Filament/Resources/StudentResource.php`

No code change required. Filament uses policy methods to gate actions; the row-level Delete button will disappear for heads automatically after the policy change.

## Explicitly out of scope

- Widget files under `app/Filament/Widgets/` — they delegate to `visibleTo($user)` and inherit correctly.
- `app/Filament/Pages/KanbanBoard.php` — same.
- Schema changes — no `team_id` column introduced. `lead_source` string matching is retained.
- `LeadIntakeService` — no change. It continues to write `"Sheet:{ownerName}"` for sheet-sourced leads.
- Transfer rules — the existing admin-OR-head-with-view rule is kept.

## Tests

Two test files are updated; no new infrastructure.

### `tests/Feature/NikhilVisibilityTest.php` (existing scope suite)

Add:

- `head_sees_lead_routed_by_lead_source_even_when_non_admin_owned` — create a Nikhil-team-owned lead with `lead_source = 'Sheet:Sonam'`; assert Sonam sees it.
- `member_sees_lead_routed_to_own_team_via_lead_source` — counsellor under Nikhil sees a lead with `lead_source = 'Sheet:Nikhil'` even when owner/referrer are unrelated.

### `tests/Feature/StudentPolicyTest.php`

One assertion per matrix cell that isn't already covered. Target ~15 cases, grouped:

- **view:** admin-sees-all, owner-sees-own, referrer-sees-referred, head-sees-team, counsellor-sees-team, stranger-denied, cross-head-denied.
- **update:** admin-can-update-any, owner-can-update-own, referrer-can-update-referenced, head-can-update-teammate's-lead, **counsellor-CANNOT-update-teammate's-lead** (the new regression gate), stranger-denied.
- **delete:** admin-can-delete, **head-CANNOT-delete** (new regression gate), **counsellor-CANNOT-delete**, stranger-denied.
- **transfer:** admin-can-transfer, head-can-transfer-own-team, counsellor-cannot-transfer.

All existing cases in `NikhilVisibilityTest.php` (added in commit `b18855b`) must keep passing — this spec only widens scope via `lead_source` and tightens edit/delete; it does not remove any existing visibility.

## Rollout

1. Branch `feature/lead-access-rule`, TDD per the test plan above.
2. Run the full Pest suite, including the Nikhil regression suite.
3. Deploy via standard pull-based deploy.
4. Post-deploy `php artisan optimize:clear` (policy cache safety).
5. Prod smoke: Nikhil login → no Delete on a team lead; counsellor login → edit-own OK, edit-teammate's returns Unauthorized.

## Risk

- **Prod behavioural changes on 533 leads:**
  - Nikhil & Sonam lose the Delete button. Worth communicating before deploy.
  - Counsellors lose edit access to teammates' leads. If counsellors have been editing each other's leads as normal practice, they will hit Unauthorized on save; confirm the workflow before deploy.
  - Some leads previously hidden from a team head become visible (lead-source routing generalised). This is the intended fix.
- **Reversibility:** pure code change, no migration. Revert = `git revert` + redeploy. Zero data risk.
- **Risk level:** low.

## Success criteria

- All new policy tests pass.
- The `b18855b` Nikhil regression suite still passes.
- Prod smoke confirms:
  - Sumit: sees and deletes any lead.
  - Nikhil: sees team leads, edits team leads, cannot delete.
  - Counsellor under Nikhil: sees team leads, edits own / referenced only.
  - Sonam cannot see Nikhil's team leads (and vice versa) except where explicitly referenced.
