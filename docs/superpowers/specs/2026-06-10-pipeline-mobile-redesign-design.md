# Pipeline (kanban) mobile-first redesign — DESIGN (approved 2026-06-10)

**Status:** Design approved by Sumit 2026-06-10. Second surface in the mobile-first CRM
program (`2026-06-10-mobile-first-crm-program-roadmap.md`), after the student-form pilot
(LIVE). Reuses the pilot's proven kit (scoped-skin render hook, cream tokens + 3 fonts,
chip styling with the `input:checked + label.fi-btn` fix).

## Goal
Make the Pipeline board mobile-first in the davya cream aesthetic, because the team works
leads from their phones daily. One Livewire page renders two ways: a re-skinned
multi-column drag board on desktop, and a stage-switcher + tap-to-move experience on
phones. **Zero feature/data regressions vs the current board.**

## Decisions made during brainstorming
1. **Mobile interaction model = approach B (mobile-native), not a responsive re-skin of the desktop board.** The team manages leads on phones daily, so drag-to-move (painful on touch) is replaced on mobile by a stage switcher + a tap-to-move action sheet.
2. **Move action sheet = Guided (forward-first).**
3. **Desktop keeps the full multi-column board + Sortable.js drag-to-move**, re-skinned in cream only (no layout change).
4. **One page, two renderings** swapped by a CSS breakpoint — no new route, no duplicated data, no new queries.

## Responsive model
- Single Livewire page `App\Filament\Pages\KanbanBoard` (route `/admin/kanban`), single `getBoard()` payload.
- **Breakpoint ~768px.** Desktop ≥768px shows the multi-column board; mobile <768px shows the stage-switcher layout. Both render from the same `getBoard()` columns array; the swap is presentation-only (CSS `@media` + a couple of blade containers both present in the DOM, one shown per breakpoint).
- Targets the existing `config('davyas.visual_v2')` board (v2 chrome is the shipped/active path). The cream skin layers on top via a scoped body class.

## Desktop — re-skinned board (no layout/behavior change)
Same columns, same drag-to-move via Sortable.js → `moveStudentToStage()`. Cream restyle only:
- Warm paper board background; columns as cream cards with a 3px stage-type top border (existing `data-stage-type` colors retained).
- Column title in **Instrument Serif**; count badge + the per-column money aggregate line (deal · received · pending · profit) in **JetBrains Mono**.
- Cards keep the response left-strip (ready/hesitant/cold), the aging dot (`days_in_stage` → green ≤3d / amber ≤14d / red >14d), name, course/round chip, amount, owner avatar, and `extras` (KanbanExtrasFormatter) — all unchanged in content.
- Toolbar re-skinned; controls and behavior unchanged.

## Mobile — stage switcher + Guided move
- **Stage-pill switcher:** a horizontally-scrolling row of all 13 stages, each pill showing its student count; the current stage is highlighted. Tapping a pill shows that stage's cards full-width below. The active stage's money aggregate renders under the pills.
- **Cards:** same content as desktop, full-width. **Card-body tap** dispatches the existing `open-student-peek` (the peek drawer / edit) — unchanged. Each card carries a small **⤳ Move** pill.
- **Guided move sheet** (opens on ⤳ Move): a bottom sheet titled "Move {name} forward" with:
  - a big primary button → **the next stage** (the stage immediately after the current one in `PipelineConfig` stage order), shown only when a next stage exists;
  - **⤺ Back a stage** → the stage immediately before, shown only when one exists;
  - **▾ Any stage** → a collapsible list of all 13 stages with counts (full freedom, incl. jumping to Complete Payment / Closed).
  - Terminal stages (won/lost) show only Back + Any stage.
- **Every move — from any of those affordances — calls the existing `moveStudentToStage($studentId, $targetStageName)`**, which already routes through `StageTransitionEngine::forStageChange()`:
  - **hard block** → the existing inline **fix-up flow** (`open-fix-modal` → `fixAndMove()`), re-skinned as a bottom sheet on mobile; the field whitelist and retry logic are unchanged.
  - **soft warnings** → warning toast (existing behavior).

## Filters on mobile
- The 3 toggle filters (stuck / seat-fee / re-entry) stay **inline** above the board as one-tap chips (they're the highest-value quick filters; `FilterKeys` keys unchanged).
- All other controls (owner / course / round / source / plan / category / response selects) collapse behind a single **Filters** button with an active-count badge → opens a **bottom sheet** containing the same controls bound to the same Livewire properties.
- Desktop toolbar keeps every control inline (re-skinned only).
- **All `#[Url]` filter properties, `FilterKeys`, `getFilterOptions()`, and `filteredStudentQuery()` are untouched** — the mobile UI is a different arrangement of the same bindings.

## Components to build / touch
- **New scoped skin stylesheet** `resources/css/pipeline-skin.css` (+ `public/css` mirror), every rule under `body.davya-pipeline-skin`. Reuses the pilot's cream token block + chip rules. Loaded only on the kanban page via a `PanelsRenderHook::PAGE_START` render hook scoped to `[KanbanBoard::class]` in `AdminPanelProvider` (mirrors the pilot; same body-class-via-JS idiom; cache-buster `?v=N`).
- **`resources/views/filament/pages/kanban-board.blade.php`** — add the mobile stage-switcher container, the Guided move sheet, and the Filters bottom sheet, alongside the existing (now cream-skinned) desktop board. Desktop markup/Sortable JS kept; mobile blocks shown via the breakpoint. Reuse existing Livewire methods.
- **A small "ordered stage" helper** for next/back resolution — `nextStageName(current)` / `prevStageName(current)` derived from `PipelineConfig::stages()` order. Lives on `KanbanBoard` (or a tiny dedicated value object) and is exposed to the blade for the Guided sheet. Read-only over existing data.
- **No changes** to `getBoard()`, `moveStudentToStage()`, `fixAndMove()`, `StageTransitionEngine`, `PipelineConfig`, `FilterKeys`, `KanbanExtrasFormatter`, policies, or any model/migration.

## Preserved untouched
StageTransitionEngine (hard/soft + missing-fields), `fixAndMove` whitelist, all filters + `#[Url]` state + `FilterKeys`, visibility scoping (`Student::visibleTo`), column aggregates, peek drawer, role access (admin/super_admin/head/member; freelancer blocked), drag-to-move on desktop. No DB/schema changes. No new queries.

## Testing
Existing kanban suite (KanbanBoardTest, KanbanAggregateTest, KanbanSoftWarningsTest, KanbanBoardAccessTest, KanbanDynamicStagesTest) stays green. New tests:
- Mobile layout renders the stage-pill switcher with **all pipeline stages and their counts** (assert each stage name + count present in the rendered page).
- `nextStageName()` / `prevStageName()` resolve correctly from `PipelineConfig` order, including null at the ends (first stage has no "back"; terminal stages have no "next").
- A move performed via the sheet path still routes through `StageTransitionEngine` — a hard-blocked target still blocks + surfaces `missing_fields` (reuse the existing `moveStudentToStage` assertions; the sheet calls the same method).
- Skin scope: `pipeline-skin.css` + the `davya-pipeline-skin` body class load on `/admin/kanban` and are **absent** on another admin page (no leak), mirroring the pilot's `FormSkinScopeTest`.

## Risks
- **Breakpoint double-render:** both desktop and mobile blocks exist in the DOM (CSS-toggled). Keep the mobile blocks lightweight; ensure Sortable.js only initializes on the visible desktop columns to avoid double-binding. Verify no duplicate `wire:` bindings.
- **Stage-pill overflow:** 13 pills scroll horizontally; ensure the current stage auto-scrolls into view on load.
- **Scoped CSS leak:** verify other admin pages (students list, dashboard, reports) are visually unchanged — same discipline as the pilot.

## Reuse / kit note
This is the second consumer of the mobile-first kit. The cream token block + chip CSS are copied from `student-form-skin.css`; if a third surface follows, extract the shared tokens into a common partial (deferred — not in this spec's scope).
