# Books — Multi-Company Finance Module

**Date:** 2026-05-11
**Owner:** Sumit Dabas (super_admin)
**Status:** Draft — pending Sumit review

## Goal

A brand-new, side-by-side finance bookkeeping module inside davya-crm. Models multiple companies, each with annual books (Apr–Mar). Tracks itemized Income, categorized outflows (Salary, Rent, Loan given, Depreciation, Expense — extensible), per-row supporting documents, and per-financial-year dashboards. Coexists with the existing Finance area (Expenses + Investments + Slack→Groq pipeline) without sharing data.

## Non-Goals

- Not a replacement for the existing Finance area. The Slack→n8n→Groq ledger continues unchanged.
- Not a tax-return preparer. P&L roll-up is for visibility, not statutory filing.
- Not a full double-entry ledger (no journal lines, no debit/credit pairing, no chart-of-accounts). A lightweight payments sub-table (see Data Model) gives time-resolved partial payments and cash/bank/UPI split without committing to a CA-grade ledger.
- Not exposed to anyone other than super_admin in v1. No role plumbing for accountants / staff.

## Users

- Only **super_admin** (locked to `sumitdabass@gmail.com`). Everyone else gets 403. Nav group hidden for non-super-admin.

## Inspiration / Reference

User's working spreadsheet (informally pasted 2026-05-11) — one Income figure at the top, categorized expense groups below (Salary, Rent, Loan, Dep, Expense), each row tracking Salary / Loan / Paid / Balance / Received Back / Loan-to-Be-Received plus KYC and employment document columns, plus a Last-Year-Loss carry-over and a Total Loss/Profit line.

## High-Level Architecture

- **Module name:** Books (nav group, separate from existing "Finance" group)
- **Side-by-side**: own tables prefixed `book_*`, no FK to or mutation of existing Expense / Investment / Slack pipeline tables
- **Multi-tenant within davya-crm**: admin-defined companies. Every URL after the landing page nests Company → FY → Section
- **Period:** one book per Indian Financial Year (Apr 1 – Mar 31). FY chip in page header, switchable via dropdown.
- **Feature-flagged:** `BOOKS_MODULE` env var so the nav group only renders when on. Default off until prod smoke.

## URL Map

```
/admin/books                              → Company picker (landing)
/admin/books/companies                    → Company CRUD (super_admin)
/admin/books/{company}/{fy}               → Dashboard (default)
/admin/books/{company}/{fy}/income        → Income entries
/admin/books/{company}/{fy}/section/{slug}→ Section page (Salary, Rent, Loan, Dep, Expense, …)
/admin/books/{company}/settings           → Sections / Fields / FY admin
```

## Data Model

```
book_companies              id, name, slug (UNIQUE), currency='INR', timezone,
                            archived_at, timestamps
book_fiscal_years           id, company_id, start_date, end_date, label ('2025-26'),
                            is_closed (bool), closing_summary_json, timestamps
                            unique(company_id, label)
book_income_entries         id, company_id, fiscal_year_id, occurred_on, source, amount,
                            notes, timestamps, deleted_at
book_sections               id, company_id, slug, name, kind ('generic'|'asset'),
                            sort_order, icon, visible_money_columns (json array of
                            'salary'|'loan'|'paid'|'received_back'|'balance'|
                            'loan_outstanding'), archived_at, timestamps
                            unique(company_id, slug)
book_entries                id, company_id, fiscal_year_id, section_id, title,
                            salary_amount  decimal(14,2) default 0,
                            loan_amount    decimal(14,2) default 0,
                            notes, sort_order, timestamps, deleted_at
                            (paid / received_back are NOT stored — computed
                             from book_entry_payments aggregates)

book_entry_payments         id, entry_id, occurred_on, amount, direction
                            ('out'|'in'), mode ('cash'|'bank'|'upi'|'cheque'|'other'),
                            reference (free text), notes, created_by, timestamps,
                            deleted_at
                            direction='out' → counts toward entry.paid (we paid them)
                            direction='in'  → counts toward entry.received_back
                            index(entry_id, occurred_on)
book_assets                 id, entry_id (FK unique), original_value, dep_percent,
                            dep_years, dep_started_at, method ('straight_line'|'wdv'),
                            timestamps
book_fields                 id, company_id, section_id (nullable=company-wide),
                            key, label, type, options_json, is_required,
                            sort_order, show_in_table (bool), archived_at, timestamps
book_field_values           id, entry_id, field_id, value_text, value_number,
                            value_date, value_json, value_attachment_id (nullable
                            FK to book_attachments — used by file-type fields),
                            timestamps
                            unique(entry_id, field_id)
book_attachments            id, attachable_type, attachable_id (polymorphic — can
                            point at book_entry or book_field_value), disk='gdrive',
                            path, original_name, mime, size, uploaded_by (user_id),
                            uploaded_at, timestamps
                            index(attachable_type, attachable_id)
```

### Indexes

- `book_entries(company_id, fiscal_year_id, section_id)` — covers most table loads
- `book_income_entries(company_id, fiscal_year_id, occurred_on)`
- `book_field_values(entry_id, field_id)` unique

### Computed accessors (no storage)

On `BookEntry`:

- `paid` = `sum(book_entry_payments.amount where direction='out')`
- `received_back` = `sum(book_entry_payments.amount where direction='in')`
- `balance = salary_amount + loan_amount - paid - received_back`
- `loan_outstanding = loan_amount - received_back`
- `is_loan = loan_amount > 0`

On `BookFiscalYear`:

- `total_income` — sum of `book_income_entries`
- `cash_outflow` — sum of all `book_entry_payments.amount where direction='out'`
- `cash_inflow_from_recoveries` — sum of all `book_entry_payments.amount where direction='in'`
- `non_cash_outflow` — sum of computed depreciation for every asset entry, this FY
- `total_outflow = cash_outflow + non_cash_outflow` (display only — the two are kept separate at field level)
- `net_pl = total_income + cash_inflow_from_recoveries - total_outflow`
- `carryover_loss` — see "Carry-over loss" below
- `cumulative_pl = net_pl + carryover_loss`

**Cash vs non-cash separation (accounting integrity):** depreciation is never written to `book_entry_payments`. It is computed on read by `DepreciationCalculator` and contributes only to `non_cash_outflow` in dashboards. Exports and CA-handoff views can render the two streams separately.

### Loan-flag semantics

There is no separate `is_loan` toggle. A row classifies itself by which money column is non-zero:

- `salary_amount > 0` → salary row
- `loan_amount > 0` → loan row
- both → mixed (Lansdown/Usha case from the spreadsheet)

Section placement is independent — the user is free to keep a loan-only row inside the Salary section (the spreadsheet's Lansdown row sits in Salary with Salary=0, Loan=1M, by design).

**No uniqueness constraint on counterparty / title.** Multiple rows for the same person or vendor are allowed within one section × FY (e.g. mid-year salary revision can be a second row, or two separate rent agreements with the same landlord can be two rows). The user controls semantics.

### Carry-over loss

- Snapshot-first: closing an FY writes `closing_summary_json = { total_income, cash_outflow, non_cash_outflow, net_pl }` and never recomputes for that FY again, unless explicitly reopened.
- Reading carry-over: if the prior FY's `closing_summary_json` is present, use it verbatim. If absent (FY still open), compute live as a best-effort estimate and badge the carry-over KPI as "estimate".
- Reopening an FY NULLs `closing_summary_json` and `is_closed`. The next close writes a fresh snapshot.
- This guarantees: closed-FY P/L never silently shifts under the user's feet, even if they later edit an entry while the FY is reopened.

## UI Flow

### Company picker (landing)

List of companies with quick stats (current FY: income, outflow, net). "+ New Company" modal: name, slug (auto), currency (default INR), timezone.

### Dashboard (Company × FY)

Five regions, top to bottom:

1. **KPI tiles (7)** — Total Income · Cash Outflow · Non-Cash Outflow (Depreciation) · Total Outflow · Net (P/L) · Carry-over from prior FY · Cumulative P/L. Carry-over tile badges "estimate" when the prior FY is still open.
2. **Income vs Outflow chart** — monthly bar chart over the FY, with stacked cash vs non-cash outflow bars
3. **Section roll-up cards** — one card per section: name, # entries, totals (salary / loan / paid / balance); clicking opens section page
4. **Asset register** — every asset entry: name, original value, dep this year, accumulated dep, current book value
5. **Loans outstanding** — every row where `loan_outstanding > 0`: counterparty, original loan, received back, outstanding

All numeric tiles and rows are clickable (drill-down to filtered section / entry list — same pattern as davya-crm SP#3 dashboard).

Year selector in header replays all five regions for the chosen FY.

**Query budget:** each region is one aggregate query (`SUM(...)` grouped by the relevant dimension), eager-loaded. Worst-case dashboard = 5 queries regardless of entry count. Per-section roll-up uses `withSum/withCount` from Eloquent, not N+1 accessors. If we ever measure a single dashboard load > 250ms in prod, revisit by introducing `book_fy_summaries` (see Out of Scope).

### Section page

Filament table view with adaptive columns:

- Money columns are toggleable per section via a `visible_money_columns` array on `book_sections`. Sensible defaults by section name / kind:
  - **Salary section** → Title, Salary, Paid, Balance, Documents, Notes (hides Loan, Received Back, Loan Outstanding)
  - **Loan section** → Title, Loan, Received Back, Loan Outstanding, Documents, Notes (hides Salary, Paid)
  - **Rent / Expense / generic** → Title, Paid, Notes, Documents
  - **Asset / Depreciation section** → Title, Original Value, Dep %, Dep Years, This-Year Dep, Accumulated Dep, Book Value, Documents
  - Mixed cases (e.g. spreadsheet's Salary section has both salary and loan rows) → admin enables both columns from settings
- Each custom field on this section with `show_in_table=true` becomes an additional column
- Bottom totals row sums every visible numeric column
- Inline `+ Add Row` action opens a form. Form fields = the section's `visible_money_columns` set (e.g. Salary section's create form shows `salary_amount` + `loan_amount` if loan is enabled, hides the rest) + Title + Notes + custom fields. Payment history (the sub-table) is **not** in the create form — payments are added from the row drawer after the row exists.
- Row click → side drawer (Filament SlideOver) with five tabs: **Details**, **Payments** (full payment history with add/edit/delete), **Documents**, **Custom Fields**, **Activity Log**
- Attachments are loaded **only inside the drawer** — never preloaded on the table page

### Income page

Filament table: Date · Source · Amount · Notes · Actions. `+ Add Income` action. Bottom totals row.

### Company settings

`/admin/books/{co}/settings` — four tabs:

1. **Sections** — list/CRUD, drag reorder, archive, choose `kind` (generic vs asset)
2. **Fields** — Phase-A-style: per section, add/edit/archive, drag reorder, toggle `show_in_table`, choose type + options
3. **Company** — rename / archive / currency / timezone
4. **Fiscal Years** — list every FY, open / close, view snapshot

### Closing an FY

Modal warns "this freezes all entries — reopen any time". Closed FYs render with a yellow banner; all forms become read-only. Reopening clears `is_closed` and the cached snapshot.

## Depreciation Engine

For any `book_section` with `kind='asset'`, every entry auto-extends to a `book_asset` row. Service: `App\Books\Services\DepreciationCalculator`.

- `yearlyDepFor(BookAsset $a, BookFiscalYear $fy) → decimal`
  - **Straight Line:** `original_value × dep_percent / 100`, prorated by days-in-FY since `dep_started_at`
  - **WDV:** `book_value_at_start_of_fy × dep_percent / 100`
- `accumulatedDepThrough(asset, fy) → decimal` — sums dep for every prior FY by date from `dep_started_at` to `fy` (regardless of closure — asset wear is a physical concept, not tied to bookkeeping closure)
- `bookValueAtEndOf(asset, fy) → decimal`

The computed depreciation for a given FY contributes **only** to the FY's `non_cash_outflow` aggregate (see "Computed accessors" above). It is **never** written into `book_entry_payments` and **never** rolled into the `paid` accessor. Asset-section table columns display the computed yearly dep + accumulated dep + book value as their own read-only columns, not as a "Paid" value. This preserves the cash vs non-cash separation end-to-end.

The spreadsheet's `Car / Depreciation 300000 / 200000 / 200000 / 0` maps to: `original_value=300000`, `dep_percent / dep_years` chosen by the user so the computed yearly dep equals 200000. The asset-section row displays original=300k, dep-this-year=200k, accumulated=200k, book value=100k.

## Custom Fields & Attachments

### Custom fields (Phase-A pattern, scoped per section)

- Types: text · textarea · number · date · email · dropdown · checkbox · multiselect · file (single)
- Admin-managed at `/admin/books/{co}/settings` (Fields tab) with the same UX as `/admin/student-fields`
- `show_in_table` flag controls whether the field appears as a section table column
- Seeded built-ins per new company (all on Salary section by default):
  - PAN (text), Aadhaar (text), Cancelled Cheque (file), Account Number (text), IFSC (text), Offer Letter (file), Joining Letter (file)
- Built-ins are flagged `is_built_in=true` — admin can edit options but not change type or delete

### Attachments (multi-file repeater per entry)

- Independent of custom fields — every `book_entry` has a `Documents` repeater
- Stored on existing flysystem Google Drive disk under `books/{company-slug}/{fy}/{section-slug}/{entry-id}/`
- Each record tracks `uploaded_by` user + `uploaded_at` timestamp
- File-type custom fields (e.g. Cancelled Cheque) store a single file independently of the multi-doc repeater

## Security

- All Books URLs require `auth()->user()->isSuperAdmin()` — gate at panel-level `canAccess()`
- 403 for any other role; nav group hidden via `shouldRegisterNavigation()`
- Soft delete on `book_companies`, `book_fiscal_years`, `book_sections`, `book_entries`, `book_entry_payments`, `book_income_entries` (recoverable from accidents)
- Closed FYs reject all write operations at controller + model level (`booted` saving guard). Guard checks the FY for `book_entries`, `book_entry_payments`, `book_income_entries`, and `book_assets`.

## Activity Log

- Spatie ActivityLog entries on every mutation (create / update / delete / restore) of:
  - `book_entries`
  - `book_entry_payments` (each payment record is itself logged — date, amount, mode, direction)
  - `book_income_entries`
  - `book_assets`
  - `book_companies` (rename, archive, restore)
  - `book_fiscal_years` (open / close / reopen — special events with old/new snapshot diffed)
- Field-level diffs for `salary_amount`, `loan_amount`, `notes`, `amount`, `mode`, `occurred_on`, `source`
- Attachment uploads / removals logged with file name + uploader
- Activity Log tab in the entry drawer reads the entry-scoped + child-payment-scoped log entries in one query

## Feature Flag

- `BOOKS_MODULE` env var
- When unset / false:
  - Nav group hidden
  - All `/admin/books/*` routes return 404
  - Tables created by migrations but unused
- When `BOOKS_MODULE=true`:
  - Module renders for super_admin
- Toggle is config-cached; flip requires `php artisan config:clear`

## Testing

Pest feature tests:

- Company / FY / Section / Entry / Income CRUD
- Custom field CRUD + per-section scoping
- Attachment upload (with mocked Google Drive disk)
- Dashboard aggregates (KPIs + section roll-ups + asset register + loans outstanding)
- Carry-over math (open FY uses live calc, closed FY uses snapshot)
- FY close locks all writes; reopen unlocks
- Super_admin-only access (403 for admin / head / member / freelancer / finance roles)
- Multi-company isolation (entries on Company A invisible to Company B context)

Unit tests:

- `DepreciationCalculator` — SL prorated by start date, WDV across multiple years, accumulated dep through N years
- `BookEntry::balance` and `BookEntry::loanOutstanding` accessors
- Income / outflow / net P/L aggregator on `BookFiscalYear`

Target: ≥ 50 new tests, all green; full davya-crm suite stays green.

## Migration / Rollout

1. Build locally on a feature branch
2. Migrations are additive only — zero risk to existing schema
3. Local smoke: seed 1 company × 1 FY × Sumit's pasted spreadsheet data via tinker script
4. Prod deploy gated behind `BOOKS_MODULE=false` initially — migrations run cold
5. Flip `BOOKS_MODULE=true` only after Sumit's local smoke green
6. Rollback: `BOOKS_MODULE=false`, nav disappears, data stays (no destructive rollback path needed)

## Estimate

7–9 dev-days, ~18–22 TDD tasks (similar shape to Phase A custom student fields).

## Out of Scope for v1 (deferred follow-ups)

- Double-entry journals (chart of accounts, debit/credit pairing)
- Tax reports / 26AS reconciliation / GST
- Bank statement import
- Multi-currency conversion (v1 is INR-only; `currency` column on company is reserved for future use and validated to `'INR'` at write-time; non-INR writes are rejected with a 422)
- Recurring entry templates (e.g. "monthly rent 45000 auto-creates 12 rows")
- Auditor-style closing entries
- Roles beyond super_admin
- Slack / n8n integration with the new module
- Reusing existing `expenses` or `investments` data
- Mobile-specific UI (desktop-first for v1; viewport stays responsive but no PWA polish in this scope)
- `book_fy_summaries` materialized aggregate cache table — designed-around but **not implemented** in v1. Add only if a single dashboard load exceeds 250ms in production. Until then, aggregate queries with proper indexes and `withSum/withCount` are O(1) at expected volume (≤ 5 companies × 10 FYs × 1000 entries each).
- `computed_fields_json` cache column on `book_entries` for custom fields — same reasoning. The sparse `book_field_values` table is fine at expected volume.

## Open Questions (none blocking spec approval)

- Company list — admin-defined at runtime, no fixed initial list (resolved 2026-05-11)
- Whether the "Last Year" line on the spreadsheet maps to `carryover_loss` or a separate column — currently mapped to `carryover_loss` (P/L of prior FY). If user wants accumulated multi-year carry-over, that's a small follow-up tweak.
- Custom-fields-in-table soft cap — Phase A doesn't cap; this module starts uncapped too. Revisit if a section grows past ~10 visible columns and table becomes unreadable.

## Files Likely Touched / Created

- `database/migrations/2026_05_11_*_create_book_*` × 10 migrations: companies, fiscal_years, sections, income_entries, entries, entry_payments, assets, fields, field_values, attachments
- `app/Models/Book/{Company,FiscalYear,IncomeEntry,Section,Entry,EntryPayment,Asset,Field,FieldValue,Attachment}.php`
- `app/Filament/Resources/Book/{Company,Section,Entry,Income,Field}Resource.php`
- `app/Filament/Pages/Book/{Dashboard,SectionPage,Settings,Companies}.php`
- `app/Books/Services/{DepreciationCalculator,EntryAggregator,CarryoverComputer,ClosingSnapshotWriter}.php`
- `app/Books/Fields/FieldRenderer.php` (adapted from Phase A's StudentFields)
- `resources/views/filament/pages/book/*.blade.php`
- `tests/Feature/Books/*` + `tests/Unit/Books/*`
- Nav registration in `AdminPanelProvider`
- `config/books.php` (feature flag exposure)
