# Books — Multi-Company Finance Module

**Date:** 2026-05-11
**Owner:** Sumit Dabas (super_admin)
**Status:** Draft — pending Sumit review

## Goal

A brand-new, side-by-side finance bookkeeping module inside davya-crm. Models multiple companies, each with annual books (Apr–Mar). Tracks itemized Income, categorized outflows (Salary, Rent, Loan given, Depreciation, Expense — extensible), per-row supporting documents, and per-financial-year dashboards. Coexists with the existing Finance area (Expenses + Investments + Slack→Groq pipeline) without sharing data.

## Non-Goals

- Not a replacement for the existing Finance area. The Slack→n8n→Groq ledger continues unchanged.
- Not a tax-return preparer. P&L roll-up is for visibility, not statutory filing.
- Not a transaction-level ledger. Each row is an aggregate per counterparty per FY; you can attach documents but you don't post double-entry journal lines.
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
book_companies              id, name, slug, currency='INR', timezone, archived_at, timestamps
book_fiscal_years           id, company_id, start_date, end_date, label ('2025-26'),
                            is_closed (bool), closing_summary_json, timestamps
book_income_entries         id, company_id, fiscal_year_id, occurred_on, source, amount,
                            notes, timestamps, deleted_at
book_sections               id, company_id, slug, name, kind ('generic'|'asset'),
                            sort_order, icon, archived_at, timestamps
book_entries                id, company_id, fiscal_year_id, section_id, title,
                            salary_amount  decimal(14,2) default 0,
                            loan_amount    decimal(14,2) default 0,
                            paid           decimal(14,2) default 0,
                            received_back  decimal(14,2) default 0,
                            notes, sort_order, timestamps, deleted_at
book_assets                 id, entry_id (FK unique), original_value, dep_percent,
                            dep_years, dep_started_at, method ('straight_line'|'wdv'),
                            timestamps
book_fields                 id, company_id, section_id (nullable=company-wide),
                            key, label, type, options_json, is_required,
                            sort_order, show_in_table (bool), archived_at, timestamps
book_field_values           id, entry_id, field_id, value_text, value_number,
                            value_date, value_json, timestamps
                            unique(entry_id, field_id)
book_attachments            id, entry_id, disk='gdrive', path, original_name,
                            mime, size, uploaded_by (user_id), uploaded_at, timestamps
```

### Indexes

- `book_entries(company_id, fiscal_year_id, section_id)` — covers most table loads
- `book_income_entries(company_id, fiscal_year_id, occurred_on)`
- `book_field_values(entry_id, field_id)` unique

### Computed accessors (no storage)

On `BookEntry`:

- `balance = salary_amount + loan_amount - paid - received_back`
- `loan_outstanding = loan_amount - received_back`
- `is_loan = loan_amount > 0`

On `BookFiscalYear`:

- `total_income` — sum of `book_income_entries`
- `total_outflow` — sum of all `book_entries.paid` + computed depreciation for the year
- `net_pl = total_income - total_outflow`
- `carryover_loss` — net P/L of the prior closed FY for the same company
- `cumulative_pl = net_pl + carryover_loss`

### Loan-flag semantics

There is no separate `is_loan` toggle. A row classifies itself by which money column is non-zero:

- `salary_amount > 0` → salary row
- `loan_amount > 0` → loan row
- both → mixed (Lansdown/Usha case from the spreadsheet)

Section placement is independent — the user is free to keep a loan-only row inside the Salary section.

### Carry-over loss

Computed at read time. When the prior FY is **closed** (`is_closed=true`), its snapshot is frozen in `closing_summary_json` for fast reads; reopening recalculates.

## UI Flow

### Company picker (landing)

List of companies with quick stats (current FY: income, outflow, net). "+ New Company" modal: name, slug (auto), currency (default INR), timezone.

### Dashboard (Company × FY)

Five regions, top to bottom:

1. **KPI tiles (5)** — Total Income · Total Outflow · Net (P/L) · Carry-over from prior FY · Cumulative P/L
2. **Income vs Outflow chart** — monthly bar chart over the FY
3. **Section roll-up cards** — one card per section: name, # entries, totals (salary / loan / paid / balance); clicking opens section page
4. **Asset register** — every asset entry: name, original value, dep this year, accumulated dep, current book value
5. **Loans outstanding** — every row where `loan_outstanding > 0`: counterparty, original loan, received back, outstanding

All numeric tiles and rows are clickable (drill-down to filtered section / entry list — same pattern as davya-crm SP#3 dashboard).

Year selector in header replays all five regions for the chosen FY.

### Section page

Filament table view with adaptive columns:

- Default columns: `# · Title · Salary · Loan · Paid · Balance · Received Back · Loan to Be Received · Documents · Notes · Actions`
- Each custom field on this section with `show_in_table=true` becomes an additional column
- Bottom totals row sums every numeric column
- Inline `+ Add Row` action opens a form: Title, Salary amount, Loan amount, Paid, Received Back, Notes, Documents repeater (multi-upload), all custom fields for this section
- Row click → side drawer (Filament SlideOver) — full detail + attachment gallery + edit-in-place
- Section page for an asset-kind section also surfaces the four asset fields (original value, method, dep %, dep years, started on)

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
- `accumulatedDepThrough(asset, fy) → decimal` — sums every closed FY from `dep_started_at` to `fy`
- `bookValueAtEndOf(asset, fy) → decimal`

The computed depreciation for a given FY is injected into the entry's "paid for this FY" view-side aggregate so the dashboard's Total Outflow includes depreciation without the user manually entering it. The underlying `book_entries.paid` column is **not** mutated.

The spreadsheet's `Car / Depreciation 300000 / 200000 / 200000 / 0` maps to: `original_value=300000`, `dep_percent / dep_years` chosen by the user so the computed yearly dep equals 200000. Display column for this FY shows the computed value.

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
- Soft delete on `book_companies`, `book_fiscal_years`, `book_sections`, `book_entries`, `book_income_entries` (recoverable from accidents)
- Closed FYs reject all write operations at controller + model level (`booted` saving guard)

## Activity Log

- Spatie ActivityLog entries on every `book_entry` mutation (create / update / delete / restore) — same `LogsActivity` trait used elsewhere
- Field-level diffs for `salary_amount`, `loan_amount`, `paid`, `received_back`, `notes`
- Attachment uploads / removals logged with file name + uploader

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

- Double-entry journals
- Tax reports / 26AS reconciliation / GST
- Bank statement import
- Multi-currency conversion (everything assumed INR — `currency` column on company is forward-looking only)
- Recurring entry templates (e.g. "monthly rent 45000 auto-creates 12 rows")
- Auditor-style closing entries
- Roles beyond super_admin
- Slack / n8n integration with the new module
- Reusing existing `expenses` or `investments` data
- Mobile-specific UI (desktop-first for v1; viewport stays responsive but no PWA polish in this scope)

## Open Questions (none blocking spec approval)

- Company list — admin-defined at runtime, no fixed initial list (resolved 2026-05-11)
- Whether the "Last Year" line on the spreadsheet maps to `carryover_loss` or a separate column — currently mapped to `carryover_loss` (P/L of prior FY). If user wants accumulated multi-year carry-over, that's a small follow-up tweak.

## Files Likely Touched / Created

- `database/migrations/2026_05_11_*_create_book_*` × 8 migrations
- `app/Models/Book/{Company,FiscalYear,IncomeEntry,Section,Entry,Asset,Field,FieldValue,Attachment}.php`
- `app/Filament/Resources/Book/{Company,Section,Entry,Income,Field}Resource.php`
- `app/Filament/Pages/Book/{Dashboard,SectionPage,Settings,Companies}.php`
- `app/Books/Services/{DepreciationCalculator,EntryAggregator,CarryoverComputer}.php`
- `app/Books/Fields/FieldRenderer.php` (adapted from Phase A's StudentFields)
- `resources/views/filament/pages/book/*.blade.php`
- `tests/Feature/Books/*` + `tests/Unit/Books/*`
- Nav registration in `AdminPanelProvider`
- `config/books.php` (feature flag exposure)
