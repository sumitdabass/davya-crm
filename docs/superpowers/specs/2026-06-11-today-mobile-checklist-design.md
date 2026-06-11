# Today — Mobile-First Action Checklist (Surface 2)

**Date:** 2026-06-11
**Program:** davya-crm mobile-first redesign — Surface 2 of 5 (Pipeline → **Today** → Reports → Finance → Rank)
**Status:** Design — approved in brainstorm, awaiting spec review
**Mockup:** `docs/superpowers/specs/mockups/today-checklist-mobile.html` (open on phone)

## Goal

Turn `/admin/today` from a generic customizable **card grid** into an opinionated **daily action checklist** — a tap-to-act agenda that answers "what do I need to do today" for a phone-first operator. Same aesthetic and scoped-skin mechanism proven on the student-form pilot and Pipeline surface.

## Approach (A — reorganize in place, prefs-driven sections)

`TodayPage::cards()` already resolves the cards for `surface='today'` through the existing prefs system (show/hide/order). The redesign keeps that resolution to drive **which sections appear and their order**, but renders each as a purpose-built checklist section rather than the existing heavyweight Filament widget.

- **`stat`-type cards** (`leads_captured_today`, `admissions_closed_today`, `meetings_held_today`) → rendered together as the **compact stats strip** at the top (reusing each card's count).
- **`list`-type cards** → rendered as **stacked full-width checklist sections** in prefs order, via a uniform `checklist-section` partial fed by a small row-provider service.

**Why purpose-built rows (not the existing widgets):** the existing list widgets don't match the action-checklist intent — `today_meetings` renders a **5-day grid** (we want today only) and `today_payments` is a **received-today log** (we also want a *to-chase* list). So presentation is new; the prefs/Customize/order machinery is reused unchanged. A section *is* a card id; show/hide/reorder a section = show/hide/reorder its card in the existing prefs.

### Section ↔ card mapping

| Section | Card id | Data source |
|---|---|---|
| Meetings today | `today_meetings` | `Meeting` scoped to **today** (reuse the widget's query, day[0] only) |
| Payments to chase | `payments_to_chase` (**NEW card**) | Students with pending/partial balance, not closed |
| Payments received today | `today_payments` | reuse `TodayPaymentsWidget::getRowsProperty()` |
| Stuck leads | `stuck_leads` | reuse existing stuck-leads query (`FilterKeys`) |
| Seat-fee pending | `seat_fee_pending` | reuse existing query |
| Re-entry candidates | `re_entry_candidates` | reuse existing query |

**New card (required):** `PaymentsToChaseCard` (`id = payments_to_chase`, `type = list`, `surface = any`, `isDefaultOn = today`) exposing the to-chase query. It appears in Customize like any other card.

**Default-on fix (required):** `StuckLeadsCard`, `SeatFeePendingCard`, and `ReEntryCandidatesCard` currently `isDefaultOn` only for `dashboard`. Each gets a one-line change to default-on for `today` as well (e.g. `in_array($surface, ['dashboard','today'], true)`) so all groups appear by default. Note: users who have *already* saved custom `today` prefs won't retroactively gain the new sections — they can add them via Customize; new/default users get all of them.

Rejected: a fresh `AgendaToday` Livewire page (more code, abandons the prefs/card model, higher regression surface) and a re-skin-only grid (already declined — the operator wants an action hub, not a dashboard).

## Layout (mobile-first, single column)

1. **Header** — "Today" (Instrument Serif italic) + the date; a `Customize` pill top-right (existing `open-customize-modal` dispatch).
2. **Stats strip** — the 3 stat cards as a horizontal 3-chip row; numbers in JetBrains Mono. Glanceable, not dominant.
3. **Action sections**, urgency-ordered by default, each a collapsible card with an icon + Instrument-Serif title + count badge + chevron:
   - **Meetings today** — rows: `time · name · course/owner`
   - **Payments to chase** (urgent styling) — rows: `name · context · pending ₹ with Indian-words subtext`
   - **Payments received today** — rows: `time · name · amount` (log; reuse existing card)
   - **Stuck leads** — rows: `aging-dot · name · stage · days-stuck pill`
   - **Seat-fee pending** — rows: `aging-dot · name · round · fee-due`
   - **Re-entry candidates** — rows: `name · last stage · re-eligible`
4. **Rows** are tap-anywhere → dispatch `open-student-peek` with the student id; a chevron hints it.
5. **Empty sections** render a muted "All clear ✓" and sink to the bottom (collapsed by default when count = 0).
6. **Whole-page empty** (all sections hidden via Customize) keeps the existing "You've hidden all cards / Reset to defaults" state.

## Aesthetic & skin

- New `resources/css/today-skin.css` (+ identical `public/css/today-skin.css` copy), scoped under `body.davya-today-skin`.
- Loaded **only on `/admin/today`** via a `PanelsRenderHook::PAGE_START` `->renderHook(..., scopes: [TodayPage::class])` that injects the stylesheet link (`/css/today-skin.css?v=1`) + a `document.body.classList.add('davya-today-skin')` script — byte-identical mechanism to the pipeline and student-form skins.
- Kit tokens reused from the program: **Instrument Serif** (display/section titles), **Bricolage Grotesque** (body), **JetBrains Mono** (numerals/amounts/counts), **emerald + vermilion on warm cream**. Aging dots (≤3d green / ≤14d amber / 15d+ red) and money rendered as `₹figure + Indian words` per existing davya convention (`MoneyFormat`).

## Components touched

| File | Change |
|---|---|
| `app/Today/ChecklistSections.php` (**new**) | Row-provider service. One method per section id returning a normalized row list `['student_id','title','subtitle','meta','dot'(?),'time'(?),'amount'(?)]`. Reuses existing queries (meetings today, today payments, stuck/seat-fee/re-entry) + the new to-chase query. Single source of section data. |
| `app/Today/SectionRegistry.php` (**new**) | Maps a `list` card id → `['label','icon','urgent'(bool),'provider' method]`. Drives presentation for whatever cards prefs resolve. |
| `app/Dashboard/Cards/ListCards/PaymentsToChaseCard.php` (**new**) | New card (`payments_to_chase`, list, default-on today) exposing the to-chase query. |
| `resources/views/filament/pages/today-page.blade.php` | Replace the card-grid body: stats strip (iterate `stat` cards) + stacked checklist sections (iterate `list` cards in prefs order → `checklist-section` partial via registry). Keep Customize button, `StudentSlideOver`, `CustomizeCardsModal`, undo toast. |
| `resources/views/filament/pages/partials/checklist-section.blade.php` (**new**) | Uniform collapsible section: header (icon/title/count/chevron) + rows; each row taps to `open-student-peek`; empty → "All clear ✓". |
| `app/Providers/Filament/AdminPanelProvider.php` | One new `PAGE_START` render hook scoped to `[TodayPage::class]` (skin link + body class). |
| `resources/css/today-skin.css` + `public/css/today-skin.css` (**new**) | Scoped stylesheet under `body.davya-today-skin`. |
| `StuckLeadsCard`, `SeatFeePendingCard`, `ReEntryCandidatesCard` | One-line `isDefaultOn` change to include `today`. |

`TodayPage.php`, `UserPrefsResolver`, `CustomizeCardsModal`, `StudentSlideOver`, and the existing widget classes are **unchanged**. Existing list-card render() / widgets stay as-is (still used on the Dashboard surface); the Today checklist reads their underlying queries via `ChecklistSections`, it does not call `card->render()`.

## Drawer / consistency note

Two distinct drawers exist and both are reused correctly:
- **`StudentPeekDrawer`** (`open-student-peek` + `studentId`) — the per-student peek with deal/context/probability + tabs. It is **globally mounted** (`AdminPanelProvider` render hook), so it is already present on `/admin/today`. **Checklist rows dispatch `open-student-peek`** → this opens the *same* drawer as Pipeline (consistency win, no new component).
- **`StudentSlideOver`** (`open-slide-over` + `cardId`) — a **stat-card drill-down list** (paginated students behind a stat). Only opens for `type='stat'` cards. **Kept for the stats-strip taps** (tapping a stat number drills into its list), exactly as today.

So: tapping a **row** → peek a student (`StudentPeekDrawer`); tapping a **stat** → drill the stat list (`StudentSlideOver`). No drawer is removed; the per-student peek is now unified with Pipeline.

## Feature parity (hard constraint — zero loss)

Retained: the 3 today-stats, full **Customize** (show/hide/reorder via existing prefs), the **student drawer**, and the **undo toast**. The only change is presentation (grid → urgency-ordered checklist) + the scoped skin.

## Responsive

Mobile-first single column. On `≥768px` the checklist renders in a centered max-width column (~720px) so it reads well on desktop — no separate desktop layout to maintain.

## Testing

New tests under `tests/Feature/MobileToday/`:
- **Skin scope** — `today-skin.css` link + `davya-today-skin` body class present on `/admin/today`, **absent** on `/admin/dashboard` and `/admin/students` (no leak).
- **ChecklistSections provider** (unit) — each provider returns the right rows: meetings scoped to today only (not the 5-day spread); `payments_to_chase` returns students with pending > 0 and excludes closed/fully-paid; `today_payments` matches the existing widget rows; stuck/seat-fee/re-entry match their existing queries. Rows carry `student_id`.
- **Checklist render** — stat strip renders the 3 stat counts; list cards render as sections in prefs order; count badges match row counts; rows carry `open-student-peek` dispatch with the right student id.
- **New card** — `PaymentsToChaseCard` is registered, default-on for `today`, off for `dashboard`, available to a viewer.
- **Customize parity** — hiding a card via prefs removes its section; reordering changes section order.
- **Empty state** — a zero-count section shows the "All clear" state; all-hidden shows the reset state.

Full suite must stay green (currently 919 pass / 0 fail / 1 skip). Test runner: `php -d memory_limit=2048M vendor/bin/phpunit` (`php artisan test` OOMs at 128M).

## Out of scope

- Per-item "mark done" / swipe-to-complete state (would need new persistence — not in this surface).
- Any change to the existing widget classes or the Dashboard surface (Today and Dashboard share the card system; the Dashboard keeps rendering the existing widgets via `card->render()`; only the `today` surface gets the checklist treatment).
