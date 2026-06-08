# Clickable Stage Summary → Add/Update/Delete Chooser — Design

**Date:** 2026-06-08
**Status:** Approved (brainstorming) — pending spec review
**Author:** Sumit + Claude

## Problem

The unified payment/payout chooser currently lives as a header action on the Payments
relation-manager. Sumit wants to (a) click the **Stage money summary** line at the top of the
student edit form to open the chooser, (b) add **Delete** to the chooser (so it's Add/Update/Delete
× Payment/Payout), and (c) remove the chooser button from the Payments panel.

## Decisions (from brainstorming)

1. Clicking the Stage summary opens the **same chooser**, extended with Delete → 6 modes.
2. **Replace** the Payments-panel "New payment / payout" header button (remove it). Records remain
   editable/deletable via the panels' per-row Edit/Delete.
3. Chooser moves to a **page-level action** on `EditStudent` (`newPaymentPayout`), mounted via
   Filament's `mountAction()`. A header button also appears (top-right) — accepted.
4. **Delete** mode: show only the record-picker dropdown; Save removes the record. The modal is the
   confirmation (no extra confirm dialog).

## Architecture

### Shared builder
`app/Filament/Support/PaymentPayoutChooser.php` — a small class with
`public static function make(): \Filament\Actions\Action` returning the fully-configured page
action. Keeps `EditStudent` lean and the chooser logic in one testable place. (It uses the
page-action namespace `Filament\Actions\Action`, NOT `Filament\Tables\Actions\Action`.)

The action's record (the student) is reached inside closures via the injected `$livewire`
(the EditStudent component): `$livewire->getRecord()`.

### The action (`newPaymentPayout`)

```php
\Filament\Actions\Action::make('newPaymentPayout')
    ->label('New payment / payout')
    ->icon('heroicon-o-banknotes')
    ->color('success')
    ->modalHeading('New payment / payout')
    ->modalWidth('xl')
    ->modalSubmitActionLabel('Save')
    ->form([... see below ...])
    ->action(function (array $data, $livewire) { ... switch on entry_action ... })
```

Form schema (same collision-avoidance as before: toggle is `entry_action`, payout fields are
`payout_*`-prefixed):

- `ToggleButtons::make('entry_action')` `->live()` `->default('add_payment')`, options:
  `add_payment` Add Payment / `update_payment` Update Payment / `delete_payment` Delete Payment /
  `add_payout` Add Payout / `update_payout` Update Payout / `delete_payout` Delete Payout.
- `Select::make('payment_id')` — options = owner's payments (`₹40,000 · advance · 09 Jun`),
  `->live()`, visible when `entry_action ∈ {update_payment, delete_payment}`, required. On select
  (update only) `afterStateUpdated` loads payment fields.
- `Select::make('payout_id')` — owner's payouts, visible when `∈ {update_payout, delete_payout}`,
  required; `afterStateUpdated` loads payout fields.
- Payment field `Group` (`PaymentFormSchema::fields()`) visible when `∈ {add_payment, update_payment}`.
- Payout field `Group` (`payout_*` fields) visible when `∈ {add_payout, update_payout}`.
- A `Placeholder` warning ("This permanently deletes the selected record.") visible when
  `∈ {delete_payment, delete_payout}`.

Submit handler `switch ($data['entry_action'])`:
- `add_payment` → `$student->payments()->create(paymentAttrs + recorded_by)`; toast "Payment recorded".
- `update_payment` → `Payment::findOrFail($data['payment_id'])->update(paymentAttrs)`; "Payment updated".
- `delete_payment` → `Payment::findOrFail($data['payment_id'])->delete()`; "Payment deleted".
- `add_payout` → `$student->payouts()->create(payoutAttrs + recorded_by)`; "Payout recorded".
- `update_payout` → `Payout::findOrFail($data['payout_id'])->update(payoutAttrs)`; "Payout updated".
- `delete_payout` → `Payout::findOrFail($data['payout_id'])->delete()`; "Payout deleted".

`paymentAttrs` / `payoutAttrs` exactly as in the existing relation-manager chooser
(`paymentAttrs` = `resolveProofUpload` then `Arr::only(['type','amount','mode','reference_number','received_at','proof_url','notes'])`;
`payoutAttrs` maps `payout_*` → payout columns).

### EditStudent wiring
`EditStudent::getHeaderActions()` adds `PaymentPayoutChooser::make()` alongside the existing
`DeleteAction`.

### Clickable summary
`student-money-summary.blade.php`: wrap the existing colored one-line summary in
`<button type="button" wire:click="mountAction('newPaymentPayout')" class="... cursor-pointer ...">`
with a subtle hover (e.g. underline/opacity). Keep the colored short-amount segments inside.
Because the summary renders inside the EditStudent component's own DOM (a form `View`, not modal
content), `wire:click` binds and reaches the page's `mountAction` — this is NOT the
modal-teleport trap. Only shown on existing students (already gated).

### Payments panel
Remove the `newPaymentPayout` header action from `PaymentsRelationManager` (no header action left;
imports added for it that become unused are removed). Row actions (open_proof / Edit / Delete) and
bulk delete stay. Payouts panel unchanged.

## Testing

`tests/Feature/PaymentPayoutChooserTest.php` rewritten to drive the page action:
`Livewire::test(EditStudent::class, ['record' => $student->getRouteKey()])->callAction('newPaymentPayout', data: [...])`
for all six branches:
- add_payment creates a payment (recorder stamped);
- update_payment updates the picked payment;
- delete_payment removes the picked payment (`assertDatabaseMissing`);
- add_payout creates a payout (recorder stamped, amount positive);
- update_payout updates the picked payout;
- delete_payout removes the picked payout.

Plus:
- `StudentFormRevertTest` (or the chooser test): the summary renders a button with
  `wire:click="mountAction('newPaymentPayout')"` for an existing student.
- `PaymentsRelationManagerTest`: the 3 tests currently calling `newPaymentPayout` on the relation
  manager are rewritten — payment-create + proof-upload coverage MOVES to the page chooser test
  (`add_payment` with `proof_upload`); any remaining RM test asserts the panel no longer exposes a
  create/`newPaymentPayout` header action (or simply tests row Edit). No coverage is lost.

Full suite (900 after the prior chooser work) stays green (net count shifts as RM tests are rewritten into page-action tests + delete cases added).

## Out of scope (YAGNI)

- Per-segment clickability (whole line opens one chooser).
- Bulk delete from the chooser (panel bulk-delete already exists).
- Extra confirmation dialog for delete (the modal is the confirm step).
- Changing profit accessors / list column / Payment Report.

## Deploy notes

No migrations. New class `App\Filament\Support\PaymentPayoutChooser` + modified EditStudent,
PaymentsRelationManager, summary blade. Per `reference_hostinger_fpm_opcache`, a NEW PHP class can
need an FPM opcache refresh — the standard route/view cache rebuild usually suffices; verify the
edit page + clickable summary + chooser modal in-browser after deploy. Standard recipe otherwise
(SSH → git pull → composer → migrate (none) → 3 caches → curl-verify; browser-confirm the action).
