# Demo Account — Pre-Brainstorm Notes

**Written:** 2026-04-22
**Status:** pre-brainstorm — do NOT start implementation. This is the input packet for a later brainstorming session.

## What Sumit asked for

A demo login for showing the CRM to prospects. The demo user:
- Can **see everyone's data** (like admin)
- Exception: demo's own created data is **not visible to real users**
- Can try **all features** — except n8n (no webhook firing from demo actions)

## Two audits feed this note

Both dated 2026-04-22:

- **Code-level audit (Claude, internal):** read app/, tests/, routes/, policies. Found architectural landmines.
- **External UI audit (browser-based inspection):** clicked through production UI, read the HTML/JS, profiled network. Found surface-level gaps that code inspection missed.

The two are complementary, not redundant — different blast radii. Sections below call out which audit surfaced each finding.

## Codebase feasibility (from code audit)

The code audit graded each capability:

| Capability | Difficulty | Where |
|------------|-----------|-------|
| Demo READS all data (students, payments, reports) | **Easy** | ~3 lines in `app/Policies/StudentPolicy.php`; `StudentResource::getEloquentQuery()` already has admin bypass at `app/Filament/Resources/StudentResource.php:335` |
| Demo WRITES sandboxed (invisible to real users) | **Medium** | New `is_demo_only` boolean on `students` + filter in `getEloquentQuery()`; requires compound unique on `(phone, is_demo_only)` to preserve dedup semantics |
| Demo skips n8n / webhooks | **Easy for leads/finance controllers** | Gate `Log::info()` calls in `LeadController:28`, `FinancePaymentController:92`, etc. Can be middleware. |

## Code-level landmines — MUST resolve before shipping demo

### 1. ActivityLog leaks demo activity to admins
Spatie `ActivityLog` has no `is_demo_only` column. Every student create, payment record, stage transition, and `ipu_password` reveal gets written to `activity_log` with the real user_id. The **Activity Audit** Filament page (`app/Filament/Pages/ActivityAudit.php`, line ~42) queries the table with no role filter. Without work, a demo user giving a live demo to a prospect would end up with their demo clicks showing up in Sonam/Nikhil's next audit review.

**Options:**
- Add `user_is_demo` bool column to `activity_log`; filter at query time.
- Tag demo activities with a `properties.demo=true` JSON flag; filter with `whereJsonContains`.
- Use a separate activity log connection / table for demo users (heavier).

### 2. Finance Assistant (Gemini) has zero row-level auth
`AssistantQueryResolver.php` (240 lines) pulls finance rows with no tenant/user filter and feeds them to Gemini. A demo user asking "show me last month's payments" would get **real** payments from admin's books in the LLM context. This is the most important landmine — finance data leak into an LLM context is not reversible.

**Fix must land before shipping demo:** pass a scope to `AssistantQueryResolver` excluding demo users' finance rows AND excluding real rows from demo users' queries. Two-way filter.

### 3. Encrypted IPU passwords
`ipu_password` field is encrypted at rest (fine). The `RevealIpuPassword` Filament action logs the reveal event into `activity_log` (fine) but the password itself is a student attribute the demo role would be able to reveal. Demo users should see **redacted** ipu_password values even in read mode.

**Fix:** gate the `RevealIpuPassword` action with a policy check that denies `demo` role outright.

### 4. Compound unique index gotcha
Adding `is_demo_only` to `students` means the existing unique-by-logic dedup (`LeadIntakeService`) needs demo-awareness. A demo user creating a student with phone `9100000200` should not collide with real student `9100000200`. The simplest model: dedup scope is per-demo-flag.

**Decision needed:** when a demo user creates a student with a phone that already exists in real data, does the demo create fail, silently succeed with a shadow record, or merge against the real record (read-only)?

## UI-level findings (from external audit) — fold into demo design

The external audit walked the UI and caught surface issues the code audit didn't. Some are independent hygiene fixes; some specifically affect demo design.

### Affects demo design

| # | Finding | Why it matters for demo |
|---|---------|------------------------|
| UI-A | Sequential integer student IDs (`/admin/students/18/edit`) | A demo user can enumerate real student records by incrementing the URL, bypassing any list-view filtering. Demo isolation must enforce at policy/query level, not just at UI filter level. Alternative: migrate to ULID — but that's a big migration with FK cascade. |
| UI-B | Kanban `moveStudentToStage` writes via Livewire, un-guarded by any demo check | Demo drag-and-drop is a "try our pipeline" demo moment — but the Livewire action mutates real data unless demo-aware. |
| UI-C | Google Drive upload for payment proofs has no "sandbox folder" concept | Demo payment proof uploads would pollute the production Drive folder. Demo should either upload-disable OR route to a `demo/` subfolder. |
| UI-D | `owner_id` / `referrer_id` show `*` in UI label but are **not** HTML `required` — rely on server-side Livewire validation alone | JS fail mid-flight = silent create of unowned student. Applies to real and demo alike, but demo UX is more fragile (prospect clicks wrongly-labeled field, expects behavior to match the asterisk). |
| UI-E | Bulk-import activity log rows have **blank Who** column | The backfill artisan command ran 503 students without a user causer. Demo seeding will do the same. Fix once for both: set a `system` user as causer on artisan imports. |

### Independent hygiene (nice to fix during demo work but not blockers)

| # | Finding | Effort |
|---|---------|--------|
| UI-F | Inline Kanban JS un-versioned (re-downloads every page) | Low — extract to asset pipeline |
| UI-G | Pusher loaded but Echo not initialized — dead ~40KB JS | Low — delete or wire up |
| UI-H | No custom 404 page — naked Laravel 404 on any mistyped route | Low |
| UI-I | Nav label mismatches: "Report" → `/admin/kanban`; "Duplicate review" → `/admin/duplicate-flags` | Cosmetic |
| UI-J | `ui-avatars.com` external avatar call leaks user initials to third party | Cosmetic / privacy |
| UI-K | `deal_amount` NULL vs ₹0 inconsistency (student #24 shows blank cell vs ₹0.00 in sub-tab) | Low — audit: should the column be `NOT NULL DEFAULT 0` or should the view coalesce? |
| UI-L | Dashboard empty widgets ("Seat Fee Pending") render pagination chrome even with 0 rows | Low — hide pagination when empty |

## Reconciled estimate

**External audit said:** 3–5 days for demo MVP (UI + `is_demo` scoping).
**That's wrong** — it missed the two code-level landmines (Finance Assistant LLM leak, ActivityLog filtering). Those aren't quick patches; they're architectural work.

**Corrected estimate:** 8–12 focused dev-days for demo MVP **including** the two landmines and the UI-level fixes that directly touch demo isolation (UI-A through UI-E). The hygiene items (UI-F through UI-L) can ship separately.

## Other questions for the brainstorm

1. **One demo account or many?** If many (e.g. per-prospect), we need tenant-ish isolation between demo accounts too — demo-A shouldn't see demo-B's records.
2. **Data visibility: real data OR curated fake data?** Real data in a demo = privacy risk with prospects. Fake data = needs a seed set.
3. **Session lifetime:** does demo data auto-purge after N days? Or does it stay forever, possibly accumulating junk?
4. **Demo password / rotation:** is this a single shared credential or per-demo provisioned?
5. **n8n isolation mechanism:** middleware vs. in-controller checks vs. event suppression?
6. **What can demo NOT do at all?** Delete real students? Reassign? Edit stage of real students? Reset 2FA? Transfer ownership?

## Scope that's clearly OUT

- Multi-tenancy refactor — too expensive.
- A separate demo.davyas.ipu.co.in subdomain — adds deploy + SSL complexity.
- Read-only mirroring of production into a demo DB — operationally heavy.

## Suggested next step

Run this through `/brainstorming` with Sumit after M6 is verified green. Key clarifying questions in the session:

1. Real data or fake data in the demo view?
2. One demo or many?
3. How to handle demo-attempted writes on real records (dedup collision case)?

With those three answered, the design collapses to an 8–12 day implementation (see reconciled estimate above).

---

**Do not start implementation.** The ActivityLog + Finance Assistant landmines are not "small fixes" — they're architectural decisions that affect other future features (audit, multi-user finance, etc.). They deserve a spec.

Related hygiene items (UI-F to UI-L above) can be batched into a separate "post-M6 cleanup" pass and do not require a demo-brainstorm to resolve.
