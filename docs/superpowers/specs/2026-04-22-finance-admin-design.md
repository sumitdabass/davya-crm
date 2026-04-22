# Finance Admin (Expense + Investment CRUD) — Design

**Date:** 2026-04-22
**Status:** Design approved, pending implementation plan
**Author:** Sumit + Claude (brainstorming session)
**Sub-project:** #1 of 3 (the other two — Finance Reports + CSV download, and Dashboard Status Tiles — are deferred to their own spec/plan cycles).

## Problem

Expenses and investments are being captured correctly from Slack (7 expense rows, 2 investment rows as of 2026-04-22), but **there is no admin UI to view, edit, or delete them**. Sumit cannot:
- Browse the full expense ledger in the admin dashboard.
- Correct a miscategorised Slack-extracted expense.
- Delete the duplicate `Tata Motors ₹100,000` investment row (currently present twice).
- Add a manual expense or investment entry without going through Slack (e.g. if Slack is down, or for a correction).

Payments already have two admin surfaces (per-student `PaymentsRelationManager` + the Payment Report) and manual entry works today (see rows #15, #16, #17 created on 2026-04-22). Expenses and Investments do not.

## Scope (sub-project #1)

**In:**
1. New Spatie role `finance`, stackable with existing roles. Any user granted `finance` gets full CRUD on Expenses + Investments. `admin` implicitly includes `finance` (via policy's `hasAnyRole(['admin','finance'])`).
2. `ExpenseResource` (Filament): list + create + edit + delete.
3. `InvestmentResource` (Filament): list + create + edit + delete.
4. `ExpensePolicy` + `InvestmentPolicy`.
5. New sidebar navigation group **Finance** containing Expenses + Investments.
6. Migration making `slack_message_id` nullable on both tables (so manual entries can exist with no Slack ID).
7. Accessor `display_id` on both models returning `"D{id}"` for manual rows (null `slack_message_id`) and `"#{id}"` for Slack-captured rows.

**Out (deferred to other sub-projects):**
- Top-level PaymentResource — not in this round; existing per-student flow is sufficient.
- Reports / CSV download — sub-project #2.
- Dashboard status tiles → pipeline drill-down — sub-project #3.
- Modifying `FinancePaymentController`, `FinanceExpenseController`, `FinanceInvestmentController`, or any n8n workflow. Slack ingestion stays untouched.

## Hard rules from user

1. **Do not touch Slack or n8n in any way.** The admin UI only reads and writes the same `expenses` and `investments` tables the Slack path uses; the Slack path is unchanged.
2. **Manual entries from the dashboard show a "D" prefix** on their display ID (e.g. `D14`) to distinguish them from Slack-captured rows (`#13`).
3. **Sumit (`sumitdabass@gmail.com`) is super-admin** — the `admin` role already grants him access to everything, including these new resources. No extra role assignment needed for him.

## Roles & access matrix

| Capability | Admin (Sumit) | Finance role | Head (Nikhil/Sonam) | Other |
|---|---|---|---|---|
| View Expense list | ✅ | ✅ | ❌ | ❌ |
| View Investment list | ✅ | ✅ | ❌ | ❌ |
| Create Expense / Investment | ✅ | ✅ | ❌ | ❌ |
| Update Expense / Investment | ✅ | ✅ | ❌ | ❌ |
| Delete Expense / Investment | ✅ | ✅ | ❌ | ❌ |

Policies gate on a single predicate: `$user->hasAnyRole(['admin','finance'])`. No per-row checks — finance is company-wide.

## Architecture

**Discriminator.** A row is "manual" iff `slack_message_id IS NULL`. That single column drives both provenance and the display-ID prefix. No extra `is_manual` or source enum.

**Display-ID accessor** (identical on both models):

```php
public function getDisplayIdAttribute(): string
{
    return $this->slack_message_id === null ? "D{$this->id}" : "#{$this->id}";
}
```

Computed at render — no schema change for the label. The "D" series will have gaps (Slack rows bump the auto-increment); that's intentional — D is a flag, not a contiguous sequence.

**Create flow.** The Filament Create form never renders `slack_message_id`. On save, it stays `NULL`, earning the row its "D" label.

**Edit flow.** All data fields are editable (`amount`, `category`/`asset_name`, `description`, `paid_at`/`transacted_at`, `direction`). Provenance fields are NOT editable:
- `slack_message_id` — hidden from the form entirely.
- `raw_input` — shown as a `disabled()` textarea for Slack rows (so you can see what the bot received); hidden for manual rows.
- `created_at` — hidden.

## File structure

### New files

| File | Purpose |
|---|---|
| `app/Filament/Resources/ExpenseResource.php` | Resource config: table columns, form schema, navigation group. |
| `app/Filament/Resources/ExpenseResource/Pages/ListExpenses.php` | Filament list-page scaffolding. |
| `app/Filament/Resources/ExpenseResource/Pages/CreateExpense.php` | Create-page scaffolding. |
| `app/Filament/Resources/ExpenseResource/Pages/EditExpense.php` | Edit-page scaffolding. |
| `app/Filament/Resources/InvestmentResource.php` | Resource config. |
| `app/Filament/Resources/InvestmentResource/Pages/ListInvestments.php` | Scaffolding. |
| `app/Filament/Resources/InvestmentResource/Pages/CreateInvestment.php` | Scaffolding. |
| `app/Filament/Resources/InvestmentResource/Pages/EditInvestment.php` | Scaffolding. |
| `app/Policies/ExpensePolicy.php` | viewAny / view / create / update / delete → `hasAnyRole(['admin','finance'])`. |
| `app/Policies/InvestmentPolicy.php` | Same shape as ExpensePolicy. |
| `database/migrations/2026_04_22_210000_make_finance_slack_id_nullable.php` | `slack_message_id` → nullable on both tables; unique constraint preserved. |
| `database/seeders/FinanceRoleSeeder.php` | `Role::findOrCreate('finance')`. Idempotent. |
| `tests/Feature/FinanceRoleTest.php` | Role-gate contract: who can / cannot access each resource. |
| `tests/Feature/ExpenseResourceTest.php` | CRUD + display_id + unique constraint regression. |
| `tests/Feature/InvestmentResourceTest.php` | Same shape as ExpenseResourceTest. |

### Modified files

| File | Change |
|---|---|
| `app/Models/Expense.php` | Add `getDisplayIdAttribute()` accessor. (Already has `$guarded = []`.) |
| `app/Models/Investment.php` | Add `getDisplayIdAttribute()` accessor. (Already has `$guarded = []`.) |
| `app/Providers/AuthServiceProvider.php` | Register `Expense::class => ExpensePolicy::class` and `Investment::class => InvestmentPolicy::class`. |
| `database/seeders/DatabaseSeeder.php` | Call `FinanceRoleSeeder` after `UsersSeeder`. |

### Sidebar layout

Both resources set `protected static ?string $navigationGroup = 'Finance'`, placing a new "Finance" group in the sidebar (ordering handled by Filament's group sort — same behaviour as existing "Reports"). Inside the group: **Expenses**, **Investments**.

### Table columns (list page)

Both resources render a similar table:

| Column | Expense | Investment |
|---|---|---|
| Display ID | `display_id` | `display_id` |
| Source badge | computed: Slack (blue) / Manual (green) | same |
| Amount (₹) | `amount` formatted | `amount` formatted |
| Specific field | `category` | `asset_name` + `direction` |
| Date | `paid_at` | `transacted_at` |
| Actions | Edit, Delete | Edit, Delete |

Filters: by source (Slack / Manual / All) and by date range.

## Tests

All tests use PHPUnit (matching existing convention), `RefreshDatabase`, and the existing `UsersSeeder` + new `FinanceRoleSeeder` fixtures.

### `tests/Feature/FinanceRoleTest.php`
- `admin_can_access_expense_resource`
- `admin_can_access_investment_resource`
- `finance_role_can_access_both_resources` (create ad-hoc user, grant `finance`, hit the routes)
- `head_cannot_access_expense_resource`
- `head_cannot_access_investment_resource`
- `member_cannot_access_expense_resource`

### `tests/Feature/ExpenseResourceTest.php`
- `manual_create_leaves_slack_message_id_null_and_shows_D_prefix`
- `slack_captured_row_displays_hash_prefix`
- `admin_can_update_amount_and_description`
- `admin_can_delete_expense`
- `raw_input_is_read_only_on_edit_form_for_slack_rows` (Livewire form assertion on `disabled`)
- `cannot_create_two_slack_rows_with_same_message_id` (unique-constraint regression)

### `tests/Feature/InvestmentResourceTest.php`
Mirror of `ExpenseResourceTest` (same six cases), using `asset_name` + `direction` in place of `category` + `description`.

Target: ~15 focused test cases total.

### Regression
Existing `FinancePaymentController` / expense / investment ingestion tests (if any) must still pass — the nullable-relaxation migration must not break the Slack insert path.

## Rollout

1. Feature branch `feature/finance-admin` (already created on 2026-04-22).
2. TDD per the test plan.
3. Run migration locally; verify `DESCRIBE expenses;` / `DESCRIBE investments;` show `slack_message_id` as `NULL YES`.
4. Full `vendor/bin/phpunit` green.
5. Deploy: pull main on prod, then:
   - `/opt/alt/php84/usr/bin/php artisan migrate --force`
   - `/opt/alt/php84/usr/bin/php artisan db:seed --class=FinanceRoleSeeder --force`
   - `/opt/alt/php84/usr/bin/php artisan optimize:clear`
6. Post-deploy smoke (as Sumit):
   - Sidebar shows new "Finance" group.
   - `/admin/expenses` lists all 7 expenses; oldest 2 show `#id`, new manual entries show `D…`.
   - Create a test expense → confirm `D{n}` label.
   - Edit that expense → amount/category changes save.
   - Delete that test row.
   - Delete duplicate Investment row (Tata Motors ₹100k × 2 → leave only one).

## Risk

- **Only schema change** is `slack_message_id` nullable on two tables. Existing 9 rows unaffected. Unique-index semantics unchanged (MySQL ignores NULLs).
- **Rollback hazard:** if any manual (NULL) rows exist at the time of rollback, the migration `down()` (restoring NOT NULL) will fail. The migration file will include a comment noting this — rollback requires backfilling a sentinel first.
- **Slack flow untouched.** No risk to the existing `FinancePaymentController` / n8n ingestion path.
- **Visibility:** no changes for Nikhil, Sonam, or counsellors. Only Sumit (admin) sees the new nav group on day one.

**Risk level:** Low–medium.

## Success criteria

- All new tests pass.
- Sumit sees `/admin/expenses` + `/admin/investments` and can create/edit/delete.
- A manually-created expense shows as `D{id}`; Slack-captured ones show as `#{id}`.
- Nikhil and Sonam get 403 on `/admin/expenses` and `/admin/investments`.
- Slack expense/investment/payment ingestion still works post-deploy (verified via a fresh Slack post).
- The 2 duplicate investment rows can be deleted from admin.
