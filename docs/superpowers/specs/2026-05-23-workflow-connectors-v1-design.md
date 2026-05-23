# Workflow Connectors v1 — Phase 2 Bundle A

**Date:** 2026-05-23
**Owner:** Sumit Dabas (super_admin)
**Status:** Draft — pending Sumit review

## Goal

Close 4 high-friction navigation gaps that today force counsellors to copy-paste, re-type, or click their way across pages to complete a single thought. Each connector removes a specific manual step:

1. **Rank → Student** — a rank lookup result can be saved as a student's Choice 1 / 2 / 3 without re-typing the college / branch.
2. **Reports → Meeting** — a row in LeadsReport or PaymentReport can spawn a meeting modal in place; no detour through the Student edit page.
3. **RoundHistory → Payment** — when a row is marked `Allotted — Fee Pending`, the fee can be captured inline; today this requires opening a separate Payments tab.
4. **LeadsReport KPI → table** — the `Owners with activity (N)` and `Referrers with activity (N)` counters scroll-anchor to the matching breakdown table below.

## Non-Goals

- Not a redesign of RankLookup itself — only adds an entry point + per-row save buttons.
- Not a meeting-form refactor — only extracts the existing MeetingsRelationManager form into a shared schema (mirror of `PaymentFormSchema`).
- Not a RoundHistory schema change — `seat_fee_paid` toggle stays exactly as it is.
- Not introducing new tables / migrations — every change is application-layer.
- Not reviving the structured-preference dropdowns that Sumit reverted on 2026-05-02. The student form keeps its freetext input; structured columns are written silently alongside for later analytics.
- Not changing PaymentReport — F8 is on LeadsReport only (the audit mis-attributed it).

## Users

- Heads + admin + super_admin: all four connectors visible.
- Members + freelancers: F1 (rank → student) hidden because they don't have `rank-admin` / `admin` role; F2 / F3 / F8 visible per existing report / RoundHistory gates.

## Affected Surfaces

| # | File | Change |
|---|---|---|
| F1.a | `app/Filament/Resources/StudentResource/Pages/EditStudent.php` | New header action `Run rank lookup` → redirects to `/admin/rank-lookup?student_id={id}` |
| F1.b | `app/Filament/Pages/Rank/RankLookup.php` | Read `?student_id=` on mount → load Student → expose `$contextStudent` to view |
| F1.c | `resources/views/filament/pages/rank/rank-lookup.blade.php` | Sticky banner when `$contextStudent` is set; per-row "Save as Choice 1 / 2 / 3" buttons |
| F1.d | `app/Filament/Pages/Rank/RankLookup.php` | New `saveChoice(int $instituteId, int $branchId, int $shift = null, int $slot)` action |
| F2.a | `app/Filament/Resources/Shared/MeetingFormSchema.php` | **New file.** Extract field list from `MeetingsRelationManager` |
| F2.b | `app/Filament/Resources/StudentResource/RelationManagers/MeetingsRelationManager.php` | Refactor `form()` to delegate to the new schema |
| F2.c | `app/Filament/Pages/PaymentReport.php` + blade | Row action `scheduleMeeting` on `today` tab + `detail` tab (rows pre-bind `student_id`, single-step modal). On `report` tab byOwner table (aggregate, 2-step picker). |
| F2.d | `app/Filament/Pages/LeadsReport.php` + blade | Row action `scheduleMeeting` on byOwner + byReferrer tables (aggregate, 2-step picker). |
| F3.a | `app/Filament/Resources/StudentResource/RelationManagers/RoundHistoryRelationManager.php` | Row action `recordFeePayment` visible when `seat_fee_paid=false` |
| F3.b | `app/Services/RoundHistory/RecordFeePaymentAction.php` | **New file.** Pure service that wraps the Payment insert + RoundHistory flip in a DB transaction |
| F8.a | `resources/views/filament/pages/leads-report.blade.php` | Wrap KPI tiles in `<a href="#by-owner">` + `<a href="#by-referrer">`; add IDs to the existing tables; smooth-scroll CSS + brief highlight pulse |

## Architecture

### F1 — Rank → Student write-back

**Trigger:** A new header action on `StudentResource\Pages\EditStudent` titled "Run rank lookup". Visible whenever the viewer has `rank-admin` or `admin`. Redirects to `/admin/rank-lookup?student_id={student_id}`.

**Context detection:** `RankLookup::mount()` reads `request()->query('student_id')`. If present and the viewer has student-read permission, it loads the Student and stores a `?Student $contextStudent` public property. If absent, the page behaves exactly as today.

**UI banner:** When `$contextStudent` is set, the blade renders a sticky top banner:

> Picking preferences for: **Aman Sharma** · JEE rank **52 800**
> [← back to student edit]

The banner stays visible even when results scroll.

**Save buttons:** Each result row renders three small buttons inline (next to the seat count / cushion %): "1ˢᵗ", "2ⁿᵈ", "3ʳᵈ". Each button calls `wire:click="saveChoice('{institute_id}', '{branch_id}', '{shift}', {slot})"` where slot ∈ {1, 2, 3}. Buttons hidden entirely when `$contextStudent` is null.

**Save action:**

```
saveChoice(int $instituteId, int $branchId, ?string $shift, int $slot)
  1. Reject if no $contextStudent or viewer lacks update permission.
  2. Hydrate Institute + Branch + Shift label.
  3. Compose freetext: "{institute_name} — {branch_name}" (em-dash, optionally
     " ({shift})" when shift is non-null).
  4. Check current value of $contextStudent->{"preference_r{$slot}"}.
     If non-empty AND different from the new value, dispatch a Filament
     confirmation modal: "Choice $slot is already set to '<old>'. Overwrite?"
  5. On confirmation (or no conflict):
       $contextStudent->update([
         "preference_r{$slot}"          => $freetext,
         "preference_r{$slot}_college"  => $institute_name,
         "preference_r{$slot}_branch"   => $branch_name,
       ]);
  6. Emit a Filament toast: "Saved as Choice $slot for {student name}".
  7. Patch in-memory state so the button on that row immediately shows
     "Saved as Choice $slot ✓" (greyed out for that slot only).
```

**Audit:** Existing `StudentObserver` writes a Timeline row on update; no new audit code needed.

**No structured-form revival:** The freetext `preference_r1/r2/r3` columns remain the source of truth for the student form (Sumit reverted the dropdowns 2026-05-02). The `preference_r{n}_college` / `_branch` columns are written silently for later analytics — e.g., a Phase 3 widget that compares predicted vs allotted college, which needs structured data to join on.

### F2 — Schedule meeting from reports

**Step 1 — Shared schema.** Create `App\Filament\Resources\Shared\MeetingFormSchema` mirroring the existing `PaymentFormSchema` pattern:

```php
final class MeetingFormSchema
{
    /** @return array<\Filament\Forms\Components\Component> */
    public static function fields(): array
    {
        return [
            Select::make('owner_id')->label('Owner')
                ->options(fn () => User::orderBy('name')->pluck('name', 'id')->all())
                ->default(fn () => auth()->id())->required(),
            DateTimePicker::make('scheduled_at')->label('Scheduled at')
                ->required()->native(false)->default(fn () => now()->addDay()),
            Select::make('mode')->options(self::MODES)
                ->default('in_person')->required(),
            Textarea::make('notes')->label('Pre-meeting notes')->rows(2),
            // outcome_notes intentionally omitted — only visible in edit flow
        ];
    }
}
```

`MeetingsRelationManager::form()` becomes:

```php
->schema(MeetingFormSchema::fields() + [
    Textarea::make('outcome_notes')->visible(fn ($record) => $record?->status === 'held')->rows(2),
])
```

**Step 2 — Row actions on reports.** PaymentReport + LeadsReport detail/by-owner tables gain:

```php
Action::make('scheduleMeeting')
    ->label('Schedule meeting')
    ->icon('heroicon-o-calendar')
    ->form(MeetingFormSchema::fields())
    ->action(function (array $data, $record) {
        $studentId = $record['student_id'] ?? $record->student_id;
        Meeting::create($data + ['student_id' => $studentId, 'status' => 'scheduled']);
    })
    ->successNotificationTitle('Meeting scheduled')
```

**Per-tab pre-binding:**

- **PaymentReport `today` + `detail` tabs** — each row already keyed by `student_id` (visible in `payment-report.blade.php:167` + `:213`). Single-step modal: `MeetingFormSchema::fields()` with `student_id` pre-bound from the row.
- **PaymentReport `report` tab byOwner** + **LeadsReport byOwner + byReferrer** — aggregate rows that don't pre-bind a single student. 2-step modal: step 1 is a student picker (autocomplete via existing StudentSearch component, scoped to the row's `owner_id` / `referrer_id`), step 2 is `MeetingFormSchema::fields()`.

**Refresh:** After save, Filament's row action auto-refreshes the table. No manual `$this->dispatch()` needed.

### F3 — RoundHistory inline payment

**Row action:**

```php
Action::make('recordFeePayment')
    ->label('Record fee payment')
    ->icon('heroicon-o-banknotes')
    ->color('success')
    ->visible(fn ($record) => ! $record->seat_fee_paid && $record->outcome === 'Allotted — Fee Pending')
    ->form(PaymentFormSchema::fields())
    ->fillForm(fn ($record) => [
        'amount'      => $record->seat_fee_amount,
        'type'        => 'advance',
        'received_at' => now(),
    ])
    ->action(function (array $data, $record) {
        app(RecordFeePaymentAction::class)->run($record, $data);
    })
```

**Service:** `App\Services\RoundHistory\RecordFeePaymentAction::run(RoundHistory $rh, array $payment)` wraps:

```
DB::transaction(function () use ($rh, $payment) {
    Payment::create($payment + [
        'student_id' => $rh->student_id,
        // round_history_id linkage if/when the column lands;
        // for v1 we don't add a FK — Timeline shows both rows.
    ]);
    $rh->update([
        'seat_fee_paid' => true,
        'fee_paid_at'   => $payment['received_at'],
    ]);
});
```

Both operations succeed atomically, or neither does. PaymentObserver still fires (Timeline row, owner stats, etc.).

**Why no `round_history_id` FK?** Adding a column means a migration and a Payment form change. v1 keeps Payments untouched; Timeline already shows both events side by side. Phase 2.5 can add the FK if we want a direct join.

### F8 — LeadsReport KPI scroll-anchor

Two KPI tiles (`Owners with activity (N)` and `Referrers with activity (N)`) get wrapped in anchors. The existing `byOwner` and `byReferrer` tables receive `id="leads-by-owner"` / `id="leads-by-referrer"`. Smooth-scroll CSS (`scroll-behavior: smooth;` on `html` is already set; no change needed) carries the click. A short CSS animation pulses the target table border for 1.2s on `:target` so the user knows where they landed:

```css
[id="leads-by-owner"]:target,
[id="leads-by-referrer"]:target {
    animation: davya-target-pulse 1.2s ease-out 1;
}
@keyframes davya-target-pulse {
    0%   { box-shadow: 0 0 0 2px var(--brand-500); }
    100% { box-shadow: 0 0 0 0 transparent; }
}
```

Token `--brand-500` already exists in `tokens.css`.

## Data Flow

```
F1 ─ Student edit → Header action → /admin/rank-lookup?student_id=X
     → RankLookup mount loads contextStudent
     → user filters & runs lookup
     → user clicks "2nd" on a result row
     → saveChoice(institute, branch, shift, 2)
     → confirmation modal if preference_r2 already set
     → Student update writes 3 columns
     → toast + button state update

F2 ─ PaymentReport today / detail row → "Schedule meeting" action
     → Modal with MeetingFormSchema fields + pre-bound student_id (1-step)
     → Meeting::create
     → toast + table refresh

F2 ─ PaymentReport byOwner / LeadsReport byOwner / byReferrer row → action
     → Modal step 1: pick student (autocomplete scoped to owner_id / referrer_id)
     → Modal step 2: MeetingFormSchema fields
     → Meeting::create
     → toast + table refresh

F3 ─ RoundHistory row (fee pending) → "Record fee payment" action
     → Modal with PaymentFormSchema fields + pre-fill
     → RecordFeePaymentAction::run inside DB transaction
     → Payment::create + RoundHistory.seat_fee_paid=true
     → toast + RoundHistory table refresh (paid icon flips to ✓)

F8 ─ LeadsReport KPI tile click → URL #fragment → scroll-anchor → CSS :target pulse
```

## Error Handling

| Path | Failure | Handling |
|---|---|---|
| F1 | viewer lacks rank-admin/admin | StudentResource header action hidden; direct URL access still allowed but RankLookup blocks via `RestrictsToRankRoles` trait |
| F1 | `student_id` query param refers to a deleted/unauth student | Banner not rendered, save buttons not shown — equivalent to cold launch |
| F1 | `preference_r{n}` write fails (constraint, observer rollback) | Filament toast `danger`: "Couldn't save Choice {n}: {message}". Buttons unchanged. |
| F2 | Meeting creation throws (validation, DB) | Standard Filament form-error display in modal; nothing persisted |
| F2 LeadsReport step 1 | Student picker empty (owner has 0 students) | Disable step 2; show "{owner} has no leads currently" |
| F3 | DB transaction rollback (payment insert fails OR roundHistory update fails) | Both reverted; toast `danger`: "Couldn't record payment: {message}" |
| F3 | RoundHistory was updated by another tab between fetch + save | Last-write-wins (no optimistic locking in current schema). Toast still shows success. Acceptable for v1. |
| F8 | Anchor target missing (table not rendered because filter returned 0 rows) | Browser scrolls to top; pulse doesn't fire. No JS error. |

## Testing

| Test class | Scope |
|---|---|
| `tests/Feature/Rank/RankLookupStudentContextTest.php` | (i) cold launch unchanged, (ii) `?student_id=` loads banner, (iii) buttons hidden when no context, (iv) `saveChoice` writes all 3 columns, (v) overwrite-confirmation flow, (vi) viewer without rank-admin role gets 403, (vii) `student_id` of a deleted student treated as cold |
| `tests/Feature/Reports/PaymentReportScheduleMeetingTest.php` | (i) `detail` + `today` tab action pre-binds student_id, (ii) `report` byOwner action opens picker scoped to owner, (iii) submit creates Meeting, (iv) toast |
| `tests/Feature/Reports/LeadsReportScheduleMeetingTest.php` | (i) byOwner action opens picker scoped to owner_id, (ii) byReferrer action opens picker scoped to referrer_id, (iii) picker empty when 0 leads, (iv) full flow creates Meeting |
| `tests/Feature/Resources/RoundHistory/RecordFeePaymentTest.php` | (i) action visible only when `seat_fee_paid=false AND outcome='Allotted — Fee Pending'`, (ii) transaction atomicity (force Payment insert to fail → RoundHistory not flipped), (iii) successful path flips both correctly, (iv) Timeline row written |
| `tests/Feature/Reports/LeadsReportKpiAnchorsTest.php` | (i) KPI tile renders as `<a href="#leads-by-owner">`, (ii) corresponding table has `id`, (iii) hidden when count == 0 |
| `tests/Unit/MeetingFormSchemaTest.php` | (i) returns 4 field components, (ii) defaults match existing RelationManager defaults |

Target: **+18 to +22 tests**. All green before merge.

## Out of Scope (v1)

- Bulk save (e.g., "save all 3 visible matches as Choices 1, 2, 3" with one click).
- Reverse direction (from a RoundHistory row, pre-fill RankLookup to verify the prediction was right).
- Editing a saved Choice from within RankLookup — must edit on the student record directly.
- Adding `round_history_id` FK to Payments (Phase 2.5 — see F3 architecture note).
- "Schedule meeting" row action on StudentResource list (out of bundle scope; could be a single-line follow-up).
- Bulk-action variants of any of these (single-row only in v1).
- Phone-first overlay of these modals (Phase 3, when phone-first dashboard lands).
