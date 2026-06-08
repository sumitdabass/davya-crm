# Per-Segment Clickable Summary Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Split the combined chooser into three scoped, hidden page actions (`editDeal`, `managePayment`, `managePayout`) and make each Stage-summary segment click to its own action; `pending`/`profit` stay plain text; remove the old combined button.

**Architecture:** `App\Filament\Support\PaymentPayoutChooser` exposes three static builders returning `Filament\Actions\Action`. EditStudent registers all three `->hidden()` (mountable but no buttons — Filament v3 `mountAction` only gates on not-found/disabled, not hidden). The summary blade mounts them via per-segment `wire:click`.

**Tech Stack:** Laravel 11, Filament 3.3.50, PHPUnit. Spec: `docs/superpowers/specs/2026-06-08-per-segment-summary-actions-design.md`.

**Baseline:** suite 903 passing / 1 skipped. Run `php -d memory_limit=2G ./vendor/bin/phpunit`; single file with path appended. Don't use `php artisan test` (128M child cap).

---

### Task 1: Three scoped builders + EditStudent wiring + rewrite chooser test

**Files:**
- Rewrite: `app/Filament/Support/PaymentPayoutChooser.php`
- Modify: `app/Filament/Resources/StudentResource/Pages/EditStudent.php`
- Rewrite: `tests/Feature/PaymentPayoutChooserTest.php`

- [ ] **Step 1: Replace the builder with three actions**

Replace the ENTIRE body of `app/Filament/Support/PaymentPayoutChooser.php` with:

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
    public static function dealAction(): Action
    {
        return Action::make('editDeal')
            ->label('Edit deal amount')
            ->modalHeading('Edit deal amount')
            ->modalWidth('sm')
            ->modalSubmitActionLabel('Save')
            ->fillForm(fn ($livewire) => ['deal_amount' => $livewire->getRecord()->deal_amount])
            ->form([
                TextInput::make('deal_amount')->label('Deal amount')->numeric()->prefix('₹'),
            ])
            ->action(function (array $data, $livewire) {
                $livewire->getRecord()->update(['deal_amount' => $data['deal_amount']]);
                Notification::make()->success()->title('Deal amount updated')->send();
            });
    }

    public static function paymentAction(): Action
    {
        return Action::make('managePayment')
            ->label('Payment')
            ->modalHeading('Payment')
            ->modalWidth('xl')
            ->modalSubmitActionLabel('Save')
            ->form([
                ToggleButtons::make('entry_action')
                    ->label('What do you want to do?')
                    ->options(['add' => 'Add', 'update' => 'Update', 'delete' => 'Delete'])
                    ->icons(['add' => 'heroicon-o-plus', 'update' => 'heroicon-o-pencil-square', 'delete' => 'heroicon-o-trash'])
                    ->inline()->live()->required()->default('add')->columnSpanFull(),

                Select::make('payment_id')
                    ->label('Which payment?')
                    ->options(fn ($livewire) => $livewire->getRecord()->payments()
                        ->latest('received_at')->get()
                        ->mapWithKeys(fn ($p) => [$p->id => '₹'.number_format((float) $p->amount, 0).' · '.$p->type.' · '.$p->received_at?->format('d M Y')])
                        ->all())
                    ->live()->required()
                    ->visible(fn (Get $get) => in_array($get('entry_action'), ['update', 'delete'], true))
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

                Placeholder::make('delete_warning')->label('')
                    ->content('This permanently deletes the selected payment.')
                    ->visible(fn (Get $get) => $get('entry_action') === 'delete')
                    ->columnSpanFull(),

                Group::make(PaymentFormSchema::fields(inlineFirstPayment: false))
                    ->visible(fn (Get $get) => in_array($get('entry_action'), ['add', 'update'], true))
                    ->columns(['default' => 1, 'md' => 2])
                    ->columnSpanFull(),
            ])
            ->action(function (array $data, $livewire) {
                $student = $livewire->getRecord();
                $attrs = function (array $d): array {
                    $d = PaymentFormSchema::resolveProofUpload($d);

                    return Arr::only($d, ['type', 'amount', 'mode', 'reference_number', 'received_at', 'proof_url', 'notes']);
                };

                switch ($data['entry_action']) {
                    case 'add':
                        $student->payments()->create($attrs($data) + ['recorded_by_user_id' => auth()->id()]);
                        $title = 'Payment recorded';
                        break;
                    case 'update':
                        Payment::findOrFail($data['payment_id'])->update($attrs($data));
                        $title = 'Payment updated';
                        break;
                    case 'delete':
                        Payment::findOrFail($data['payment_id'])->delete();
                        $title = 'Payment deleted';
                        break;
                    default:
                        $title = 'Saved';
                }

                Notification::make()->success()->title($title)->send();
            });
    }

    public static function payoutAction(): Action
    {
        return Action::make('managePayout')
            ->label('Payout')
            ->modalHeading('Payout')
            ->modalWidth('xl')
            ->modalSubmitActionLabel('Save')
            ->form([
                ToggleButtons::make('entry_action')
                    ->label('What do you want to do?')
                    ->options(['add' => 'Add', 'update' => 'Update', 'delete' => 'Delete'])
                    ->icons(['add' => 'heroicon-o-plus', 'update' => 'heroicon-o-pencil-square', 'delete' => 'heroicon-o-trash'])
                    ->inline()->live()->required()->default('add')->columnSpanFull(),

                Select::make('payout_id')
                    ->label('Which payout?')
                    ->options(fn ($livewire) => $livewire->getRecord()->payouts()
                        ->latest()->get()
                        ->mapWithKeys(fn ($po) => [$po->id => '₹'.number_format((float) $po->amount, 0).' · '.ucfirst($po->payee_type).' · '.$po->created_at?->format('d M Y')])
                        ->all())
                    ->live()->required()
                    ->visible(fn (Get $get) => in_array($get('entry_action'), ['update', 'delete'], true))
                    ->afterStateUpdated(function ($state, Set $set) {
                        $po = Payout::find($state);
                        if (! $po) {
                            return;
                        }
                        $set('payee_type', $po->payee_type);
                        $set('payee_name', $po->payee_name);
                        $set('amount', $po->amount);
                        $set('status', $po->status);
                        $set('paid_at', $po->paid_at);
                        $set('notes', $po->notes);
                    }),

                Placeholder::make('delete_warning')->label('')
                    ->content('This permanently deletes the selected payout.')
                    ->visible(fn (Get $get) => $get('entry_action') === 'delete')
                    ->columnSpanFull(),

                Group::make([
                    Select::make('payee_type')->label('Payee')
                        ->options(['college' => 'College', 'other' => 'Other'])->default('college')->required(),
                    TextInput::make('payee_name')->label('Payee name')->placeholder('College / party name')->maxLength(120),
                    TextInput::make('amount')->numeric()->prefix('₹')->required(),
                    Select::make('status')->options(['to_pay' => 'To be paid', 'paid' => 'Paid'])->default('to_pay')->live()->required(),
                    DateTimePicker::make('paid_at')->label('Paid on')
                        ->visible(fn (Get $get) => $get('status') === 'paid'),
                    Textarea::make('notes')->rows(2)->columnSpanFull(),
                ])
                    ->visible(fn (Get $get) => in_array($get('entry_action'), ['add', 'update'], true))
                    ->columns(['default' => 1, 'md' => 2])
                    ->columnSpanFull(),
            ])
            ->action(function (array $data, $livewire) {
                $student = $livewire->getRecord();
                $attrs = fn (array $d): array => Arr::only($d, ['payee_type', 'payee_name', 'amount', 'status', 'paid_at', 'notes']);

                switch ($data['entry_action']) {
                    case 'add':
                        $student->payouts()->create($attrs($data) + ['recorded_by_user_id' => auth()->id()]);
                        $title = 'Payout recorded';
                        break;
                    case 'update':
                        Payout::findOrFail($data['payout_id'])->update($attrs($data));
                        $title = 'Payout updated';
                        break;
                    case 'delete':
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

- [ ] **Step 2: Wire the three hidden actions into EditStudent**

In `app/Filament/Resources/StudentResource/Pages/EditStudent.php`, change `getHeaderActions()` to:

```php
    protected function getHeaderActions(): array
    {
        return [
            PaymentPayoutChooser::dealAction()->hidden(),
            PaymentPayoutChooser::paymentAction()->hidden(),
            PaymentPayoutChooser::payoutAction()->hidden(),
            Actions\DeleteAction::make(),
        ];
    }
```

(`use App\Filament\Support\PaymentPayoutChooser;` is already imported from the prior task.)

- [ ] **Step 3: Rewrite the chooser test for the three actions**

Replace the ENTIRE contents of `tests/Feature/PaymentPayoutChooserTest.php` with:

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
            'name' => 'SegmentTester',
            'owner_id' => $sumit->id,
            'referrer_id' => $sumit->id,
            'lead_source' => 'Sumit',
        ]);
    }

    private function edit(Student $student)
    {
        return Livewire::test(EditStudent::class, ['record' => $student->getRouteKey()]);
    }

    private function sumit(): User
    {
        $this->seed();
        $sumit = User::where('email', 'sumit@davya.local')->firstOrFail();
        $this->actingAs($sumit);

        return $sumit;
    }

    public function test_edit_deal_updates_deal_amount(): void
    {
        $sumit = $this->sumit();
        $student = $this->studentFor($sumit);

        $this->edit($student)
            ->callAction('editDeal', data: ['deal_amount' => 250000])
            ->assertHasNoActionErrors();

        $this->assertEquals(250000.0, (float) $student->fresh()->deal_amount);
    }

    public function test_manage_payment_add_creates_payment_with_recorder(): void
    {
        $sumit = $this->sumit();
        $student = $this->studentFor($sumit);

        $this->edit($student)
            ->callAction('managePayment', data: [
                'entry_action' => 'add',
                'type' => 'advance', 'amount' => 10000, 'mode' => 'cash',
                'received_at' => now()->toDateTimeString(),
            ])
            ->assertHasNoActionErrors();

        $this->assertEquals(1, $student->payments()->count());
        $this->assertEquals($sumit->id, $student->payments()->first()->recorded_by_user_id);
    }

    public function test_manage_payment_add_with_file_upload_resolves_proof_url(): void
    {
        Storage::fake('drive');
        $sumit = $this->sumit();
        $student = $this->studentFor($sumit);

        $this->edit($student)
            ->callAction('managePayment', data: [
                'entry_action' => 'add',
                'type' => 'advance', 'amount' => 2500,
                'received_at' => now()->toDateTimeString(),
                'proof_upload' => [UploadedFile::fake()->image('proof.png')],
            ])
            ->assertHasNoActionErrors();

        $this->assertStringContainsString('payment-proofs/', (string) $student->payments()->latest('id')->first()->proof_url);
    }

    public function test_manage_payment_add_url_fallback_persists_proof_url(): void
    {
        Storage::fake('drive');
        $sumit = $this->sumit();
        $student = $this->studentFor($sumit);

        $this->edit($student)
            ->callAction('managePayment', data: [
                'entry_action' => 'add',
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

    public function test_manage_payment_update_updates_selected_record(): void
    {
        $sumit = $this->sumit();
        $student = $this->studentFor($sumit);
        $payment = $student->payments()->create([
            'type' => 'advance', 'amount' => 10000, 'mode' => 'cash',
            'received_at' => now(), 'recorded_by_user_id' => $sumit->id,
        ]);

        $this->edit($student)
            ->callAction('managePayment', data: [
                'entry_action' => 'update', 'payment_id' => $payment->id,
                'type' => 'partial', 'amount' => 25000, 'mode' => 'upi',
                'received_at' => now()->toDateTimeString(),
            ])
            ->assertHasNoActionErrors();

        $this->assertEquals(25000.0, (float) $payment->fresh()->amount);
        $this->assertEquals('partial', $payment->fresh()->type);
    }

    public function test_manage_payment_delete_removes_selected_record(): void
    {
        $sumit = $this->sumit();
        $student = $this->studentFor($sumit);
        $payment = $student->payments()->create([
            'type' => 'advance', 'amount' => 10000, 'mode' => 'cash',
            'received_at' => now(), 'recorded_by_user_id' => $sumit->id,
        ]);

        $this->edit($student)
            ->callAction('managePayment', data: [
                'entry_action' => 'delete', 'payment_id' => $payment->id,
            ])
            ->assertHasNoActionErrors();

        $this->assertDatabaseMissing('payments', ['id' => $payment->id]);
    }

    public function test_manage_payout_add_creates_payout_with_recorder(): void
    {
        $sumit = $this->sumit();
        $student = $this->studentFor($sumit);

        $this->edit($student)
            ->callAction('managePayout', data: [
                'entry_action' => 'add',
                'payee_type' => 'college', 'payee_name' => 'GGSIPU',
                'amount' => 40000, 'status' => 'to_pay',
            ])
            ->assertHasNoActionErrors();

        $payout = $student->payouts()->first();
        $this->assertNotNull($payout);
        $this->assertEquals(40000.0, (float) $payout->amount);
        $this->assertEquals($sumit->id, $payout->recorded_by_user_id);
    }

    public function test_manage_payout_update_updates_selected_record(): void
    {
        $sumit = $this->sumit();
        $student = $this->studentFor($sumit);
        $payout = Payout::factory()->create([
            'student_id' => $student->id, 'amount' => 30000,
            'payee_type' => 'college', 'status' => 'to_pay',
            'recorded_by_user_id' => $sumit->id,
        ]);

        $this->edit($student)
            ->callAction('managePayout', data: [
                'entry_action' => 'update', 'payout_id' => $payout->id,
                'payee_type' => 'college', 'amount' => 55000,
                'status' => 'paid', 'paid_at' => now()->toDateTimeString(),
            ])
            ->assertHasNoActionErrors();

        $this->assertEquals(55000.0, (float) $payout->fresh()->amount);
        $this->assertEquals('paid', $payout->fresh()->status);
    }

    public function test_manage_payout_delete_removes_selected_record(): void
    {
        $sumit = $this->sumit();
        $student = $this->studentFor($sumit);
        $payout = Payout::factory()->create([
            'student_id' => $student->id, 'amount' => 30000,
            'recorded_by_user_id' => $sumit->id,
        ]);

        $this->edit($student)
            ->callAction('managePayout', data: [
                'entry_action' => 'delete', 'payout_id' => $payout->id,
            ])
            ->assertHasNoActionErrors();

        $this->assertDatabaseMissing('payouts', ['id' => $payout->id]);
    }
}
```

- [ ] **Step 4: Run the chooser test — expect pass**

Run: `php -d memory_limit=2G ./vendor/bin/phpunit tests/Feature/PaymentPayoutChooserTest.php`
Expected: PASS (9 tests). If a hidden action can't be mounted via `callAction`, that contradicts the verified `mountAction` behavior — recheck the action names match (`editDeal`/`managePayment`/`managePayout`). Do not weaken assertions.

- [ ] **Step 5: Pint + commit**

```bash
./vendor/bin/pint app/Filament/Support/PaymentPayoutChooser.php app/Filament/Resources/StudentResource/Pages/EditStudent.php tests/Feature/PaymentPayoutChooserTest.php
git add app/Filament/Support/PaymentPayoutChooser.php app/Filament/Resources/StudentResource/Pages/EditStudent.php tests/Feature/PaymentPayoutChooserTest.php
git commit -m "feat(students): split chooser into per-segment editDeal/managePayment/managePayout actions"
```

---

### Task 2: Per-segment clickable summary

**Files:**
- Modify: `resources/views/filament/forms/student-money-summary.blade.php`
- Modify: `tests/Feature/StudentFormRevertTest.php`

- [ ] **Step 1: Make each editable segment its own trigger**

Replace the ENTIRE contents of `resources/views/filament/forms/student-money-summary.blade.php` with:

```blade
@php($r = $getRecord())
@if ($r)
    @php($mf = \App\Support\MoneyFormat::class)
    @php($fmt = fn ($v) => ((float) $v < 0 ? '-₹' : '₹') . $mf::indianShort(abs((float) $v)))
    <div class="text-sm text-gray-500 dark:text-gray-400" style="margin-top:4px; line-height:1.5;" data-testid="student-money-summary">
        <button type="button" wire:click="mountAction('editDeal')"
                class="hover:underline cursor-pointer" style="display:inline; color:inherit;"
                title="Edit deal amount">{{ $fmt($r->deal_amount) }} deal</button>
        <span class="text-gray-300 dark:text-gray-600"> · </span>
        <button type="button" wire:click="mountAction('managePayment')"
                class="hover:underline cursor-pointer" style="display:inline; color:inherit;"
                title="Add / update / delete a payment">{{ $fmt($r->total_received) }} received</button>
        <span class="text-gray-300 dark:text-gray-600"> · </span>
        <span @style(['color:var(--warning,#D97706)' => $r->pending_amount > 0])>{{ $fmt($r->pending_amount) }} pending</span>
        <span class="text-gray-300 dark:text-gray-600"> · </span>
        <button type="button" wire:click="mountAction('managePayout')"
                class="hover:underline cursor-pointer" style="display:inline; color:inherit;"
                title="Add / update / delete a payout">{{ $fmt($r->total_payouts) }} payouts</button>
        <span class="text-gray-300 dark:text-gray-600"> · </span>
        <span @style(['color:var(--danger,#DC2626)' => $r->expected_profit < 0])>{{ $fmt($r->expected_profit) }} profit</span>
    </div>
@endif
```

- [ ] **Step 2: Update the summary tests**

In `tests/Feature/StudentFormRevertTest.php`, replace the existing `test_stage_summary_is_clickable_to_open_chooser` method (it asserts the old `mountAction('newPaymentPayout')`) with:

```php
    public function test_stage_summary_segments_are_clickable(): void
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
            ->assertSeeHtml('wire:click="mountAction(\'editDeal\')"')
            ->assertSeeHtml('wire:click="mountAction(\'managePayment\')"')
            ->assertSeeHtml('wire:click="mountAction(\'managePayout\')"');
    }
```

The other method `test_stage_section_shows_money_summary_on_existing_student` still passes (the `data-testid` + `profit`/`received` text remain). If it asserted `Expected profit` it was already updated to `profit`/`received` earlier — leave as is.

- [ ] **Step 3: Run the test — expect pass**

Run: `php -d memory_limit=2G ./vendor/bin/phpunit tests/Feature/StudentFormRevertTest.php`
Expected: PASS (4 tests).

- [ ] **Step 4: Pint + commit**

```bash
./vendor/bin/pint resources/views/filament/forms/student-money-summary.blade.php tests/Feature/StudentFormRevertTest.php
git add resources/views/filament/forms/student-money-summary.blade.php tests/Feature/StudentFormRevertTest.php
git commit -m "feat(students): per-segment clickable Stage summary (deal/received/payouts)"
```

---

### Task 3: Full-suite verification

- [ ] **Step 1: Run the entire suite**

Run: `php -d memory_limit=2G ./vendor/bin/phpunit`
Expected: 0 failures, 1 pre-existing skip, 4 pre-existing deprecations. PaymentPayoutChooserTest now 9 tests; StudentFormRevertTest 4. If any other test referenced the removed `newPaymentPayout` action, update it.

- [ ] **Step 2: Pint sweep + commit residue**

Run: `./vendor/bin/pint` ; `git add -A && git commit -m "style: pint"` (skip if clean).

- [ ] **Step 3: Deploy (Sumit / pre-deploy gate)**

Do NOT deploy without Sumit's go-ahead. When approved: SSH `ssh -i ~/.ssh/davyas-active ipuc@ipu.co.in` → `cd ~/davya-crm` → `git pull` → `composer install --no-dev --optimize-autoloader` → `php artisan migrate --force` (none) → `config:cache && route:cache && view:cache`. No new class files. Browser-confirm: click `deal` → edit-amount modal; `received` → payment add/update/delete; `payouts` → payout add/update/delete; `pending`/`profit` are not clickable. curl-verify `/admin/students=302`, `/admin/login=200`.

---

## Self-Review

**Spec coverage:**
- `dealAction`/`paymentAction`/`payoutAction` builders (scoped add/update/delete; deal edits amount) → Task 1 Step 1 ✓
- Registered `->hidden()` on EditStudent (mountable, no buttons) → Task 1 Step 2 ✓
- Old combined `make()` + header button removed → Task 1 Step 1 (make() gone) + Step 2 (not registered) ✓
- Per-segment clickable summary; pending/profit plain → Task 2 Step 1 ✓
- Tests for all branches + 3-button render → Tasks 1 & 2 ✓

**Placeholder scan:** no TBD/TODO; full code in every code step.

**Type/name consistency:** action names `editDeal`/`managePayment`/`managePayout` consistent across builder, EditStudent registration, summary `wire:click`, and tests; `entry_action` values `add`/`update`/`delete` consistent in toggle options, visibility predicates, submit switch, and test data; payout fields use real column names (`payee_type`/`payee_name`/`amount`/`status`/`paid_at`/`notes`) in form, `afterStateUpdated`, and `Arr::only`; payment `attrs` keys match `PaymentFormSchema` outputs.
