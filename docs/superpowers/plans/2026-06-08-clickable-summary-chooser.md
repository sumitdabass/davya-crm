# Clickable Stage Summary → Add/Update/Delete Chooser Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Move the payment/payout chooser to a page action on EditStudent (extended with Delete → 6 modes), make the Stage money-summary line click to open it, and remove the chooser button from the Payments panel.

**Architecture:** A shared builder `App\Filament\Support\PaymentPayoutChooser::make()` returns a `Filament\Actions\Action` (page-action namespace). `EditStudent::getHeaderActions()` registers it. The summary blade wraps its line in `<button wire:click="mountAction('newPaymentPayout')">`. The relation-manager header chooser is removed.

**Tech Stack:** Laravel 11, Filament 3, PHPUnit. Spec: `docs/superpowers/specs/2026-06-08-clickable-summary-chooser-design.md`.

**Baseline:** suite 900 passing / 1 skipped. Run `php -d memory_limit=2G ./vendor/bin/phpunit`; single file with the path appended. Don't use `php artisan test` (128M child cap).

---

### Task 1: Shared chooser builder (6 modes) + EditStudent wiring + remove RM chooser + tests

**Files:**
- Create: `app/Filament/Support/PaymentPayoutChooser.php`
- Modify: `app/Filament/Resources/StudentResource/Pages/EditStudent.php`
- Modify: `app/Filament/Resources/StudentResource/RelationManagers/PaymentsRelationManager.php`
- Rewrite: `tests/Feature/PaymentPayoutChooserTest.php`
- Modify: `tests/Feature/PaymentsRelationManagerTest.php`

- [ ] **Step 1: Create the shared builder**

Create `app/Filament/Support/PaymentPayoutChooser.php`. This is the existing relation-manager chooser moved to the page-action namespace (`Filament\Actions\Action`), with `$livewire->getRecord()` (the EditStudent record) instead of `getOwnerRecord()`, plus `delete_payment`/`delete_payout` modes:

```php
<?php

namespace App\Filament\Support;

use App\Filament\Resources\Shared\PaymentFormSchema;
use App\Models\Payment;
use App\Models\Payout;
use Filament\Actions\Action;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Group;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\ToggleButtons;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Notifications\Notification;
use Illuminate\Support\Arr;

class PaymentPayoutChooser
{
    public static function make(): Action
    {
        return Action::make('newPaymentPayout')
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
                        'delete_payment' => 'Delete Payment',
                        'add_payout'     => 'Add Payout',
                        'update_payout'  => 'Update Payout',
                        'delete_payout'  => 'Delete Payout',
                    ])
                    ->icons([
                        'add_payment'    => 'heroicon-o-plus',
                        'update_payment' => 'heroicon-o-pencil-square',
                        'delete_payment' => 'heroicon-o-trash',
                        'add_payout'     => 'heroicon-o-plus',
                        'update_payout'  => 'heroicon-o-pencil-square',
                        'delete_payout'  => 'heroicon-o-trash',
                    ])
                    ->inline()
                    ->live()
                    ->required()
                    ->default('add_payment')
                    ->columnSpanFull(),

                Select::make('payment_id')
                    ->label('Which payment?')
                    ->options(fn ($livewire) => $livewire->getRecord()->payments()
                        ->latest('received_at')->get()
                        ->mapWithKeys(fn ($p) => [$p->id => '₹'.number_format((float) $p->amount, 0).' · '.$p->type.' · '.$p->received_at?->format('d M Y')])
                        ->all())
                    ->live()
                    ->required()
                    ->visible(fn (Get $get) => in_array($get('entry_action'), ['update_payment', 'delete_payment'], true))
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
                    ->options(fn ($livewire) => $livewire->getRecord()->payouts()
                        ->latest()->get()
                        ->mapWithKeys(fn ($po) => [$po->id => '₹'.number_format((float) $po->amount, 0).' · '.ucfirst($po->payee_type).' · '.$po->created_at?->format('d M Y')])
                        ->all())
                    ->live()
                    ->required()
                    ->visible(fn (Get $get) => in_array($get('entry_action'), ['update_payout', 'delete_payout'], true))
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

                Placeholder::make('delete_warning')
                    ->label('')
                    ->content('This permanently deletes the selected record.')
                    ->visible(fn (Get $get) => in_array($get('entry_action'), ['delete_payment', 'delete_payout'], true))
                    ->columnSpanFull(),

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
                $student = $livewire->getRecord();

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
                    case 'delete_payment':
                        Payment::findOrFail($data['payment_id'])->delete();
                        $title = 'Payment deleted';
                        break;
                    case 'add_payout':
                        $student->payouts()->create($payoutAttrs($data) + ['recorded_by_user_id' => auth()->id()]);
                        $title = 'Payout recorded';
                        break;
                    case 'update_payout':
                        Payout::findOrFail($data['payout_id'])->update($payoutAttrs($data));
                        $title = 'Payout updated';
                        break;
                    case 'delete_payout':
                        Payout::findOrFail($data['payout_id'])->delete();
                        $title = 'Payout deleted';
                        break;
                    default:
                        $title = 'Saved';
                }

                Notification::make()->success()->title($title)->send();
            });
    }
}
```

- [ ] **Step 2: Register the action on EditStudent**

In `app/Filament/Resources/StudentResource/Pages/EditStudent.php`, add `use App\Filament\Support\PaymentPayoutChooser;`, and change `getHeaderActions()` to:

```php
    protected function getHeaderActions(): array
    {
        return [
            PaymentPayoutChooser::make(),
            Actions\DeleteAction::make(),
        ];
    }
```

- [ ] **Step 3: Remove the chooser from the Payments relation manager**

In `app/Filament/Resources/StudentResource/RelationManagers/PaymentsRelationManager.php`, delete the entire `->headerActions([... Tables\Actions\Action::make('newPaymentPayout') ...])` block (lines ~47-184). Leave `->columns([...])`, `->actions([...])`, `->bulkActions([...])` intact. Then remove imports that are now unused (grep each): `Payment`, `Payout`, `DateTimePicker`, `Group`, `Select`, `TextInput`, `Textarea`, `ToggleButtons`, `Filament\Forms\Get`, `Filament\Forms\Set`, `Notification`, `Arr`. KEEP `use App\Filament\Resources\Shared\PaymentFormSchema;` (still used by `form()`). Run `php -l app/Filament/Resources/StudentResource/RelationManagers/PaymentsRelationManager.php` → "No syntax errors".

- [ ] **Step 4: Rewrite the chooser test to drive the page action**

Replace `tests/Feature/PaymentPayoutChooserTest.php` entirely with (page action via `callAction`, all 6 branches + 2 proof scenarios ported from the old PaymentsRelationManagerTest):

```php
<?php

namespace Tests\Feature;

use App\Filament\Resources\StudentResource\Pages\EditStudent;
use App\Models\Payment;
use App\Models\Payout;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class PaymentPayoutChooserTest extends TestCase
{
    use RefreshDatabase;

    private function studentFor(User $sumit): Student
    {
        return Student::create([
            'phone' => '910000'.random_int(1000, 9999),
            'name' => 'ChooserTester',
            'owner_id' => $sumit->id,
            'referrer_id' => $sumit->id,
            'lead_source' => 'Sumit',
        ]);
    }

    private function edit(Student $student)
    {
        return Livewire::test(EditStudent::class, ['record' => $student->getRouteKey()]);
    }

    public function test_add_payment_creates_payment_with_recorder(): void
    {
        $this->seed();
        $sumit = User::where('email', 'sumit@davya.local')->firstOrFail();
        $this->actingAs($sumit);
        $student = $this->studentFor($sumit);

        $this->edit($student)
            ->callAction('newPaymentPayout', data: [
                'entry_action' => 'add_payment',
                'type' => 'advance', 'amount' => 10000, 'mode' => 'cash',
                'received_at' => now()->toDateTimeString(),
            ])
            ->assertHasNoActionErrors();

        $this->assertEquals(1, $student->payments()->count());
        $this->assertEquals($sumit->id, $student->payments()->first()->recorded_by_user_id);
    }

    public function test_add_payment_with_file_upload_resolves_proof_url(): void
    {
        Storage::fake('drive');
        $this->seed();
        $sumit = User::where('email', 'sumit@davya.local')->firstOrFail();
        $this->actingAs($sumit);
        $student = $this->studentFor($sumit);

        $this->edit($student)
            ->callAction('newPaymentPayout', data: [
                'entry_action' => 'add_payment',
                'type' => 'advance', 'amount' => 2500,
                'received_at' => now()->toDateTimeString(),
                'proof_upload' => [UploadedFile::fake()->image('proof.png')],
            ])
            ->assertHasNoActionErrors();

        $payment = $student->payments()->latest('id')->first();
        $this->assertNotNull($payment);
        $this->assertStringContainsString('payment-proofs/', (string) $payment->proof_url);
    }

    public function test_add_payment_url_fallback_persists_proof_url(): void
    {
        Storage::fake('drive');
        $this->seed();
        $sumit = User::where('email', 'sumit@davya.local')->firstOrFail();
        $this->actingAs($sumit);
        $student = $this->studentFor($sumit);

        $this->edit($student)
            ->callAction('newPaymentPayout', data: [
                'entry_action' => 'add_payment',
                'type' => 'advance', 'amount' => 1500,
                'received_at' => now()->toDateTimeString(),
                'proof_url' => 'https://drive.google.com/file/d/manual-url/view',
            ])
            ->assertHasNoActionErrors();

        $this->assertSame(
            'https://drive.google.com/file/d/manual-url/view',
            $student->payments()->latest('id')->first()->proof_url
        );
    }

    public function test_add_payout_creates_payout_with_recorder(): void
    {
        $this->seed();
        $sumit = User::where('email', 'sumit@davya.local')->firstOrFail();
        $this->actingAs($sumit);
        $student = $this->studentFor($sumit);

        $this->edit($student)
            ->callAction('newPaymentPayout', data: [
                'entry_action' => 'add_payout',
                'payout_payee_type' => 'college', 'payout_payee_name' => 'GGSIPU',
                'payout_amount' => 40000, 'payout_status' => 'to_pay',
            ])
            ->assertHasNoActionErrors();

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
        $student = $this->studentFor($sumit);
        $payment = $student->payments()->create([
            'type' => 'advance', 'amount' => 10000, 'mode' => 'cash',
            'received_at' => now(), 'recorded_by_user_id' => $sumit->id,
        ]);

        $this->edit($student)
            ->callAction('newPaymentPayout', data: [
                'entry_action' => 'update_payment', 'payment_id' => $payment->id,
                'type' => 'partial', 'amount' => 25000, 'mode' => 'upi',
                'received_at' => now()->toDateTimeString(),
            ])
            ->assertHasNoActionErrors();

        $this->assertEquals(25000.0, (float) $payment->fresh()->amount);
        $this->assertEquals('partial', $payment->fresh()->type);
    }

    public function test_delete_payment_removes_selected_record(): void
    {
        $this->seed();
        $sumit = User::where('email', 'sumit@davya.local')->firstOrFail();
        $this->actingAs($sumit);
        $student = $this->studentFor($sumit);
        $payment = $student->payments()->create([
            'type' => 'advance', 'amount' => 10000, 'mode' => 'cash',
            'received_at' => now(), 'recorded_by_user_id' => $sumit->id,
        ]);

        $this->edit($student)
            ->callAction('newPaymentPayout', data: [
                'entry_action' => 'delete_payment', 'payment_id' => $payment->id,
            ])
            ->assertHasNoActionErrors();

        $this->assertDatabaseMissing('payments', ['id' => $payment->id]);
    }

    public function test_update_payout_updates_selected_record(): void
    {
        $this->seed();
        $sumit = User::where('email', 'sumit@davya.local')->firstOrFail();
        $this->actingAs($sumit);
        $student = $this->studentFor($sumit);
        $payout = Payout::factory()->create([
            'student_id' => $student->id, 'amount' => 30000,
            'payee_type' => 'college', 'status' => 'to_pay',
            'recorded_by_user_id' => $sumit->id,
        ]);

        $this->edit($student)
            ->callAction('newPaymentPayout', data: [
                'entry_action' => 'update_payout', 'payout_id' => $payout->id,
                'payout_payee_type' => 'college', 'payout_amount' => 55000,
                'payout_status' => 'paid', 'payout_paid_at' => now()->toDateTimeString(),
            ])
            ->assertHasNoActionErrors();

        $fresh = $payout->fresh();
        $this->assertEquals(55000.0, (float) $fresh->amount);
        $this->assertEquals('paid', $fresh->status);
    }

    public function test_delete_payout_removes_selected_record(): void
    {
        $this->seed();
        $sumit = User::where('email', 'sumit@davya.local')->firstOrFail();
        $this->actingAs($sumit);
        $student = $this->studentFor($sumit);
        $payout = Payout::factory()->create([
            'student_id' => $student->id, 'amount' => 30000,
            'recorded_by_user_id' => $sumit->id,
        ]);

        $this->edit($student)
            ->callAction('newPaymentPayout', data: [
                'entry_action' => 'delete_payout', 'payout_id' => $payout->id,
            ])
            ->assertHasNoActionErrors();

        $this->assertDatabaseMissing('payouts', ['id' => $payout->id]);
    }
}
```

- [ ] **Step 5: Reconcile PaymentsRelationManagerTest**

The relation manager no longer has the `newPaymentPayout` action; its 3 tests (`test_create_payment_defaults_recorded_by_user_id_to_current_user`, `test_payments_tab_accepts_file_upload_and_resolves_to_proof_url`, `test_payments_tab_url_fallback_still_persists_proof_url_unchanged`) are obsolete (their coverage is now in the page chooser test). Replace ALL THREE methods with a single method (keep the file's existing `use` imports; remove any that become unused like `UploadedFile`/`Storage` only if unreferenced):

```php
    public function test_payments_panel_has_no_create_chooser_button(): void
    {
        $this->seed();
        $sumit = User::where('email', 'sumit@davya.local')->firstOrFail();
        $student = Student::create([
            'phone' => '9100000300', 'name' => 'NoChooser',
            'owner_id' => $sumit->id, 'referrer_id' => $sumit->id, 'lead_source' => 'Sumit',
        ]);
        $this->actingAs($sumit);

        Livewire::test(PaymentsRelationManager::class, [
            'ownerRecord' => $student,
            'pageClass' => EditStudent::class,
        ])
            ->assertSuccessful()
            ->assertTableActionDoesNotExist('newPaymentPayout');
    }
```

If `assertTableActionDoesNotExist` is unavailable for header actions in this Filament version, use `->assertDontSee('New payment / payout')` instead. Run the file:

Run: `php -d memory_limit=2G ./vendor/bin/phpunit tests/Feature/PaymentsRelationManagerTest.php`
Expected: PASS (1 test).

- [ ] **Step 6: Run the chooser test**

Run: `php -d memory_limit=2G ./vendor/bin/phpunit tests/Feature/PaymentPayoutChooserTest.php`
Expected: PASS (8 tests). If `assertHasNoActionErrors`/`callAction` names differ, note: page actions use `callAction()` + `assertHasNoActionErrors()` (NOT the `callTableAction`/`assertHasNoTableActionErrors` used for relation managers). If a payout-mode submit trips payment-field validation, confirm hidden groups are excluded (they are in Filament 3). Do not weaken assertions.

- [ ] **Step 7: Pint + commit**

```bash
./vendor/bin/pint app/Filament/Support/PaymentPayoutChooser.php app/Filament/Resources/StudentResource/Pages/EditStudent.php app/Filament/Resources/StudentResource/RelationManagers/PaymentsRelationManager.php tests/Feature/PaymentPayoutChooserTest.php tests/Feature/PaymentsRelationManagerTest.php
git add app/Filament/Support/PaymentPayoutChooser.php app/Filament/Resources/StudentResource/Pages/EditStudent.php app/Filament/Resources/StudentResource/RelationManagers/PaymentsRelationManager.php tests/Feature/PaymentPayoutChooserTest.php tests/Feature/PaymentsRelationManagerTest.php
git commit -m "feat(students): move chooser to EditStudent page action + add delete modes"
```

---

### Task 2: Make the Stage summary clickable

**Files:**
- Modify: `resources/views/filament/forms/student-money-summary.blade.php`
- Test: append to `tests/Feature/StudentFormRevertTest.php`

- [ ] **Step 1: Wrap the summary line in a mount-action button**

Edit `resources/views/filament/forms/student-money-summary.blade.php` — wrap the existing `<div ... data-testid="student-money-summary">...</div>` content in a `<button>` that mounts the page action, keeping the colored segments. Full new file:

```blade
@php($r = $getRecord())
@if ($r)
    @php($mf = \App\Support\MoneyFormat::class)
    @php($fmt = fn ($v) => ((float) $v < 0 ? '-₹' : '₹') . $mf::indianShort(abs((float) $v)))
    <button type="button"
            wire:click="mountAction('newPaymentPayout')"
            class="text-sm text-gray-500 dark:text-gray-400 text-left hover:opacity-70 transition cursor-pointer"
            style="margin-top:4px; line-height:1.5;"
            title="Click to add / update / delete a payment or payout"
            data-testid="student-money-summary">
        <span>{{ $fmt($r->deal_amount) }} deal</span>
        <span class="text-gray-300 dark:text-gray-600"> · </span>
        <span>{{ $fmt($r->total_received) }} received</span>
        <span class="text-gray-300 dark:text-gray-600"> · </span>
        <span @style(['color:var(--warning,#D97706)' => $r->pending_amount > 0])>{{ $fmt($r->pending_amount) }} pending</span>
        <span class="text-gray-300 dark:text-gray-600"> · </span>
        <span>{{ $fmt($r->total_payouts) }} payouts</span>
        <span class="text-gray-300 dark:text-gray-600"> · </span>
        <span @style(['color:var(--danger,#DC2626)' => $r->expected_profit < 0])>{{ $fmt($r->expected_profit) }} profit</span>
    </button>
@endif
```

- [ ] **Step 2: Add the assertion**

Append to `tests/Feature/StudentFormRevertTest.php` (reuses existing imports):

```php
    public function test_stage_summary_is_clickable_to_open_chooser(): void
    {
        $this->seed();
        $sumit = User::where('email', 'sumit@davya.local')->firstOrFail();
        $this->actingAs($sumit);

        $student = Student::create([
            'phone' => '9100000055', 'name' => 'ClickSummary', 'deal_amount' => 100000,
            'owner_id' => $sumit->id, 'referrer_id' => $sumit->id, 'lead_source' => 'Sumit',
        ]);

        Livewire::test(EditStudent::class, ['record' => $student->getRouteKey()])
            ->assertSuccessful()
            ->assertSeeHtml('wire:click="mountAction(\'newPaymentPayout\')"');
    }
```

- [ ] **Step 3: Run the test**

Run: `php -d memory_limit=2G ./vendor/bin/phpunit tests/Feature/StudentFormRevertTest.php`
Expected: PASS (4 tests — the existing 3 + this one). The existing `test_stage_section_shows_money_summary_on_existing_student` still passes (the `data-testid` + `profit`/`received` text survive inside the button).

- [ ] **Step 4: Pint + commit**

```bash
./vendor/bin/pint resources/views/filament/forms/student-money-summary.blade.php tests/Feature/StudentFormRevertTest.php
git add resources/views/filament/forms/student-money-summary.blade.php tests/Feature/StudentFormRevertTest.php
git commit -m "feat(students): Stage money summary opens the chooser on click"
```

---

### Task 3: Full-suite verification

- [ ] **Step 1: Run the entire suite**

Run: `php -d memory_limit=2G ./vendor/bin/phpunit`
Expected: 0 failures, 1 pre-existing skip, 4 pre-existing deprecations. Net test count shifts (PaymentPayoutChooserTest now 8; PaymentsRelationManagerTest down to 1; StudentFormRevertTest 4). If any other test referenced the removed RM header chooser, update it.

- [ ] **Step 2: Pint sweep + commit residue**

Run: `./vendor/bin/pint` ; `git add -A && git commit -m "style: pint"` (skip if clean).

- [ ] **Step 3: Deploy (Sumit / pre-deploy gate)**

Do NOT deploy without Sumit's go-ahead. When approved: SSH `ssh -i ~/.ssh/davyas-active ipuc@ipu.co.in` → `cd ~/davya-crm` → `git pull` → `composer install --no-dev --optimize-autoloader` → `php artisan migrate --force` (none) → `config:cache && route:cache && view:cache`. **New class `App\Filament\Support\PaymentPayoutChooser`** — per `reference_hostinger_fpm_opcache`, verify in-browser: open a student edit page, click the Stage summary → chooser modal opens, exercise add/update/delete for payment+payout; if the action 404s/blanks trigger the cPanel FPM toggle. curl-verify `/admin/students=302`, `/admin/login=200`.

---

## Self-Review

**Spec coverage:**
- Chooser moved to EditStudent page action (`Filament\Actions\Action`, `$livewire->getRecord()`) → Task 1 Steps 1-2 ✓
- 6 modes incl Delete (record-picker only, delete on submit, warning placeholder) → Task 1 Step 1 ✓
- Remove RM header chooser + unused imports → Task 1 Step 3 ✓
- Clickable summary via `wire:click="mountAction('newPaymentPayout')"` (keeps colors) → Task 2 ✓
- Header button also present (page action renders in header) → Task 1 Step 2 ✓
- Tests: 6 branches + 2 proof scenarios on the page action; summary-button render; RM no-chooser → Tasks 1-2 ✓
- No coverage lost (proof tests ported) → Task 1 Steps 4-5 ✓
- No migrations; FPM caveat for new class → Task 3 Step 3 ✓

**Placeholder scan:** no TBD/TODO; full code in every code step. Import removal (Task 1 Step 3) and the RM-test replacement (Step 5) name exact symbols + provide the full replacement method.

**Type/name consistency:** action key `newPaymentPayout` consistent across builder, EditStudent, summary `wire:click`, and all tests; `entry_action` 6 values consistent in toggle options, visibility predicates, and the submit switch; payout fields `payout_*` consistent in form + `afterStateUpdated` + `payoutAttrs`; page-action test API (`callAction`/`assertHasNoActionErrors`) used throughout the rewritten chooser test (not the relation-manager `callTableAction`/`assertHasNoTableActionErrors`).
