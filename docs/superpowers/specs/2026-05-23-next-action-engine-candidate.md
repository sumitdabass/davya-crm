# Next Action Engine — Phase 2 candidate (not yet specced)

**Date:** 2026-05-23
**Status:** Candidate / parked for separate brainstorm
**Sequenced after:** Bundle A (Workflow Connectors v1) ships

## What

A deterministic per-student "what should I do next?" recommender. For every student row, evaluate a small set of rules and surface the top 1–2 recommended actions as colored pills on the row, the edit page header, and the kanban card.

Not "AI". No ML. No DB table. Computed on-demand for visible rows only.

## Why this is on the backlog (not Bundle A)

This is the per-student version of davya-crm's existing aggregate widgets (StuckLeads / ReEntry / SeatFeePending). Those say "5 students need attention"; the Engine says "this student needs *Record payment* right now."

Concept-wise it overlaps Bundle B (Smart attention widgets — Payment Due This Week, Owner Workload, SLA Breach). Folding it in is an open question; for now it's filed as its own candidate.

## v1 scope (when picked up)

**4 rules, hardcoded order (no registry yet):**

1. `PaymentDueRule` — pending_amount > 0 AND deal-amount cap unmet → "Record payment" CTA → reuses **Bundle A F2 modal**.
2. `NoRecentMeetingRule` — last_meeting_at older than 5 days AND stage in active set → "Schedule meeting" CTA → reuses **Bundle A F2 modal**.
3. `StageStuckRule` — same stage for 7+ days (already a signal in StuckLeads widget) → "Nudge / advance stage" CTA.
4. `PreferenceMissingRule` — preference_r1 empty AND rank set → "Run rank lookup" CTA → reuses **Bundle A F1 entry point**.

**Output:**

- `App\Services\NextAction\NextActionEngine::for(Student $s): array<NextAction>`
- Display: top 2 only, colored by priority (red / amber).
- Per-row column in StudentResource list, header pill on Edit page, badge on Kanban card.

**Out of scope for v1:**

- Rule registry / dynamic registration
- DB cache / event-driven invalidation
- User-configurable rule thresholds
- Aggregate dashboard widget ("12 Urgent Actions")
- Cross-rule deduplication (4 rules × 1 student per row = max 4 pills, top 2 trimmed visually)

## Open questions for a future brainstorm

- **Computation trigger** — `getStateUsing` per row (Filament-native, cheap, no async needed) vs. async job with cached column.
- **Priority tie-breaking** — when 2 rules have the same priority weight, which wins?
- **Null-safety contract** — every rule asserts via a `Rule::eligible(Student $s): bool` guard before running, returning `null` (no action) cleanly.
- **Folding into Bundle B** — is this the same project as Smart Attention Widgets, or a sibling?
- **Reuses existing actions** — once Bundle A ships, the Engine's CTAs are just URLs / modal triggers into those connectors. No duplicate UI to build.

## What this document is NOT

- Not yet a design spec — placeholder so the idea isn't lost.
- Not a commitment to ship in any particular order.
- Not a green-light for Bundle B reorganization.

When this gets picked up, run it through `superpowers:brainstorming` to expand into a real spec.
