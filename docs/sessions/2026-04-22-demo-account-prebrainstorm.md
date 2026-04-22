# Demo Account — Pre-Brainstorm Notes

**Written:** 2026-04-22
**Status:** pre-brainstorm — do NOT start implementation. This is the input packet for a later brainstorming session.

## What Sumit asked for

A demo login for showing the CRM to prospects. The demo user:
- Can **see everyone's data** (like admin)
- Exception: demo's own created data is **not visible to real users**
- Can try **all features** — except n8n (no webhook firing from demo actions)

## Codebase feasibility (from audit, 2026-04-22)

The audit graded each capability:

| Capability | Difficulty | Where |
|------------|-----------|-------|
| Demo READS all data (students, payments, reports) | **Easy** | ~3 lines in `app/Policies/StudentPolicy.php`; `StudentResource::getEloquentQuery()` already has admin bypass at `app/Filament/Resources/StudentResource.php:335` |
| Demo WRITES sandboxed (invisible to real users) | **Medium** | New `is_demo_only` boolean on `students` + filter in `getEloquentQuery()`; requires compound unique on `(phone, is_demo_only)` to preserve dedup semantics |
| Demo skips n8n / webhooks | **Easy for leads/finance controllers** | Gate `Log::info()` calls in `LeadController:28`, `FinancePaymentController:92`, etc. Can be middleware. |

## The hidden landmines (the reason this needs a brainstorm, not a quick patch)

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

With those three answered, the design collapses to a 1–2 week implementation.

---

**Do not start implementation.** The ActivityLog + Finance Assistant landmines are not "small fixes" — they're architectural decisions that affect other future features (audit, multi-user finance, etc.). They deserve a spec.
