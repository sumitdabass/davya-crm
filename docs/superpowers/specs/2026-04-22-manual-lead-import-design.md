# Manual Bulk Lead Import — Design Spec

**Date:** 2026-04-22
**Status:** Approved for planning
**Owner:** Sumit

## Context

Since 2026-04-17 the n8n Google Sheets OAuth credential has been intermittently broken (`reference_n8n_sheets_trigger_broken`), and even with it working the three Sheet-trigger workflows are opaque to operators — rejections surface only in the central Rejections spreadsheet, with no single screen showing "what landed, what got merged, what got flagged". Sumit wants to **pause** the three Sheet-trigger workflows and handle lead intake manually: paste or upload a block of rows, see exactly what the system will do, confirm, commit.

This is a pause, not a replacement. The `/api/leads` webhook, the legacy `Davya Lead Capture` Forms workflow, and the ability to re-enable the Sheet-trigger workflows all stay intact (`feedback_keep_n8n`).

The heavy lifting (phone normalization, Sonam > Nikhil > Sumit dedup priority, re-parenting payments/notes on demotion, `DuplicateFlag` creation) already exists in `app/Services/LeadIntakeService.php` (shipped 2026-04-22 as PR #5). This feature is a **preview-then-commit UI** that feeds that service, nothing more.

## Goals

- Admin-only bulk import screen at `/admin/lead-import`.
- Four source templates matching the existing n8n mappings 1:1 (Sonam, Nikhil, Sumit-website, canonical).
- Two input modes: paste TSV from Google Sheets, or upload CSV/XLSX.
- Preview screen showing create/merge/flag/reject counts before any DB write.
- Single-transaction commit with post-commit Rejections CSV download.
- Zero new dedup or validation logic — everything routes through `LeadIntakeService`.

## Non-Goals

- Two-way sync to Google Sheets.
- Mirroring rejections to the central `Rejections` spreadsheet (it was an n8n-era artifact to surface errors that were otherwise invisible; on-screen preview + CSV download replaces its purpose for manual imports). If Sheet-trigger workflows are later re-enabled alongside manual imports, they keep writing there on their own.
- Role-aware scoping for imports. Admin-only by design — heads/counsellors cannot import.
- Scheduled or API-driven bulk import. This screen is manual, human-in-the-loop.

## Architecture

```
Choose source ───> Paste TSV OR Upload CSV/XLSX
                          │
                          v
                    Parser (TSV | CSV | XLSX)
                          │
                          v
              SourceMapper (per-source column map)
                          │
                          v
       LeadIntakeService::preview(canonicalLead)    [no DB writes]
                          │
                          v
               ImportAction[] aggregated
                          │
                          v
            Preview screen: ✅N create  🔁M merge  🚩K flag  ❌P reject
                          │
               [Confirm import]
                          │
                          v
    DB::transaction { LeadIntakeService::ingest() per non-rejected row }
                          │
                          v
     LeadImportBatch row written + Rejections.csv download link
```

The preview and commit phases walk the same rows through the same decision logic. Divergence is prevented by refactoring `LeadIntakeService::ingest()` to internally call `preview()`, and enforced by a parity test.

## Components

### 1. `app/Filament/Pages/LeadImport.php`

Filament page under **Reports** group, admin-only (same gate as `/admin/duplicate-flags`). Livewire-driven stepped form:

1. **Source** — radio: Sonam / Nikhil / Sumit-website / Other (canonical). Shows a "Download template" link per source.
2. **Input** — tabs for "Paste" (textarea, expects TSV with header row) and "Upload" (file input, accepts `.csv`, `.tsv`, `.xlsx`).
3. **Preview** — summary counts + per-bucket expandable table (first 50 rows per bucket, rest collapsed behind "+N more"). Cancel button returns to step 2 with the input preserved.
4. **Done** — success toast + "Download rejections CSV" button + "Import another batch" link.

### 2. `app/Services/LeadImport/SourceMapper` interface

```php
interface SourceMapper {
    public function expectedHeaders(): array;           // for template + validation
    public function map(array $row): array;             // raw row → canonical payload
    public function ownerHint(): string;                // 'sonam' | 'nikhil' | 'sumit'
}
```

Four implementations: `SonamMapper`, `NikhilMapper`, `SumitWebsiteMapper`, `CanonicalMapper`. Each mirrors the Set-node logic of its corresponding n8n workflow. Canonical output shape matches what `/api/leads` already accepts, so `LeadIntakeService` needs no changes to accept mapped rows.

`SonamMapper` handles the narrow columns `Date | Ph no | Course | Rank | D/OD | enquiry | connected to.` (note the trailing dot). `connected to.` → `referrer_name`.

### 3. `app/Services/LeadImport/Parser` interface

```php
interface Parser {
    public function parse(string $raw, array $expectedHeaders): array;   // ['rows' => [...], 'errors' => [...]]
}
```

- `TsvParser` — native `str_getcsv` with `"\t"` separator, per line.
- `CsvParser` — native `str_getcsv` with `,` separator.
- `XlsxParser` — PhpSpreadsheet, first sheet, first row as headers.

PhpSpreadsheet dependency: confirmed at plan-time. If not already transitively present via a Filament export package, add `phpoffice/phpspreadsheet` explicitly — it's ~8 MB, used server-side only, acceptable.

All parsers return rows keyed by header name. Header mismatch (missing required columns) = whole-batch parse error; no preview renders.

### 4. `app/Services/LeadImport/LeadImportService`

Orchestrator. Two public methods:

```php
public function preview(string $source, string $raw|UploadedFile $input): ImportPreview;
public function commit(ImportPreview $preview, User $user): LeadImportBatch;
```

`preview()` picks the mapper + parser, walks rows, calls `LeadIntakeService::preview()` per row, returns an `ImportPreview` value object holding the source, the user, and an `ImportAction[]` grouped by bucket. The preview is not written to the database; Livewire retains it on the component (serialized in the request payload, not session) so "Confirm" re-posts it back on commit. If the user abandons the preview, it's garbage-collected with the component.

`commit()` wraps `DB::transaction`, iterates non-rejected rows, calls `LeadIntakeService::ingest()` on each, then writes one `LeadImportBatch` row with aggregated counts. Rejection rows are serialized to a temporary CSV in `storage/app/lead-imports/{batch_id}.csv`, served once via a signed route, and deleted on download.

### 5. `LeadIntakeService` refactor

Extract the decision logic:

```php
public function preview(array $canonicalLead): ImportAction;  // new, pure, no writes
public function ingest(array $canonicalLead): Student|null;   // refactored to call preview() then act
```

`ImportAction` is a value object: `{action: 'create'|'merge'|'flag'|'reject', existingStudentId: ?int, reason: ?string, mappedPayload: array}`. Parity test locks the invariant that `ingest(x)` and `preview(x)` agree on `action` for the same `x`.

### 6. Data model: `LeadImportBatch`

New migration. One row per successful commit. Ride on Spatie ActivityLog for the `created_by` + timestamp trail.

```
id, user_id, source, row_count, created_count, merged_count, flagged_count, rejected_count,
rejections_csv_path (nullable, cleared after download), created_at
```

No `lead_import_batch_id` foreign key on `students` — a student from a batch has the same shape as a student from n8n; conflating them creates join noise for no benefit. The batch row is audit trail, not a relationship.

### 7. Templates (static files)

Four CSVs in `public/templates/`:

- `lead-import-sonam.csv` — header row only, matches Sonam's sheet
- `lead-import-nikhil.csv` — matches Nikhil's sheet
- `lead-import-sumit-website.csv` — matches the website-form sheet
- `lead-import-canonical.csv` — CRM-canonical columns (`phone, name, course, rank, state, referrer_name, notes, source`)

Linked from each source radio on step 1.

## Data Flow Detail

1. Admin opens `/admin/lead-import`, picks "Sonam", downloads template if needed.
2. Admin copies 40 rows from Sonam's Google Sheet (header + 40 data rows), pastes into the textarea. Clicks "Preview".
3. Server: `LeadImportService::preview('sonam', $raw)` → `TsvParser` splits to 40 associative arrays → `SonamMapper::map()` produces 40 canonical payloads → `LeadIntakeService::preview()` decides each.
4. Server returns `ImportPreview` with, e.g., 28 create / 8 merge / 3 flag / 1 reject. Livewire stores preview in session keyed by a UUID.
5. Admin inspects the 4 bucket tables, clicks "Confirm import".
6. Server: `LeadImportService::commit($preview, $user)` → transaction → 39 ingests + 1 batch row written → rejection row written to `storage/app/lead-imports/{uuid}.csv`.
7. Admin sees "Done" screen with download button. Clicking it streams the CSV and clears `rejections_csv_path`.

## Error Handling

| Failure mode | Behavior |
|---|---|
| Paste empty / file 0 bytes | Form validation, no server call |
| Parse fail (bad header, unparseable XLSX) | Full-page error before preview renders; original input preserved in form |
| Per-row validation (blank phone, unparseable course) | Row lands in reject bucket with per-row reason; preview renders normally |
| Commit-phase exception (DB constraint, service throw) | Transaction rolls back; user sees "Import failed, nothing was saved" with exception class + ID for logs; same input can be re-previewed and re-committed |
| CSV download after file already deleted (post-download one-shot OR 7d retention cron) | Show "Rejections CSV no longer available" with the batch summary counts still readable from `LeadImportBatch` |

## Testing

- **Feature tests, one per source** (`tests/Feature/LeadImport/*`):
  - Happy-path create: N clean rows → N students + 1 batch.
  - Dedup demote: existing Sumit row with phone P; Nikhil-sourced upload with phone P demotes Sumit, re-parents payments/notes.
  - Conflict flag: existing Sonam row with phone P; Nikhil-sourced upload with phone P creates `DuplicateFlag`, both marked `flagged_for_review`.
  - Bad phone: reject bucket, no DB write, CSV contains original row + reason.
- **Mapper unit tests** (`tests/Unit/LeadImport/Mappers/*`): each mapper on 3 sample rows — clean, whitespace-messy, optional columns missing.
- **Parser unit tests**: TSV/CSV/XLSX on a 5-row fixture + 2 malformed fixtures.
- **Parity test** (`tests/Unit/LeadIntakeServiceParityTest.php`): 20-row fixture where `preview(x).action` equals the action taken by `ingest(x)` for every row. Regresses if preview drifts from commit.
- **Filament page test**: authorization (non-admin gets 403), step transitions, commit wires through to service.

## Rollout

1. Merge code + migration to `main`.
2. Deploy to prod (Hostinger, standard pull-based deploy).
3. **Deactivate** the three Sheet-trigger workflows in n8n UI: `7cqS00mq6r2yGJDG`, `v3b8K2UC08QY4V3H`, `P1e55kFMiE7AYlmN`. `/api/leads` and `Davya Lead Capture` stay active.
4. Sumit does first live import (small batch, ~10 rows from Sonam) to confirm the flow end-to-end in prod.
5. Document the new flow in `docs/LEAD_IMPORT.md` — one page, screenshots optional.

## Open Questions (resolved during planning)

- **PhpSpreadsheet already transitively available?** Check composer.lock at plan time; if not, add it.
- **Rejections CSV retention?** Default 7 days, purged by existing `storage:prune` or a new tiny artisan command. Finalize at plan time.
- **Reservation of batch UUID for preview session?** Do we need a `LeadImportBatch` row pre-commit (with `status=pending`) for audit, or is session-only fine? Recommendation: session-only. No audit value in previews that never commit.
