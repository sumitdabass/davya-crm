# Unified Payment/Payout Chooser Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make the Payments-panel "New payment" button a single chooser (Add/Update × Payment/Payout) in one modal, remove the now-redundant Deal-tab payouts repeater and Account-tab "+ New Payment", and add a Payouts relation-manager tab for browsing.

**Architecture:** A single custom relation-manager header action with one form: a `ToggleButtons('entry_action')` (`->live()`) at the top reveals the matching field group below (payment fields reused from `PaymentFormSchema`; payout fields `payout_*`-prefixed to avoid name collisions). One `->action()` handler branches on `entry_action`. This avoids the `wire:click`-in-modal teleport trap.

**Tech Stack:** Laravel 11, Filament 3, PHPUnit. Spec: `docs/superpowers/specs/2026-06-08-payment-payout-chooser-design.md`.

**Baseline:** suite green at 892 passing / 1 skipped (from the payouts feature). Run with `php -d memory_limit=2G ./vendor/bin/phpunit`; single file `php -d memory_limit=2G ./vendor/bin/phpunit tests/Feature/X.php`. The `php artisan test` wrapper hits a 128M child cap — don't use it.

---

### Task 1: Revert the Deal & Counselling tab + remove the Account "+ New Payment"

**Files:**
- Modify: `app/Filament/Resources/StudentResource.php`
- Test: `tests/Feature/StudentFormRevertTest.php`

The payouts feature added (a) a `Repeater::make('payouts')` block + a `Placeholder::make('expected_profit_preview')` block in the Deal & Counselling tab right after `TextInput::make('deal_amount')`, and (b) imports `DateTimePicker`, `Placeholder`, `Repeater`, `Filament\Forms\Get`, `Illuminate\Support\HtmlString`. The Account tab has an `Action::make('addPayment')` inside an `Actions::make([...])` block. Remove all of these. Keep the `plan` Select with its current options. Keep the Account `addNote` action.

- [ ] **Step 1: Write the failing/guard test**

Create `tests/Feature/StudentFormRevertTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Filament\Resources\StudentResource\Pages\CreateStudent;
use App\Filament\Resources\StudentResource\Pages\EditStudent;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class StudentFormRevertTest extends TestCase
{
    use RefreshDatabase;

    public function test_create_and_edit_student_pages_mount_after_deal_tab_revert(): void
    {
        $this->seed();
        $sumit = User::where('email', 'sumit@davya.local')->firstOrFail();
        $this->actingAs($sumit);

        Livewire::test(CreateStudent::class)->assertSuccessful();

        $student = Student::create([
            'phone' => '9100000077',
            'name' => 'RevertTester',
            'owner_id' => $sumit->id,
            'referrer_id' => $sumit->id,
            'lead_source' => 'Sumit',
        ]);

        Livewire::test(EditStudent::class, ['record' => $student->getRouteKey()])->assertSuccessful();
    }

    public function test_deal_tab_no_longer_defines_a_payouts_repeater(): void
    {
        $source = file_get_contents(app_path('Filament/Resources/StudentResource.php'));
        $this->assertStringNotContainsString("Repeater::make('payouts')", $source);
        $this->assertStringNotContainsString("Placeholder::make('expected_profit_preview')", $source);
        $this->assertStringNotContainsString("Action::make('addPayment')", $source);
    }
}
```

- [ ] **Step 2: Run it — expect failure**

Run: `php -d memory_limit=2G ./vendor/bin/phpunit tests/Feature/StudentFormRevertTest.php`
Expected: `test_deal_tab_no_longer_defines_a_payouts_repeater` FAILS (strings still present).

- [ ] **Step 3: Remove the payouts Repeater + Placeholder from the Deal tab**

In `app/Filament/Resources/StudentResource.php`, delete the entire `Repeater::make('payouts')->relationship()-> ... ->live(),` block and the entire `Placeholder::make('expected_profit_preview')-> ... ,` block that sit immediately after `TextInput::make('deal_amount')->numeric()->prefix('₹'),` in the Deal & Counselling tab schema. Leave `deal_amount` and the `plan`/registration/seat Selects intact.

- [ ] **Step 4: Remove the Account-tab addPayment action**

In the Account tab's `Actions::make([...])` block, delete the entire `Action::make('addPayment')-> ... ->modalWidth('lg'),` element. Keep `Action::make('addNote')...`. The `Actions::make([ ... ])->columnSpanFull()` wrapper stays with just `addNote`.

- [ ] **Step 5: Remove now-unused imports**

Run `grep -n "Repeater\|Placeholder\|DateTimePicker\|Forms\\\\Get\|HtmlString" app/Filament/Resources/StudentResource.php`. For each of `use Filament\Forms\Components\Repeater;`, `use Filament\Forms\Components\Placeholder;`, `use Filament\Forms\Components\DateTimePicker;`, `use Filament\Forms\Get;`, `use Illuminate\Support\HtmlString;` — if grep shows the symbol is no longer referenced anywhere else in the file, remove that `use` line. Do NOT remove `use App\Support\MoneyFormat;` (still used by table money columns). Run `php -l app/Filament/Resources/StudentResource.php` → expect "No syntax errors".

- [ ] **Step 6: Run the test — expect pass**

Run: `php -d memory_limit=2G ./vendor/bin/phpunit tests/Feature/StudentFormRevertTest.php`
Expected: PASS (2 tests). Also run `php -d memory_limit=2G ./vendor/bin/phpunit tests/Feature/ListStudentsPageTest.php tests/Feature/StudentPayoutFormTest.php` → PASS (the payouts relationship contract test is unaffected; the repeater removal doesn't touch the model).

- [ ] **Step 7: Pint + commit**

```bash
./vendor/bin/pint app/Filament/Resources/StudentResource.php tests/Feature/StudentFormRevertTest.php
git add app/Filament/Resources/StudentResource.php tests/Feature/StudentFormRevertTest.php
git commit -m "revert(students): remove Deal-tab payouts repeater + Account New Payment action"
```

---

### Task 2: Add the Payouts relation-manager tab

**Files:**
- Create: `app/Filament/Resources/StudentResource/RelationManagers/PayoutsRelationManager.php`
- Modify: `app/Filament/Resources/StudentResource.php` (`getRelations()` ~line 571)
- Test: `tests/Feature/PayoutsRelationManagerTest.php`

- [ ] **Step 1: Create the relation manager**

Create `app/Filament/Resources/StudentResource/RelationManagers/PayoutsRelationManager.php` (mirrors `PaymentsRelationManager`):

```php
<?php

namespace App\Filament\Resources\StudentResource\RelationManagers;

use App\Support\MoneyFormat;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

class PayoutsRelationManager extends RelationManager
{
    protected static string $relationship = 'payouts';

    public function form(Form $form): Form
    {
        return $form->schema([
            Select::make('payee_type')->label('Payee')
                ->options(['college' => 'College', 'other' => 'Other'])
                ->default('college')->required(),
            TextInput::make('payee_name')->label('Payee name')->maxLength(120),
            TextInput::make('amount')->numeric()->prefix('₹')->required(),
            Select::make('status')
                ->options(['to_pay' => 'To be paid', 'paid' => 'Paid'])
                ->default('to_pay')->live()->required(),
            DateTimePicker::make('paid_at')->label('Paid on')
                ->visible(fn (\Filament\Forms\Get $get) => $get('status') === 'paid'),
            Textarea::make('notes')->rows(2)->columnSpanFull(),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('payee_type')
            ->columns([
                Tables\Columns\TextColumn::make('created_at')->since()
                    ->tooltip(fn ($record) => $record->created_at?->format('d M Y, H:i'))->sortable(),
                Tables\Columns\TextColumn::make('payee_type')->label('Payee')->badge(),
                Tables\Columns\TextColumn::make('payee_name')->label('Name'),
                Tables\Columns\TextColumn::make('amount')
                    ->formatStateUsing(fn ($state) => MoneyFormat::asInlineHtml((float) $state))->html(),
                Tables\Columns\TextColumn::make('status')->badge()
                    ->color(fn ($state) => $state === 'paid' ? 'success' : 'warning'),
                Tables\Columns\TextColumn::make('recordedBy.name')->label('Recorded by'),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }
}
```

Note: no header `CreateAction` — adds go through the chooser (Task 3). The per-row Edit form does not set `recorded_by_user_id`; that column is only required on create, which happens via the chooser, so editing an existing payout never needs it.

- [ ] **Step 2: Register it in `getRelations()`**

In `app/Filament/Resources/StudentResource.php`, add `PayoutsRelationManager::class` right after `PaymentsRelationManager::class` in the `getRelations()` array, and add the import `use App\Filament\Resources\StudentResource\RelationManagers\PayoutsRelationManager;` next to the other RelationManager imports (lines ~7-11).

- [ ] **Step 3: Write the test**

Create `tests/Feature/PayoutsRelationManagerTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Filament\Resources\StudentResource\Pages\EditStudent;
use App\Filament\Resources\StudentResource\RelationManagers\PayoutsRelationManager;
use App\Models\Payout;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class PayoutsRelationManagerTest extends TestCase
{
    use RefreshDatabase;

    public function test_payouts_panel_lists_student_payouts(): void
    {
        $this->seed();
        $sumit = User::where('email', 'sumit@davya.local')->firstOrFail();
        $student = Student::create([
            'phone' => '9100000088', 'name' => 'PayoutPanel',
            'owner_id' => $sumit->id, 'referrer_id' => $sumit->id, 'lead_source' => 'Sumit',
        ]);
        $payout = Payout::factory()->create([
            'student_id' => $student->id, 'amount' => 40000, 'payee_type' => 'college',
            'recorded_by_user_id' => $sumit->id,
        ]);
        $this->actingAs($sumit);

        Livewire::test(PayoutsRelationManager::class, [
            'ownerRecord' => $student,
            'pageClass' => EditStudent::class,
        ])
            ->assertSuccessful()
            ->assertCanSeeTableRecords([$payout]);
    }
}
```

- [ ] **Step 4: Run the test — expect pass**

Run: `php -d memory_limit=2G ./vendor/bin/phpunit tests/Feature/PayoutsRelationManagerTest.php`
Expected: PASS.

- [ ] **Step 5: Pint + commit**

```bash
./vendor/bin/pint app/Filament/Resources/StudentResource/RelationManagers/PayoutsRelationManager.php app/Filament/Resources/StudentResource.php tests/Feature/PayoutsRelationManagerTest.php
git add app/Filament/Resources/StudentResource/RelationManagers/PayoutsRelationManager.php app/Filament/Resources/StudentResource.php tests/Feature/PayoutsRelationManagerTest.php
git commit -m "feat(payouts): Payouts relation-manager tab for browsing/editing"
```

---

### Task 3: The chooser action on the Payments panel

**Files:**
- Modify: `app/Filament/Resources/StudentResource/RelationManagers/PaymentsRelationManager.php`
- Modify: `tests/Feature/PaymentsRelationManagerTest.php` (existing `callTableAction('create', ...)` usages break — update them)
- Test: `tests/Feature/PaymentPayoutChooserTest.php`

- [ ] **Step 1: Add imports to PaymentsRelationManager**

In `app/Filament/Resources/StudentResource/RelationManagers/PaymentsRelationManager.php`, add:

```php
use App\Models\Payment;
use App\Models\Payout;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Group;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\ToggleButtons;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Notifications\Notification;
use Illuminate\Support\Arr;
```

(`PaymentFormSchema` is already imported.)

- [ ] **Step 2: Replace the header `CreateAction` with the chooser action**

In `table()`, replace the entire `->headerActions([...])` block with:

```php
            ->headerActions([
                Tables\Actions\Action::make('newPaymentPayout')
                    ->label('New payment / payout')
                    ->icon('heroicon-o-banknotes')
                    ->color('success')
                    ->modalHeading('New payment / payout')
                    ->modalWidth('xl')
                    ->modalSubmitActionLabel('Save')
                    ->form([
                        ToggleButtons::make('entry_action')
                            ->label('What do you want to do?')
                            ->options([
                                'add_payment'    => 'Add Payment',
                                'update_payment' => 'Update Payment',
                                'add_payout'     => 'Add Payout',
                                'update_payout'  => 'Update Payout',
                            ])
                            ->icons([
                                'add_payment'    => 'heroicon-o-plus',
                                'update_payment' => 'heroicon-o-pencil-square',
                                'add_payout'     => 'heroicon-o-plus',
                                'update_payout'  => 'heroicon-o-pencil-square',
                            ])
                            ->inline()
                            ->live()
                            ->required()
                            ->default('add_payment')
                            ->columnSpanFull(),

                        Select::make('payment_id')
                            ->label('Which payment?')
                            ->options(fn ($livewire) => $livewire->getOwnerRecord()->payments()
                                ->latest('received_at')->get()
                                ->mapWithKeys(fn ($p) => [$p->id => '₹'.number_format((float) $p->amount, 0).' · '.$p->type.' · '.$p->received_at?->format('d M Y')])
                                ->all())
                            ->live()
                            ->required()
                            ->visible(fn (Get $get) => $get('entry_action') === 'update_payment')
                            ->afterStateUpdated(function ($state, Set $set) {
                                $p = Payment::find($state);
                                if (! $p) {
                                    return;
                                }
                                $set('type', $p->type);
                                $set('amount', $p->amount);
                                $set('mode', $p->mode);
                                $set('reference_number', $p->reference_number);
                                $set('received_at', $p->received_at);
                                $set('proof_url', $p->proof_url);
                                $set('notes', $p->notes);
                            }),

                        Select::make('payout_id')
                            ->label('Which payout?')
                            ->options(fn ($livewire) => $livewire->getOwnerRecord()->payouts()
                                ->latest()->get()
                                ->mapWithKeys(fn ($po) => [$po->id => '₹'.number_format((float) $po->amount, 0).' · '.ucfirst($po->payee_type).' · '.$po->created_at?->format('d M Y')])
                                ->all())
                            ->live()
                            ->required()
                            ->visible(fn (Get $get) => $get('entry_action') === 'update_payout')
                            ->afterStateUpdated(function ($state, Set $set) {
                                $po = Payout::find($state);
                                if (! $po) {
                                    return;
                                }
                                $set('payout_payee_type', $po->payee_type);
                                $set('payout_payee_name', $po->payee_name);
                                $set('payout_amount', $po->amount);
                                $set('payout_status', $po->status);
                                $set('payout_paid_at', $po->paid_at);
                                $set('payout_notes', $po->notes);
                            }),

                        Group::make(PaymentFormSchema::fields(inlineFirstPayment: false))
                            ->visible(fn (Get $get) => in_array($get('entry_action'), ['add_payment', 'update_payment'], true))
                            ->columns(['default' => 1, 'md' => 2])
                            ->columnSpanFull(),

                        Group::make([
                            Select::make('payout_payee_type')->label('Payee')
                                ->options(['college' => 'College', 'other' => 'Other'])
                                ->default('college')->required(),
                            TextInput::make('payout_payee_name')->label('Payee name')
                                ->placeholder('College / party name')->maxLength(120),
                            TextInput::make('payout_amount')->label('Amount')->numeric()->prefix('₹')->required(),
                            Select::make('payout_status')->label('Status')
                                ->options(['to_pay' => 'To be paid', 'paid' => 'Paid'])
                                ->default('to_pay')->live()->required(),
                            DateTimePicker::make('payout_paid_at')->label('Paid on')
                                ->visible(fn (Get $get) => $get('payout_status') === 'paid'),
                            Textarea::make('payout_notes')->label('Notes')->rows(2)->columnSpanFull(),
                        ])
                            ->visible(fn (Get $get) => in_array($get('entry_action'), ['add_payout', 'update_payout'], true))
                            ->columns(['default' => 1, 'md' => 2])
                            ->columnSpanFull(),
                    ])
                    ->action(function (array $data, $livewire) {
                        $student = $livewire->getOwnerRecord();

                        $paymentAttrs = function (array $d): array {
                            $d = PaymentFormSchema::resolveProofUpload($d);

                            return Arr::only($d, ['type', 'amount', 'mode', 'reference_number', 'received_at', 'proof_url', 'notes']);
                        };
                        $payoutAttrs = fn (array $d): array => [
                            'payee_type' => $d['payout_payee_type'] ?? 'college',
                            'payee_name' => $d['payout_payee_name'] ?? null,
                            'amount'     => $d['payout_amount'] ?? 0,
                            'status'     => $d['payout_status'] ?? 'to_pay',
                            'paid_at'    => $d['payout_paid_at'] ?? null,
                            'notes'      => $d['payout_notes'] ?? null,
                        ];

                        switch ($data['entry_action']) {
                            case 'add_payment':
                                $student->payments()->create($paymentAttrs($data) + ['recorded_by_user_id' => auth()->id()]);
                                $title = 'Payment recorded';
                                break;
                            case 'update_payment':
                                Payment::findOrFail($data['payment_id'])->update($paymentAttrs($data));
                                $title = 'Payment updated';
                                break;
                            case 'add_payout':
                                $student->payouts()->create($payoutAttrs($data) + ['recorded_by_user_id' => auth()->id()]);
                                $title = 'Payout recorded';
                                break;
                            case 'update_payout':
                                Payout::findOrFail($data['payout_id'])->update($payoutAttrs($data));
                                $title = 'Payout updated';
                                break;
                            default:
                                $title = 'Saved';
                        }

                        Notification::make()->success()->title($title)->send();
                    }),
            ])
```

Leave the row `->actions([...])` (open_proof / EditAction / DeleteAction) and `->bulkActions([...])` unchanged.

- [ ] **Step 3: Update the existing PaymentsRelationManagerTest `create` calls**

The header action is no longer named `create`. In `tests/Feature/PaymentsRelationManagerTest.php`, find every `->callTableAction('create', data: [ ... ])` and change it to `->callTableAction('newPaymentPayout', data: [ 'entry_action' => 'add_payment', ... ])` (insert the `entry_action` key into the existing data array; keep the rest). Run `grep -n "callTableAction('create'" tests/Feature/PaymentsRelationManagerTest.php` to find them all. Re-run the file after editing:

Run: `php -d memory_limit=2G ./vendor/bin/phpunit tests/Feature/PaymentsRelationManagerTest.php`
Expected: PASS (all existing payment tests, now via the chooser).

- [ ] **Step 4: Write the chooser test**

Create `tests/Feature/PaymentPayoutChooserTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Filament\Resources\StudentResource\Pages\EditStudent;
use App\Filament\Resources\StudentResource\RelationManagers\PaymentsRelationManager;
use App\Models\Payment;
use App\Models\Payout;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class PaymentPayoutChooserTest extends TestCase
{
    use RefreshDatabase;

    private function student(User $sumit): Student
    {
        return Student::create([
            'phone' => '910000'.random_int(1000, 9999),
            'name' => 'ChooserTester',
            'owner_id' => $sumit->id,
            'referrer_id' => $sumit->id,
            'lead_source' => 'Sumit',
        ]);
    }

    private function panel(Student $student): \Livewire\Features\SupportTesting\Testable
    {
        return Livewire::test(PaymentsRelationManager::class, [
            'ownerRecord' => $student,
            'pageClass' => EditStudent::class,
        ]);
    }

    public function test_add_payment_creates_payment_with_recorder(): void
    {
        $this->seed();
        $sumit = User::where('email', 'sumit@davya.local')->firstOrFail();
        $this->actingAs($sumit);
        $student = $this->student($sumit);

        $this->panel($student)
            ->callTableAction('newPaymentPayout', data: [
                'entry_action' => 'add_payment',
                'type' => 'advance',
                'amount' => 10000,
                'mode' => 'cash',
                'received_at' => now()->toDateTimeString(),
            ])
            ->assertHasNoTableActionErrors();

        $this->assertEquals(1, $student->payments()->count());
        $this->assertEquals($sumit->id, $student->payments()->first()->recorded_by_user_id);
    }

    public function test_add_payout_creates_payout_with_recorder(): void
    {
        $this->seed();
        $sumit = User::where('email', 'sumit@davya.local')->firstOrFail();
        $this->actingAs($sumit);
        $student = $this->student($sumit);

        $this->panel($student)
            ->callTableAction('newPaymentPayout', data: [
                'entry_action' => 'add_payout',
                'payout_payee_type' => 'college',
                'payout_payee_name' => 'GGSIPU',
                'payout_amount' => 40000,
                'payout_status' => 'to_pay',
            ])
            ->assertHasNoTableActionErrors();

        $payout = $student->payouts()->first();
        $this->assertNotNull($payout);
        $this->assertEquals(40000.0, (float) $payout->amount);
        $this->assertEquals($sumit->id, $payout->recorded_by_user_id);
    }

    public function test_update_payment_updates_selected_record(): void
    {
        $this->seed();
        $sumit = User::where('email', 'sumit@davya.local')->firstOrFail();
        $this->actingAs($sumit);
        $student = $this->student($sumit);
        $payment = $student->payments()->create([
            'type' => 'advance', 'amount' => 10000, 'mode' => 'cash',
            'received_at' => now(), 'recorded_by_user_id' => $sumit->id,
        ]);

        $this->panel($student)
            ->callTableAction('newPaymentPayout', data: [
                'entry_action' => 'update_payment',
                'payment_id' => $payment->id,
                'type' => 'partial',
                'amount' => 25000,
                'mode' => 'upi',
                'received_at' => now()->toDateTimeString(),
            ])
            ->assertHasNoTableActionErrors();

        $this->assertEquals(25000.0, (float) $payment->fresh()->amount);
        $this->assertEquals('partial', $payment->fresh()->type);
    }

    public function test_update_payout_updates_selected_record(): void
    {
        $this->seed();
        $sumit = User::where('email', 'sumit@davya.local')->firstOrFail();
        $this->actingAs($sumit);
        $student = $this->student($sumit);
        $payout = Payout::factory()->create([
            'student_id' => $student->id, 'amount' => 30000,
            'payee_type' => 'college', 'status' => 'to_pay',
            'recorded_by_user_id' => $sumit->id,
        ]);

        $this->panel($student)
            ->callTableAction('newPaymentPayout', data: [
                'entry_action' => 'update_payout',
                'payout_id' => $payout->id,
                'payout_payee_type' => 'college',
                'payout_amount' => 55000,
                'payout_status' => 'paid',
                'payout_paid_at' => now()->toDateTimeString(),
            ])
            ->assertHasNoTableActionErrors();

        $fresh = $payout->fresh();
        $this->assertEquals(55000.0, (float) $fresh->amount);
        $this->assertEquals('paid', $fresh->status);
        $this->assertNotNull($fresh->paid_at);
    }
}
```

- [ ] **Step 5: Run the chooser test — expect pass**

Run: `php -d memory_limit=2G ./vendor/bin/phpunit tests/Feature/PaymentPayoutChooserTest.php`
Expected: PASS (4 tests). If a validation error surfaces (`assertHasNoTableActionErrors` fails), check that hidden field groups are excluded from validation — they should be, since `visible(false)` fields are not validated/dehydrated in Filament 3. Do not weaken assertions.

- [ ] **Step 6: Pint + commit**

```bash
./vendor/bin/pint app/Filament/Resources/StudentResource/RelationManagers/PaymentsRelationManager.php tests/Feature/PaymentsRelationManagerTest.php tests/Feature/PaymentPayoutChooserTest.php
git add app/Filament/Resources/StudentResource/RelationManagers/PaymentsRelationManager.php tests/Feature/PaymentsRelationManagerTest.php tests/Feature/PaymentPayoutChooserTest.php
git commit -m "feat(payments): unified payment/payout chooser on the Payments panel"
```

---

### Task 4: Money summary strip in the Stage section

**Files:**
- Create: `resources/views/filament/forms/student-money-summary.blade.php`
- Modify: `app/Filament/Resources/StudentResource.php` (Stage section, ~lines 148-152)
- Test: append to `tests/Feature/StudentFormRevertTest.php`

- [ ] **Step 1: Create the blade view**

Create `resources/views/filament/forms/student-money-summary.blade.php`:

```blade
@php($r = $getRecord())
@if ($r)
    @php($mf = \App\Support\MoneyFormat::class)
    <div class="mt-3 grid grid-cols-2 md:grid-cols-5 gap-2" data-testid="student-money-summary">
        <div class="davya-books-kpi">
            <div class="davya-books-kpi__label">Deal</div>
            {!! $mf::asInlineHtml((float) $r->deal_amount) !!}
        </div>
        <div class="davya-books-kpi">
            <div class="davya-books-kpi__label">Received</div>
            {!! $mf::asInlineHtml((float) $r->total_received) !!}
        </div>
        <div class="davya-books-kpi">
            <div class="davya-books-kpi__label">Pending</div>
            {!! $mf::asInlineHtml((float) $r->pending_amount, $r->pending_amount > 0) !!}
        </div>
        <div class="davya-books-kpi">
            <div class="davya-books-kpi__label">Payouts</div>
            {!! $mf::asInlineHtml((float) $r->total_payouts) !!}
        </div>
        <div class="davya-books-kpi">
            <div class="davya-books-kpi__label">Expected profit</div>
            {!! $mf::asInlineHtml((float) $r->expected_profit, $r->expected_profit < 0) !!}
        </div>
    </div>
@endif
```

(`MoneyFormat::asInlineHtml(float $amount, bool $danger = false, bool $inline = false)` — the second arg drives danger color. `davya-books-kpi` tiles + `__label` are the existing KPI styles used on the Payment Report and Books.)

- [ ] **Step 2: Embed the view in the Stage section**

In `app/Filament/Resources/StudentResource.php`, change the Stage `Section::make('Stage')` schema from `->schema([$stageField])` to:

```php
                ->schema([
                    $stageField,
                    View::make('filament.forms.student-money-summary')
                        ->visible(fn ($record) => $record !== null)
                        ->columnSpanFull(),
                ])
```

(`View` is already imported. Confirm with `grep -n "use Filament\\\\Forms\\\\Components\\\\View;" app/Filament/Resources/StudentResource.php`.)

- [ ] **Step 3: Add the test**

Append to `tests/Feature/StudentFormRevertTest.php`:

```php
    public function test_stage_section_shows_money_summary_on_existing_student(): void
    {
        $this->seed();
        $sumit = User::where('email', 'sumit@davya.local')->firstOrFail();
        $this->actingAs($sumit);

        $student = Student::create([
            'phone' => '9100000066', 'name' => 'SummaryTester', 'deal_amount' => 100000,
            'owner_id' => $sumit->id, 'referrer_id' => $sumit->id, 'lead_source' => 'Sumit',
        ]);
        $student->payouts()->create([
            'payee_type' => 'college', 'amount' => 30000, 'status' => 'to_pay',
            'recorded_by_user_id' => $sumit->id,
        ]);

        Livewire::test(EditStudent::class, ['record' => $student->getRouteKey()])
            ->assertSuccessful()
            ->assertSee('Expected profit')
            ->assertSeeHtml('data-testid="student-money-summary"');
    }
```

- [ ] **Step 4: Run the test — expect pass**

Run: `php -d memory_limit=2G ./vendor/bin/phpunit tests/Feature/StudentFormRevertTest.php`
Expected: PASS (3 tests now). If `assertSee('Expected profit')` fails because the view isn't rendered into the Livewire HTML, confirm the Stage `View::make` was added and the record is non-null.

- [ ] **Step 5: Pint + commit**

```bash
./vendor/bin/pint app/Filament/Resources/StudentResource.php tests/Feature/StudentFormRevertTest.php
git add resources/views/filament/forms/student-money-summary.blade.php app/Filament/Resources/StudentResource.php tests/Feature/StudentFormRevertTest.php
git commit -m "feat(students): money summary strip in the Stage section"
```

---

### Task 5: Full-suite verification

- [ ] **Step 1: Run the entire suite**

Run: `php -d memory_limit=2G ./vendor/bin/phpunit`
Expected: 0 failures. New tests added (StudentFormRevert ×3, PayoutsRelationManager ×1, PaymentPayoutChooser ×4) plus the existing suite; 1 pre-existing skip; the 4 PHP/PHPUnit deprecations are pre-existing and expected. If any OTHER test referenced the removed Deal-tab repeater or Account `addPayment`, update it to the new contract and re-run.

- [ ] **Step 2: Pint sweep**

Run: `./vendor/bin/pint` (whole project) and commit any residual formatting:
`git add -A && git commit -m "style: pint"` (skip if clean).

- [ ] **Step 3: Deploy (Sumit / pre-deploy gate)**

Do NOT deploy without Sumit's go-ahead. When approved: SSH `ssh -i ~/.ssh/davyas-active ipuc@ipu.co.in` → `cd ~/davya-crm` → `git pull` → `composer install --no-dev --optimize-autoloader` → `php artisan migrate --force` (none expected) → 3 rank seeders (idempotent) → `config:cache && route:cache && view:cache`. **New Filament relation-manager class `PayoutsRelationManager`** — per `reference_hostinger_fpm_opcache`, verify in-browser that the Payouts panel renders (route/view cache rebuild usually suffices; if the panel 404s/blanks, trigger the cPanel FPM toggle). curl-verify `/admin/students=302`, `/admin/login=200`.

---

## Self-Review

**Spec coverage:**
- Chooser on Payments-panel header (ToggleButtons + conditional groups, single submit) → Task 3 ✓
- Add/Update Payment + Add/Update Payout branches → Task 3 `->action()` switch ✓
- Update via record dropdown (payment_id/payout_id + afterStateUpdated load) → Task 3 ✓
- Remove Deal-tab repeater + placeholder; keep plan options → Task 1 ✓
- Remove Account "+ New Payment", keep New Note → Task 1 ✓
- New Payouts relation-manager tab (no create button, row edit/delete) → Task 2 ✓
- Stage-section money summary (Deal·Received·Pending·Payouts·Profit, existing students only) → Task 4 ✓
- No field-name collisions (`entry_action` toggle, `payout_*` payout fields) → Task 3 ✓
- Tests for all four branches + panels + form mount → Tasks 1-3 ✓
- Existing `create`-action tests updated → Task 3 Step 3 ✓
- No migrations; FPM opcache caveat for new RM class → Task 4 Step 3 ✓

**Placeholder scan:** no TBD/TODO; full code in every code step. Import-removal (Task 1 Step 5) and the existing-test update (Task 3 Step 3) are grep-guided edits over a small known set, with exact symbols listed — not vague.

**Type/name consistency:** chooser field `entry_action`; record pickers `payment_id`/`payout_id`; payout fields `payout_payee_type|payout_payee_name|payout_amount|payout_status|payout_paid_at|payout_notes` consistently used in the form, `afterStateUpdated`, and `payoutAttrs`; payment columns pulled via `Arr::only(['type','amount','mode','reference_number','received_at','proof_url','notes'])` match `PaymentFormSchema` outputs (`proof_upload` stripped by `resolveProofUpload`). Action name `newPaymentPayout` consistent across implementation + all tests.
