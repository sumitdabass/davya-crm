# Workflow Connectors v1 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Close 4 high-friction navigation gaps in davya-crm so a counsellor can (F1) save Rank-Lookup picks back to a student, (F2) schedule a meeting from any report row, (F3) record a seat-fee payment inline on RoundHistory, and (F8) jump from a LeadsReport KPI to its breakdown table.

**Architecture:** Application-layer only — no migrations. Extract `MeetingFormSchema` to mirror the existing `PaymentFormSchema` pattern (single source of truth across RelationManager + new row actions). Add a `RecordFeePaymentAction` service that wraps the F3 Payment-create + RoundHistory-flip in a DB transaction. F1 reads `?student_id=` query param on RankLookup and exposes per-row "Save as Choice N" buttons that write both freetext `preference_r{n}` and structured `preference_r{n}_college/_branch` columns.

**Tech Stack:** Laravel 11, Filament 3, Livewire 3, Pest, MySQL prod / SQLite `:memory:` test.

**Spec:** `docs/superpowers/specs/2026-05-23-workflow-connectors-v1-design.md`

---

## File Structure

**New files (4):**
- `app/Filament/Resources/Shared/MeetingFormSchema.php` — shared meeting field list (mirrors PaymentFormSchema)
- `app/Services/RoundHistory/RecordFeePaymentAction.php` — atomic service for F3
- `tests/Unit/MeetingFormSchemaTest.php`
- `tests/Feature/Rank/RankLookupStudentContextTest.php`
- `tests/Feature/Reports/PaymentReportScheduleMeetingTest.php`
- `tests/Feature/Reports/LeadsReportScheduleMeetingTest.php`
- `tests/Feature/Resources/RoundHistory/RecordFeePaymentTest.php`
- `tests/Feature/Reports/LeadsReportKpiAnchorsTest.php`

**Modified files (8):**
- `app/Filament/Pages/Rank/RankLookup.php` — context + saveChoice
- `resources/views/filament/pages/rank/rank-lookup.blade.php` — banner + save buttons
- `app/Filament/Resources/StudentResource/Pages/EditStudent.php` — header action "Run rank lookup"
- `app/Filament/Resources/StudentResource/RelationManagers/MeetingsRelationManager.php` — use MeetingFormSchema
- `app/Filament/Pages/PaymentReport.php` + `resources/views/filament/pages/payment-report.blade.php` — row action `scheduleMeeting`
- `app/Filament/Pages/LeadsReport.php` + `resources/views/filament/pages/leads-report.blade.php` — row action + KPI anchors
- `app/Filament/Resources/StudentResource/RelationManagers/RoundHistoryRelationManager.php` — row action `recordFeePayment`
- `public/css/tokens.css` AND `resources/css/tokens.css` — `:target` pulse animation (BOTH per the tokens.css drift gotcha)

**Sequencing rationale:** Build inside-out — refactors and small isolated changes first (MeetingFormSchema extract, F8 anchors), then F3 (independent of F2/F1), then F2 (depends on MeetingFormSchema), then F1 (biggest, most coupled). Each sub-bundle commits independently so partial progress is always reviewable.

---

## Pre-flight

- [ ] **Step 0.1: Verify branch and clean tree**

```bash
git status
git branch --show-current
```

Expected: branch is `main` (or a feature branch), working tree clean (HEAD at `2893691` or later).

- [ ] **Step 0.2: Verify test suite is green before any changes**

```bash
php artisan test --parallel 2>&1 | tail -20
```

Expected: `Tests: 817 passed, 1 skipped` (the baseline from polish-batch on 2026-05-22). If failing on un-touched tests, **STOP** — investigate before plan execution.

- [ ] **Step 0.3: Verify spec exists and is committed**

```bash
git log --oneline docs/superpowers/specs/2026-05-23-workflow-connectors-v1-design.md
```

Expected: at least one commit (the spec at `26d2e06`).

---

## Sub-Bundle 1 — MeetingFormSchema extraction (refactor, F2 prep)

### Task 1: MeetingFormSchema unit test

**Files:**
- Create: `tests/Unit/MeetingFormSchemaTest.php`

- [ ] **Step 1.1: Write the failing test**

```php
<?php

use App\Filament\Resources\Shared\MeetingFormSchema;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;

it('returns four field components in canonical order', function () {
    $fields = MeetingFormSchema::fields();
    expect($fields)->toHaveCount(4);
    expect($fields[0])->toBeInstanceOf(Select::class);
    expect($fields[0]->getName())->toBe('owner_id');
    expect($fields[1])->toBeInstanceOf(DateTimePicker::class);
    expect($fields[1]->getName())->toBe('scheduled_at');
    expect($fields[2])->toBeInstanceOf(Select::class);
    expect($fields[2]->getName())->toBe('mode');
    expect($fields[3])->toBeInstanceOf(Textarea::class);
    expect($fields[3]->getName())->toBe('notes');
});

it('defaults owner_id to authenticated user id and scheduled_at to next day', function () {
    $user = \App\Models\User::factory()->create();
    $this->actingAs($user);

    $fields = MeetingFormSchema::fields();
    $ownerField = $fields[0];
    $scheduledField = $fields[1];

    expect(($ownerField->getDefaultState())())->toBe($user->id);
    expect(($scheduledField->getDefaultState())()->format('Y-m-d'))
        ->toBe(now()->addDay()->format('Y-m-d'));
});

it('exposes the supported mode options', function () {
    $fields = MeetingFormSchema::fields();
    $modeField = $fields[2];

    expect($modeField->getOptions())->toBe([
        'in_person' => 'In person',
        'phone'     => 'Phone',
        'video'     => 'Video',
        'whatsapp'  => 'WhatsApp',
    ]);
});
```

- [ ] **Step 1.2: Run — confirm it fails**

```bash
php artisan test --filter MeetingFormSchemaTest
```

Expected: 3 failures with `Class App\Filament\Resources\Shared\MeetingFormSchema not found`.

### Task 2: Create MeetingFormSchema

**Files:**
- Create: `app/Filament/Resources/Shared/MeetingFormSchema.php`

- [ ] **Step 2.1: Write the class**

```php
<?php

namespace App\Filament\Resources\Shared;

use App\Models\User;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;

final class MeetingFormSchema
{
    public const MODES = [
        'in_person' => 'In person',
        'phone'     => 'Phone',
        'video'     => 'Video',
        'whatsapp'  => 'WhatsApp',
    ];

    /**
     * Canonical field list for any surface that creates / edits a Meeting.
     * Edit-only fields (e.g. outcome_notes) are not included here; the
     * MeetingsRelationManager appends them when needed.
     *
     * @return array<\Filament\Forms\Components\Component>
     */
    public static function fields(): array
    {
        return [
            Select::make('owner_id')
                ->label('Owner')
                ->options(fn () => User::orderBy('name')->pluck('name', 'id')->all())
                ->default(fn () => auth()->id())
                ->required(),

            DateTimePicker::make('scheduled_at')
                ->label('Scheduled at')
                ->required()
                ->native(false)
                ->default(fn () => now()->addDay()),

            Select::make('mode')
                ->options(self::MODES)
                ->default('in_person')
                ->required(),

            Textarea::make('notes')
                ->label('Pre-meeting notes')
                ->rows(2),
        ];
    }
}
```

- [ ] **Step 2.2: Run — confirm it passes**

```bash
php artisan test --filter MeetingFormSchemaTest
```

Expected: 3 passed.

### Task 3: Refactor MeetingsRelationManager to use the schema

**Files:**
- Modify: `app/Filament/Resources/StudentResource/RelationManagers/MeetingsRelationManager.php:22-52`

- [ ] **Step 3.1: Replace inline form definition**

Replace the `form(Form $form): Form` method body (lines 22–52) with:

```php
public function form(Form $form): Form
{
    return $form->schema(array_merge(
        \App\Filament\Resources\Shared\MeetingFormSchema::fields(),
        [
            Forms\Components\Textarea::make('outcome_notes')
                ->label('Outcome notes (after meeting held)')
                ->rows(2)
                ->visible(fn ($record) => $record?->status === 'held'),
        ],
    ));
}
```

Remove the now-unused `use App\Models\User;` import at the top if no other references remain.

- [ ] **Step 3.2: Run existing meeting tests — confirm no regression**

```bash
php artisan test --filter Meeting
```

Expected: all pre-existing Meeting tests pass (the relation manager's form fields are unchanged in shape).

- [ ] **Step 3.3: Commit Sub-Bundle 1**

```bash
git add app/Filament/Resources/Shared/MeetingFormSchema.php \
        app/Filament/Resources/StudentResource/RelationManagers/MeetingsRelationManager.php \
        tests/Unit/MeetingFormSchemaTest.php
git commit -m "$(cat <<'EOF'
feat(meetings): extract MeetingFormSchema for cross-surface reuse

Mirrors PaymentFormSchema pattern. MeetingsRelationManager keeps its
outcome_notes (edit-only) bolt-on. Enables F2 row actions on
PaymentReport + LeadsReport to share field defs.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Sub-Bundle 2 — F8 LeadsReport KPI scroll-anchors

### Task 4: LeadsReport KPI anchor test

**Files:**
- Create: `tests/Feature/Reports/LeadsReportKpiAnchorsTest.php`

- [ ] **Step 4.1: Write the failing test**

```php
<?php

use App\Models\Student;
use App\Models\User;

beforeEach(function () {
    $admin = User::factory()->create();
    $admin->assignRole('admin');
    $this->actingAs($admin);
});

it('renders Owners with activity tile as anchor link to #leads-by-owner', function () {
    Student::factory()->count(2)->create(['owner_id' => User::factory()->create()->id]);

    $response = $this->get('/admin/leads-report');
    $response->assertSee('href="#leads-by-owner"', false);
    $response->assertSee('id="leads-by-owner"', false);
});

it('renders Referrers with activity tile as anchor link to #leads-by-referrer', function () {
    Student::factory()->count(2)->create(['referrer_id' => User::factory()->create()->id]);

    $response = $this->get('/admin/leads-report');
    $response->assertSee('href="#leads-by-referrer"', false);
    $response->assertSee('id="leads-by-referrer"', false);
});
```

- [ ] **Step 4.2: Run — confirm it fails**

```bash
php artisan test --filter LeadsReportKpiAnchorsTest
```

Expected: 2 failures — the anchor markup is not yet present.

### Task 5: Add anchors to leads-report.blade.php

**Files:**
- Modify: `resources/views/filament/pages/leads-report.blade.php` (KPI tiles section near line 30-36, byOwner/byReferrer foreach near line 44)

- [ ] **Step 5.1: Wrap KPI tiles in anchor `<a>` tags**

Find the two KPI tile blocks rendering `$r['owners_counted']` and `$r['referrers_counted']` (around lines 29-36). Wrap each in an `<a>` tag pointing to the corresponding section. Example pattern:

```blade
<a href="#leads-by-owner" class="kpi-tile-link">
    <div class="kpi-tile">
        <div class="text-2xl font-semibold tabular-nums">{{ $r['owners_counted'] }}</div>
        <div class="text-xs text-gray-500 dark:text-gray-400">Owners with activity</div>
    </div>
</a>
```

(Keep the existing tile inner markup; just add the outer `<a>` wrap and a minimal `.kpi-tile-link` style if needed — text-decoration:none.)

- [ ] **Step 5.2: Add `id="leads-by-owner"` and `id="leads-by-referrer"` to the foreach group containers**

The foreach at line 44 iterates two sections. Add `id` attributes to the wrapping `<div>` of each grouping. Example:

```blade
@foreach ([['By owner', $r['byOwner'], 'owner_id', 'leads-by-owner'], ['By referrer', $r['byReferrer'], 'referrer_id', 'leads-by-referrer']] as [$heading, $rows, $userKey, $anchorId])
    <div id="{{ $anchorId }}" class="leads-report-table-section">
        <h3>{{ $heading }}</h3>
        ...existing inner foreach...
    </div>
@endforeach
```

- [ ] **Step 5.3: Run — confirm tests pass**

```bash
php artisan test --filter LeadsReportKpiAnchorsTest
```

Expected: 2 passed.

### Task 6: Add `:target` pulse animation to BOTH tokens.css files

**Files:**
- Modify: `public/css/tokens.css`
- Modify: `resources/css/tokens.css`

> **IMPORTANT:** Per the davya-crm tokens.css drift trap, BOTH copies must be edited or the change silently no-ops (panel loads the public copy).

- [ ] **Step 6.1: Append the pulse animation to both files**

Append this CSS to the END of both `public/css/tokens.css` AND `resources/css/tokens.css`:

```css
/* Scroll-target pulse for KPI anchor jumps (F8 in workflow connectors v1) */
[id="leads-by-owner"]:target,
[id="leads-by-referrer"]:target {
    animation: davya-target-pulse 1.2s ease-out 1;
}
@keyframes davya-target-pulse {
    0%   { box-shadow: 0 0 0 2px var(--brand-500); }
    100% { box-shadow: 0 0 0 0 transparent; }
}
```

- [ ] **Step 6.2: Verify both files are byte-identical at the new addition**

```bash
diff <(tail -10 public/css/tokens.css) <(tail -10 resources/css/tokens.css)
```

Expected: no output (identical tails).

- [ ] **Step 6.3: Commit Sub-Bundle 2**

```bash
git add resources/views/filament/pages/leads-report.blade.php \
        public/css/tokens.css resources/css/tokens.css \
        tests/Feature/Reports/LeadsReportKpiAnchorsTest.php
git commit -m "$(cat <<'EOF'
feat(reports): F8 — make LeadsReport KPI tiles scroll-anchor to breakdown tables

Owners/Referrers KPIs now anchor-link to #leads-by-owner /
#leads-by-referrer. Brief box-shadow pulse on :target so the user
sees where they landed. Audit mis-attributed F8 to PaymentReport;
verified the KPIs live on LeadsReport (owners_counted /
referrers_counted backed by byOwner/byReferrer tables already
rendered below).

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Sub-Bundle 3 — F3 RoundHistory inline fee payment

### Task 7: RecordFeePaymentAction service contract test

**Files:**
- Create: `tests/Feature/Resources/RoundHistory/RecordFeePaymentTest.php`

- [ ] **Step 7.1: Write the failing test**

```php
<?php

use App\Models\Payment;
use App\Models\RoundHistory;
use App\Models\Student;
use App\Models\User;
use App\Services\RoundHistory\RecordFeePaymentAction;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->user->assignRole('admin');
    $this->actingAs($this->user);

    $this->student = Student::factory()->create(['owner_id' => $this->user->id, 'deal_amount' => 500000]);
    $this->roundHistory = RoundHistory::create([
        'student_id'      => $this->student->id,
        'round_name'      => 'Online_R1',
        'outcome'         => 'Allotted — Fee Pending',
        'seat_fee_amount' => 90000,
        'seat_fee_paid'   => false,
    ]);
});

it('atomically creates Payment and flips seat_fee_paid', function () {
    app(RecordFeePaymentAction::class)->run($this->roundHistory, [
        'amount'      => 90000,
        'type'        => 'advance',
        'received_at' => now(),
        'mode'        => 'cash',
    ]);

    $this->roundHistory->refresh();
    expect($this->roundHistory->seat_fee_paid)->toBeTrue();
    expect($this->roundHistory->fee_paid_at)->not->toBeNull();

    $payment = Payment::where('student_id', $this->student->id)->first();
    expect($payment)->not->toBeNull();
    expect((float) $payment->amount)->toBe(90000.0);
    expect($payment->type)->toBe('advance');
});

it('rolls back both writes when Payment insert fails', function () {
    expect(fn () => app(RecordFeePaymentAction::class)->run($this->roundHistory, [
        // Missing required fields → Payment::create throws QueryException
        'amount' => null,
        'type'   => null,
    ]))->toThrow(\Throwable::class);

    $this->roundHistory->refresh();
    expect($this->roundHistory->seat_fee_paid)->toBeFalse();
    expect(Payment::where('student_id', $this->student->id)->count())->toBe(0);
});
```

- [ ] **Step 7.2: Run — confirm failure**

```bash
php artisan test --filter RecordFeePaymentTest
```

Expected: 2 failures — `Class App\Services\RoundHistory\RecordFeePaymentAction not found`.

### Task 8: Implement RecordFeePaymentAction service

**Files:**
- Create: `app/Services/RoundHistory/RecordFeePaymentAction.php`

- [ ] **Step 8.1: Write the service**

```php
<?php

namespace App\Services\RoundHistory;

use App\Models\Payment;
use App\Models\RoundHistory;
use Illuminate\Support\Facades\DB;

final class RecordFeePaymentAction
{
    /**
     * Atomically:
     *   1. create a Payment row linked to the student that owns this RoundHistory
     *   2. flip the RoundHistory's seat_fee_paid + fee_paid_at
     *
     * Throws if either write fails — both are rolled back.
     *
     * @param  array<string, mixed>  $paymentData  fields accepted by PaymentFormSchema
     */
    public function run(RoundHistory $rh, array $paymentData): Payment
    {
        return DB::transaction(function () use ($rh, $paymentData) {
            $payment = Payment::create($paymentData + [
                'student_id' => $rh->student_id,
            ]);

            $rh->update([
                'seat_fee_paid' => true,
                'fee_paid_at'   => $paymentData['received_at'] ?? now(),
            ]);

            return $payment;
        });
    }
}
```

- [ ] **Step 8.2: Run — confirm tests pass**

```bash
php artisan test --filter RecordFeePaymentTest
```

Expected: 2 passed.

### Task 9: RoundHistoryRelationManager row action

**Files:**
- Modify: `app/Filament/Resources/StudentResource/RelationManagers/RoundHistoryRelationManager.php`

- [ ] **Step 9.1: Add the recordFeePayment row action**

In the `table(Table $table)` method, modify the `->actions([...])` block (currently lines 86-89) to:

```php
->actions([
    Tables\Actions\Action::make('recordFeePayment')
        ->label('Record fee payment')
        ->icon('heroicon-o-banknotes')
        ->color('success')
        ->visible(fn ($record) => ! $record->seat_fee_paid && $record->outcome === 'Allotted — Fee Pending')
        ->form(\App\Filament\Resources\Shared\PaymentFormSchema::fields())
        ->fillForm(fn ($record) => [
            'amount'      => $record->seat_fee_amount,
            'type'        => 'advance',
            'received_at' => now(),
        ])
        ->action(function (array $data, $record) {
            app(\App\Services\RoundHistory\RecordFeePaymentAction::class)->run($record, $data);
        })
        ->successNotificationTitle('Payment recorded; seat marked paid'),
    Tables\Actions\EditAction::make(),
    Tables\Actions\DeleteAction::make(),
])
```

- [ ] **Step 9.2: Add a visibility test**

Append to `tests/Feature/Resources/RoundHistory/RecordFeePaymentTest.php`:

```php
it('shows the action only when seat_fee_paid is false and outcome is Allotted — Fee Pending', function () {
    // Already-paid row → action hidden
    $paid = RoundHistory::create([
        'student_id'      => $this->student->id,
        'round_name'      => 'Online_R1',
        'outcome'         => 'Allotted — Fee Paid',
        'seat_fee_amount' => 90000,
        'seat_fee_paid'   => true,
    ]);

    // Not-allotted row → action hidden
    $notAllotted = RoundHistory::create([
        'student_id' => $this->student->id,
        'round_name' => 'Online_R1',
        'outcome'    => 'Not Allotted',
        'seat_fee_paid' => false,
    ]);

    $action = (new \App\Filament\Resources\StudentResource\RelationManagers\RoundHistoryRelationManager())
        ->table(\Filament\Tables\Table::make(\Mockery::mock(\Filament\Tables\Contracts\HasTable::class)))
        ->getActions();

    // The fee-payment action's visibility closure is the canonical check.
    $closure = collect($action)->first(fn ($a) => $a->getName() === 'recordFeePayment')->getVisible();

    expect($closure($this->roundHistory))->toBeTrue();      // fee pending → visible
    expect($closure($paid))->toBeFalse();                    // paid → hidden
    expect($closure($notAllotted))->toBeFalse();             // not allotted → hidden
});
```

- [ ] **Step 9.3: Run — full F3 suite green**

```bash
php artisan test --filter RecordFeePaymentTest
```

Expected: 3 passed.

- [ ] **Step 9.4: Commit Sub-Bundle 3**

```bash
git add app/Services/RoundHistory/RecordFeePaymentAction.php \
        app/Filament/Resources/StudentResource/RelationManagers/RoundHistoryRelationManager.php \
        tests/Feature/Resources/RoundHistory/RecordFeePaymentTest.php
git commit -m "$(cat <<'EOF'
feat(round-history): F3 — inline "Record fee payment" action

Atomic transaction creates Payment + flips seat_fee_paid=true.
Reuses PaymentFormSchema pre-filled with seat_fee_amount + type=advance.
Visible only on rows where outcome='Allotted — Fee Pending' AND
seat_fee_paid=false. Service is the single owner of the transaction
so the relation manager stays thin.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Sub-Bundle 4 — F2 Schedule meeting on PaymentReport

### Task 10: PaymentReport schedule-meeting test (today + detail = 1-step)

**Files:**
- Create: `tests/Feature/Reports/PaymentReportScheduleMeetingTest.php`

- [ ] **Step 10.1: Write the failing test**

```php
<?php

use App\Models\Meeting;
use App\Models\Payment;
use App\Models\Student;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->admin = User::factory()->create();
    $this->admin->assignRole('admin');
    $this->actingAs($this->admin);

    $this->student = Student::factory()->create(['owner_id' => $this->admin->id]);
    $this->payment = Payment::create([
        'student_id'  => $this->student->id,
        'amount'      => 25000,
        'type'        => 'advance',
        'received_at' => now(),
        'mode'        => 'cash',
    ]);
});

it('renders Schedule meeting row action on the detail tab', function () {
    $response = $this->get('/admin/payments-report?activeTab=detail');
    $response->assertOk();
    $response->assertSee('Schedule meeting');
});

it('creates a Meeting pre-bound to the row student when action fires', function () {
    Livewire::test(\App\Filament\Pages\PaymentReport::class)
        ->set('activeTab', 'detail')
        ->callTableAction('scheduleMeeting', $this->payment->id, [
            'owner_id'     => $this->admin->id,
            'scheduled_at' => now()->addDay(),
            'mode'         => 'phone',
            'notes'        => 'Follow up on advance',
        ]);

    $meeting = Meeting::where('student_id', $this->student->id)->first();
    expect($meeting)->not->toBeNull();
    expect($meeting->mode)->toBe('phone');
    expect($meeting->status)->toBe('scheduled');
});
```

- [ ] **Step 10.2: Run — confirm failure**

```bash
php artisan test --filter PaymentReportScheduleMeetingTest
```

Expected: 2 failures — the action isn't yet registered.

### Task 11: PaymentReport row action implementation

**Files:**
- Modify: `app/Filament/Pages/PaymentReport.php`
- Modify: `resources/views/filament/pages/payment-report.blade.php` (today/detail tab tables — render the action button per row)

- [ ] **Step 11.1: Add an `scheduleMeeting` Filament action method to PaymentReport**

Append a new method on the class (after `apply()` or wherever helpers live):

```php
public function scheduleMeetingAction(): \Filament\Actions\Action
{
    return \Filament\Actions\Action::make('scheduleMeeting')
        ->label('Schedule meeting')
        ->icon('heroicon-o-calendar')
        ->form(\App\Filament\Resources\Shared\MeetingFormSchema::fields())
        ->action(function (array $data, array $arguments) {
            $studentId = $arguments['student_id'] ?? null;
            abort_unless($studentId, 422, 'Missing student_id');

            \App\Models\Meeting::create($data + [
                'student_id' => $studentId,
                'status'     => 'scheduled',
            ]);
        })
        ->successNotificationTitle('Meeting scheduled');
}
```

Register the action in `getActions(): array` (Filament Page convention) by adding it to the returned array. If `getActions()` doesn't exist, create:

```php
protected function getActions(): array
{
    return [
        $this->scheduleMeetingAction(),
    ];
}
```

- [ ] **Step 11.2: Wire the action into the today + detail tab blade rows**

In `resources/views/filament/pages/payment-report.blade.php`, find the today-tab table row at line ~167 and the detail-tab row at line ~213. After each row's existing cells, add a final cell rendering the action button:

```blade
<td>
    {{ ($this->scheduleMeetingAction)(['student_id' => $r['student_id']]) }}
</td>
```

(Filament renders the Action object passed via `(...)` invocation.)

- [ ] **Step 11.3: Run — confirm tests pass**

```bash
php artisan test --filter PaymentReportScheduleMeetingTest
```

Expected: 2 passed.

### Task 12: PaymentReport byOwner 2-step picker

**Files:**
- Modify: `app/Filament/Pages/PaymentReport.php`
- Modify: `resources/views/filament/pages/payment-report.blade.php` (byOwner table around line 103)

- [ ] **Step 12.1: Add a test for the 2-step byOwner path**

Append to the same test file:

```php
it('scheduleMeeting on byOwner opens a 2-step modal with student picker scoped to that owner', function () {
    $owner = User::factory()->create();
    $owner->assignRole('member');
    $studentA = Student::factory()->create(['owner_id' => $owner->id, 'name' => 'Student A']);
    $studentB = Student::factory()->create(['owner_id' => $owner->id, 'name' => 'Student B']);
    $otherOwnersStudent = Student::factory()->create(['owner_id' => User::factory()->create()->id]);

    Livewire::test(\App\Filament\Pages\PaymentReport::class)
        ->set('activeTab', 'report')
        ->callTableAction('scheduleMeetingByOwner', $owner->id, [
            'student_id'   => $studentA->id,
            'owner_id'     => $owner->id,
            'scheduled_at' => now()->addDay(),
            'mode'         => 'phone',
            'notes'        => 'Q from owner roll-up',
        ]);

    $meeting = Meeting::where('student_id', $studentA->id)->first();
    expect($meeting)->not->toBeNull();
});
```

- [ ] **Step 12.2: Add the scheduleMeetingByOwner action method**

```php
public function scheduleMeetingByOwnerAction(): \Filament\Actions\Action
{
    return \Filament\Actions\Action::make('scheduleMeetingByOwner')
        ->label('Schedule meeting')
        ->icon('heroicon-o-calendar')
        ->form(function (array $arguments) {
            $ownerId = $arguments['owner_id'] ?? null;
            abort_unless($ownerId, 422, 'Missing owner_id');

            return array_merge([
                \Filament\Forms\Components\Select::make('student_id')
                    ->label('Student')
                    ->options(fn () => \App\Models\Student::query()
                        ->where('owner_id', $ownerId)
                        ->orderBy('name')
                        ->pluck('name', 'id')
                        ->all())
                    ->searchable()
                    ->required()
                    ->placeholder('Pick a student for this meeting'),
            ], \App\Filament\Resources\Shared\MeetingFormSchema::fields());
        })
        ->action(function (array $data) {
            \App\Models\Meeting::create([
                'student_id'   => $data['student_id'],
                'owner_id'     => $data['owner_id'],
                'scheduled_at' => $data['scheduled_at'],
                'mode'         => $data['mode'],
                'notes'        => $data['notes'] ?? null,
                'status'       => 'scheduled',
            ]);
        })
        ->successNotificationTitle('Meeting scheduled');
}
```

Add it to `getActions()`:

```php
protected function getActions(): array
{
    return [
        $this->scheduleMeetingAction(),
        $this->scheduleMeetingByOwnerAction(),
    ];
}
```

- [ ] **Step 12.3: Render it in the byOwner table**

In `payment-report.blade.php` at the byOwner foreach (line ~103), add a final cell per row:

```blade
<td>
    {{ ($this->scheduleMeetingByOwnerAction)(['owner_id' => $ownerId]) }}
</td>
```

- [ ] **Step 12.4: Run — confirm**

```bash
php artisan test --filter PaymentReportScheduleMeetingTest
```

Expected: 3 passed.

- [ ] **Step 12.5: Commit Sub-Bundle 4**

```bash
git add app/Filament/Pages/PaymentReport.php \
        resources/views/filament/pages/payment-report.blade.php \
        tests/Feature/Reports/PaymentReportScheduleMeetingTest.php
git commit -m "$(cat <<'EOF'
feat(reports): F2 — Schedule meeting row actions on PaymentReport

today + detail tabs use single-step modal (student_id pre-bound from
the row). report tab byOwner table uses a 2-step modal that opens a
searchable student picker scoped to that owner's students. Both
reuse the new MeetingFormSchema.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Sub-Bundle 5 — F2 Schedule meeting on LeadsReport

### Task 13: LeadsReport schedule-meeting test (byOwner + byReferrer = 2-step both)

**Files:**
- Create: `tests/Feature/Reports/LeadsReportScheduleMeetingTest.php`

- [ ] **Step 13.1: Write the failing test**

```php
<?php

use App\Models\Meeting;
use App\Models\Student;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->admin = User::factory()->create();
    $this->admin->assignRole('admin');
    $this->actingAs($this->admin);
});

it('renders scheduleMeetingByOwner on LeadsReport byOwner rows', function () {
    $owner = User::factory()->create();
    $student = Student::factory()->create(['owner_id' => $owner->id]);

    $response = $this->get('/admin/leads-report');
    $response->assertSee('Schedule meeting');
});

it('byOwner picker is scoped to owner students only', function () {
    $owner = User::factory()->create();
    $mine = Student::factory()->create(['owner_id' => $owner->id]);
    $other = Student::factory()->create(['owner_id' => User::factory()->create()->id]);

    Livewire::test(\App\Filament\Pages\LeadsReport::class)
        ->callTableAction('scheduleMeetingByOwner', $owner->id, [
            'student_id'   => $mine->id,
            'owner_id'     => $owner->id,
            'scheduled_at' => now()->addDay(),
            'mode'         => 'phone',
        ]);

    expect(Meeting::where('student_id', $mine->id)->count())->toBe(1);
});

it('byReferrer picker is scoped to referred students only', function () {
    $referrer = User::factory()->create();
    $referred = Student::factory()->create(['referrer_id' => $referrer->id]);

    Livewire::test(\App\Filament\Pages\LeadsReport::class)
        ->callTableAction('scheduleMeetingByReferrer', $referrer->id, [
            'student_id'   => $referred->id,
            'owner_id'     => $this->admin->id,
            'scheduled_at' => now()->addDay(),
            'mode'         => 'phone',
        ]);

    expect(Meeting::where('student_id', $referred->id)->count())->toBe(1);
});
```

- [ ] **Step 13.2: Run — confirm failure**

```bash
php artisan test --filter LeadsReportScheduleMeetingTest
```

Expected: 3 failures.

### Task 14: LeadsReport action implementations

**Files:**
- Modify: `app/Filament/Pages/LeadsReport.php`
- Modify: `resources/views/filament/pages/leads-report.blade.php`

- [ ] **Step 14.1: Add the two action methods + getActions to LeadsReport**

```php
protected function getActions(): array
{
    return [
        $this->scheduleMeetingByOwnerAction(),
        $this->scheduleMeetingByReferrerAction(),
    ];
}

public function scheduleMeetingByOwnerAction(): \Filament\Actions\Action
{
    return \Filament\Actions\Action::make('scheduleMeetingByOwner')
        ->label('Schedule meeting')
        ->icon('heroicon-o-calendar')
        ->form(function (array $arguments) {
            $ownerId = $arguments['owner_id'] ?? null;
            abort_unless($ownerId, 422, 'Missing owner_id');

            return array_merge([
                \Filament\Forms\Components\Select::make('student_id')
                    ->label('Student')
                    ->options(fn () => \App\Models\Student::query()
                        ->where('owner_id', $ownerId)
                        ->orderBy('name')
                        ->pluck('name', 'id')
                        ->all())
                    ->searchable()
                    ->required(),
            ], \App\Filament\Resources\Shared\MeetingFormSchema::fields());
        })
        ->action(fn (array $data) => \App\Models\Meeting::create([
            'student_id'   => $data['student_id'],
            'owner_id'     => $data['owner_id'],
            'scheduled_at' => $data['scheduled_at'],
            'mode'         => $data['mode'],
            'notes'        => $data['notes'] ?? null,
            'status'       => 'scheduled',
        ]))
        ->successNotificationTitle('Meeting scheduled');
}

public function scheduleMeetingByReferrerAction(): \Filament\Actions\Action
{
    return \Filament\Actions\Action::make('scheduleMeetingByReferrer')
        ->label('Schedule meeting')
        ->icon('heroicon-o-calendar')
        ->form(function (array $arguments) {
            $referrerId = $arguments['referrer_id'] ?? null;
            abort_unless($referrerId, 422, 'Missing referrer_id');

            return array_merge([
                \Filament\Forms\Components\Select::make('student_id')
                    ->label('Student')
                    ->options(fn () => \App\Models\Student::query()
                        ->where('referrer_id', $referrerId)
                        ->orderBy('name')
                        ->pluck('name', 'id')
                        ->all())
                    ->searchable()
                    ->required(),
            ], \App\Filament\Resources\Shared\MeetingFormSchema::fields());
        })
        ->action(fn (array $data) => \App\Models\Meeting::create([
            'student_id'   => $data['student_id'],
            'owner_id'     => $data['owner_id'],
            'scheduled_at' => $data['scheduled_at'],
            'mode'         => $data['mode'],
            'notes'        => $data['notes'] ?? null,
            'status'       => 'scheduled',
        ]))
        ->successNotificationTitle('Meeting scheduled');
}
```

- [ ] **Step 14.2: Wire actions into leads-report.blade.php byOwner + byReferrer foreach**

In the foreach at line 44, inside the row rendering, add a per-row action cell. Adjust based on which `$userKey` is in scope:

```blade
@foreach ($rows as $userId => $row)
    <tr>
        {{-- existing cells: name, leads, paid, pending, etc. --}}
        <td>
            @if ($userKey === 'owner_id')
                {{ ($this->scheduleMeetingByOwnerAction)(['owner_id' => $userId]) }}
            @else
                {{ ($this->scheduleMeetingByReferrerAction)(['referrer_id' => $userId]) }}
            @endif
        </td>
    </tr>
@endforeach
```

- [ ] **Step 14.3: Run — confirm tests pass**

```bash
php artisan test --filter LeadsReportScheduleMeetingTest
```

Expected: 3 passed.

- [ ] **Step 14.4: Commit Sub-Bundle 5**

```bash
git add app/Filament/Pages/LeadsReport.php \
        resources/views/filament/pages/leads-report.blade.php \
        tests/Feature/Reports/LeadsReportScheduleMeetingTest.php
git commit -m "$(cat <<'EOF'
feat(reports): F2 — Schedule meeting row actions on LeadsReport

byOwner and byReferrer rows both use the 2-step modal (student picker
first, then MeetingFormSchema). Pickers are scoped to the owner's
or referrer's students respectively.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Sub-Bundle 6 — F1 Rank → Student write-back

### Task 15: RankLookup student-context detection test

**Files:**
- Create: `tests/Feature/Rank/RankLookupStudentContextTest.php`

- [ ] **Step 15.1: Write the failing test (mount + banner)**

```php
<?php

use App\Models\Student;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->admin = User::factory()->create();
    $this->admin->assignRole('admin');
    $this->admin->assignRole('rank-admin');
    $this->actingAs($this->admin);

    $this->student = Student::factory()->create([
        'owner_id'    => $this->admin->id,
        'name'        => 'Aman Sharma',
        'rank'        => 52800,
    ]);
});

it('cold-launches with no student context (no banner)', function () {
    $response = $this->get('/admin/rank-lookup');
    $response->assertOk();
    $response->assertDontSee('Picking preferences for:');
});

it('loads contextStudent when ?student_id= is present', function () {
    Livewire::test(\App\Filament\Pages\Rank\RankLookup::class, ['student_id' => $this->student->id])
        ->assertSet('contextStudent.id', $this->student->id);
});

it('renders the sticky banner with student name + rank when context is set', function () {
    $response = $this->get('/admin/rank-lookup?student_id=' . $this->student->id);
    $response->assertSee('Picking preferences for');
    $response->assertSee('Aman Sharma');
    $response->assertSee('52,800', false);
});

it('treats deleted / out-of-scope student_id as cold-launch', function () {
    $other = Student::factory()->create(['owner_id' => User::factory()->create()->id]);
    $this->admin->removeRole('admin');                       // demote to non-admin
    $this->admin->assignRole('member');

    $response = $this->get('/admin/rank-lookup?student_id=' . $other->id);
    $response->assertOk();
    $response->assertDontSee('Picking preferences for:');    // out-of-scope → no banner
});
```

- [ ] **Step 15.2: Run — confirm failure**

```bash
php artisan test --filter RankLookupStudentContextTest
```

Expected: 4 failures.

### Task 16: RankLookup mount() reads student_id

**Files:**
- Modify: `app/Filament/Pages/Rank/RankLookup.php`

- [ ] **Step 16.1: Add `$contextStudent` property + load on mount**

Near the existing property declarations (after `$showAll` around line 42), add:

```php
public ?\App\Models\Student $contextStudent = null;
```

Modify the `mount()` method signature to accept `?int $student_id` and load the student inside it:

```php
public function mount(?int $student_id = null): void
{
    if ($student_id !== null) {
        $candidate = \App\Models\Student::query()
            ->visibleTo(auth()->user())
            ->find($student_id);
        $this->contextStudent = $candidate;
    }

    // ... existing mount() body below ...
    $latestYear = Cutoff::max('year') ?? (int) date('Y');
    // ...
}
```

- [ ] **Step 16.2: Add the banner block to the blade**

In `resources/views/filament/pages/rank/rank-lookup.blade.php`, at the TOP of the rendered content (before the form), add:

```blade
@if ($contextStudent)
    <div class="rank-lookup-banner sticky top-0 z-30 bg-white dark:bg-gray-900 border-b border-gray-200 dark:border-gray-700 -mx-6 px-6 py-4 mb-6">
        <div class="text-xs uppercase tracking-wider text-gray-500">Picking preferences for</div>
        <div class="flex items-baseline gap-4 mt-1">
            <h2 class="text-2xl font-semibold">{{ $contextStudent->name }}</h2>
            @if ($contextStudent->rank)
                <span class="text-gray-500">JEE rank</span>
                <span class="text-xl tabular-nums text-red-700">{{ number_format($contextStudent->rank) }}</span>
            @endif
            <a href="{{ \App\Filament\Resources\StudentResource::getUrl('edit', ['record' => $contextStudent->id]) }}"
               class="ml-auto text-sm text-gray-600 hover:text-emerald-700 underline">
                ↗ Back to student edit
            </a>
        </div>
    </div>
@endif
```

- [ ] **Step 16.3: Run — confirm 3 of 4 tests pass (4th depends on save buttons)**

```bash
php artisan test --filter RankLookupStudentContextTest
```

Expected: first 3 + 4th pass (banner test, cold-launch test, deleted-student test, mount-property test).

### Task 17: Per-row "Save as Choice N" buttons (test then implementation)

**Files:**
- Modify: `app/Filament/Pages/Rank/RankLookup.php` (add `saveChoice` action)
- Modify: `resources/views/filament/pages/rank/rank-lookup.blade.php` (add buttons inside each result row)

- [ ] **Step 17.1: Append the saveChoice test**

In `tests/Feature/Rank/RankLookupStudentContextTest.php`, append:

```php
it('saveChoice writes freetext + structured columns', function () {
    Livewire::test(\App\Filament\Pages\Rank\RankLookup::class, ['student_id' => $this->student->id])
        ->call('saveChoice', 'VIPS-TC', 'Computer Science & Engineering', 'Shift I', 1);

    $this->student->refresh();
    expect($this->student->preference_r1)->toBe('VIPS-TC — Computer Science & Engineering');
    expect($this->student->preference_r1_college)->toBe('VIPS-TC');
    expect($this->student->preference_r1_branch)->toBe('Computer Science & Engineering');
});

it('saveChoice overwrites existing value when forceOverwrite is true', function () {
    $this->student->update(['preference_r1' => 'BVCOE — IT']);

    Livewire::test(\App\Filament\Pages\Rank\RankLookup::class, ['student_id' => $this->student->id])
        ->call('saveChoice', 'VIPS-TC', 'CSE', null, 1, true);

    $this->student->refresh();
    expect($this->student->preference_r1)->toBe('VIPS-TC — CSE');
});

it('saveChoice without forceOverwrite + existing value emits conflict event', function () {
    $this->student->update(['preference_r1' => 'BVCOE — IT']);

    Livewire::test(\App\Filament\Pages\Rank\RankLookup::class, ['student_id' => $this->student->id])
        ->call('saveChoice', 'VIPS-TC', 'CSE', null, 1, false)
        ->assertDispatched('rank:choice-conflict');

    $this->student->refresh();
    expect($this->student->preference_r1)->toBe('BVCOE — IT');           // unchanged
});

it('saveChoice rejects when contextStudent is null', function () {
    Livewire::test(\App\Filament\Pages\Rank\RankLookup::class)
        ->call('saveChoice', 'X', 'Y', null, 1)
        ->assertNotDispatched('rank:saved');

    expect($this->student->fresh()->preference_r1)->toBeNull();
});
```

- [ ] **Step 17.2: Add `saveChoice` method to RankLookup**

```php
public function saveChoice(string $institute, string $branch, ?string $shift, int $slot, bool $forceOverwrite = false): void
{
    if (! $this->contextStudent) {
        return;
    }
    if (! in_array($slot, [1, 2, 3], true)) {
        return;
    }

    $freetext = $institute . ' — ' . $branch;
    if ($shift) {
        $freetext .= ' (' . $shift . ')';
    }

    $currentFreetext = $this->contextStudent->{"preference_r{$slot}"};
    if (! $forceOverwrite && filled($currentFreetext) && $currentFreetext !== $freetext) {
        $this->dispatch('rank:choice-conflict', [
            'slot'    => $slot,
            'oldText' => $currentFreetext,
            'newText' => $freetext,
            'args'    => [$institute, $branch, $shift, $slot, true],
        ]);
        return;
    }

    $this->contextStudent->update([
        "preference_r{$slot}"          => $freetext,
        "preference_r{$slot}_college"  => $institute,
        "preference_r{$slot}_branch"   => $branch,
    ]);

    $this->dispatch('rank:saved', ['slot' => $slot, 'studentName' => $this->contextStudent->name]);
    \Filament\Notifications\Notification::make()
        ->title("Saved as Choice {$slot} for {$this->contextStudent->name}")
        ->success()
        ->send();
}
```

- [ ] **Step 17.3: Render per-row buttons in the blade**

In `rank-lookup.blade.php`, find the foreach iterating over `colleges` → `branches`. Inside each branch row (after the round cells and cushion/seat info), add:

```blade
@if ($contextStudent)
    <div class="flex gap-1 mt-2">
        @foreach ([1, 2, 3] as $slot)
            <button type="button"
                    class="px-3 py-1 text-xs rounded-full border border-gray-300 hover:bg-emerald-50 hover:border-emerald-600"
                    wire:click="saveChoice('{{ addslashes($col['institute']) }}', '{{ addslashes($branch['branch']) }}', '{{ $branch['shift'] }}', {{ $slot }})">
                Save as Choice {{ $slot }}
            </button>
        @endforeach
    </div>
@endif
```

- [ ] **Step 17.4: Run — confirm full F1 suite green**

```bash
php artisan test --filter RankLookupStudentContextTest
```

Expected: 8 passed.

### Task 18: StudentResource header action "Run rank lookup"

**Files:**
- Modify: `app/Filament/Resources/StudentResource/Pages/EditStudent.php`

- [ ] **Step 18.1: Add a header action test**

Append to `tests/Feature/Rank/RankLookupStudentContextTest.php`:

```php
it('EditStudent header shows "Run rank lookup" action for rank-admin viewers', function () {
    $response = $this->get('/admin/students/' . $this->student->id . '/edit');
    $response->assertSee('Run rank lookup');
});

it('header action hidden from members without rank-admin role', function () {
    $member = User::factory()->create();
    $member->assignRole('member');
    $this->actingAs($member);

    $student = Student::factory()->create(['owner_id' => $member->id]);
    $response = $this->get('/admin/students/' . $student->id . '/edit');
    $response->assertDontSee('Run rank lookup');
});
```

- [ ] **Step 18.2: Add the header action**

In `EditStudent.php`, add to the existing `getHeaderActions(): array` (or create it):

```php
protected function getHeaderActions(): array
{
    return [
        \Filament\Actions\Action::make('runRankLookup')
            ->label('Run rank lookup')
            ->icon('heroicon-o-magnifying-glass')
            ->color('gray')
            ->visible(fn () => auth()->user()->hasAnyRole(['admin', 'rank-admin']))
            ->url(fn ($record) => '/admin/rank-lookup?student_id=' . $record->id),
        // ... any pre-existing actions ...
    ];
}
```

If a `getHeaderActions()` already exists, prepend the new action; otherwise create the method.

- [ ] **Step 18.3: Run — full F1 suite green**

```bash
php artisan test --filter RankLookupStudentContextTest
```

Expected: 10 passed.

- [ ] **Step 18.4: Commit Sub-Bundle 6**

```bash
git add app/Filament/Pages/Rank/RankLookup.php \
        resources/views/filament/pages/rank/rank-lookup.blade.php \
        app/Filament/Resources/StudentResource/Pages/EditStudent.php \
        tests/Feature/Rank/RankLookupStudentContextTest.php
git commit -m "$(cat <<'EOF'
feat(rank): F1 — Rank Lookup → Student preference write-back

EditStudent header gains "Run rank lookup" action (rank-admin/admin
only) → /admin/rank-lookup?student_id=X. RankLookup detects the
query param via mount, loads contextStudent via scopeVisibleTo (so
permission checks ride for free), renders a sticky banner and adds
per-row "Save as Choice 1/2/3" buttons. saveChoice writes both
freetext preference_r{n} AND structured preference_r{n}_college /
_branch columns. Existing value triggers a conflict event handled
client-side; passing forceOverwrite=true commits anyway.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Post-implementation verification

### Task 19: Full suite + smoke checks

- [ ] **Step 19.1: Run the entire test suite**

```bash
php artisan test --parallel 2>&1 | tail -10
```

Expected: 817 baseline + ~20 new = ~837 passed, 1 skipped (unchanged). Zero failures.

- [ ] **Step 19.2: Lint pass**

```bash
./vendor/bin/pint --test
```

Expected: no formatting drift on any modified file.

- [ ] **Step 19.3: Curl smoke each touched route**

```bash
php artisan serve --port=8000 &
sleep 2

for route in \
    /admin/login \
    /admin/leads-report \
    /admin/payments-report \
    /admin/payments-report?activeTab=detail \
    /admin/payments-report?activeTab=today \
    /admin/rank-lookup \
    ; do
  echo "GET $route → $(curl -s -o /dev/null -w '%{http_code}' http://127.0.0.1:8000$route)"
done

kill %1
```

Expected each: `200` (for /admin/login) or `302` (unauth redirect for the rest).

- [ ] **Step 19.4: Commit anything outstanding (lint / smoke fixes)**

If lint flagged anything, fix it and:

```bash
git add -p && git commit -m "style: pint auto-formatting"
```

---

## Out of scope for this plan

- Bulk save (save all 3 visible rank results in one click) — Phase 2.5
- Adding `round_history_id` FK on Payments — Phase 2.5
- Schedule-meeting action on StudentResource list — out of bundle scope
- Mobile-first redesign per the v3 typography candidate (mockup at `docs/mockups/rank-lookup-student-context-mockup.html`) — separate spec
- Next Action Engine (`docs/superpowers/specs/2026-05-23-next-action-engine-design.md`) — depends on this plan landing first

---

## Hand-off note for the implementing agent

- **Spec is authoritative.** Where this plan and the spec disagree, follow the spec and flag the drift. (Memory: `feedback_subagent_env_inference_trap.md`.)
- **DB safety**: tests run on SQLite `:memory:` per `phpunit.xml`. **DO NOT** run `migrate:fresh` against the local MySQL connection mid-execution — kyne-rebuild precedent shows that drops the prod-mirror DB. If a clean state is needed, use `RefreshDatabase` trait per test class (already used by base TestCase).
- **tokens.css drift**: any CSS change MUST go to both `public/css/tokens.css` AND `resources/css/tokens.css`. (Memory: `reference_davya-crm_tokens_css_drift.md`.)
- **No FTP push in this plan.** Deploy is a separate manual step that runs the full recipe (git pull + composer + migrate + rank seeders + 3 caches). Don't bake deploy into any task. (Memory: `feedback_full_deploy_recipe_no_shortcuts.md`.)
