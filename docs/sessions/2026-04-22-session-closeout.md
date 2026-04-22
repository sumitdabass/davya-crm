# davya-crm — 2026-04-22 Session Close-out

**Status:** M6 shipped + operationalised + post-M6 hygiene pass done.
**Prod HEAD:** see `git log origin/main -1` — final commit of this session.

## Shipped this session (code + ops)

| What | Commit | Where |
|------|--------|-------|
| M6 finish runbook (doc) | `afbe771` | `docs/sessions/` |
| Today tab brainstorm prep + capture template (doc) | `404364d` | `docs/sessions/` |
| Demo account pre-brainstorm (doc; includes external UI audit fold-in) | `86de133`, `00ff285` | `docs/sessions/` |
| DECISIONS.md backfill for PR #4 + PR #5 | `9773c31` | `docs/` |
| Dashboard: role-aware scoping + hide-when-empty on 3 table widgets | `963e9ed` | `app/Filament/Widgets/`, `app/Models/Student.php` |
| Filament brand logo + favicon (Davyas Consultancy logo) | `06c4838` | `config/filament.php`, `public/` |
| PWA: manifest + sw + icons + dashboard InstallAppWidget | `1cf8d4d`, `87bddfc` | `public/`, `app/Filament/Widgets/`, `resources/views/` |
| Backfill command: activity_log causer attribution (`--as-user`) | `658f4ec` | `app/Console/Commands/` |
| Nav label "Report" → "Pipeline"; custom 404 page | `500ac9e` | `app/Filament/Pages/`, `resources/views/errors/` |
| Student list: `deal_amount` coalesces NULL → ₹0 | (this commit) | `app/Filament/Resources/` |

## Ops state at close

- n8n OAuth cred `A8Grx7J6ZfarJVR1` reconnected; all 4 workflows active.
- Central Rejections sheet tab named `Rejections` (confirmed).
- CRM student count: **533** (was 510 pre-activation — +23 organic flow).
- Prod first-payment smoke: green (student #533, ₹100 advance / Cash).
- n8n execution stats: **155 executions, 30 failed (~19%)** — not gating but concerning. Hypothesised to cluster in the legacy 4th workflow; unpublishing expected to drop the rate.
- SSH deploy key rotated; old key revoked from server; backup kept till 2026-04-29.

## External-audit items — status board

| # | Item | Status |
|---|------|--------|
| 1 | owner_id / referrer_id HTML-required mismatch | **Dismissed** — Filament Select is Livewire-driven, not native `<select>`; HTML `required` doesn't attach. Server-side `->required()` is the authoritative gate and is in place. |
| 2 | Bulk-import blank Who in activity_log | **Fixed** (`658f4ec`) — admin causer attached; `--as-user=<email>` override available. |
| 3 | `deal_amount` NULL vs ₹0 inconsistency | **Fixed** (this commit) — list column coalesces NULL to ₹0 at display. DB still allows NULL by design. |
| 4 | 2FA not enforced for admin | **Open** — policy decision; should enforce for admin/head, optional for counsellor. Needs UX call: auto-prompt on next login vs mandated immediately. |
| 5 | Sequential integer student IDs (IDOR enumeration) | **Open** — ULID migration is 2–4h + cascade effects. Queued for a focused session. |
| 6 | Pusher/Echo dead JS weight | **Dismissed** — no explicit pusher/echo deps; `BROADCAST_CONNECTION=log`. What auditor saw is Livewire's internal runtime. |
| 7 | Kanban JS inline + un-versioned | **Open** — risky to refactor (could break kanban drag-drop). Needs a careful TDD pass. |
| 8 | Dashboard empty widgets render pagination chrome | **Fixed** (`963e9ed`) — `canView()` returns false when scoped query has zero rows; widget hides entirely. |
| 9 | No custom 404 page | **Fixed** (`500ac9e`) — emerald-themed page with Davyas logo + Back-to-Dashboard CTA. |
| 10 | Nav label "Report" confusing | **Fixed** (`500ac9e`) — sidebar now says "Pipeline"; page H1 stays "Pipeline Report". |
| 11 | "Duplicate review" nav vs `/duplicate-flags` route | **Open (cosmetic)** — can align in next UI pass. |

## Still gating: B4 decision

Legacy workflow "Davya Lead Capture — Sheets → CRM" (reads `18MGObYp3g…_kQ` / `Form Responses 1`) — **recommended deactivate**. Last updated 4 days ago, predates M6 ingest contract, likely source of the 30 failed n8n executions.

## Queued — next sessions (designed, not shipped)

1. **Today tab team brainstorm** — facilitator-ready prep at `docs/sessions/2026-04-22-today-tab-brainstorm-prep.md`.
2. **Demo account brainstorm** — pre-brainstorm at `docs/sessions/2026-04-22-demo-account-prebrainstorm.md`. Known landmines: Finance Assistant Gemini LLM leak; ActivityLog filtering. Real estimate 8–12 days including landmines.
3. **2FA enforcement** (audit #4) — policy decision + implementation.
4. **ULID migration** (audit #5) — IDOR hardening.
5. **Kanban JS extraction** (audit #7) — hygiene.
6. **Medium-severity audit fixes bundle** — the remaining open items above as a single half-day pass.

## Memory state

Updated 2026-04-22 to reflect:
- M6 fully green (not just "shipped")
- All 4 workflows active + polling
- Dashboard + logo + PWA live
- OAuth reconnect resolved (procedure kept for future breakage)
- 533 students in prod

## What's NOT in scope for this session

- Visual identity / brand refresh beyond the logo drop (Area 5 of Today-tab brief)
- Finance Assistant row-level auth (will be addressed in demo-account work)
- Mobile-first rebuild (Area 4 of Today-tab brief) — PWA installability covers the basic case
