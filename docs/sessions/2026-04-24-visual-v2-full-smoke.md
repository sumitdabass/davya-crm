# Visual v2 — Full Local Smoke Checklist

**Date:** 2026-04-24
**Phase:** 5 — Pre-deploy smoke
**Branch:** main, HEAD `1ff7b39`
**Rollback tag:** `pre-visual-refresh-20260424` at `b333bc2`

## Shipped in the refresh

### Phase 0 — Setup
- Task 0.1 — `config/davyas.php` + `.env.example` + `(bool)` cast — commit `b333bc2`
- Task 0.2 — rollback tag `pre-visual-refresh-20260424`

### Phase 1 — Tokens + CSS primitives (all page restyles gated on flag)
- Task 1.1 — `tests/Feature/VisualRefreshFlagTest.php` (2 tests)
- Task 1.2 — `resources/css/tokens.css` (210 lines)
- Task 1.3 — load tokens via HEAD_END; `npm run build:tokens` keeps public in sync
- Task 1.4 — `pipeline-config.blade.php`: card-row + Won/Lost thumbs
- Task 1.5 — `student-fields-config.blade.php`: green/red accents
- Task 1.6 — `today-page.blade.php`: section-card wrapper
- Task 1.7 — `dashboard.blade.php`: section-card wrapper
- Task 1.8 — `duplicate-flags-review.blade.php`: card-row
- Task 1.9 — StudentResource: 7 tabs get `davya-section` class
- Task 1.10 — new `SettingsLanding` at `/admin/settings` (6-tile grid)
- Task 1.11 — Phase 1 smoke doc

### Phase 2 — Kanban
- Task 2.1–2.2 — per-stage aggregates (`received_total` / `pending_total`) — commits `6a7d252` + `88db564`
- Task 2.3 — `AvatarColor` + `MoneyFormat` helpers — commit `5af75bd`
- Task 2.4–2.5 — dense kanban cards + stage_type accent — commits `f51b535` + `a87c581`

### Phase 3 — Shell + command palette
- Task 3.1 — `TopBar` Livewire component — commit `08eb37e`
- Task 3.2 — mount TopBar + hide Filament sidebar when flag on — commit `a12db48`
- Task 3.3 — `CommandPalette` with ⌘K keybind — commit `305cabf` + cleanup `a4bfff6`
- Task 3.4 — kanban sub-toolbar (filter pills + view switch) — commit `2da88f3`

### Phase 4 — Student peek drawer
- Task 4.1 — `StudentPeekDrawer` + 5 lazy tabs (Overview/Payments/Notes/Meetings/Activity) — commit `1ff7b39`

## Automated verification

- [x] `php artisan view:clear && php artisan view:cache` — compiles cleanly.
- [x] `diff resources/css/tokens.css public/css/tokens.css` — identical.
- [x] `./vendor/bin/phpunit` full suite:
  - Baseline (on `pre-visual-refresh-20260424`): 567 tests, 1 failure.
  - Current HEAD: 583 tests, 1 failure.
  - Pre-existing failure: `tests/Feature/Dashboard/TodayPageTest.php::test_empty_prefs_array_renders_empty_state_then_auto_append_hydrates_default_cards` — confirmed pre-existing on the rollback tag.
  - **Delta**: 16 new tests added by the refresh, all passing.

## Manual browser smoke (Sumit — run before flipping the flag on prod)

Log in as `sumit@davya.local` / `smoke-test-pw`. Run with `DAVYAS_VISUAL_V2=true php artisan serve`.

### Shell
- [ ] Top bar renders: brand left, 5 tabs (Pipeline · Students · Today · Reports · Finance), search pill in middle with `⌘K` hint, `+ New Student` emerald CTA, settings gear, avatar pill.
- [ ] Active tab highlighted with emerald-50 background.
- [ ] Filament's legacy sidebar is hidden.
- [ ] Pressing `⌘K` (or `Ctrl+K` on Linux/Windows) opens the command palette.
- [ ] Typing "chait" (or any student name) returns matching students.
- [ ] Clicking a student row in the palette opens the peek drawer.
- [ ] Clicking a page row navigates (Pipeline, Settings — Fields, etc.).
- [ ] Pressing Escape or clicking outside closes the palette.
- [ ] Clicking Davyas brand routes to /admin (dashboard).

### Kanban — /admin/kanban
- [ ] Sub-toolbar above column row shows Course/Owner/Round pills + Kanban/List view switch.
- [ ] Columns are 260 px wide with 3 px stage-accent top border (colour varies by stage type).
- [ ] Column header shows stage name + count pill + `₹X received · ₹Y pending` aggregate.
- [ ] Cards are dense one-row: left status strip coloured by student_response, name, course/round chip, amount right-aligned, owner avatar.
- [ ] Hover tints card emerald-50. Click opens peek drawer.
- [ ] Drag-drop between columns still enforces stage transition validator (tested by dragging an Advance Received card to a later stage).

### Peek drawer
- [ ] Header: bold name + phone/course/round line + owner pill top-right + close ✕.
- [ ] Stage stepper renders (past = emerald, current = amber, future = grey) with N/total indicator.
- [ ] 5 tabs: Overview (Deal + Context cards), Payments (list), Notes (list + textarea "Add note"), Meetings (list), Activity (activitylog entries). Switch between tabs.
- [ ] Clicking another kanban card while drawer is open swaps content (same drawer).
- [ ] "Open full page ↗" footer link routes to `/admin/students/:id/edit`.
- [ ] "+ Note" / "+ Payment" footer buttons switch to the matching tab.

### Forms
- [ ] `/admin/students/create` — 7 tab sections render with card-like styling.
- [ ] Required fields (Name, Phone, Lead Source, Stage) show red 3 px left bar on inputs.
- [ ] Asterisks on labels are hidden (replaced by the red bar).

### Settings surfaces
- [ ] `/admin/settings` — 6-tile grid (Fields / Stages / Duplicate review / Users & roles / Lead import / Activity audit).
- [ ] `/admin/student-fields` — custom fields green-accent, required built-ins red-accent.
- [ ] `/admin/pipeline-config` — Won row shows thumbs-up SVG (emerald) + emerald left accent; Lost shows thumbs-down (red) + red accent.
- [ ] `/admin/duplicate-flags` — rows in card-row style.
- [ ] `/admin/today` — cards wrapped in section-card.
- [ ] `/admin` dashboard — cards wrapped in section-card.

### Flag off (`unset DAVYAS_VISUAL_V2 && php artisan serve`)
- [ ] Top bar is GONE; Filament sidebar is back.
- [ ] Command palette is GONE (⌘K does nothing).
- [ ] Peek drawer never opens.
- [ ] Every page renders exactly as it did before the refresh.

### Browser matrix (optional but recommended)
- [ ] Chrome/Edge — required-field red bar visible (CSS `:has()` support confirmed).
- [ ] Safari 17+ — red bar visible.
- [ ] Firefox — red bar visible.
- [ ] Narrow mobile landscape — top bar items may stack; command palette should still work.

## Known non-blockers

- Pre-existing failure in `Dashboard/TodayPageTest::test_empty_prefs_array_renders_empty_state_then_auto_append_hydrates_default_cards`. Confirmed pre-existing on rollback tag. Investigate separately.
- Filament `:has()` required-bar silently skips on Safari <15.4 (negligible share).

## Ready to deploy?

Before flipping `DAVYAS_VISUAL_V2=true` on prod, confirm ALL manual smoke items are green locally. Then see Task 5.2 in the plan for deploy steps.

Rollback path: set `DAVYAS_VISUAL_V2=false` (or unset) + `php artisan config:clear && php artisan view:clear` on prod. The refresh disappears entirely — no data touched, no schema changes.
