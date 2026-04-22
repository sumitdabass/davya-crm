# Manual Bulk Lead Import

Admin-only screen at `/admin/lead-import`. Built to replace the three n8n Sheet-trigger workflows while they're paused.

## How to use

1. **Pick source** — Sonam / Nikhil / Sumit-website / Other (canonical).
2. **Paste rows** — copy a range from the matching Google Sheet (including the header row) into the textarea. OR upload a CSV/XLSX. The "Download template" link shows the exact columns expected for each source.
3. **Preview** — click **Preview**. You'll see counts for create / merge / flag / reject plus a row-level table. Nothing is written yet.
4. **Confirm import** — click it. All non-rejected rows are ingested inside one DB transaction. If anything throws, everything rolls back.
5. **Done** — the summary shows the committed counts. If there were rejections, click the one-shot download link for the CSV. The link works exactly once — the server deletes the file after you download it.

## Source templates

| Source          | Columns                                                                 |
| --------------- | ----------------------------------------------------------------------- |
| Sonam           | `Date, Ph no, Course, Rank, D/OD, enquiry, connected to.`               |
| Nikhil          | `Name, Phone, Course, Rank, State, Referrer, Remarks`                   |
| Sumit — Website | `Timestamp, Name, Email, Phone, Course, Rank, State, Message`           |
| Canonical       | `phone, name, course, rank, state, referrer_name, remarks, source`      |

Templates are also downloadable from the page itself.

## Dedup rules

Same as the existing n8n pipeline — nothing has changed. Priority `Sonam > Nikhil > Sumit`:

- **Higher tier beats existing:** demote the loser, re-parent payments/notes, insert the new row
- **Same tier duplicate:** reject (no change, report in the rejections CSV)
- **Head-vs-head conflict (Sonam vs Nikhil):** create the row, flag both for admin review at `/admin/duplicate-flags`

The preview-vs-commit decisions are locked by a parity test — what you see in the preview is what commits.

## Pausing and resuming n8n Sheet-trigger workflows

When this feature goes live, deactivate these three n8n workflows in the UI at `srv1117424.hstgr.cloud`:

- `lead-sumit-website-sheet` — `7cqS00mq6r2yGJDG`
- `lead-nikhil-sheet` — `v3b8K2UC08QY4V3H`
- `lead-sonam-sheet` — `P1e55kFMiE7AYlmN`

Leave these active:

- `/api/leads` webhook workflow
- `Davya Lead Capture` Forms workflow (legacy, B4 kept per 2026-04-22 decision)

**To resume later:** open n8n UI, toggle each Sheet-trigger workflow back on. No code change needed on the CRM — manual imports and automated Sheet polling coexist fine (both route to the same `LeadIntakeService`).

## Architecture

Under the hood:

- `app/Filament/Pages/LeadImport.php` — the 4-step Livewire page
- `app/Services/LeadImport/LeadImportService.php` — orchestrates `preview(source, input)` and `commit(preview, user)`
- `app/Services/LeadImport/Mappers/` — per-source column mapping (4 mappers, one per source)
- `app/Services/LeadImport/Parsers/` — TSV (paste), CSV, XLSX parsers
- `app/Services/LeadIntakeService::preview()` — pure decision logic (refactored from `ingest()` in this feature)
- `app/Services/LeadIntakeService::ingestDecision()` — executes a pre-baked decision (used by commit to prevent preview→commit drift)
- `app/Http/Controllers/LeadImportRejectionsController.php` — signed one-shot CSV download
- `app/Models/LeadImportBatch` — one row per committed batch (audit trail)

Rejection CSVs live under `storage/app/lead-imports/` until downloaded. They're cleared on first download; if never downloaded they persist and can be purged by a cron job (not wired yet — add to `app/Console/Kernel.php` if storage growth becomes an issue).
