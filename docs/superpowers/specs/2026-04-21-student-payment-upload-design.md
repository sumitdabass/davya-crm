# Student First-Payment Capture + Drive Upload — Design Spec

**Date:** 2026-04-21
**Status:** Approved for planning
**Owner:** Sumit

## Context

The `/admin/students` resource has a working Payments tab (`PaymentsRelationManager`) with Create/Edit/Delete actions and multi-payment support. Two friction points:

1. **Proof upload is URL-only.** The `proof_url` field is a text URL input — operators must upload to Google Drive manually elsewhere, then paste the share link. Existing `flysystem-gdrive` credentials are configured in `.env`.
2. **No in-flow payment capture on student create.** When onboarding a lead who has already paid an advance, the operator must first save the student, then navigate to the Payments tab to record the payment — two steps where one would do.

Goal: let operators upload a proof image/PDF directly (Drive is the store of record) and record the first payment inline on the student create page.

## Non-Goals

- Changing the Payments API, the Payment model schema, or the `proof_url` column. `proof_url` remains the canonical storage; the new file upload resolves to a Drive URL that is written into it.
- Supporting multiple inline payments at student-create time. Only one first-payment block is shown. All subsequent payments go through the existing Payments tab.
- Showing the inline block on student **edit**. Once a student exists, the Payments tab is the sole entry point.
- Migrating existing URL-only `proof_url` rows. They stay as-is.
- Changing Slack proof-permalink behavior (M6 work).

## User-Visible Behavior

### 1. Drive upload on payment proof (both entry points)

- `PaymentsRelationManager` form and new `StudentResource` create form both gain a `proof_upload` field.
- Accepts: `image/jpeg`, `image/png`, `image/webp`, `application/pdf`. Max size: 5 MB.
- Writes to `gdrive` disk under `payment-proofs/` with private visibility.
- On save, if an upload is present, its Drive URL is resolved and written into `proof_url`. The upload field itself is transient (`dehydrated(false)`) — it is never a DB column.
- If both `proof_upload` and `proof_url` are filled, **upload wins** (overwrites `proof_url`).
- If neither is filled, `proof_url` stays null.
- `proof_url` remains optional everywhere.

### 2. "First payment" block on student create

- Collapsed `Section` titled "First payment (optional)" under the main student form, visible **only on** `/admin/students/create`.
- Fields (identical to the PaymentsRelationManager form, reused via a shared schema): `type`, `amount`, `mode`, `reference_number`, `received_at` (default `now()`), `proof_upload`, `proof_url`, `notes`.
- Block is all-or-nothing:
  - If `amount` is blank → entire block is ignored; no Payment row is created.
  - If `amount` is present → `type` and `received_at` become required. Other fields remain optional.
- On successful student create, a single Payment row is inserted with `student_id = <new_student.id>` and `recorded_by_user_id = auth()->id()`.
- No inline block on `/admin/students/{id}/edit`. Users continue using the Payments tab.

## Architecture

### Shared form schema

New file: `app/Filament/Resources/Shared/PaymentFormSchema.php`

```php
final class PaymentFormSchema
{
    /** @return array<\Filament\Forms\Components\Component> */
    public static function fields(): array { /* type, amount, mode, ref, received_at, proof_upload, proof_url, notes */ }

    /**
     * Called from mutateFormDataBeforeCreate / mutateFormDataBeforeSave.
     * If data['proof_upload'] contains a path, resolve gdrive URL into data['proof_url'],
     * then unset data['proof_upload'].
     */
    public static function resolveProofUpload(array $data): array;
}
```

Both the `PaymentsRelationManager` form and the `StudentResource` inline block call `PaymentFormSchema::fields($inlineFirstPayment)` (see Validation below for the flag). Both mutation points call `PaymentFormSchema::resolveProofUpload($data)` before persisting a Payment row.

### File-upload component

```php
FileUpload::make('proof_upload')
    ->label('Upload proof')
    ->disk('gdrive')
    ->directory('payment-proofs')
    ->acceptedFileTypes(['image/jpeg','image/png','image/webp','application/pdf'])
    ->maxSize(5120) // KB
    ->visibility('private')
    ->helperText('Optional — uploads to Google Drive. Leave empty to paste a URL instead.');
```

The field is **dehydrated (default)** so the uploaded path reaches `$data` in `mutateFormDataBeforeCreate`/`BeforeSave`. `PaymentFormSchema::resolveProofUpload($data)`:

1. If `$data['proof_upload']` is a non-empty string, treat it as the gdrive path; call `Storage::disk('gdrive')->url($path)` and write the result into `$data['proof_url']` (overwriting any URL the user also typed).
2. `unset($data['proof_upload'])` regardless (it is not a column on `payments`).
3. Return `$data`.

This unset step is load-bearing — `Payment` has `protected $guarded = []`, so leaving `proof_upload` in the array would trigger an "unknown column" error on insert.

### Create-page hook

`app/Filament/Resources/StudentResource/Pages/CreateStudent.php` gains `afterCreate()`:

```php
protected function afterCreate(): void
{
    $fp = $this->data['first_payment'] ?? [];
    if (empty($fp['amount'])) {
        return;
    }

    $fp = PaymentFormSchema::resolveProofUpload($fp);

    Payment::create([
        'student_id'          => $this->record->id,
        'type'                => $fp['type'],
        'amount'              => $fp['amount'],
        'mode'                => $fp['mode']             ?? null,
        'reference_number'    => $fp['reference_number'] ?? null,
        'received_at'         => $fp['received_at']      ?? now(),
        'proof_url'           => $fp['proof_url']        ?? null,
        'notes'               => $fp['notes']            ?? null,
        'recorded_by_user_id' => auth()->id(),
    ]);
}
```

The inline block wraps `PaymentFormSchema::fields()` inside `Section::make('First payment (optional)')->statePath('first_payment')->collapsed()->visibleOn('create')`.

### PaymentsRelationManager wiring

- `form()` returns `$form->schema(PaymentFormSchema::fields())`.
- `$this->mutateFormDataBeforeCreate($data)` and `mutateFormDataBeforeSave($data)` both call `PaymentFormSchema::resolveProofUpload($data)` before returning.

### Validation

The two entry points have different validation needs:

- **Payments tab:** the user opened the form to record a payment; `amount`, `type`, `received_at` are always required.
- **Student create (inline block):** the whole block is optional; fields are "all-or-nothing" keyed off `amount`.

The shared schema partial takes a flag:

```php
public static function fields(bool $inlineFirstPayment = false): array
```

When `$inlineFirstPayment === false` (Payments tab — default):

```php
TextInput::make('amount')->numeric()->prefix('₹')->required(),
Select::make('type')->options([...])->required(),
DateTimePicker::make('received_at')->default(now())->required(),
```

When `$inlineFirstPayment === true` (student create page):

```php
TextInput::make('amount')->numeric()->prefix('₹'),
Select::make('type')->options([...])
    ->required(fn (Get $get) => filled($get('amount'))),
DateTimePicker::make('received_at')->default(now())
    ->required(fn (Get $get) => filled($get('amount'))),
```

`mode`, `reference_number`, `proof_upload`, `proof_url`, `notes` are optional in both modes.

## Data Changes

**None.** `proof_url` exists; `payments` table supports all required fields; `Payment` model has `protected $guarded = []`. No migration.

## Testing

New: `tests/Feature/StudentFirstPaymentTest.php` (uses `RefreshDatabase` + seeded users, authenticated as Sumit admin):

1. `test_creating_student_with_first_payment_block_persists_one_payment` — Livewire test posts create form with `first_payment.type = advance`, `first_payment.amount = 5000`, `first_payment.received_at = now`. Assert new Student exists; assert `Payment::where('student_id', $id)->count() === 1`; assert `recorded_by_user_id === $actor->id`.
2. `test_creating_student_without_first_payment_block_creates_no_payment` — empty first-payment fields. Student created; zero payments.
3. `test_first_payment_amount_without_type_fails_validation` — amount filled, type empty. Livewire `assertHasFormErrors(['first_payment.type'])`. Because Filament runs the full form through validation before calling `create()`, no Student row is inserted and no Payment row is inserted. Assert `Student::count() === 0` and `Payment::count() === 0`.
4. `test_first_payment_url_fallback_persists_proof_url` — `first_payment.proof_url = 'https://drive.google.com/file/d/abc/view'`, no upload. Stored verbatim on the resulting Payment row.
5. `test_first_payment_file_upload_resolves_to_proof_url` — `Storage::fake('gdrive')`. Submit with `UploadedFile::fake()->image('proof.png')`. Resulting Payment row has a non-null `proof_url`; `Storage::disk('gdrive')->exists()` is true for the saved path.

Extend `tests/Feature/PaymentsRelationManagerTest.php`:

6. `test_payments_tab_accepts_file_upload_and_resolves_to_proof_url` — upload path on the Payments tab, not the student create form.
7. `test_payments_tab_url_fallback_is_unchanged` — existing URL path still works (regression guard).

**Test infrastructure:** A test trait or `setUp()` helper that runs `Storage::fake('gdrive')` so no real Drive traffic is generated.

**Manual smoke after deploy:**
- Create student with a real PNG in first-payment block → Payments tab shows the row → "Open proof" opens the Drive link.
- Add a PDF via the Payments tab → "Open proof" opens the PDF.

## Authorization

- `FileUpload::visibility('private')` — Drive share mode follows the existing `gdrive` disk config. No change to Drive permissions. Operators who can open existing `proof_url` links can open new ones.
- `recorded_by_user_id` is always the currently authenticated admin user (from `auth()->id()`). No way to impersonate another recorder through form data.

## Rollout

1. Merge PR containing the shared schema, create-page hook, and tests.
2. Deploy to `davyas.ipu.co.in` (Hostinger, PHP 8.4 alt-path).
3. Smoke-test once in prod with a real proof file.
4. No data migration. No feature-flag. Shipped for all admin users immediately.

## Risks

- **Drive upload latency.** A 5 MB upload on a slow connection blocks the form submit. Mitigation: size cap at 5 MB; helper text tells the user to compress large files; URL fallback is always available.
- **Drive credential expiry or quota.** If the gdrive disk returns an error during upload, Filament surfaces it inline and the whole form fails to save — the operator can retry with the URL fallback. No partial-save risk because the Student row is created only if form validation passes (upload is part of validation).
- **Filament FileUpload with custom disk gotcha.** `->disk('gdrive')` relies on the existing `flysystem-gdrive` binding — confirm `Storage::disk('gdrive')->put()` works in an artisan tinker session before rollout. If it doesn't, surface the config bug before shipping.
- **Schema shared partial coupling.** Both the Payments tab and the student create form now depend on one file. Rename or remove a field without updating both call sites and one breaks. Mitigated by tests #6 and #7 which exercise both paths.
- **`recorded_by_user_id` fallback.** If `auth()->id()` returns null (it shouldn't, since the admin panel requires auth), the insert fails with an FK error. The controller hook does not guard against this; we rely on the panel's auth middleware.

## Open Items (decided at plan stage, not design stage)

- Whether `PaymentFormSchema` lives under `App\Filament\Resources\Shared` or `App\Filament\Forms` — minor naming choice.
- Whether "Open proof" on the Payments tab should verify the URL is still reachable before opening — probably YAGNI, but worth confirming during plan.
