# Unified Payment/Payout Chooser — Design

**Date:** 2026-06-08
**Status:** Approved (brainstorming) — pending spec review
**Author:** Sumit + Claude

## Problem

The just-shipped payouts feature put payout entry in a Deal-tab repeater, separate from how
payments are entered (Account-tab "+ New Payment" action + Payments relation-manager
CreateAction). Sumit wants ONE entry point: the existing green **"New payment"** button on the
**Payments panel** (relation-manager header) should open a chooser offering **Add Payment /
Update Payment / Add Payout / Update Payout**, then show the matching form.

## Decisions (from brainstorming)

1. **Scope:** REPLACE existing entry points. Remove the Deal-tab payouts repeater and the
   Account-tab "+ New Payment" action. One chooser button only.
2. **Entry point:** the Payments relation-manager header button (currently `CreateAction` labelled
   "New payment"), relabelled **"New payment / payout"**.
3. **Chooser UI:** a single modal. A row of toggle-buttons at the top (`ToggleButtons`, 4 options);
   selecting one reveals the matching form below; one Save button. (NOT a multi-modal swap — see
   Rationale.)
4. **Update flow:** pick the record from a dropdown of the student's existing payments/payouts
   (labelled `₹40,000 · advance · 09 Jun` etc.); its fields load; edit + Save updates that row.
5. **Browsing:** add a **Payouts** relation-manager tab (mirrors Payments) for viewing/editing
   payout history; no create button on it (adds go through the chooser).
6. **Fully revert the Deal & Counselling tab** to its pre-payouts state: remove the payouts
   repeater AND remove the live Expected-profit placeholder entirely (no read-only line either).
   The `plan` dropdown options (Sitting / Counselling Online / Counselling Offline) STAY — that was
   a separately-requested feature, not a payout change. Expected profit stays visible via the
   students-list column and the Payment Report rollup.

## Rationale for single-modal ToggleButtons (not blade buttons that swap the modal)

`reference_filament_modal_wireclick_trap`: `wire:click` inside an action's `modalContent` silently
no-ops because Filament teleports the modal out of the owning Livewire component's DOM, so Livewire
never binds the directive (this bit the Books module, F14). A "modal with 4 buttons that each
`replaceMountedAction`" would rely on exactly that. Instead we use a `ToggleButtons` form field
(`->live()`) at the top of one action form; conditional field groups appear below based on its
value. This is the same visual ("buttons, then the form") without the teleport trap, and keeps a
single form/single submit handler.

## Component: the chooser action

Lives on `PaymentsRelationManager::table()->headerActions()`, replacing the current `CreateAction`.
A custom `Tables\Actions\Action::make('newPaymentPayout')`:

- `->label('New payment / payout')`, success color, banknotes icon, `->modalWidth('xl')`.
- `->modalHeading('New payment / payout')`, dynamic submit label.
- `->form([...])` (below). Owner record (the student) via `$this->getOwnerRecord()` /
  the action's `$livewire->getOwnerRecord()`.
- `->action(fn (array $data) => ...)` branches on `$data['entry_action']`.

### Form schema (one form, no field-name collisions)

The chooser toggle is named **`entry_action`** (NOT `mode` — `mode` collides with the payment
`mode` field). Payout fields are **prefixed** (`payout_*`) so they never collide with payment
fields (`amount`, `notes` exist in both).

```
ToggleButtons::make('entry_action')
    ->label('What do you want to do?')
    ->options([
        'add_payment'    => 'Add Payment',
        'update_payment' => 'Update Payment',
        'add_payout'     => 'Add Payout',
        'update_payout'  => 'Update Payout',
    ])
    ->inline()->live()->required()->default('add_payment')->columnSpanFull(),

// --- record pickers (update modes) ---
Select::make('payment_id')
    ->label('Which payment?')
    ->options(fn () => $ownerPaymentsOptions())   // id => "₹40,000 · advance · 09 Jun"
    ->live()
    ->required()
    ->visible(fn (Get $get) => $get('entry_action') === 'update_payment')
    ->afterStateUpdated(function ($state, Set $set) {
        $p = Payment::find($state);
        if (! $p) return;
        $set('amount', $p->amount);
        $set('mode', $p->mode);
        $set('type', $p->type);
        $set('received_at', $p->received_at);
        $set('reference_number', $p->reference_number);
        $set('notes', $p->notes);
    }),
Select::make('payout_id')
    ->label('Which payout?')
    ->options(fn () => $ownerPayoutsOptions())    // id => "₹40,000 · College · 09 Jun"
    ->live()
    ->required()
    ->visible(fn (Get $get) => $get('entry_action') === 'update_payout')
    ->afterStateUpdated(function ($state, Set $set) {
        $po = Payout::find($state);
        if (! $po) return;
        $set('payout_payee_type', $po->payee_type);
        $set('payout_payee_name', $po->payee_name);
        $set('payout_amount', $po->amount);
        $set('payout_status', $po->status);
        $set('payout_paid_at', $po->paid_at);
        $set('payout_notes', $po->notes);
    }),

// --- payment fields (shared by add_payment + update_payment) ---
Group::make(PaymentFormSchema::fields(inlineFirstPayment: false))
    ->visible(fn (Get $get) => in_array($get('entry_action'), ['add_payment', 'update_payment'], true))
    ->columnSpanFull(),

// --- payout fields (shared by add_payout + update_payout), payout_* prefixed ---
Group::make([
    Select::make('payout_payee_type')->label('Payee')->options(['college' => 'College', 'other' => 'Other'])->default('college')->required(),
    TextInput::make('payout_payee_name')->label('Payee name')->placeholder('College / party name')->maxLength(120),
    TextInput::make('payout_amount')->label('Amount')->numeric()->prefix('₹')->required(),
    Select::make('payout_status')->label('Status')->options(['to_pay' => 'To be paid', 'paid' => 'Paid'])->default('to_pay')->live()->required(),
    DateTimePicker::make('payout_paid_at')->label('Paid on')->visible(fn (Get $get) => $get('payout_status') === 'paid'),
    Textarea::make('payout_notes')->label('Notes')->rows(2)->columnSpanFull(),
])
    ->visible(fn (Get $get) => in_array($get('entry_action'), ['add_payout', 'update_payout'], true))
    ->columns(['default' => 1, 'md' => 2])
    ->columnSpanFull(),
```

Option builders (private methods on the relation manager):
- `$ownerPaymentsOptions()` → `$this->getOwnerRecord()->payments()->latest('received_at')->get()
  ->mapWithKeys(fn ($p) => [$p->id => '₹'.number_format($p->amount,0).' · '.$p->type.' · '.$p->received_at?->format('d M')])`
- `$ownerPayoutsOptions()` → `$this->getOwnerRecord()->payouts()->latest()->get()
  ->mapWithKeys(fn ($po) => [$po->id => '₹'.number_format($po->amount,0).' · '.ucfirst($po->payee_type).' · '.$po->created_at?->format('d M')])`

### Submit handler

```
->action(function (array $data) {
    $student = $this->getOwnerRecord();
    switch ($data['entry_action']) {
        case 'add_payment':
            $d = PaymentFormSchema::resolveProofUpload($data);
            $student->payments()->create(paymentAttrs($d) + ['recorded_by_user_id' => auth()->id()]);
            $msg = 'Payment recorded'; break;
        case 'update_payment':
            $d = PaymentFormSchema::resolveProofUpload($data);
            Payment::findOrFail($data['payment_id'])->update(paymentAttrs($d));
            $msg = 'Payment updated'; break;
        case 'add_payout':
            $student->payouts()->create(payoutAttrs($data) + ['recorded_by_user_id' => auth()->id()]);
            $msg = 'Payout recorded'; break;
        case 'update_payout':
            Payout::findOrFail($data['payout_id'])->update(payoutAttrs($data));
            $msg = 'Payout updated'; break;
    }
    Notification::make()->success()->title($msg)->send();
})
```

- `paymentAttrs($d)` = pull the payment-named keys (`amount`, `mode`, `type`, `received_at`,
  `reference_number`, `proof_drive_url`/proof, `notes`) from `$d` (whatever `PaymentFormSchema`
  produces — match it exactly).
- `payoutAttrs($data)` = map prefixed keys → columns: `payee_type` ← `payout_payee_type`,
  `payee_name` ← `payout_payee_name`, `amount` ← `payout_amount`, `status` ← `payout_status`,
  `paid_at` ← `payout_paid_at`, `notes` ← `payout_notes`. (`Payout::booted()` still forces amount
  positive + manages `paid_at` by status.)

The relation manager's table `recordTitleAttribute`, columns, per-row Edit/Delete stay as-is.

## Removals

1. **Deal tab** (`StudentResource.php`): remove the `Repeater::make('payouts')` block AND the
   `Placeholder::make('expected_profit_preview')` block entirely — i.e. revert the Deal &
   Counselling tab to exactly its pre-payouts state (only `deal_amount` + the registration/seat/
   plan selects remain, with `plan` keeping its new options). Payouts are added/edited solely via
   the chooser; profit is seen on the list column + Payment Report.
2. **Account tab** (`StudentResource.php`): remove the `Action::make('addPayment')` from the
   `Actions::make([...])` block. Keep `addNote`.
   - If removing `addPayment` leaves the `Actions::make([...])` with only `addNote`, keep the
     `Actions` wrapper with the single remaining action.
3. Remove now-unused imports from `StudentResource.php` that were added for the repeater/placeholder
   (`Repeater`, `Placeholder`, `DateTimePicker`, `Get`, `Illuminate\Support\HtmlString`) — but only
   if grep confirms they're no longer referenced anywhere in the file. Keep `MoneyFormat` (still
   used by the table money columns). `php -l` + Pint must pass.

## New: Payouts relation manager

`app/Filament/Resources/StudentResource/RelationManagers/PayoutsRelationManager.php`
(mirrors PaymentsRelationManager):
- `protected static string $relationship = 'payouts';`
- `form()` → the payout fields (payee_type/payee_name/amount/status/paid_at/notes) using their
  REAL column names (this form is for the per-row Edit action, so no `payout_` prefix here).
- `table()` columns: `created_at` (since + tooltip), `payee_type` (badge), `payee_name`, `amount`
  (MoneyFormat html), `status` (badge), `recordedBy.name`.
- Header actions: NONE (no create button — adds go through the chooser on the Payments panel).
- Row actions: `EditAction` (sets `recorded_by_user_id` only on create, not needed on edit),
  `DeleteAction`. Bulk: DeleteBulkAction.
- Register in `StudentResource::getRelations()` right after `PaymentsRelationManager::class`.

## Testing

Feature tests (`tests/Feature/PaymentPayoutChooserTest.php`), driving the relation manager via
`Livewire::test(PaymentsRelationManager::class, ['ownerRecord' => $student, 'pageClass' => EditStudent::class])`
and `callTableAction('newPaymentPayout', data: [...])` (match the project's existing relation-manager
test pattern in `tests/Feature/PaymentsRelationManagerTest.php`):
- `add_payment` creates a Payment on the student with recorder stamped.
- `add_payout` creates a Payout with recorder stamped (amount positive, paid_at by status).
- `update_payment` with `payment_id` updates that payment's amount.
- `update_payout` with `payout_id` updates that payout's amount/status.
- `PayoutsRelationManager` renders its table for a student with payouts.
- Smoke: `EditStudent` / `CreateStudent` page still mounts after the Deal-tab repeater removal and
  Account-tab action removal (no leftover references); Deal tab no longer contains a payouts
  repeater.

Existing suite (892 after the payouts feature) stays green; the prior `StudentPayoutFormTest`
relationship-contract test still holds (payouts relation unchanged). If any existing test asserted
the Deal-tab repeater or Account `addPayment` specifically, update it to the new contract.

## Out of scope (YAGNI)

- Deleting payments/payouts from the chooser (use the panel row Delete).
- Multi-record / bulk add in one modal.
- Re-introducing payout entry on the student CREATE form (save first, then chooser — same as
  payments today).
- Any change to the profit accessors, list column, or Payment Report rollup (unchanged).

## Deploy notes

No migrations. New relation-manager class (`PayoutsRelationManager`) + modified StudentResource +
PaymentsRelationManager. Per `reference_hostinger_fpm_opcache`: a NEW Filament relation-manager
class can 404/blank until FPM opcache picks it up — after deploy, verify the Payouts panel renders
in-browser; if stale, the route/view cache rebuild in the standard recipe usually suffices, else
trigger the FPM toggle. Standard recipe otherwise (SSH → git pull → composer → migrate (none) →
seeders → 3 caches → curl-verify).
