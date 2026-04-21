# Student First-Payment Capture + Drive Upload Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a Google Drive `FileUpload` for payment proofs (URL fallback preserved) and a collapsed optional "First payment" block to the Student create form that persists one `Payment` row on successful create.

**Architecture:** Extract the Payment form fields into a shared `PaymentFormSchema` partial consumed by both the existing `PaymentsRelationManager` and a new inline section on `StudentResource` (create form only). A static `resolveProofUpload()` helper converts the transient `proof_upload` field into a `proof_url` via `Storage::disk('drive')->url(...)` and unsets the key before the Payment row is inserted. `CreateStudent::afterCreate()` persists the optional first payment.

**Tech Stack:** PHP 8.4+, Laravel 11, Filament 3 (Forms + RelationManager), PHPUnit with Livewire test utilities, SQLite `:memory:` + `RefreshDatabase`, `masbug/flysystem-google-drive-ext` (disk name `drive`).

**Spec:** `docs/superpowers/specs/2026-04-21-student-payment-upload-design.md`

**Spec divergence to note:** The spec refers to the Drive disk as `gdrive`. The repo's actual disk name is `drive` (see `config/filesystems.php` and `app/Providers/AppServiceProvider.php` — registered via `Storage::extend('google', ...)`). **This plan uses the real name `drive` throughout.** No config rename is needed.

**Working directory:** `/Users/Sumit/davya-crm`. On the Hostinger server, always invoke PHP as `/opt/alt/php84/usr/bin/php`. Locally, run `php` with 8.4+ active.

---

## File Structure

**New files:**
- `app/Filament/Resources/Shared/PaymentFormSchema.php` — shared Filament field list + `resolveProofUpload()` helper.
- `tests/Unit/PaymentFormSchemaTest.php` — unit coverage for `resolveProofUpload()`.
- `tests/Feature/StudentFirstPaymentTest.php` — Livewire feature tests for the student-create inline block.

**Modified files:**
- `app/Filament/Resources/StudentResource/RelationManagers/PaymentsRelationManager.php` — form delegates to `PaymentFormSchema::fields(false)`; `mutateFormDataBeforeCreate` / `BeforeSave` call `resolveProofUpload`.
- `app/Filament/Resources/StudentResource.php` — append a collapsed `Section` "First payment (optional)" to the create form (hidden on edit).
- `app/Filament/Resources/StudentResource/Pages/CreateStudent.php` — `afterCreate()` persists the optional first payment.
- `tests/Feature/PaymentsRelationManagerTest.php` — add regression tests for upload → `proof_url` resolution and for URL-only fallback still working.

**Not modified:** database schema, Payment model, middleware, or API routes.

---

## Task 1: Pre-flight — confirm the `drive` disk is reachable and fakeable

The whole design relies on `Storage::disk('drive')` + `Storage::fake('drive')`. Confirm both before writing feature code.

**Files:** (read-only exploration — no edits)

- [ ] **Step 1: Confirm disk registration**

Run: `grep -n "'drive'" config/filesystems.php`
Expected: one hit showing a `drive` entry with `'driver' => 'google'`.

Run: `grep -n "Storage::extend" app/Providers/AppServiceProvider.php`
Expected: one hit calling `Storage::extend('google', ...)`.

- [ ] **Step 2: Confirm `Storage::fake('drive')` works in the test harness**

Create a throwaway sanity test at `tests/Unit/DriveFakeSanityTest.php`:

```php
<?php

namespace Tests\Unit;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DriveFakeSanityTest extends TestCase
{
    public function test_drive_disk_can_be_faked_and_accepts_uploads(): void
    {
        Storage::fake('drive');
        $file = UploadedFile::fake()->image('x.png');
        $path = $file->store('payment-proofs', 'drive');
        $this->assertNotEmpty($path);
        Storage::disk('drive')->assertExists($path);
    }
}
```

Run: `php artisan test --filter=DriveFakeSanityTest`
Expected: PASS.

- [ ] **Step 3: Delete the sanity test and commit nothing**

```bash
rm tests/Unit/DriveFakeSanityTest.php
```

This was a one-off check — do not commit it.

---

## Task 2: Shared `PaymentFormSchema` partial (TDD on `resolveProofUpload`)

The helper is pure-logic and easy to unit-test. Start there.

**Files:**
- Create: `app/Filament/Resources/Shared/PaymentFormSchema.php`
- Create: `tests/Unit/PaymentFormSchemaTest.php`

- [ ] **Step 1: Write the failing unit tests**

Create `tests/Unit/PaymentFormSchemaTest.php`:

```php
<?php

namespace Tests\Unit;

use App\Filament\Resources\Shared\PaymentFormSchema;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PaymentFormSchemaTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('drive');
    }

    public function test_resolves_upload_path_to_drive_url_and_unsets_upload_key(): void
    {
        Storage::disk('drive')->put('payment-proofs/test.png', 'fake-bytes');

        $data = [
            'amount'       => 5000,
            'type'         => 'advance',
            'proof_upload' => 'payment-proofs/test.png',
            'proof_url'    => null,
        ];

        $out = PaymentFormSchema::resolveProofUpload($data);

        $this->assertArrayNotHasKey('proof_upload', $out);
        $this->assertNotNull($out['proof_url']);
        $this->assertStringContainsString('payment-proofs/test.png', $out['proof_url']);
    }

    public function test_upload_wins_over_existing_url(): void
    {
        Storage::disk('drive')->put('payment-proofs/winner.pdf', 'fake-bytes');

        $data = [
            'proof_upload' => 'payment-proofs/winner.pdf',
            'proof_url'    => 'https://manual-url.example/keepme',
        ];

        $out = PaymentFormSchema::resolveProofUpload($data);

        $this->assertArrayNotHasKey('proof_upload', $out);
        $this->assertStringContainsString('payment-proofs/winner.pdf', $out['proof_url']);
        $this->assertStringNotContainsString('manual-url.example', $out['proof_url']);
    }

    public function test_no_upload_preserves_existing_url(): void
    {
        $data = [
            'proof_upload' => null,
            'proof_url'    => 'https://drive.google.com/file/d/abc/view',
        ];

        $out = PaymentFormSchema::resolveProofUpload($data);

        $this->assertArrayNotHasKey('proof_upload', $out);
        $this->assertSame('https://drive.google.com/file/d/abc/view', $out['proof_url']);
    }

    public function test_no_upload_and_no_url_leaves_proof_url_null(): void
    {
        $data = ['proof_upload' => null, 'proof_url' => null];

        $out = PaymentFormSchema::resolveProofUpload($data);

        $this->assertArrayNotHasKey('proof_upload', $out);
        $this->assertNull($out['proof_url']);
    }

    public function test_missing_keys_are_tolerated(): void
    {
        $data = ['amount' => 100, 'type' => 'advance'];

        $out = PaymentFormSchema::resolveProofUpload($data);

        $this->assertArrayNotHasKey('proof_upload', $out);
        $this->assertSame(100, $out['amount']);
        $this->assertSame('advance', $out['type']);
    }

    public function test_empty_string_upload_is_ignored(): void
    {
        $data = [
            'proof_upload' => '',
            'proof_url'    => 'https://keep.example',
        ];

        $out = PaymentFormSchema::resolveProofUpload($data);

        $this->assertArrayNotHasKey('proof_upload', $out);
        $this->assertSame('https://keep.example', $out['proof_url']);
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --filter=PaymentFormSchemaTest`
Expected: FAIL — `App\Filament\Resources\Shared\PaymentFormSchema` does not exist.

- [ ] **Step 3: Create the shared schema file**

Create `app/Filament/Resources/Shared/PaymentFormSchema.php`:

```php
<?php

namespace App\Filament\Resources\Shared;

use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Get;
use Illuminate\Support\Facades\Storage;

final class PaymentFormSchema
{
    public const DRIVE_DISK = 'drive';
    public const UPLOAD_DIRECTORY = 'payment-proofs';

    /**
     * @return array<\Filament\Forms\Components\Component>
     */
    public static function fields(bool $inlineFirstPayment = false): array
    {
        $typeField = Select::make('type')
            ->options([
                'advance' => 'Advance',
                'partial' => 'Partial',
                'full'    => 'Full',
                'refund'  => 'Refund',
            ]);

        $amountField = TextInput::make('amount')
            ->numeric()
            ->prefix('₹');

        $receivedAtField = DateTimePicker::make('received_at')->default(now());

        if ($inlineFirstPayment) {
            $typeField        = $typeField->required(fn (Get $get) => filled($get('amount')));
            $receivedAtField  = $receivedAtField->required(fn (Get $get) => filled($get('amount')));
        } else {
            $typeField        = $typeField->required();
            $amountField      = $amountField->required();
            $receivedAtField  = $receivedAtField->required();
        }

        return [
            $typeField,
            $amountField,
            Select::make('mode')->options([
                'cash'          => 'Cash',
                'upi'           => 'UPI',
                'bank_transfer' => 'Bank Transfer',
                'card'          => 'Card',
                'cheque'        => 'Cheque',
                'other'         => 'Other',
            ]),
            TextInput::make('reference_number')->maxLength(80),
            $receivedAtField,
            FileUpload::make('proof_upload')
                ->label('Upload proof')
                ->disk(self::DRIVE_DISK)
                ->directory(self::UPLOAD_DIRECTORY)
                ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp', 'application/pdf'])
                ->maxSize(5120)
                ->visibility('private')
                ->helperText('Optional — uploads to Google Drive. Leave empty to paste a URL instead.'),
            TextInput::make('proof_url')
                ->label('Proof URL')
                ->placeholder('https://...')
                ->url()
                ->maxLength(2048),
            Textarea::make('notes')->rows(2),
            Hidden::make('recorded_by_user_id')->default(fn () => auth()->id()),
        ];
    }

    /**
     * Resolve a pending upload path to a Drive URL and remove the transient
     * proof_upload key. Always strips proof_upload from the returned array.
     */
    public static function resolveProofUpload(array $data): array
    {
        $uploadPath = $data['proof_upload'] ?? null;
        unset($data['proof_upload']);

        if (is_string($uploadPath) && $uploadPath !== '') {
            $data['proof_url'] = Storage::disk(self::DRIVE_DISK)->url($uploadPath);
        } elseif (! array_key_exists('proof_url', $data)) {
            $data['proof_url'] = null;
        }

        return $data;
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `php artisan test --filter=PaymentFormSchemaTest`
Expected: all 6 PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Filament/Resources/Shared/PaymentFormSchema.php tests/Unit/PaymentFormSchemaTest.php
git commit -m "feat(filament): shared PaymentFormSchema with proof upload resolver"
```

---

## Task 3: Refactor `PaymentsRelationManager` to use the shared schema (no behavior change)

Goal: existing `PaymentsRelationManagerTest` stays entirely green. This commit is a pure refactor.

**Files:**
- Modify: `app/Filament/Resources/StudentResource/RelationManagers/PaymentsRelationManager.php`

- [ ] **Step 1: Run existing tests and confirm baseline green**

Run: `php artisan test --filter=PaymentsRelationManagerTest`
Expected: PASS.

- [ ] **Step 2: Replace the relation manager with the refactored version**

Overwrite `app/Filament/Resources/StudentResource/RelationManagers/PaymentsRelationManager.php`:

```php
<?php

namespace App\Filament\Resources\StudentResource\RelationManagers;

use App\Filament\Resources\Shared\PaymentFormSchema;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

class PaymentsRelationManager extends RelationManager
{
    protected static string $relationship = 'payments';

    public function form(Form $form): Form
    {
        return $form->schema(PaymentFormSchema::fields(inlineFirstPayment: false));
    }

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return PaymentFormSchema::resolveProofUpload($data);
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        return PaymentFormSchema::resolveProofUpload($data);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('type')
            ->columns([
                Tables\Columns\TextColumn::make('received_at')->dateTime('d M Y H:i')->sortable(),
                Tables\Columns\TextColumn::make('type')->badge(),
                Tables\Columns\TextColumn::make('amount')->money('INR'),
                Tables\Columns\TextColumn::make('mode'),
                Tables\Columns\TextColumn::make('recordedBy.name')->label('Recorded by'),
            ])
            ->headerActions([
                Tables\Actions\CreateAction::make(),
            ])
            ->actions([
                Tables\Actions\Action::make('open_proof')
                    ->label('Open proof')
                    ->icon('heroicon-o-document-arrow-down')
                    ->url(fn ($record) => $record->proof_url)
                    ->openUrlInNewTab()
                    ->visible(fn ($record) => filled($record->proof_url)),
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

- [ ] **Step 3: Re-run existing tests**

Run: `php artisan test --filter=PaymentsRelationManagerTest`
Expected: PASS (unchanged behavior).

- [ ] **Step 4: Commit**

```bash
git add app/Filament/Resources/StudentResource/RelationManagers/PaymentsRelationManager.php
git commit -m "refactor(filament): PaymentsRelationManager uses shared PaymentFormSchema"
```

---

## Task 4: Add upload regression tests on the Payments tab

Adds two new tests to the existing Payments tab test file. These exercise the upload → `proof_url` resolution via the relation manager, closing the loop on Task 3's refactor.

**Files:**
- Modify: `tests/Feature/PaymentsRelationManagerTest.php`

- [ ] **Step 1: Add the new tests**

Append the following two methods inside the `PaymentsRelationManagerTest` class (before the closing `}`):

```php
public function test_payments_tab_accepts_file_upload_and_resolves_to_proof_url(): void
{
    \Illuminate\Support\Facades\Storage::fake('drive');
    $this->seed();

    $sumit = \App\Models\User::where('email', 'sumit@davya.local')->firstOrFail();
    $student = \App\Models\Student::create([
        'phone'       => '9100000201',
        'name'        => 'UploadTester',
        'owner_id'    => $sumit->id,
        'referrer_id' => $sumit->id,
        'lead_source' => 'Sumit',
    ]);

    $this->actingAs($sumit);

    $file = \Illuminate\Http\UploadedFile::fake()->image('proof.png');

    \Livewire\Livewire::test(PaymentsRelationManager::class, [
        'ownerRecord' => $student,
        'pageClass'   => \App\Filament\Resources\StudentResource\Pages\EditStudent::class,
    ])
        ->callTableAction('create', data: [
            'type'                => 'advance',
            'amount'              => 2500,
            'received_at'         => now()->toDateTimeString(),
            'proof_upload'        => [$file],
            'recorded_by_user_id' => $sumit->id,
        ])
        ->assertHasNoTableActionErrors();

    $payment = $student->payments()->latest('id')->first();
    $this->assertNotNull($payment);
    $this->assertNotNull($payment->proof_url);
    $this->assertStringContainsString('payment-proofs/', $payment->proof_url);
}

public function test_payments_tab_url_fallback_still_persists_proof_url_unchanged(): void
{
    \Illuminate\Support\Facades\Storage::fake('drive');
    $this->seed();

    $sumit = \App\Models\User::where('email', 'sumit@davya.local')->firstOrFail();
    $student = \App\Models\Student::create([
        'phone'       => '9100000202',
        'name'        => 'UrlFallbackTester',
        'owner_id'    => $sumit->id,
        'referrer_id' => $sumit->id,
        'lead_source' => 'Sumit',
    ]);

    $this->actingAs($sumit);

    \Livewire\Livewire::test(PaymentsRelationManager::class, [
        'ownerRecord' => $student,
        'pageClass'   => \App\Filament\Resources\StudentResource\Pages\EditStudent::class,
    ])
        ->callTableAction('create', data: [
            'type'                => 'advance',
            'amount'              => 1500,
            'received_at'         => now()->toDateTimeString(),
            'proof_url'           => 'https://drive.google.com/file/d/manual-url/view',
            'recorded_by_user_id' => $sumit->id,
        ])
        ->assertHasNoTableActionErrors();

    $payment = $student->payments()->latest('id')->first();
    $this->assertSame('https://drive.google.com/file/d/manual-url/view', $payment->proof_url);
}
```

- [ ] **Step 2: Run the file**

Run: `php artisan test --filter=PaymentsRelationManagerTest`
Expected: all tests PASS, including the two new ones.

- [ ] **Step 3: Commit**

```bash
git add tests/Feature/PaymentsRelationManagerTest.php
git commit -m "test(filament): payments tab upload + URL fallback regression"
```

---

## Task 5: Inline "First payment" section on Student create form + `afterCreate` hook

**Files:**
- Modify: `app/Filament/Resources/StudentResource.php`
- Modify: `app/Filament/Resources/StudentResource/Pages/CreateStudent.php`

- [ ] **Step 1: Add the inline section to the Student create form**

Open `app/Filament/Resources/StudentResource.php`. Add this import near the top (alongside the existing `use` statements):

```php
use App\Filament\Resources\Shared\PaymentFormSchema;
```

Find the `public static function form(Form $form): Form` method. Locate the closing `])` of the final `Section::make(...)->schema([...])` in the top-level `$form->schema([...])` array. Immediately before that closing `]);` (i.e., as the last entry in the top-level schema array), insert:

```php
Section::make('First payment (optional)')
    ->description('Only shown when creating a new student. Additional payments go on the Payments tab.')
    ->icon('heroicon-o-banknotes')
    ->statePath('first_payment')
    ->collapsed()
    ->visibleOn('create')
    ->schema(PaymentFormSchema::fields(inlineFirstPayment: true))
    ->columns(2),
```

- [ ] **Step 2: Add `afterCreate` to `CreateStudent`**

Overwrite `app/Filament/Resources/StudentResource/Pages/CreateStudent.php`:

```php
<?php

namespace App\Filament\Resources\StudentResource\Pages;

use App\Filament\Resources\Shared\PaymentFormSchema;
use App\Filament\Resources\StudentResource;
use App\Models\Payment;
use Filament\Resources\Pages\CreateRecord;

class CreateStudent extends CreateRecord
{
    protected static string $resource = StudentResource::class;

    protected function afterCreate(): void
    {
        $fp = $this->data['first_payment'] ?? null;

        if (! is_array($fp) || empty($fp['amount'])) {
            return;
        }

        $fp = PaymentFormSchema::resolveProofUpload($fp);

        Payment::create([
            'student_id'          => $this->record->id,
            'type'                => $fp['type']             ?? null,
            'amount'              => $fp['amount'],
            'mode'                => $fp['mode']             ?? null,
            'reference_number'    => $fp['reference_number'] ?? null,
            'received_at'         => $fp['received_at']      ?? now(),
            'proof_url'           => $fp['proof_url']        ?? null,
            'notes'               => $fp['notes']            ?? null,
            'recorded_by_user_id' => auth()->id(),
        ]);
    }
}
```

- [ ] **Step 3: Run the existing suite to make sure nothing regressed**

Run: `php artisan test`
Expected: green (we haven't added the new feature tests yet; existing tests still pass because the new inline section is `visibleOn('create')` and default-collapsed, and `afterCreate` noops when `first_payment` is unset).

- [ ] **Step 4: Commit**

```bash
git add app/Filament/Resources/StudentResource.php app/Filament/Resources/StudentResource/Pages/CreateStudent.php
git commit -m "feat(filament): inline first-payment block on student create form"
```

---

## Task 6: Feature tests for the inline first-payment block

**Files:**
- Create: `tests/Feature/StudentFirstPaymentTest.php`

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/StudentFirstPaymentTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Filament\Resources\StudentResource\Pages\CreateStudent;
use App\Models\Payment;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class StudentFirstPaymentTest extends TestCase
{
    use RefreshDatabase;

    private User $sumit;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('drive');
        $this->seed();
        $this->sumit = User::where('email', 'sumit@davya.local')->firstOrFail();
        $this->actingAs($this->sumit);
    }

    private function baseStudentData(): array
    {
        return [
            'phone'         => '9100000301',
            'name'          => 'InlineTester',
            'owner_id'      => $this->sumit->id,
            'referrer_id'   => $this->sumit->id,
            'lead_source'   => 'Sumit',
            'stage'         => 'Lead Captured',
        ];
    }

    public function test_creating_student_with_first_payment_persists_one_payment(): void
    {
        $data = $this->baseStudentData() + [
            'first_payment' => [
                'type'        => 'advance',
                'amount'      => 5000,
                'received_at' => now()->toDateTimeString(),
            ],
        ];

        Livewire::test(CreateStudent::class)
            ->fillForm($data)
            ->call('create')
            ->assertHasNoFormErrors();

        $student = Student::where('phone', '9100000301')->firstOrFail();
        $payments = Payment::where('student_id', $student->id)->get();

        $this->assertCount(1, $payments);
        $this->assertSame('advance', $payments[0]->type);
        $this->assertSame('5000.00', $payments[0]->amount);
        $this->assertSame($this->sumit->id, $payments[0]->recorded_by_user_id);
    }

    public function test_creating_student_without_first_payment_creates_no_payment(): void
    {
        Livewire::test(CreateStudent::class)
            ->fillForm($this->baseStudentData())
            ->call('create')
            ->assertHasNoFormErrors();

        $student = Student::where('phone', '9100000301')->firstOrFail();
        $this->assertSame(0, Payment::where('student_id', $student->id)->count());
    }

    public function test_first_payment_amount_without_type_blocks_submission(): void
    {
        $data = $this->baseStudentData() + [
            'first_payment' => [
                'amount'      => 5000,
                'type'        => null,
                'received_at' => null,
            ],
        ];

        Livewire::test(CreateStudent::class)
            ->fillForm($data)
            ->call('create')
            ->assertHasFormErrors(['first_payment.type']);

        $this->assertSame(0, Student::where('phone', '9100000301')->count());
        $this->assertSame(0, Payment::count());
    }

    public function test_first_payment_url_fallback_persists_proof_url_verbatim(): void
    {
        $data = $this->baseStudentData() + [
            'first_payment' => [
                'type'        => 'advance',
                'amount'      => 7500,
                'received_at' => now()->toDateTimeString(),
                'proof_url'   => 'https://drive.google.com/file/d/fallback/view',
            ],
        ];

        Livewire::test(CreateStudent::class)
            ->fillForm($data)
            ->call('create')
            ->assertHasNoFormErrors();

        $student = Student::where('phone', '9100000301')->firstOrFail();
        $payment = Payment::where('student_id', $student->id)->firstOrFail();
        $this->assertSame('https://drive.google.com/file/d/fallback/view', $payment->proof_url);
    }

    public function test_first_payment_file_upload_resolves_to_proof_url(): void
    {
        $file = UploadedFile::fake()->image('proof.png');

        $data = $this->baseStudentData() + [
            'first_payment' => [
                'type'         => 'advance',
                'amount'       => 9999,
                'received_at'  => now()->toDateTimeString(),
                'proof_upload' => [$file],
            ],
        ];

        Livewire::test(CreateStudent::class)
            ->fillForm($data)
            ->call('create')
            ->assertHasNoFormErrors();

        $student = Student::where('phone', '9100000301')->firstOrFail();
        $payment = Payment::where('student_id', $student->id)->firstOrFail();

        $this->assertNotNull($payment->proof_url);
        $this->assertStringContainsString('payment-proofs/', $payment->proof_url);
    }
}
```

- [ ] **Step 2: Run the test file**

Run: `php artisan test --filter=StudentFirstPaymentTest`
Expected: all 5 PASS. If any fail, read the failure to determine whether it's a Livewire form-state plumbing issue (most likely `first_payment` key delivery via `fillForm`) and fix as follows:

- If `fillForm(['first_payment' => [...]])` doesn't propagate nested state in your Filament version, use dot-notation:
  ```php
  ->fillForm([
      ...$this->baseStudentData(),
      'first_payment.type'        => 'advance',
      'first_payment.amount'      => 5000,
      'first_payment.received_at' => now()->toDateTimeString(),
  ])
  ```

- [ ] **Step 3: Run the full suite**

Run: `php artisan test`
Expected: green.

- [ ] **Step 4: Commit**

```bash
git add tests/Feature/StudentFirstPaymentTest.php
git commit -m "test(filament): inline first-payment block on student create"
```

---

## Task 7: Manual smoke + final verification

No code changes — verification only.

- [ ] **Step 1: Full suite one more time**

Run: `php artisan test`
Expected: green. Zero skipped tests for new code.

- [ ] **Step 2: Quick static check**

Run: `php -l app/Filament/Resources/Shared/PaymentFormSchema.php && php -l app/Filament/Resources/StudentResource/Pages/CreateStudent.php && php -l app/Filament/Resources/StudentResource/RelationManagers/PaymentsRelationManager.php`
Expected: `No syntax errors detected` for each.

- [ ] **Step 3: Confirm branch state**

Run: `git log --oneline main..HEAD` (or `origin/main..HEAD` as appropriate)
Expected: 5 commits — PaymentFormSchema, relation-manager refactor, tab regression tests, inline block + afterCreate, first-payment feature tests.

- [ ] **Step 4: Hand off for smoke-test in prod**

After deploy to `davyas.ipu.co.in`:
1. Log into `/admin/students/create`. Confirm a collapsed "First payment (optional)" section appears below the existing sections.
2. Expand it, pick `type=advance`, `amount=1`, leave `received_at` as default, upload a real small PNG, submit.
3. Open the new student's Payments tab. Confirm the row appears with `proof_url` set and "Open proof" link clicks through to the Drive file.
4. On the Payments tab, add a second payment with a PDF upload. Confirm it lands with proof_url resolved.
5. On `/admin/students/{id}/edit` for an **existing** student, confirm **no** "First payment" section is shown (verify `visibleOn('create')` works).

If any smoke step fails, capture the browser error / server log line and report back before merging.

---

## Out of Scope / Deferred

- Changing `proof_url` to a FK'd attachments table (the spec deliberately reuses the existing column).
- Uploading multiple proof files per payment (single file only).
- Thumbnailing uploaded images for inline preview in the Payments tab.
- Changing the "Open proof" action to verify URL reachability before opening.
- Moving `PaymentFormSchema` under a different namespace (e.g. `App\Filament\Forms`) — keep current location unless a clear need emerges.
- Any change to the Slack proof-permalink work tracked under M6.
