# Mobile-first CRM redesign — program roadmap (APPROVED 2026-06-10)

**Status:** Program-level decomposition + sequencing approved by Sumit 2026-06-10.
This is the north-star doc for rolling the student-form mobile-first aesthetic across
the whole davya-crm admin. It is NOT an implementation spec — each surface below gets
its own brainstorm → spec → plan → build cycle when we reach it.

## Goal
Update the entire davya-crm admin to a mobile-first policy in the student-form
aesthetic (`feedback_davya_crm_typography`: Instrument Serif + Bricolage Grotesque +
JetBrains Mono; emerald + vermilion on warm cream).

## The two halves
The request "redesign Pipeline/Today/Reports/Finance/Rank like the student form" is
really a **shared foundation** plus **five independent surfaces**.

### Foundation — shared mobile-first skin + component kit
Currently exists ONLY inside the student-form mockups. The pilot build turns it into
real, reusable, Filament-compatible code. Reusable pieces to factor out:
- Scoped skin stylesheet (one wrapper class; fonts + warm-cream tokens loaded only on
  skinned pages, mirroring the existing `config('davyas.visual_v2')` + `tokens.css`
  HEAD_END render-hook pattern in `AdminPanelProvider`).
- **Stepper** (tappable pipeline-stage stepper, routes through StageTransitionEngine).
- **Segmented chips** (Filament ToggleButtons replacing short-enum Selects).
- **Money bar**, **timeline**, **card masthead**, **sticky save bar**.

### Five surfaces (each its own spec → plan → build, reusing the kit)
1. **Pipeline** (kanban) — pairs naturally with the student form.
2. **Today** (daily driver, highest mobile traffic).
3. **Reports** (Leads + Payment reports, data-dense).
4. **Finance** (Books / Expenses / Investments).
5. **Rank** (lookup + manage-data CRUDs).

## Sequencing — PILOT-FIRST (approved)
1. **Build the student form now** (design already locked in
   `2026-06-10-student-form-mobile-redesign-design.md`). This hardens the shared kit.
2. **Then** brainstorm + build each surface one at a time on the proven kit. Per-surface
   mockups are produced during that surface's own brainstorm — NOT up front. This keeps
   each spec focused and lets the kit stabilise before fan-out.

Rationale vs alternatives: building the pilot first surfaces the real Filament
constraints (ToggleButtons, render-hook scoping, sticky bars) on ONE surface instead of
guessing six times; the other five then inherit a proven kit. "Design-all-first" risks
mockups that don't survive Filament; "fan-out in parallel" is viable only AFTER the kit
is stable (it can become the post-pilot accelerator if desired).

## Constraints carried across every surface
- ZERO feature/field regressions vs the current surface.
- Scoped CSS must not leak — verify untouched surfaces stay visually identical.
- No DB/schema changes unless a surface's own spec justifies one.
- Existing suite stays green; new tests per surface.

## Immediate next step
Write the implementation plan for the pilot (student form) off its locked spec, build it
on branch `feat/student-form-mobile-redesign`, factoring the foundation kit out as we go.
