# Davyas CRM — Visual Refresh Design

**Date:** 2026-04-24
**Scope:** Admin UI presentation layer only. No schema changes, no new features, no stack changes.
**Stack:** Laravel 11 + Filament 3 + Livewire (unchanged).
**Reference:** five Bigin screenshots (2026-04-24 9:34–9:36 AM) plus existing Davyas CRM state.

## 1. Goal

Give the admin panel a modern, stylish, consistent look while making it easy to jump from any screen to any part. The refresh is CSS-heavy with two targeted Livewire additions (top-bar command palette, student slide-over). Nothing about the data model, stage machine, or business logic changes.

## 2. Non-goals (explicitly out of scope)

These were discussed and rejected:

- Stack swap to React / shadcn / TanStack Query.
- Sub-pipelines (nested pipelines inside Team Pipelines). Single-pipeline model stays.
- Saved Views persistence beyond the existing URL-persisted filters (`#[Url]`).
- "Unused Fields" template library on the schema editor. Current Phase A editor covers field creation already.
- Formula field type on custom fields.
- Sheet view, realtime push (`ws:students:updated`), stage automation rules.
- Schema migrations of any kind. Existing `stages`, `students`, `student_fields` tables stay as they are.

## 3. Design tokens

A single `resources/css/tokens.css` file declares the variables below and is loaded into the admin panel via `AdminPanelProvider::HEAD_END` render hook. Tokens are the only colour / spacing / radius source of truth for custom blade templates going forward; Filament's internal components keep their built-in styles.

```css
:root {
  /* Brand (emerald — matches existing logo/PWA) */
  --brand-50:  #ECFDF5;
  --brand-100: #D1FAE5;
  --brand-500: #10B981;
  --brand-600: #059669;   /* primary CTA background */
  --brand-700: #047857;   /* primary CTA hover */

  /* Semantic */
  --success: #10B981;
  --warning: #F59E0B;
  --danger:  #EF4444;
  --info:    #3B82F6;

  /* Neutrals */
  --bg:       #F7F8FA;
  --surface:  #FFFFFF;
  --border:   #E5E7EB;
  --border-muted: #F3F4F6;
  --text:     #111827;
  --text-sub: #6B7280;
  --text-muted: #9CA3AF;

  /* Stage accents (3 px column top-border) */
  --stage-new:     #3B82F6;  /* blue */
  --stage-active:  #F59E0B;  /* amber */
  --stage-meeting: #8B5CF6;  /* violet */
  --stage-advance: #10B981;  /* emerald */
  --stage-round:   #06B6D4;  /* cyan */
  --stage-offline: #6366F1;  /* indigo */
  --stage-won:     #059669;
  --stage-lost:    #EF4444;

  /* Student response (card left status dot) */
  --resp-ready:    #10B981;
  --resp-hesitant: #F59E0B;
  --resp-cold:     #EF4444;

  /* Radii */
  --r-sm: 4px;
  --r-md: 6px;
  --r-lg: 8px;
  --r-xl: 10px;
  --r-pill: 9999px;

  /* Spacing (4 pt) */
  --s-1: 4px;  --s-2: 8px;  --s-3: 12px;
  --s-4: 16px; --s-5: 20px; --s-6: 24px; --s-8: 32px;

  /* Shadow */
  --elev-1: 0 1px 2px rgba(0,0,0,.04);
  --elev-2: 0 4px 12px rgba(0,0,0,.08);
  --elev-drawer: -12px 0 32px rgba(0,0,0,.12);

  /* Type scale */
  --fs-10: 10px; --fs-11: 11px; --fs-12: 12px; --fs-13: 13px;
  --fs-14: 14px; --fs-16: 16px; --fs-18: 18px; --fs-24: 24px;
}
```

**Typography:** system font stack (`-apple-system, 'Inter', 'Segoe UI', sans-serif`). No webfont added — Inter is a fallback name only, the stack degrades naturally. Line-height 1.4 for body, 1.2 for headings.

**Known Filament CSS gotcha** (already documented in `CLAUDE.md`): Tailwind utility colours may not reach Filament admin pages because Filament compiles its own CSS bundle. Every colour used by custom blade templates must either (a) come from our token `var(--...)`, or (b) fall back to inline `style="..."` as we do today. Design tokens are resolved via CSS variables so they work in both contexts.

## 4. Navigation shell

**Layout:** single top bar (56 px), no left sidebar, no right rail.

Top bar contents (left to right):

1. Davyas brand mark (emerald wordmark, uses existing `brandLogo`).
2. Primary nav tabs: **Pipeline · Students · Today · Reports · Finance**. Active tab gets `--brand-50` background and `--brand-700` text. Tabs are `<a>` tags routing to existing Filament pages.
3. Command search — `<button>` styled as a pill, placeholder "Jump to anything — student, stage, setting…", with a `⌘K` kbd hint on the right. Clicking it or pressing `⌘K` / `Ctrl+K` opens the command palette (section 5).
4. Primary CTA: green **+ New Student** button. Opens `/admin/students/create` directly.
5. Notifications bell (keeps Filament's existing dropdown).
6. Settings gear — opens Settings landing (section 10).
7. Avatar pill — links to user profile and logout.

**Sticky sub-toolbar (48 px)** appears under the top bar on list / kanban pages. Contents:

- Filter pills (re-uses existing URL-persisted `#[Url]` filters). Pills show the active value; click to edit, `+ Filter` to add.
- Sort selector ("Sort: Created ↓").
- View switcher on the right — a segmented control between **Kanban** and **List** (matches the existing views).

**Mobile / narrow:** primary nav tabs collapse into a hamburger menu at `<900 px`. Command search becomes an icon button.

### 4.1 Command palette (net-new)

A Livewire component, `App\Livewire\CommandPalette`, rendered once in `AdminPanelProvider` so it mounts on every page. Triggered by `⌘K` (Alpine.js keybind on the root layout) or the top-bar search pill.

Palette is a centred 600 px modal with a search input and result list. Results are grouped:

- **Students** — fuzzy over `students.name` + `students.phone`, limit 8. Clicking opens the student slide-over on top of whatever the user was doing (see section 6).
- **Pages** — static list: Pipeline, Students, Today, Dashboard, Reports → Activity Audit / Duplicate Review / Lead Import / Leads / Payments, Finance → Expenses / Investments, Settings → Stages / Fields / Users.
- **Actions** — "Create student", "Import leads", "Jump to stage: …".

Keyboard: `↑ ↓` to move, `↵` to select, `esc` to close. Search is debounced 150 ms; student lookup uses the same `Student::scopeVisibleTo($user)` scope as the resource so role-gating is respected.

## 5. Kanban board (`/admin/kanban`)

### 5.1 Column

- **Width:** 260 px (was ~440). Gap: 10 px. Canvas scrolls horizontally; top bar and sub-toolbar stay pinned.
- **Top accent:** 3 px top border coloured by stage type — maps `stages.color` to the `--stage-*` tokens (defaulting to `--stage-active` amber if unmapped). Closed stages use `--stage-won` / `--stage-lost`.
- **Header (two-line):**
  - Line 1 — stage name (bold 12 px) + count pill on the right. Pill uses a stage-tinted background (e.g. amber stages → `#FEF3C7 / #92400E`).
  - Line 2 — aggregate summary: `₹X received · ₹Y pending` where `X` = sum of `payments.amount` for students in this stage and `Y` = sum of `deal_amount - received` (pending). Computed server-side in `KanbanBoard::getBoard()` with one grouped query per view.
- **Footer (visible on hover):** `+ Students` ghost button for that stage + a collapse chevron.
- **Empty state:** centred dashed box "This stage is empty" (12 px muted text).

### 5.2 Dense card

Single-row card, ~40 px tall:

```
│● Name (bold)              BA · R2     ₹1.25L   ⓢⓓ
```

Elements (left to right):

- **Status strip:** 3 px left bar, rounded right edge. Colour = `--resp-ready / --resp-hesitant / --resp-cold` based on `student_response`; no value → `--border` grey.
- **Name:** 12 px `font-weight: 600`, colour `--text`.
- **Chips row (inline):** course abbreviation (e.g. "BA", "BCA") + round ("R1", "R2"). Rendered as plain muted 10 px text separated by `·`, no background — this is density-first, not chip-heavy.
- **Amount:** right-aligned, 11 px `font-weight: 700`, `--brand-700` emerald. Uses Indian grouping (`₹1.25L`, `₹80K`). If `received = 0`, shows `₹0` in `--text-muted`.
- **Owner avatar:** 18 px circle with two-letter initials (e.g. `SD`, `NK`, `SN`). Colour derived from a hash of the user id → stable per user.

**Hover state:** background lifts to `--brand-50`, cursor pointer.
**Selected state** (after click, before drawer fully open): outline 2 px `--brand-600`, background `--brand-50`.
**Drag state:** existing SortableJS behaviour preserved; drag-ghost uses `--elev-2`.

Nothing else on the card — no progress bar, no last-touch, no call/message icons. Those live in the slide-over.

### 5.3 Kanban toolbar interactions

The sub-toolbar on the kanban page exposes:

- Course filter pill (existing `#[Url] course` filter).
- Owner filter pill (existing `#[Url] owner` filter).
- Round filter pill.
- Source filter pill.
- `has-pending` boolean pill.
- `+ Filter` opens a menu of any remaining filterable field.

The View switcher toggles between `/admin/kanban` (Kanban) and `/admin/students` (List, native Filament table).

## 6. Student slide-over (net-new)

**Trigger:** clicking anywhere on a kanban card (except the drag handle area) or selecting a student in the command palette.
**Component:** `App\Livewire\StudentPeekDrawer`, mounted once at the layout level.
**Width:** 560 px on ≥1280 px viewports, `min(100vw - 40 px, 560 px)` on smaller. Slides in from right with a 200 ms ease-out transition; background gets a `rgba(17,24,39,.25)` dim overlay. `esc` or clicking the dim closes.

### 6.1 Header

- 18 px bold name + 11 px muted line `phone · course · round`.
- Owner pill (top right) — same emerald 50/700 styling as the top-bar active tab, showing owner's initials avatar + full name.
- Close button (✕) in the upper-right corner.

### 6.2 Stage stepper

Horizontal bar of equal-width segments (one per stage in the active pipeline). Filled emerald (`--success`) for stages the student has passed, amber (`--warning`) for the current stage, grey (`--border`) for pending. Below the segments: stage-name labels in 10 px muted text (only the first, current, and last labels render on narrow widths to avoid crowding). A "4 / 5" indicator sits to the right of the bar.

Clicking a segment triggers the existing stage transition flow — reuses the current validator + fix-up modal unchanged; the drawer simply dispatches to the same Livewire action the kanban drag uses.

### 6.3 Tabs

`Overview · Payments · Notes · Meetings · Activity`. Active tab has a 2 px emerald underline. Each tab's content is rendered by a small sub-component so the drawer itself stays thin.

- **Overview:** two section cards — **Deal** (deal_amount / received / pending + progress bar) and **Touch** (last note, last meeting, next action if available).
- **Payments:** list of `payments` for this student; each row shows direction (received/refund), amount, mode, date, and a permalink to the Drive proof.
- **Notes:** chronological list; `+ Note` inline textarea at the bottom.
- **Meetings:** list from the `meetings` table; `+ Schedule` button reuses the existing Today modal.
- **Activity:** `spatie/activitylog` entries for this subject.

Tabs fetch lazily — only the currently-visible tab queries data.

### 6.4 Footer

Sticky at the bottom of the drawer. Left: text-link `Open full page ↗` → `/admin/students/:id/edit` (existing Filament edit page, unchanged). Right: three buttons — `+ Note` (inline), `+ Payment` (opens existing modal), `Move stage →` (opens stage picker).

### 6.5 Swap-in behaviour

Clicking another kanban card while the drawer is open re-hydrates the drawer in place (no close/open animation), so the user can triage a column without pogo-sticking.

## 7. Create / Edit Student form

Used at `/admin/students/create`, `/admin/students/:id/edit`, and by the `+ New Student` button in the top bar.

### 7.1 Layout

- Owner pill top-right of the form header (existing Filament owner control, restyled to match section 4's pill).
- Each form section becomes a **section card**: 1 px `--border` border, `--r-lg` radius, `#FAFBFC` background, 14 px inner padding, 12 px gap between cards.
- Section title is uppercase 11 px `--text-sub`, letter-spacing 0.5 px, above the fields.
- Field labels remain top-stacked (Filament default). Label text is 11 px `--text` medium-weight. No asterisk on required fields — see 7.2.
- Inputs keep Filament's native markup so validation, autofill, and Livewire bindings continue to work. CSS only restyles padding, radius, border, and focus ring (emerald).

### 7.2 Required-field indicator

A 3 px **red left bar** on required inputs instead of an asterisk. Implemented as:

```css
.fi-fo-field-wrp:has(> *[required]) .fi-input-wrp,
.fi-fo-field-wrp:has(.fi-fo-field-wrp-label-wrp sup) .fi-input-wrp {
  position: relative;
  padding-left: 12px;
}
.fi-fo-field-wrp:has(> *[required]) .fi-input-wrp::before,
.fi-fo-field-wrp:has(.fi-fo-field-wrp-label-wrp sup) .fi-input-wrp::before {
  content: '';
  position: absolute;
  left: 0; top: 4px; bottom: 4px;
  width: 3px;
  background: var(--danger);
  border-radius: 0 3px 3px 0;
}
```

`:has()` is used to avoid touching Filament's blade files — one CSS file styles all required fields across every form (student create/edit, login, 2FA setup, payment, finance, user management). If `:has()` support becomes a problem on older browsers (Safari <15.4), fall back to adding a `data-required` attribute from a small Livewire trait on the student form only; tracked as a minor follow-up, not a blocker.

### 7.3 Sections rendered

The existing `StudentResource::form()` sections already map to `StudentField.section` groupings (Identity · Source & Stage · Academic · Deal · Counselling · History · Closure). Each becomes one section card. No new sections.

## 8. Schema editor (`/admin/student-fields`)

Existing Phase A page. Visual refresh only — no behaviour changes.

- Page wrapper inherits the new tokens.
- Each field row gets a **3 px left accent** — green (`--brand-500`) for custom fields (`is_built_in = false`), grey (`--border`) for built-ins. This mirrors Bigin's "green dot = custom" language but as a bar for visual consistency with forms.
- Required built-in fields (phone) show the red left bar variant (section 7.2) to hint to the admin that required-ness is expressed the same way across the app.
- Section headers adopt the uppercase-muted style from section 7.1.
- `+ Add Section` and `+ Add Field` buttons restyled as emerald ghost buttons matching the top-bar CTA. "Archive" / "Restore" / "Hard delete" text-links adopt `--text-sub` with emerald hover.
- No layout changes, no second rail, no module switcher.

## 9. Pipeline / Stages config (`/admin/pipeline-config`)

Existing SP#1 page. Visual refresh only.

- Stages list items adopt the card-row look — 1 px `--border`, `--r-md` radius, 10 px padding, drag handle left, inline-edit name centre, pencil/trash icons right on hover.
- Closed-Won / Closed-Lost stages get a 👍 / 👎 badge before the name (inline SVG, no new dependency). Won uses `--brand-600` text, Lost uses `--danger`.
- Rules tab rows adopt the same card-row look.
- Tabs (Stages / Rules) adopt the underline-tab pattern from the slide-over.

## 10. Settings landing (new route `/admin/settings`)

Currently settings are scattered across top-level routes (`/admin/student-fields`, `/admin/pipeline-config`, `/admin/duplicate-flags`). This stays true — no consolidation of routes. But we add a **settings landing page** at `/admin/settings` as a grid of clickable tiles, linked from the top-bar gear icon:

- **Fields** → `/admin/student-fields`
- **Stages** → `/admin/pipeline-config`
- **Duplicate review** → `/admin/duplicate-flags`
- **Users & roles** → existing Filament user resource
- **Lead import** → `/admin/lead-import`
- **Data admin** (export / activity log) → `/admin/activity-audit`

Each tile is a section card with an icon, title, and one-line description. Reuses the section-card styling from 7.1 — zero new primitives.

## 11. Today page (`/admin/today`)

Existing SP#1 Today page. Visual refresh only:

- Meetings strip card adopts `--elev-1` shadow and `--r-lg` radius.
- `+ Schedule` modal button styled as the emerald primary CTA.
- Payments table restyled with the card-row look; rows gain subtle hover state (`--brand-50`).

## 12. Dashboard (`/admin`)

Existing SP#3 customizable cards dashboard. Visual refresh only:

- Each card adopts the section-card visual language (white surface, 1 px border, `--r-lg`, `--elev-1`).
- Card titles use 13 px bold + uppercase mini-label for subtitle consistency with forms.
- The "Customize →" affordance moves from inline link to a small gear icon in the top-right of each card; the Customize modal itself keeps its current Livewire implementation, restyled via tokens.
- Stat cards (MeetingsHeldToday / LeadsCapturedToday / etc.) get the big-number-small-label treatment: 24 px bold number, 11 px `--text-sub` label under it, stage-accent colour strip on the left edge that matches the card's subject.

## 13. Implementation surface (file map)

**New files:**

- `resources/css/tokens.css` — all tokens + required-field bar + section-card class + status-strip utility. Registered via `AdminPanelProvider::HEAD_END`.
- `app/Livewire/TopBar.php` + `resources/views/livewire/top-bar.blade.php` — the shell top bar. Mounted in the Filament panel layout via render hook.
- `app/Livewire/CommandPalette.php` + `resources/views/livewire/command-palette.blade.php` — `⌘K` palette.
- `app/Livewire/StudentPeekDrawer.php` + `resources/views/livewire/student-peek-drawer.blade.php` — slide-over with 5 sub-views.
- `app/Livewire/Drawer/{OverviewTab,PaymentsTab,NotesTab,MeetingsTab,ActivityTab}.php` — one per tab. Kept thin; lazy-mounted.
- `app/Filament/Pages/SettingsLanding.php` + `resources/views/filament/pages/settings-landing.blade.php` — the new landing tile grid.

**Files touched (visual only):**

- `resources/views/filament/pages/kanban-board.blade.php` — column + card redesign.
- `resources/views/filament/pages/pipeline-config.blade.php` — card-row restyle + Won/Lost badges.
- `resources/views/filament/pages/student-fields-config.blade.php` — card-row restyle + left accent.
- `resources/views/filament/pages/today-page.blade.php` — meetings strip + payments table restyle.
- `resources/views/filament/pages/dashboard.blade.php` — card wrapper restyle + gear affordance.
- `resources/views/filament/pages/duplicate-flags-review.blade.php` — list restyle.
- `app/Providers/Filament/AdminPanelProvider.php` — register `HEAD_END` hook for `tokens.css`, mount TopBar + StudentPeekDrawer + CommandPalette Livewire components.
- `app/Filament/Resources/StudentResource.php` — add a CSS wrapper class per section so sections pick up the section-card styling without changing the Filament schema.

**Files not touched:**

- Any migration, any model, any service, any Spatie role/activitylog config, any test file (beyond adding a few Livewire component tests).

## 14. Risk, rollback, success criteria

### 14.1 Risks

1. **Filament CSS spec fight.** The `:has()` selector for required-field bar could be defeated by a Filament upgrade that renames `.fi-fo-field-wrp`. Mitigation: test on the current Filament 3.x minor version, add a visual regression snapshot on the student create form.
2. **SortableJS global re-include.** Pass 3 kanban column header changes may need SortableJS to re-init. Mitigation: reuse the existing global `defer`'d include set up in SP#3 — no new include added.
3. **Command palette performance.** If `students.name` lookup isn't indexed for `LIKE '%X%'`, fuzzy search could stall at 500+ rows. Mitigation: debounce 150 ms; limit result set to 8; verify EXPLAIN plan; index if needed (*index is a schema change — only if needed*, and simple enough to not violate the no-schema-change rule).
4. **Mobile sub-toolbar overflow.** The filter pill row can overflow on narrow viewports. Mitigation: horizontal scroll with fade-edge, no wrap.
5. **Dense card legibility.** At 260 px column width, long names (>18 chars) truncate. Mitigation: `text-overflow: ellipsis` + native `title` tooltip.

### 14.2 Rollback

Feature-flag the new look behind `config('davyas.visual_v2')` read into `AdminPanelProvider`. When `false`, the `HEAD_END` hook skips loading `tokens.css`, TopBar/CommandPalette/StudentPeekDrawer skip mounting, and kanban / form blade templates render their current versions. Git tag `pre-visual-refresh-20260424` before rollout.

### 14.3 Success criteria

- Kanban loads in ≤1.5 s on 515-student dataset (current baseline).
- Zero regressions in the 500+ existing test suite.
- The full five screens (pipeline, student form, slide-over, schema editor, stages config) all share the same design tokens — no inline hex in blade templates except the documented Filament CSS workaround cases.
- ⌘K opens the palette and navigates to any of the top-level pages within ≤2 keystrokes after search-focus.
- Required fields on every form show the red left bar; custom fields on the schema editor show the green left bar.

## 15. What happens after this spec

One implementation plan, built in four phases so each phase ships independently:

1. **Tokens + CSS primitives** — `tokens.css`, required-bar, section-card, status-strip. Ship first; visible in small ways across every page.
2. **Kanban column + dense card redesign** — blade + CSS only.
3. **Top bar + command palette** — new Livewire components.
4. **Student slide-over** — new Livewire component with five tabs.

Settings landing, pipeline-config restyle, today restyle, dashboard restyle, schema editor restyle all land alongside Phase 1 since they're pure CSS.

Each phase is behind the `visual_v2` flag until the whole set is green on local smoke.
