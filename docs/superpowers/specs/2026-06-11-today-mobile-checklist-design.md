# Today — Mobile-First Action Checklist (Surface 2)

**Date:** 2026-06-11
**Program:** davya-crm mobile-first redesign — Surface 2 of 5 (Pipeline → **Today** → Reports → Finance → Rank)
**Status:** Design — approved in brainstorm, awaiting spec review
**Mockup:** `docs/superpowers/specs/mockups/today-checklist-mobile.html` (open on phone)

## Goal

Turn `/admin/today` from a generic customizable **card grid** into an opinionated **daily action checklist** — a tap-to-act agenda that answers "what do I need to do today" for a phone-first operator. Same aesthetic and scoped-skin mechanism proven on the student-form pilot and Pipeline surface.

## Approach (A — reorganize in place, reuse data sources)

`TodayPage::cards()` already resolves the cards for `surface='today'` through the existing prefs system (show/hide/order). The redesign **re-renders those resolved cards** instead of re-querying:

- **`stat`-type cards** (`leads_captured_today`, `admissions_closed_today`, `meetings_held_today`) → rendered together as the **compact stats strip** at the top.
- **`list`-type cards** (`today_meetings`, `today_payments`, `stuck_leads`, `seat_fee_pending`, `re_entry_candidates`) → rendered as **stacked full-width checklist sections** in prefs order.

This means Customize, undo, and prefs persistence keep working **unchanged** — a section *is* a card; show/hide/reorder a section = show/hide/reorder its card. No new backend queries.

**Default-on fix (required):** `StuckLeadsCard`, `SeatFeePendingCard`, and `ReEntryCandidatesCard` currently `isDefaultOn` only for `dashboard`. To make all four action groups appear on Today by default (as requested), each gets a one-line change to default-on for `today` as well (e.g. `in_array($surface, ['dashboard','today'], true)`). This is the only logic change to a card class. Note: users who have *already* saved custom `today` prefs won't retroactively gain the new sections — they can add them via Customize; new/default users get all four.

Rejected: a fresh `AgendaToday` Livewire page (more code, abandons the prefs/card model, higher regression surface) and a re-skin-only grid (already declined — the operator wants an action hub, not a dashboard).

## Layout (mobile-first, single column)

1. **Header** — "Today" (Instrument Serif italic) + the date; a `Customize` pill top-right (existing `open-customize-modal` dispatch).
2. **Stats strip** — the 3 stat cards as a horizontal 3-chip row; numbers in JetBrains Mono. Glanceable, not dominant.
3. **Action sections**, urgency-ordered by default, each a collapsible card with an icon + Instrument-Serif title + count badge + chevron:
   - **Meetings today** — rows: `time · name · course/owner`
   - **Payments to chase** (urgent styling) — rows: `name · context · pending ₹ with Indian-words subtext`
   - **Stuck leads** — rows: `aging-dot · name · stage · days-stuck pill`
   - **Admission actions** — one section, two labelled sub-groups: **Seat-fee pending** and **Re-entry candidates**
4. **Rows** are tap-anywhere → dispatch `open-slide-over` with the student id; a chevron hints it.
5. **Empty sections** render a muted "All clear ✓" and sink to the bottom (collapsed by default when count = 0).
6. **Whole-page empty** (all sections hidden via Customize) keeps the existing "You've hidden all cards / Reset to defaults" state.

## Aesthetic & skin

- New `resources/css/today-skin.css` (+ identical `public/css/today-skin.css` copy), scoped under `body.davya-today-skin`.
- Loaded **only on `/admin/today`** via a `PanelsRenderHook::PAGE_START` `->renderHook(..., scopes: [TodayPage::class])` that injects the stylesheet link (`/css/today-skin.css?v=1`) + a `document.body.classList.add('davya-today-skin')` script — byte-identical mechanism to the pipeline and student-form skins.
- Kit tokens reused from the program: **Instrument Serif** (display/section titles), **Bricolage Grotesque** (body), **JetBrains Mono** (numerals/amounts/counts), **emerald + vermilion on warm cream**. Aging dots (≤3d green / ≤14d amber / 15d+ red) and money rendered as `₹figure + Indian words` per existing davya convention (`MoneyFormat`).

## Components touched

| File | Change |
|---|---|
| `resources/views/filament/pages/today-page.blade.php` | Replace the card-grid body with: stats strip (stat cards) + stacked checklist sections (list cards), wrapped in skin classes. Keep Customize button, `StudentSlideOver`, `CustomizeCardsModal`, undo toast. |
| List-card blades (`today-meetings-card`, `today-payments-card`, `stuck-leads`, `seat-fee-pending`, `re-entry`) | **Additive** skin classes so each renders as a tap-row list under the skin (same technique as the pilot's money-bar/timeline blades). No query changes. |
| `app/Providers/Filament/AdminPanelProvider.php` | One new `PAGE_START` render hook scoped to `[TodayPage::class]`. |
| `resources/css/today-skin.css` + `public/css/today-skin.css` | New scoped stylesheet. |

`StuckLeadsCard` / `SeatFeePendingCard` / `ReEntryCandidatesCard` get the one-line `isDefaultOn` change above. `TodayPage.php`, the stat/meetings/payments card classes, `UserPrefsResolver`, `CustomizeCardsModal`, and `StudentSlideOver` are otherwise **unchanged** (logic-wise) — no query changes anywhere.

## Drawer / consistency note

Today's drawer is **`StudentSlideOver`** (listens to `open-slide-over`); Pipeline's is `StudentPeekDrawer` (`open-student-peek`). They are separate components. This surface **keeps Today on its existing `StudentSlideOver`** — no regression, no scope creep. Unifying the two drawers is explicitly out of scope (a possible later task).

## Feature parity (hard constraint — zero loss)

Retained: the 3 today-stats, full **Customize** (show/hide/reorder via existing prefs), the **student drawer**, and the **undo toast**. The only change is presentation (grid → urgency-ordered checklist) + the scoped skin.

## Responsive

Mobile-first single column. On `≥768px` the checklist renders in a centered max-width column (~720px) so it reads well on desktop — no separate desktop layout to maintain.

## Testing

New tests under `tests/Feature/MobileToday/`:
- **Skin scope** — `today-skin.css` link + `davya-today-skin` body class present on `/admin/today`, **absent** on `/admin/dashboard` and `/admin/students` (no leak).
- **Checklist render** — stat strip renders the 3 stat cards; list cards render as sections in prefs order; section count badges match item counts; rows carry `open-slide-over` dispatch with the right student id.
- **Customize parity** — hiding a card via prefs removes its section; reordering changes section order.
- **Empty state** — a zero-count section shows the "All clear" state; all-hidden shows the reset state.

Full suite must stay green (currently 919 pass / 0 fail / 1 skip). Test runner: `php -d memory_limit=2048M vendor/bin/phpunit` (`php artisan test` OOMs at 128M).

## Out of scope

- Per-item "mark done" / swipe-to-complete state (would need new persistence — not in this surface).
- Unifying `StudentSlideOver` and `StudentPeekDrawer`.
- Any change to card queries or the Dashboard surface (Today and Dashboard share the card system; this only re-renders the `today` surface).
