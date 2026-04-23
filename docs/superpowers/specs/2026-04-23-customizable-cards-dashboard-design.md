# Customizable Cards Dashboard (Today Tab SP#3) — Design

**Date:** 2026-04-23
**Status:** Design approved, pending implementation plan
**Author:** Sumit + Claude (brainstorming session 2026-04-23)
**Sub-project:** #3 of 3 for the **Today Tab** initiative. SP#1 (meetings + Today page) shipped 2026-04-22. SP#2 (follow-up strip + call log) **dropped** from scope by user decision during this session.

## Problem

Every page in davya-crm that surfaces an aggregate (Dashboard, Today page, Pipeline stats) shows a fixed set of numbers decided by a developer. Heads and counsellors cannot pick which numbers they care about or remove the noise. Every stat is also a dead end — you see "Meeting Scheduled: 47" but clicking it does nothing, so users bounce to the Students table and hand-filter.

SP#3 closes both gaps on the two surfaces users spend their day in — the `/admin` Dashboard and `/admin/today` page. Every aggregate becomes a first-class **card** with a self-serve customization model (add, remove, reorder) and every stat-type card is clickable → slide-over drill-down with filtered rows + CSV.

## Scope

**In:**
1. New per-user JSON column `users.dashboard_prefs` storing ordered enabled-card arrays per surface.
2. New `app/Dashboard/` component tree (Card interface, registry, resolver, concrete cards, slide-over).
3. Replace default Filament Dashboard with custom `DashboardPage` rendering the resolved card set.
4. Update `TodayPage` (SP#1) to render via the same resolver.
5. **Hybrid card granularity:** stat cards explode per-number (each pipeline stage = one card, each "today metric" = one card), list cards stay whole (existing widgets wrapped in a card frame).
6. Three new Today stat cards: **Meetings Held Today**, **Leads Captured Today**, **Admissions Closed Today** (replaces the original SP#3 "Today Reports strip / Core 4 metrics" concept now that SP#2 is dropped).
7. `CustomizeCardsModal` Livewire component — checkbox toggle + drag-reorder (SortableJS, reused from SP#1 pipeline-config page), per surface, writes `users.dashboard_prefs`.
8. `StudentSlideOver` Livewire component — right-side slide-over with a per-card column schema, search, pagination, CSV download, and "Open in full table" deep-link to filtered `StudentResource`.
9. Dynamic stage card generation from `stages` table (SP#1) — new/renamed/deleted stages auto-propagate.

**Out (not in SP#3):**
- PaymentReport, LeadsReport, Kanban — unchanged. (Considered for scope C, dropped to scope B.)
- Per-role default card sets — same defaults for every role; `Student::scopeVisibleTo` handles visibility.
- Card resizing — fixed uniform widths (locked during roadmap reshape 2026-04-22).
- Composite/custom-filter card builder — out of scope; users select from the fixed card list only.
- Follow-up / call-log features — entire SP#2 dropped by user this session.
- `InstallAppWidget` card treatment — stays as a pinned header element, auto-hides in standalone mode.

## Hard rules from user (locked)

1. **Scope B.** Dashboard + Today only. Reports pages untouched.
2. **Hybrid cards (option C of Q2).** Stat-style aggregates explode into per-number cards; list-style widgets stay whole.
3. **Shared pool with curated defaults (option C of Q3).** Card id namespace is shared across surfaces; each surface has an opinionated default list; users can rearrange freely.
4. **Slide-over + "Open in full table" (option C of Q4).** Stat card click → right-side slide-over; slide-over has a footer link to the full `StudentResource` with the same filter applied.
5. **Today metrics card-ified (option C of Q5).** Three individual stat cards, not one composite strip widget.
6. **Same defaults for all roles (option A of Q6).** One shared default layout; `visibleTo` auto-scopes the numbers per user.
7. **Every stage is a card by default.** All 10 current stages render in the Dashboard default layout, in pipeline sort order. New stages auto-propagate (see resolver behavior below).
8. **Full self-serve customization, no code.** Admin-users control their own layouts via the Customize modal; no developer involvement for card selection, reorder, or hide.
9. **Local preview sign-off required before deploy.** Sumit walks the full feature locally (Customize modal, drill-down, role scoping) before merge to main. Prod smoke is minimal.

## Card inventory (v1)

### Stat cards (clickable number → drill-down slide-over)

| Card id | Label | Number shown | Default surface |
|---|---|---|---|
| `stage.<stage_id>` | *<Stage Name>* (dynamic, one per row in `stages`) | count of students in stage, scoped `visibleTo` | Dashboard |
| `meetings_held_today` | Meetings Held Today | `meetings` where `status=held AND held_at::date=today` | Today |
| `leads_captured_today` | Leads Captured Today | `students` where `created_at::date=today` | Today |
| `admissions_closed_today` | Admissions Closed Today | `students` where `stage=Admission Confirmed AND updated_at::date=today` | Today |

Stage cards are generated at request time from `Stage::all()`. 10 stages today; new stages added via `/admin/pipeline-config` become available cards automatically. Secondary metric on stage cards shows the ₹ total at that stage (matching current `PipelineSummaryWidget` output).

### List cards (rows, no drill-down — "View all" link only)

| Card id | Source widget | Default surface |
|---|---|---|
| `today_meetings` | `TodayMeetingsWidget` (unchanged internally) | Today |
| `today_payments` | `TodayPaymentsWidget` (unchanged internally) | Today |
| `stuck_leads` | `StuckLeadsWidget` (unchanged internally) | Dashboard |
| `re_entry_candidates` | `ReEntryCandidatesWidget` (unchanged internally) | Dashboard |
| `seat_fee_pending` | `SeatFeePendingWidget` (unchanged internally) | Dashboard |

Each list card is a thin wrapper: the existing widget Blade partial renders inside the common card frame. No behavioral changes to the widgets themselves.

### Totals

- 10 dynamic stage stat cards (varies with pipeline config)
- 3 Today metric stat cards
- 5 list cards
- **~18 items in the Customize modal per surface.**

### Default layouts (day 0, before any user opens Customize)

**Dashboard default (in order):**
1. `stuck_leads`
2. `re_entry_candidates`
3. `seat_fee_pending`
4. All 10 stage stat cards, in pipeline sort order (matches current `PipelineSummaryWidget` ordering)

**Today default (in order):**
1. `today_meetings`
2. `today_payments`
3. `meetings_held_today`
4. `leads_captured_today`
5. `admissions_closed_today`

## Data model & storage

### Migration

```
2026_04_24_000000_add_dashboard_prefs_to_users.php
  Schema::table('users', fn ($t) => $t->json('dashboard_prefs')->nullable());
```

Additive, reversible, no backfill. Every user starts `null` = defaults.

### JSON shape

```json
{
  "dashboard": {
    "enabled": ["stuck_leads", "re_entry_candidates", "seat_fee_pending",
                "stage.1", "stage.2", "stage.3", ...]
  },
  "today": {
    "enabled": ["today_meetings", "today_payments",
                "meetings_held_today", "leads_captured_today", "admissions_closed_today"]
  }
}
```

**Properties:**
- `enabled` is an ordered array of card ids. Array order = display order.
- Cards absent from the array = removed (not rendered).
- Unknown ids (e.g., deleted stage) are silently ignored on render.
- Cards a user has never seen (new default card) are **auto-appended** to the end of their array on next page load (see `UserPrefsResolver`).
- `null` `dashboard_prefs` = use defaults. Zero cost for unmodified users.

### Card id scheme

| Pattern | Meaning | Example |
|---|---|---|
| `<snake_case>` | Static card, fixed id | `today_meetings`, `meetings_held_today` |
| `stage.<stage_id>` | Stage stat card keyed by `stages.id` | `stage.7` |

Stage FK by integer id (not slug/name) so rename in `/admin/pipeline-config` does not break prefs.

## Architecture & components

### New PHP structure

```
app/Dashboard/
├── Card.php                          ← interface
├── CardRegistry.php                  ← static + dynamic card discovery
├── DrillDownPayload.php              ← DTO: filter + column schema
├── Resolver/
│   └── UserPrefsResolver.php         ← merges user prefs with available cards
├── Cards/
│   ├── Stat/
│   │   ├── StageStatCard.php         ← parameterised by Stage
│   │   ├── MeetingsHeldTodayCard.php
│   │   ├── LeadsCapturedTodayCard.php
│   │   └── AdmissionsClosedTodayCard.php
│   └── ListCards/
│       ├── TodayMeetingsCard.php
│       ├── TodayPaymentsCard.php
│       ├── StuckLeadsCard.php
│       ├── ReEntryCandidatesCard.php
│       └── SeatFeePendingCard.php
```

### Livewire components

| File | Purpose |
|---|---|
| `app/Livewire/CustomizeCardsModal.php` | Reorder + toggle + save; accepts `surface` param |
| `app/Livewire/StudentSlideOver.php` | Filtered student list + CSV; accepts `cardId` param |

### Filament pages

| File | Purpose |
|---|---|
| `app/Filament/Pages/DashboardPage.php` | New; replaces Filament default Dashboard; renders `surface='dashboard'` |
| `app/Filament/Pages/TodayPage.php` | Existing from SP#1; simplified to render `surface='today'` via resolver |

### `Card` interface

```php
interface Card
{
    public function id(): string;
    public function label(): string;
    public function surface(): string;                       // 'dashboard' | 'today' | 'any'
    public function isDefaultOn(string $surface): bool;
    public function type(): string;                          // 'stat' | 'list'
    public function render(User $viewer): string;            // Blade partial or Livewire mount
    public function drillDown(User $viewer): ?DrillDownPayload;  // null for list cards
}
```

### `CardRegistry::all()` (sketch)

```php
public static function all(): array
{
    $static = [
        new TodayMeetingsCard,
        new TodayPaymentsCard,
        new StuckLeadsCard,
        new ReEntryCandidatesCard,
        new SeatFeePendingCard,
        new MeetingsHeldTodayCard,
        new LeadsCapturedTodayCard,
        new AdmissionsClosedTodayCard,
    ];
    $dynamic = Stage::orderBy('sort_order')
        ->get()
        ->map(fn ($s) => new StageStatCard($s))
        ->all();

    return [...$static, ...$dynamic];
}
```

In-request static cache so `Stage::all()` is called once per request.

### `UserPrefsResolver::resolve()` (sketch)

```php
public function resolve(User $user, string $surface): array
{
    $prefs = $user->dashboard_prefs ?? [];
    $saved = $prefs[$surface]['enabled'] ?? null;

    $available = CardRegistry::all();
    $availableById = collect($available)->keyBy(fn ($c) => $c->id());

    if ($saved === null) {
        return collect($available)
            ->filter(fn ($c) => $c->isDefaultOn($surface))
            ->values()
            ->all();
    }

    $resolved = collect($saved)
        ->map(fn ($id) => $availableById[$id] ?? null)
        ->filter()
        ->values();

    // Auto-append unseen defaults (e.g., newly-created stage).
    $seenIds = $resolved->map(fn ($c) => $c->id())->all();
    foreach ($available as $card) {
        if ($card->isDefaultOn($surface) && !in_array($card->id(), $seenIds, true)) {
            $resolved->push($card);
        }
    }

    return $resolved->all();
}
```

### Existing widgets — disposition

| Current widget | SP#3 disposition |
|---|---|
| `PipelineSummaryWidget` | **Deleted.** Replaced by `StageStatCard × N`. |
| `TodayMeetingsWidget`, `TodayPaymentsWidget`, `StuckLeadsWidget`, `ReEntryCandidatesWidget`, `SeatFeePendingWidget` | **Kept as-is.** A thin `*Card` wrapper renders each widget's existing Blade partial inside the card frame. |
| `InstallAppWidget` | **Kept as-is.** Outside the card system — pinned header element, auto-hides in standalone mode. |

### Untouched code

- `StudentResource`, `PaymentResource`, `MeetingsRelationManager` — no changes.
- `Student::scopeVisibleTo` and `StudentPolicy` — no changes (every card uses them).
- Kanban, pipeline-config, PaymentReport, LeadsReport — no changes.
- n8n / Slack ingestion paths, Finance resources — no changes.
- `routes/api.php` — no changes.

### Package additions

None. SortableJS is already vendored (SP#1). Filament 3 slide-over modal is stock. CSV streaming uses Laravel's built-in `StreamedResponse`.

## UX

### Card frame (shared)

```
┌──────────────────────────────────────────────┐
│  <Title>                           ···   ✕   │
├──────────────────────────────────────────────┤
│                                              │
│          [card body — stat or list]          │
│                                              │
└──────────────────────────────────────────────┘
```

- `···` is a per-card menu (v1: only "Remove").
- `✕` is quick-remove with 8s undo toast.
- Grid layout: 3 columns desktop, 2 tablet, 1 mobile. Uniform widths.

### Stat card body

```
┌──────────────────────────────────────────────┐
│  Meeting Scheduled                   ···  ✕  │
├──────────────────────────────────────────────┤
│   47                                         │
│   ₹ 1,45,000                                 │
│                               View all →     │
└──────────────────────────────────────────────┘
```

- Primary number is clickable → opens `StudentSlideOver` for that card id.
- Secondary metric = ₹ total (stage cards); blank for Today-metric cards.
- "View all →" deep-links to filtered `StudentResource`.

### List card body

Existing widget's Blade partial. No behavioral changes. Card header may show a "View all →" link per card, declared on the `*Card` class (`viewAllHref()`), with the following v1 mapping:

| Card | View-all target |
|---|---|
| `stuck_leads` | `StudentResource` list filtered to the same stuck criteria as the widget |
| `re_entry_candidates` | `StudentResource` list filtered to re-entry criteria |
| `seat_fee_pending` | `StudentResource` list filtered to seat-fee-pending criteria |
| `today_meetings` | No link (user is already on Today, or lands on Today from Dashboard) |
| `today_payments` | `PaymentReport` → "Today Received" tab |

### Drill-down slide-over (`StudentSlideOver`)

Right-side slide-over, ~600px desktop, full-width mobile.

```
┌─────────────────────────────────────────┐
│  Meeting Scheduled — 47 students    ✕   │
├─────────────────────────────────────────┤
│  [search]                   [↓ CSV]     │
├─────────────────────────────────────────┤
│  Name           Phone    Owner   Course │
│  Priya Sharma   9876…    Sonam   BBA    │
│  Rahul Verma    9823…    Nikhil  BCom   │
│  ... (paginated, 20/page)               │
├─────────────────────────────────────────┤
│  Open in full table →                   │
└─────────────────────────────────────────┘
```

**Per-card column schema**:

| Card | Slide-over columns |
|---|---|
| `stage.<id>` | Name · Phone · Owner · Course · Days in stage |
| `meetings_held_today` | Time held · Student · Course · Owner |
| `leads_captured_today` | Time · Student · Source · Owner |
| `admissions_closed_today` | Time · Student · Course · Final college · Owner |

Rows clickable → student detail page. CSV streams the visible rows (respects current search filter). "Open in full table" routes to `StudentResource` with the same scope filter applied. Slide-over queries are always wrapped in `whereHas('student', fn ($q) => $q->visibleTo($user))`.

### Customize modal (`CustomizeCardsModal`)

Triggered by a "Customize" button in the page header, top-right, on both Dashboard and Today. One modal instance per surface.

```
┌─ Customize Today ────────────────────────────┐
│                                              │
│  Drag to reorder. Uncheck to hide.           │
│                                              │
│  ⠿  ☑  Today Meetings                        │
│  ⠿  ☑  Today Payments                        │
│  ⠿  ☑  Meetings Held Today                   │
│  ⠿  ☑  Leads Captured Today                  │
│  ⠿  ☑  Admissions Closed Today               │
│  ⠿  ☐  Stuck Leads                           │
│  ⠿  ☐  Lead Captured                         │
│  ⠿  ☐  Meeting Scheduled                     │
│  ...                                         │
│                                              │
│  [Reset to defaults]     [Cancel]  [Save]    │
└──────────────────────────────────────────────┘
```

- SortableJS drag-reorder (reused from `/admin/pipeline-config`).
- Checkbox toggles inclusion.
- Every available card listed; checked = enabled, unchecked = hidden.
- "Reset to defaults" nulls `dashboard_prefs[$surface]` (user returns to curated default next load).
- Save writes the ordered array of checked card ids.

### Empty state

If a user unchecks every card on a surface:

```
No cards enabled.
[ Customize → ]
```

### Undo on quick-remove

`✕` on the card header triggers bottom toast: "Removed <Card>. Undo (8s)". Click undo → re-adds at original position; otherwise the change persists.

## New-stage / deleted-stage behavior

**Admin creates stage "Interview Stage" (id=11):**
1. `CardRegistry::all()` picks up the new row on next request.
2. Customize modal now lists `stage.11` as available.
3. Users with `null` prefs see `stage.11` appended to their Dashboard default layout.
4. Users with saved prefs get `stage.11` auto-appended by the resolver (new default, not seen before).
5. Users who actively don't want it open Customize → uncheck or drag elsewhere.

**Admin deletes a stage (id=7):**
1. `stage.7` no longer in `CardRegistry::all()`.
2. Users with `stage.7` in saved prefs: resolver silently drops the unknown id on render. No error.
3. Customize modal no longer shows `stage.7`.
4. No cleanup migration needed; stale ids in JSON are harmless.

**Admin renames a stage:**
1. Card id stays `stage.<id>`; only `label()` updates.
2. User prefs unaffected. New label appears everywhere next request.

**Race: stage created while user is viewing dashboard:**
1. User needs to refresh to see the new card. No live push.

## Testing

Target: ~25 new tests. Full existing suite (~400 tests post-SP#1) stays green.

### Unit / service

- `CardRegistryTest` — static cards always present; stage cards generated from `Stage::all()`; registry reflects stage add/rename/delete.
- `UserPrefsResolverTest` — null prefs → defaults; saved prefs honor order; unknown ids dropped; new default cards auto-appended; empty array → empty surface.

### Livewire

- `CustomizeCardsModalTest` — open, toggle, reorder, save writes expected JSON shape; Reset nulls the surface key.
- `StudentSlideOverTest` — correct row set per stat card id; CSV download returns 200 + expected header + rows; role scoping via `visibleTo` respected; "Open in full table" href matches expected filter.

### Feature / page

- `DashboardPageTest` — user-specific card order rendered; scoping preserved per role (admin, head, counsellor, freelancer).
- `TodayPageTest` — same for Today surface. Regression lock: null prefs produce the exact layout of post-SP#1 Today page (2 widgets) plus the 3 new Today metric cards.

### Stat card individual tests

- `MeetingsHeldTodayCardTest` — count matches `Meeting::where('status','held')->whereDate('held_at', today())` for viewer's scope.
- `LeadsCapturedTodayCardTest` — count matches students created today in scope.
- `AdmissionsClosedTodayCardTest` — count matches students in `Admission Confirmed` with `updated_at` today.
- `StageStatCardTest` — parameterised across all current stages; count + ₹ total both correct per scope.

### Migration

- `AddDashboardPrefsToUsersTest` — up/down; column is nullable JSON; existing users unaffected.

### Security regression

- Slide-over and drill-down obey `Student::scopeVisibleTo` for every role. Dedicated test: counsellor in Team A cannot drill down into a Meeting-Scheduled stat and see Team B's students.

## Rollout

1. Branch: `feature/customizable-cards-dashboard` off `main`.
2. Implementation via TDD task sequence produced by the writing-plans skill.
3. Local suite green + **local smoke walkthrough by Sumit required before merge** (per locked rule 9):
    - `/admin` renders as Sumit with day-0 defaults: 3 list cards + 10 stage stat cards.
    - `/admin/today` renders with 5 default cards (2 list + 3 new Today metrics).
    - Click a stage card number → slide-over opens with filtered students + working CSV + working "Open in full table".
    - Open Customize modal on Today; uncheck `today_payments`, drag `meetings_held_today` to position 1, Save. Page reflects.
    - Reset to defaults → day-0 layout restored.
    - Log in as a counsellor test account → scoped counts, scoped slide-over drill-down.
4. Merge to main after local sign-off + full suite green.
5. `git push origin main`.
6. Deploy to prod:
   ```bash
   ssh -i ~/.ssh/davyas-active ipuc@ipu.co.in \
     "cd /home/ipuc/davya-crm && git pull --ff-only origin main \
      && /opt/alt/php84/usr/bin/php artisan migrate --force \
      && /opt/alt/php84/usr/bin/php artisan optimize:clear \
      && git log -1 --oneline"
   ```
7. Prod smoke (minimal; local already verified):
    - `/admin` renders for Sumit with day-0 defaults.
    - One stage drill-down opens a slide-over with correct students + CSV.
    - Customize modal opens, saves, reload reflects.
    - Log in as any counsellor account → scoped counts.

## Rollback

Pure additive migration (one nullable column on `users`). Revert:

```bash
git revert <merge-sha>
git push origin main
ssh -i ~/.ssh/davyas-active ipuc@ipu.co.in \
  "cd /home/ipuc/davya-crm && git pull && \
   /opt/alt/php84/usr/bin/php artisan migrate:rollback --step=1 --force && \
   /opt/alt/php84/usr/bin/php artisan optimize:clear"
```

Rollback drops `users.dashboard_prefs`. No user data lost — any customisation is erased and every user returns to day-0 defaults on the next deploy. `PipelineSummaryWidget` is restored by the revert.

## Known risks

1. **Filament default Dashboard replacement.** Custom `DashboardPage` must register correctly in `AdminPanelProvider`. Confirm no plugin expects `Filament\Pages\Dashboard` specifically — grep shows zero external references today, re-verify on branch.
2. **SortableJS reuse.** SP#1's pipeline-config SortableJS is loaded per-page via `@script`. For SP#3 either extract an Alpine directive or include per-page. Not hard; flag in the plan.
3. **Filament CSS on card chrome.** Per the project gotchas (`CLAUDE.md` / memory), Tailwind utility classes don't always reach Filament admin pages. Card-frame styles must be validated in Filament dark mode during local smoke. Fall back to inline `style="..."` for dark variants if utility classes render transparent.
4. **`admissions_closed_today` precision.** v1 uses `students.updated_at WHERE stage='Admission Confirmed' AND updated_at::date=today`. Any unrelated update to the row today (e.g., owner reassignment) counts as a closure. Acceptable for v1; replace with an ActivityLog query later if false positives appear.
5. **Stage card explosion if admin creates 30+ stages.** Customize modal would balloon. Not a current risk (10 stages today); if it becomes one, add search/filter inside the modal.

## Acceptance criteria

SP#3 is done when:

- Full existing suite + ~25 new tests are green.
- Local smoke walkthrough signed off by Sumit (every step in rollout #3).
- Prod HEAD sits at the SP#3 merge commit.
- Every user with `dashboard_prefs IS NULL` sees a layout equivalent to the current admin layout (via `PipelineSummaryWidget` visual equivalence) plus the 3 new Today metric cards on `/admin/today`.
- Customize modal works end-to-end on both surfaces for all 4 role tiers.
- `StudentSlideOver` returns correctly-scoped students + CSV for every stat card.
- Creating a new stage via `/admin/pipeline-config` makes a new `stage.<id>` card appear in every user's Customize modal on next page load; users with null prefs see it automatically in the Dashboard list.
- Rolling back via `git revert + migrate:rollback` leaves the app in the exact pre-SP#3 state with no broken references.
