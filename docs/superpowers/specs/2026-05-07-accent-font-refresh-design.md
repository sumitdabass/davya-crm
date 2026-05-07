# Visual v2 — Accent + Font Refresh

**Date:** 2026-05-07
**Status:** Implemented locally, awaiting prod deploy approval
**Scope:** Surgical refinement of Visual v2 token system. Not a redesign.

## Why

After the Visual v2 launch (2026-04-24), two issues surfaced in the design audit:

1. **`--stage-won` was identical to `--brand-600`** (both `#059669`). The "Won" kanban column read as "just more emerald" — won leads did not feel celebratory or distinct from active leads, weakening the most semantically important stage in the pipeline.
2. **Font inconsistency.** `tailwind.config.js` declares Figtree, but `/admin/login` was loading Inter from `fonts.bunny.net` (Filament's default). Two geometric sans fighting each other, and no display face for identity surfaces (student names, stage titles, stat numbers).

## Design decisions

### Won-state accent — terracotta `#D97757`

- Chosen over marigold gold (too close to `--stage-active` amber `#F59E0B`) and deep magenta (too aggressive for a CRM used by school staff).
- Warm earthy orange-red. Rare in SaaS CRM space, culturally resonant for an India-based education consultancy. Distinct from every other stage accent on the wheel.
- Adds `--stage-won-soft: #FBE9DF` for tinted backgrounds (reserved for future use; no surface consumes it yet).
- Brand emerald primary, all other stage accents, and response strips remain unchanged.

### Font system — Figtree body + Fraunces display

- Figtree (existing body face) is now applied panel-wide via `->font('Figtree', url: ...)` in `AdminPanelProvider`, replacing Filament's default Inter on `/admin/login` and any other surface that fell back to Filament defaults.
- Fraunces (variable serif) is added as `--font-display` and applied to **three identity surfaces only**:
  1. **Kanban column titles** — pipeline stage names carry hierarchy in the kanban view.
  2. **Filament page heading (`.fi-header-heading`)** — student name on detail/edit pages.
  3. **Dashboard stat numbers (`.davya-stat-number`)** — the big `30px` digits in widget cards.
- All other surfaces (labels, table cells, form fields, buttons, nav, topbar, peek drawer, badges, pills) remain Figtree. Fraunces is *seasoning*, not *replacement*.

## Implementation

Four files changed:

| File | Change |
|---|---|
| `resources/css/tokens.css` | Added `@import` for Fraunces, added `--font-display` variable, changed `--stage-won` from `#059669` to `#D97757`, added `--stage-won-soft: #FBE9DF`, added 3 font-family rules at end of file (`.davya-kanban-col-head h4`, `.fi-header-heading`, `.davya-stat-number`) all gated under `body.davya-v2`. |
| `public/css/tokens.css` | Synced from `resources/css/tokens.css` (this is the file the panel actually loads via `asset('css/tokens.css')`). |
| `resources/views/components/dashboard/stat-body.blade.php` | Added `class="davya-stat-number"` to both stat-number elements (drillable button and plain span). |
| `app/Providers/Filament/AdminPanelProvider.php` | Added `->font('Figtree', url: 'https://fonts.bunny.net/css?family=figtree:400,500,600,700&display=swap')` so Filament stops loading Inter as fallback. |

All CSS rules are gated under `body.davya-v2`, so when `DAVYAS_VISUAL_V2=false` the panel reverts to stock Filament with the only persistent change being Figtree-instead-of-Inter (acceptable; Figtree was always the intended body face).

## Out of scope

- Dark mode (deferred — needs separate audit of every custom widget against dark surface).
- Other stage colors (new/active/meeting/advance/round/offline/lost — unchanged).
- Brand emerald primary, response strips, secondary palette.
- Kanban layout, form density, dashboard composition, navigation.
- Topbar, peek drawer, pill filters.
- Logo, favicon, PWA manifest.

## Risks and mitigations

1. **Terracotta contrast against white** — `#D97757` against pure white sits at ~3.5:1 contrast ratio (below WCAG AA for body text but acceptable for a 3px decorative top-border, which is the only surface using it). No accessibility regression.
2. **Fraunces font-load weight** — Variable woff2 from fonts.bunny.net, ~50KB. One additional font request on first load. Acceptable for staff-only admin tool; ignored on subsequent visits via browser cache.
3. **Inter→Figtree visual shift on login page** — Existing users may notice the body type feels slightly more friendly. No information density change. No layout shift.
4. **`public/` and `resources/` tokens.css drift** — These two files must be kept in sync manually. The deploy step must always copy `resources/css/tokens.css` → `public/css/tokens.css` before pushing. Future plan should add a Composer/npm script to automate this.

## Verification (local — done)

- `DAVYAS_VISUAL_V2=true php artisan serve --port=8766` started
- `curl http://127.0.0.1:8766/css/tokens.css` confirms terracotta won + Fraunces vars present
- `/admin/login` HTML confirms `figtree:400,500,600,700` link present, no `family=inter`
- Manual visual inspection of `/admin`, `/admin/kanban`, student detail page pending user sign-off

## Deploy

Production deploy requires Sumit's explicit go-ahead. Steps when approved:

1. Run pre-deploy quality check (lint + curl-verify every changed file per `feedback_pre_deploy_quality_check.md`).
2. Sync `resources/css/tokens.css` → `public/css/tokens.css` locally.
3. FTP push the 4 changed files to prod.
4. Restart PHP-FPM via cPanel MultiPHP Manager toggle (per `reference_hostinger_fpm_opcache.md`) so the panel provider picks up the `->font()` change.
5. Curl `https://davyas.ipu.co.in/admin/login` and grep for `family=figtree` and absence of `family=inter`.
6. Curl `https://davyas.ipu.co.in/css/tokens.css` and grep for `D97757` and `fraunces`.

Rollback: revert the 4 files, redeploy, restart FPM. No schema changes, no data touched.
