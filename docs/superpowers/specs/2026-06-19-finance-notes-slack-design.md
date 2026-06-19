# Finance Notes via Slack/n8n — Design

**Date:** 2026-06-19
**Status:** Approved (brainstorming)

## Goal

Let Sumit capture free-form finance/business notes the same way expenses are
captured today: type `note <message>` in Slack → the existing n8n workflow
strips the `note` keyword and POSTs the message to the CRM → the note is stored
and shown under **Finance › Notes** in the Filament admin.

This is a deliberate mirror of the existing expense capture path
(`POST /api/finance/expenses` → `expenses` table → `ExpenseResource`), minus the
amount and minus ledger routing.

## Scope

- Standalone finance notes. A note is just a text message — **not** attached to
  an expense, payment, or student.
- No `amount`, no `category`/tag (YAGNI — easy to add a nullable tag later).
- No `LedgerRoutingService` involvement. Notes never create ledger entries.

## Non-goals

- Editing/replying to notes from Slack.
- Linking notes to expenses or students (explicitly rejected during brainstorming).
- Any change to the existing expense/payment/investment endpoints.

## Components

### 1. Data — `notes` table

New migration `create_notes_table`, parallel to `expenses`:

| column             | type                | notes                                  |
|--------------------|---------------------|----------------------------------------|
| `id`               | id                  |                                        |
| `body`             | text                | the note message                       |
| `slack_message_id` | string(50), unique  | dedup key (same role as on `expenses`) |
| `raw_input`        | text, nullable      | original Slack text                    |
| `noted_at`         | timestamp           | defaults to `now()`                    |
| `created_at`/`updated_at` | timestamps   |                                        |

### 2. Model — `App\Models\Note`

Mirrors `Expense`:

- `protected $guarded = [];`
- casts: `noted_at => datetime`
- `getDisplayIdAttribute()`: `"D{$id}"` when `slack_message_id` is null,
  `"#{$id}"` otherwise (same convention as `Expense`).

### 3. API — `POST /api/finance/notes`

Added inside the existing `finance` route group in `routes/api.php`, so it
reuses `VerifyFinanceToken` (header `X-Finance-Token`, value from
`config('finance.capture_token')`) and `throttle:60,1`.

New `App\Http\Controllers\FinanceNoteController@store` + `StoreNoteRequest`.
Logic mirrors `FinanceExpenseController` exactly, **without** the ledger call:

1. Validate input.
2. Look up existing note by `slack_message_id`; if found, return
   `409 {"error":"duplicate_slack_message","existing_id":...}`.
3. Create the note inside a `DB::transaction`.
4. On a `23000` (unique constraint) `QueryException`, re-check for the existing
   row and return `409` — same race-safety pattern as expenses.
5. `Log::info('finance.note.captured', [...])`.
6. Return `201 {"id": <note id>}`.

**`StoreNoteRequest` rules:**

```php
'body'             => ['required', 'string', 'max:4000'],
'slack_message_id' => ['required', 'string', 'max:50'],
'raw_input'        => ['nullable', 'string', 'max:4000'],
'noted_at'         => ['nullable', 'date'],
```

`authorize()` returns `true` (endpoint is protected by the token middleware, same
as `StoreExpenseRequest`). `failedValidation` throws a `422` JSON response,
identical to the expense request.

**Expected n8n payload:**

```json
{
  "body": "Paid electrician advance, will adjust next month",
  "slack_message_id": "<slack ts/id>",
  "raw_input": "note Paid electrician advance, will adjust next month"
}
```

### 4. Admin — `App\Filament\Resources\NoteResource`

- Nav group `Finance`, `navigationLabel = 'Notes'`, `navigationIcon =
  'heroicon-o-pencil-square'`, `navigationSort = 11` (right after Expenses = 10).
- `canViewAny()` defers to the policy: `auth()->user()?->can('viewAny', Note::class)`.
- **Form:** `Textarea` `body` (required) + disabled, non-dehydrated `raw_input`
  Textarea visible only when `slack_message_id !== null` (mirrors ExpenseResource).
- **Table:** `display_id` (label "ID"), `body` (limited/truncated), `created_at`
  (datetime, sortable). Default sort newest first.
- Pages: List / Create / Edit (standard Filament resource pages).

### 5. Authorization — `App\Policies\NotePolicy`

Mirrors `ExpensePolicy`:

- `viewAny` / `view` / `create` / `update`: `hasAnyRole(['admin', 'finance'])`.
- `delete`: `isSuperAdmin()`.

Auto-discovered by Laravel 11 naming convention (`Note` → `NotePolicy`); no
manual `Gate::policy()` registration needed.

### 6. n8n (Sumit's side — out of repo)

Add a branch to the existing Slack→CRM workflow: if the Slack message text
starts with `note` (case-insensitive), strip the keyword and POST the remainder
as `body` to `POST {APP_URL}/api/finance/notes` with header
`X-Finance-Token: <finance.capture_token>`. Use the Slack message ts/id as
`slack_message_id` for idempotency. Exact node config to be provided after the
Laravel side is built and deployed.

## Data flow

```
Slack: "note <message>"
        │
        ▼
n8n  ──(strip "note", build payload)──▶  POST /api/finance/notes  (X-Finance-Token)
                                              │
                                              ▼
                                   VerifyFinanceToken + throttle
                                              │
                                              ▼
                                   FinanceNoteController@store
                                     ├─ dedup on slack_message_id → 409
                                     └─ Note::create(...)  → 201
                                              │
                                              ▼
                                   Filament: Finance › Notes
```

## Error handling

- Missing/invalid token → `401 {"error":"unauthorized"}` (middleware).
- Validation failure → `422` with `errors` map.
- Duplicate `slack_message_id` → `409` with `existing_id` (both pre-check and
  unique-constraint race path).
- Throttle exceeded → `429` (existing group middleware).

## Testing

Mirror the existing expense tests:

- `tests/Feature/NoteCaptureTest.php` — happy path (`201`, row persisted), dedup
  (`409`), bad/missing token (`401`), validation (`422` on empty `body` / missing
  `slack_message_id`).
- `tests/Feature/NoteResourceTest.php` — role gating (admin/finance can view &
  create; other roles denied; only super_admin can delete).
- `tests/Unit/NoteModelTest.php` — `display_id` accessor for both Slack-sourced
  and manually-created notes.
- `database/factories/NoteFactory.php` to support the above.

## Files

**New:**
- `database/migrations/2026_06_19_000000_create_notes_table.php`
- `app/Models/Note.php`
- `app/Http/Controllers/FinanceNoteController.php`
- `app/Http/Requests/StoreNoteRequest.php`
- `app/Policies/NotePolicy.php`
- `app/Filament/Resources/NoteResource.php`
- `app/Filament/Resources/NoteResource/Pages/{ListNotes,CreateNote,EditNote}.php`
- `database/factories/NoteFactory.php`
- `tests/Feature/NoteCaptureTest.php`
- `tests/Feature/NoteResourceTest.php`
- `tests/Unit/NoteModelTest.php`

**Modified:**
- `routes/api.php` — add `Route::post('/notes', [FinanceNoteController::class, 'store']);`
  inside the `finance` group.
