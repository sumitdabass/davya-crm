# Student Payouts & Expected Profit — Design

**Date:** 2026-06-08
**Status:** Approved (brainstorming) — pending spec review
**Author:** Sumit + Claude

## Problem

`students.deal_amount` captures gross revenue (what the student pays the consultancy), and
the `payments` sub-table tracks money **received in**. There is currently no record of money
the consultancy pays **out** for a student — e.g. a fee paid to the college, or a cut paid to
another party. Without it, Sumit cannot see real margin per deal.

Sumit wants: **Expected Profit = Deal Amount − (money paid/owed to college + other)**, with a
distinction between amounts already *paid* and amounts *still to be paid* ("to be paid to
college"). He also wants the `plan` dropdown options updated, and profit visible in the
students list and the payment report.

## Decisions (from brainstorming)

1. **Cost model:** payout **line-items** in a new sub-table (not flat columns) — mirrors `payments`.
2. **Profit basis:** **Expected profit** = `deal_amount − sum(all committed payouts)`, independent
   of how much the student has paid in so far. This is the headline number.
3. **Plan options:** replace `Online / Offline / All` with **`Sitting` / `Counselling Online` /
   `Counselling Offline`**. Existing records with old values still display but old values are no
   longer selectable.
4. **Profit visibility:** student form (live), **students-list column** (sortable), **Payment
   Report rollup**.
5. **Entry UX:** inline **repeater** in the Deal tab, identical on create and edit.
6. **Payee set:** `College` / `Other` only (no `Agent` for now — additive later if needed).

## Data model — new `payouts` table

New migration `create_payouts_table`:

| column | type | notes |
|---|---|---|
| `id` | bigint pk | |
| `student_id` | FK → students | `constrained()->cascadeOnDelete()` |
| `payee_type` | enum `college`,`other` | |
| `payee_name` | string(120) nullable | free text, e.g. "GGSIPU" / "Mr. Sharma" |
| `amount` | decimal(12,2) | always positive |
| `status` | enum `to_pay`,`paid` | `to_pay` = "to be paid to college"; `paid` = "paid to college" |
| `paid_at` | dateTime nullable | set when status becomes `paid` |
| `notes` | text nullable | |
| `recorded_by_user_id` | FK → users | `constrained('users')` |
| `timestamps` | | |
| index | `(student_id, status)` | |

**`Payout` model** (`app/Models/Payout.php`): `protected $guarded = []`; casts `amount` →
`decimal:2`, `paid_at` → `datetime`; relations `student(): BelongsTo`,
`recordedBy(): BelongsTo(User, 'recorded_by_user_id')`. `booted()` hook: force `amount = abs(amount)`
on saving (payouts are never negative — refunds are out of scope here).

**`Student` relation:** `payouts(): HasMany { return $this->hasMany(Payout::class); }`

## Profit accessors on `Student`

Sits alongside the existing `getTotalReceivedAttribute` / `getPendingAmountAttribute`:

```php
public function getTotalPayoutsAttribute(): float      // all committed (to_pay + paid)
{ return (float) $this->payouts()->sum('amount'); }

public function getPayoutsPaidAttribute(): float
{ return (float) $this->payouts()->where('status', 'paid')->sum('amount'); }

public function getPayoutsOutstandingAttribute(): float // still owe college/other
{ return $this->total_payouts - $this->payouts_paid; }

public function getExpectedProfitAttribute(): float     // headline
{ return (float) ($this->deal_amount ?? 0) - $this->total_payouts; }
```

`expected_profit` can be negative (over-committed) — display in danger color when `< 0`.

## Form — "Payouts" repeater in the Deal tab

In `StudentResource` Deal section, directly under Deal Amount, add a `Repeater::make('payouts')`
bound to the `payouts` relationship (`->relationship()`), with schema per row:

- `Select payee_type` → `['college' => 'College', 'other' => 'Other']`, required, default `college`.
- `TextInput payee_name` → nullable text (placeholder "College / party name").
- `TextInput amount` → numeric, required, ₹ prefix.
- `Select status` → `['to_pay' => 'To be paid', 'paid' => 'Paid']`, required, default `to_pay`.
- `DateTimePicker paid_at` → visible only when `status === 'paid'`, defaults to now on paid.
- `recorded_by_user_id` set to `auth()->id()` on create (mutateRelationshipDataBeforeCreateUsing /
  default), not a visible field.

Below the repeater, a live read-only **Expected Profit** display:
`Deal ₹{deal_amount} − Payouts ₹{sum} = ₹{profit}`, rendered with the existing
`MoneyFormat` Indian-words helper (reuse `<x-book-amount>` / `MoneyFormat::asInlineHtml()` style
already used for deal/received/pending). It reads the live repeater state (Filament `$get`) so it
updates as rows are added/edited without a save.

Works identically on create and edit because it is relationship-bound.

## Plan dropdown update

In `database/migrations/2026_04_24_010400_seed_live_form_sections_and_fields.php` (and any live
StudentField row), change the `plan` field options from `['Online','Offline','All']` to
`['Sitting','Counselling Online','Counselling Offline']`. Provide an idempotent data migration
`update_plan_field_options` that updates the StudentField options JSON in place so prod picks it up
on deploy. Old `plan` values on existing students are left untouched (display-only).

## Reporting

### Students list column (`StudentResource` table)
Add a sortable **Expected Profit** `TextColumn`:
- `formatStateUsing` → `MoneyFormat::asInlineHtml()` (Indian words), danger when negative.
- Sortable at the DB level via a `withExpectedProfit()` query scope on `Student` that selects
  `deal_amount - COALESCE((select sum(amount) from payouts where payouts.student_id = students.id), 0)`
  as `expected_profit_sort`, and `->sortable(query: ...)` ordering on that subquery — so sorting
  doesn't fall back to the PHP accessor. Toggleable column (`->toggleable()`), aligned right,
  obeys the existing mobile column-shedding rules (keep visible ≥ 640px, OK to shed on phone).

### Payment Report rollup (`PaymentReport`)
Add to the report view (the "report" tab aggregates):
- **Total Deal** = sum of `deal_amount` over the filtered student set.
- **Total Paid Out** = sum of payouts with `status='paid'`.
- **Total Committed Payouts** = sum of all payouts.
- **Total Expected Profit** = Total Deal − Total Committed Payouts (headline KPI tile).
- **Outstanding Payouts** = Total Committed − Total Paid Out.
- By-owner breakdown row gains an Expected-Profit cell, consistent with existing by-owner columns.

These respect the report's existing owner/date filters (filter the underlying student/payout
queries by the same owner + date window the report already uses).

## Testing

- **Migration/model:** payouts table exists; `Payout` casts + abs-on-save; cascade delete with student.
- **Accessors:** `total_payouts`, `payouts_paid`, `payouts_outstanding`, `expected_profit`
  (including negative-profit and no-payouts cases).
- **Form:** repeater persists payout rows on create + edit; `recorded_by_user_id` stamped; `paid_at`
  set when status paid.
- **Plan options:** data migration produces the 3 new options; old values still render.
- **List column:** `withExpectedProfit()` scope orders correctly; column renders formatted value.
- **Payment Report:** rollup totals compute correctly under owner/date filters; profit = deal −
  committed payouts.

Target: all new tests green, existing 884-test suite stays green.

## Out of scope (YAGNI)

- `Agent` payee type (additive later).
- Payout proof-document upload (payments have `proof_drive_url`; payouts can add later if needed).
- Cash-in-hand profit view (received − paid) — expected profit only for now.
- Books-module integration / posting payouts as Book entries.
- Refund/negative payouts.

## Deploy notes

Standard davya-crm recipe (SSH → git pull → composer → migrate → seeders → 3 caches). One new
migration (`create_payouts_table`) + one data migration (`update_plan_field_options`). New model
class `Payout` — no new Filament page/resource class (repeater + relationship only), so FPM
opcache new-class discovery is **not** a concern; route/view cache rebuild suffices.
