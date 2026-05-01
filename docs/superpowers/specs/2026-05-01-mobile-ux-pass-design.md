# Mobile UX Pass — Visual v2 — Design

**Date:** 2026-05-01
**Goal:** Eliminate field/value/button overlap on phone-width viewports across 5 high-traffic Filament surfaces. Visual v2 is the only target — fallback (non-v2) layouts are unchanged.

## Scope

5 surfaces are in scope:

1. **StudentResource** Create/Edit form — Tab-internal field grids
2. **PaymentReport** filter row (From / To / Owner)
3. **Student peek drawer** — tab strip + footer action bar
4. **Kanban cards** — internal flex row (₹X received + owner avatar)
5. **Dashboard custom-table widgets** — Today Payments, Today Meetings (the only two list cards with hand-rolled `<table>` markup; the other three — Stuck Leads, Re-Entry, Seat Fee Pending — extend Filament's `TableWidget` which already has native horizontal-scroll)

Out of scope: inline-style refactor, Filament tailwind-utility issue, dark-mode mobile bugs, tablet landscape. Each is its own concern and not currently broken in a way users have reported.

## Approach — hybrid

Two distinct fixes, picked per surface based on what's idiomatic:

- **Filament responsive grid API** for surfaces that ARE Filament forms (1, 2). Use `->columns(['default' => 1, 'md' => 2])` instead of `->columns(2)`. No CSS, no `!important`, no risk to ≥md (768px) rendering.
- **CSS-only fixes** for surfaces that are custom blades (3, 4, 5). Extend the existing `@media (max-width: 768px)` block in `resources/css/tokens.css` with rules targeting class hooks.

This avoids mixing the two strategies on a single surface and keeps the diff minimal.

## Surface-by-surface design

### 1. StudentResource form

**Problem:** 7 tabs in `app/Filament/Resources/StudentResource.php` declare `->columns(2)` (or `columns(3)`) on the tab itself or on Sections inside. On phone widths, fields keep their 2-column grid, so a left-column field's value overlaps the right-column field's label.

**Fix:** At every `->columns(N)` call-site in the file, change to `->columns(['default' => 1, 'md' => N])`. ~7 call-sites expected; final count to confirm during implementation.

**Edge case to verify:** `StudentFormDynamicTrait` (Phase A custom-field renderer) — if it builds Sections with `columns(2)` for custom fields, fix there too. If the trait emits flat fields without a Section grid, no change needed.

**Files:**
- `app/Filament/Resources/StudentResource.php`
- `app/Support/StudentFormDynamicTrait.php` (conditional — only if it uses `columns(N)`)

### 2. PaymentReport filter row

**Problem:** `PaymentReport::form()` line 57 — `->columns(3)`. From / To / Owner stay 3-wide on mobile, DatePicker fields overflow each cell.

**Fix:** `->columns(['default' => 1, 'md' => 3])`.

**Files:** `app/Filament/Pages/PaymentReport.php`

### 3. Student peek drawer

**Problem (two issues):**

- **Tab strip** (`student-peek-drawer.blade.php` lines 46–53): 5 tabs in `display: flex; gap: 18px;` with no wrap or scroll → bottom of viewport scrollbar appears, but on iOS it just clips.
- **Footer action bar** (lines 69–80): 3 buttons in `justify-content: space-between;` → cramped and overlap below ~400px width.

**Fix:** Add class hooks to the blade, then style in tokens.css.

Blade edits:

```blade
{{-- line 46-ish: was: <div style="display: flex; gap: 18px; ..."> --}}
<div class="davya-drawer-tabs" style="display: flex; gap: 18px; padding: 0 18px; border-bottom: 1px solid var(--border); font-size: var(--fs-12);">

{{-- line 69-ish: footer container --}}
<div class="davya-drawer-footer" style="position: sticky; bottom: 0; ...">
```

Tokens.css extension to the existing `@media (max-width: 768px)` block:

```css
/* Peek drawer tab strip — horizontal scroll on phones */
body.davya-v2 .davya-drawer-tabs {
    overflow-x: auto;
    flex-wrap: nowrap;
    scrollbar-width: none;
    -webkit-overflow-scrolling: touch;
}
body.davya-v2 .davya-drawer-tabs::-webkit-scrollbar { display: none; }

/* Peek drawer footer — wrap CTAs */
body.davya-v2 .davya-drawer-footer {
    flex-wrap: wrap;
    gap: 8px;
}
body.davya-v2 .davya-drawer-footer > div {
    width: 100%;
    justify-content: flex-start;
}
```

**Files:**
- `resources/views/livewire/student-peek-drawer.blade.php` (2 class additions)
- `resources/css/tokens.css`

### 4. Kanban cards

**Problem:** Inferred from audit. Markup not yet inspected. The hypothesis: card inner row uses `display: flex` without `flex-wrap`, so on cards narrower than ~240px, "₹X received" + owner avatar collide.

**Fix path:** First read `kanban-board.blade.php` at the card-row level. Then:
- If the row uses non-wrapping flex and overlap is reproducible at 360px → add `flex-wrap: wrap; row-gap: 4px` rule scoped to mobile @media. Continue.
- If overlap is NOT reproducible at 360px → skip this surface entirely. Note in session log.
- If markup needs structural changes (elements positioned absolutely, conflicting inline widths) → pause and update this spec before continuing.

**Files:**
- `resources/views/filament/pages/kanban-board.blade.php` (TBD)
- `resources/css/tokens.css`

### 5. Dashboard custom-table widgets

**Problem:** `TodayPaymentsWidget` and `TodayMeetingsWidget` extend `Filament\Widgets\Widget` and render hand-rolled `<table>` markup in their custom blades. On phones the 6-column table spills past the card and gets clipped by the surrounding card's `overflow: hidden`.

**Fix:** Wrap each `<table>` in `<div class="davya-table-scroll">…</div>`. Add one tokens.css rule:

```css
.davya-table-scroll {
    overflow-x: auto;
    -webkit-overflow-scrolling: touch;
}
```

This rule is non-conditional (no @media) — horizontal scroll on a table that fits is a no-op.

**Why only these 2 widgets:** `StuckLeadsWidget`, `ReEntryCandidatesWidget`, and `SeatFeePendingWidget` extend Filament's `TableWidget` which uses Filament's built-in `.fi-ta` wrapper that already has horizontal-scroll on narrow viewports. They render fine on mobile already.

**Files:**
- `resources/views/filament/widgets/today-payments-widget.blade.php`
- `resources/views/filament/widgets/today-meetings-widget.blade.php`
- `resources/css/tokens.css`

## Breakpoints

Match Visual v2's existing breakpoints — no new ones.

| Width | Existing rule | This pass adds |
|---|---|---|
| ≤ 480px | `tokens.css:331` minor adjustments | none |
| ≤ 768px | `tokens.css:266` topbar + kanban-col + drawer-width | drawer-tabs + drawer-footer + kanban card row |
| ≥ 768px | n/a (default desktop) | unchanged |

## Testing

- Manual visual smoke at **360px / 390px / 414px / 768px** in Chrome DevTools device emulator.
- Per surface: 1 screenshot before fix at 360px, 1 after — saved to `docs/sessions/2026-05-01-mobile-ux-pass.md`.
- Run `php artisan test` after all edits — confirm 0 regressions in existing 590-test suite.
- No new automated tests. CSS layout assertions are brittle and don't catch real visual bugs anyway.

## Risks

- **None to desktop rendering.** Filament responsive grid syntax and the @media query are both no-ops at ≥768px.
- **Kanban scope creep.** If kanban card markup requires structural changes beyond a wrap rule, the implementer will pause + update spec before continuing.
- **Phase A custom fields.** If `StudentFormDynamicTrait` doesn't go through the responsive Section API, custom fields might still render 2-col on mobile. Implementer audits the trait first; fix is identical (`columns(['default' => 1, 'md' => 2])`).

## Effort estimate

~2 hours total:
- 30 min: edit Filament forms (1, 2)
- 30 min: blade hooks + tokens.css (3, 5)
- 30 min: kanban inspection + fix (4)
- 30 min: visual smoke at 4 breakpoints across 5 surfaces

## Deploy

Local-only first. Sumit smoke-tests on his phone via local IP serve, then we push to GitHub and pull on Hostinger per `DEPLOY.md`. No migration, no asset build needed (tokens.css is already published to `public/css/tokens.css` and includes the `?v={filemtime}` cache-bust from Visual v2).
