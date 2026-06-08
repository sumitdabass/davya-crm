# Per-Segment Clickable Summary — Design

**Date:** 2026-06-08
**Status:** Approved (brainstorming) — pending spec review
**Author:** Sumit + Claude

## Problem

The Stage money-summary currently opens one combined chooser (toggle Add/Update/Delete ×
Payment/Payout) via a whole-line click + a header button. Sumit wants each **segment** to be its own
entry point: click **deal** → edit the deal amount; **received** → manage payments; **payouts** →
manage payouts. Derived segments (**pending**, **profit**) are not editable. Drop the old combined
button/whole-line click.

## Decisions (from brainstorming)

1. `deal` click → edit `deal_amount` (saves to the student). `pending` and `profit` are plain text
   (derived, not clickable).
2. `received` and `payouts` each open a **scoped Add/Update/Delete** modal (payment-only /
   payout-only — no payment/payout toggle).
3. Remove the combined `newPaymentPayout` action, its header button, and the whole-line click.

## Key feasibility note

Filament v3.3.50 `InteractsWithActions::mountAction()` aborts only when the action is **not found**
or **`isDisabled()`** — it does NOT check `isHidden()`. So a page action registered in
`getHeaderActions()` as `->hidden()` renders **no button** yet is still mountable via
`mountAction('name')`. We use this: the three segment actions are hidden header actions; the
summary segments mount them with `wire:click`. (This is the same wire:click→mountAction path
already shipped and working for the current summary — the summary is inline form content, not modal
content, so no teleport trap.)

## Architecture

### Builder: `app/Filament/Support/PaymentPayoutChooser.php`
Refactor the single `make()` into three static builders (each returns `Filament\Actions\Action`):

**`dealAction(): Action`** — name `editDeal`:
- `->fillForm(fn ($livewire) => ['deal_amount' => $livewire->getRecord()->deal_amount])`
- form: `TextInput::make('deal_amount')->numeric()->prefix('₹')->label('Deal amount')`
- `->action(fn (array $data, $livewire) => $livewire->getRecord()->update(['deal_amount' => $data['deal_amount']]))`
  + success toast "Deal amount updated". `->modalHeading('Edit deal amount')->modalWidth('sm')`.

**`paymentAction(): Action`** — name `managePayment`, `->modalHeading('Payment')->modalWidth('xl')`:
- `ToggleButtons::make('entry_action')->options(['add'=>'Add','update'=>'Update','delete'=>'Delete'])`
  `->inline()->live()->required()->default('add')->columnSpanFull()`.
- `Select::make('payment_id')` options = `$livewire->getRecord()->payments()` (`₹X · type · date`),
  visible when `entry_action ∈ {update, delete}`, `->live()->required()`; `afterStateUpdated` loads
  the payment's fields (type/amount/mode/reference_number/received_at/proof_url/notes).
- `Placeholder` delete warning visible when `entry_action === 'delete'`.
- `Group::make(PaymentFormSchema::fields(inlineFirstPayment: false))` visible when
  `entry_action ∈ {add, update}`.
- `->action`: `add` → `$student->payments()->create(paymentAttrs + recorded_by)`;
  `update` → `Payment::findOrFail($data['payment_id'])->update(paymentAttrs)`;
  `delete` → `Payment::findOrFail($data['payment_id'])->delete()`. Toasts accordingly.
  (`paymentAttrs` = `resolveProofUpload` then `Arr::only(['type','amount','mode','reference_number','received_at','proof_url','notes'])`.)

**`payoutAction(): Action`** — name `managePayout`, `->modalHeading('Payout')->modalWidth('xl')`.
Because it's a separate form (no payment fields present), payout fields use their **real column
names** (no `payout_` prefix needed):
- `ToggleButtons::make('entry_action')` (add/update/delete) as above.
- `Select::make('payout_id')` options = `$livewire->getRecord()->payouts()` (`₹X · College · date`),
  visible `∈ {update, delete}`, loads fields on select.
- `Placeholder` delete warning visible when delete.
- `Group` (visible add/update): `payee_type` (College/Other), `payee_name`, `amount` (₹ required),
  `status` (To be paid/Paid, `->live()`), `paid_at` (visible when status=paid), `notes`.
- `->action`: `add` → `$student->payouts()->create(payoutAttrs + recorded_by)`;
  `update` → `Payout::findOrFail($data['payout_id'])->update(payoutAttrs)`;
  `delete` → `Payout::findOrFail($data['payout_id'])->delete()`.
  (`payoutAttrs` = `Arr::only(['payee_type','payee_name','amount','status','paid_at','notes'])`.)

Remove the old combined `make()`.

### EditStudent wiring
`getHeaderActions()`:
```php
return [
    PaymentPayoutChooser::dealAction()->hidden(),
    PaymentPayoutChooser::paymentAction()->hidden(),
    PaymentPayoutChooser::payoutAction()->hidden(),
    Actions\DeleteAction::make(),
];
```
Hidden = no rendered buttons, still mountable. DeleteAction stays visible.

### Summary blade
`student-money-summary.blade.php`: keep the colored inline line. Wrap each editable segment in an
inline `<button type="button" wire:click="mountAction('…')" style="display:inline; …">`:
- deal → `mountAction('editDeal')`
- received → `mountAction('managePayment')`
- payouts → `mountAction('managePayout')`

`pending` and `profit` stay as plain `<span>` (with their warning/danger colors). Buttons get a
hover affordance (underline) and `cursor:pointer`; styled inline so the `·`-separated line is
preserved.

## Testing

`tests/Feature/PaymentPayoutChooserTest.php` rewritten to the three actions via
`Livewire::test(EditStudent::class, ['record' => …])->callAction('<name>', data: […])->assertHasNoActionErrors()`:
- `editDeal` updates `deal_amount`.
- `managePayment` add (recorder stamped) / update (amount changes) / delete (`assertDatabaseMissing`)
  + the two proof scenarios (file upload resolves proof_url; url fallback persists).
- `managePayout` add (recorder stamped) / update / delete.

`StudentFormRevertTest`: the summary renders three distinct buttons —
`assertSeeHtml("mountAction('editDeal')")`, `…('managePayment')`, `…('managePayout')`.

`PaymentsRelationManagerTest`: the panel still has no header create/chooser action (unchanged
assertion).

Full suite (903) stays green; net count shifts with the split actions + delete cases.

## Out of scope (YAGNI)

- pending / profit clickability (derived).
- A visible header button (segments are the entry points; DeleteAction is the only header button).
- Bulk operations from the modals (panel bulk-delete exists).
- Changing profit accessors / list column / Payment Report.

## Deploy notes

No migrations. Modified `PaymentPayoutChooser`, EditStudent, summary blade, tests. No new class
files (PaymentPayoutChooser already exists). Standard recipe (pull → composer → migrate (none) →
3 caches → curl-verify); browser-confirm each segment opens its scoped modal and editDeal saves.
