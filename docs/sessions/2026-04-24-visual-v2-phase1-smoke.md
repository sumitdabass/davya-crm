# Visual v2 — Phase 1 Smoke Checklist

**Date:** 2026-04-24
**Phase:** 1 — Tokens + CSS primitives + visual-only page restyles
**Flag:** `DAVYAS_VISUAL_V2` (in `config/davyas.php`, default false)
**Rollback tag:** `pre-visual-refresh-20260424` at `b333bc2`

## Shipped in Phase 1

- Task 0.1 — `config/davyas.php` with `visual_v2` flag + `.env.example` docs + `(bool)` cast
- Task 0.2 — rollback tag `pre-visual-refresh-20260424`
- Task 1.1 — `tests/Feature/VisualRefreshFlagTest.php` (2 tests)
- Task 1.2 — `resources/css/tokens.css` (210 lines: tokens + utility classes + required-bar + owner-pill)
- Task 1.3 — `AdminPanelProvider` loads `tokens.css` via `HEAD_END` when flag on; `npm run build:tokens` keeps `public/css/tokens.css` in sync
- Task 1.4 — pipeline-config: card-row + Won/Lost thumbs badges
- Task 1.5 — student-fields-config: card-row with green accent on custom, red accent on required built-ins
- Task 1.6 — today-page: `davya-section-card` wrapper (unified via `x-dashboard.card-frame`)
- Task 1.7 — dashboard: same `davya-section-card` wrapper on outer card loop
- Task 1.8 — duplicate-flags-review: card-row style
- Task 1.9 — StudentResource: 7 `Tabs\Tab`s get `davya-section` class via `extraAttributes`
- Task 1.10 — new `SettingsLanding` page at `/admin/settings`, 6-tile grid

## Automated verification

- [x] `php artisan view:clear && php artisan view:cache` — compiles cleanly.
- [x] `./vendor/bin/phpunit tests/Feature/VisualRefreshFlagTest.php` — 2/2 pass.
- [x] `./vendor/bin/phpunit --filter PipelineConfig` — 15/15 pass.
- [x] `./vendor/bin/phpunit --filter StudentResource` — 8/8 pass.
- [x] Full suite — 567 tests, 1675 assertions, 1 failure. Pre-existing failure: `tests/Feature/Dashboard/TodayPageTest.php::test_empty_prefs_array_renders_empty_state_then_auto_append_hydrates_default_cards` — confirmed pre-existing on tag `pre-visual-refresh-20260424` (b333bc2). Not introduced by Phase 1.

## Manual browser smoke (Sumit — run before deploying Phase 1+)

Log in as `sumit@davya.local` / `smoke-test-pw`. Run with `DAVYAS_VISUAL_V2=true php artisan serve`.

### Flag ON

- [ ] `/admin` — dashboard cards wrapped in section-card with title "Dashboard".
- [ ] `/admin/today` — cards wrapped in section-card with title "Today's overview".
- [ ] `/admin/students/create` — 7 tab sections have card-like styling; required fields (Name, Phone, Lead Source, Stage) show a red 3 px left bar on the input; asterisks are hidden.
- [ ] `/admin/student-fields` — custom fields show a green 3 px left accent; required built-ins show red accent; other rows are plain grey card-rows.
- [ ] `/admin/pipeline-config` — Won row has thumbs-up SVG (emerald) + emerald left accent; Lost row has thumbs-down SVG (red) + red left accent.
- [ ] `/admin/duplicate-flags` — flag rows render as card-rows.
- [ ] `/admin/settings` — 6-tile grid (Fields / Stages / Duplicate review / Users & roles / Lead import / Activity audit). Clicking a tile routes correctly.

### Flag OFF

Stop the server, restart without env var: `php artisan serve`. Walk the same pages. Expected: every page renders EXACTLY as it did before the refresh. No card wrappers, no accents, no Settings menu item.

### Browser matrix

- [ ] Chrome/Edge — red bar on required fields (CSS `:has()` supported).
- [ ] Safari 17+ — red bar present.
- [ ] Firefox — red bar present.

## Known non-blockers

- Pre-existing test failure on `TodayPageTest::test_empty_prefs_array_renders_empty_state_then_auto_append_hydrates_default_cards` — investigate separately, not a Phase 1 regression.
- Filament `:has()` required-bar degrades silently on Safari <15.4 (negligible share of traffic).

## Next — Phase 2

Kanban column + dense card rewrite. Plan: `docs/superpowers/plans/2026-04-24-visual-refresh.md` Tasks 2.1–2.5.
