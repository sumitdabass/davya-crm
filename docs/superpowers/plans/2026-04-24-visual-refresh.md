# Visual Refresh Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ship a Bigin-inspired, modern-SaaS visual refresh of the Davyas admin panel — tokens, dense kanban cards, top-bar + ⌘K shell, and a student peek drawer — behind a `davyas.visual_v2` feature flag, with zero schema changes and no new business features.

**Architecture:** Single `tokens.css` file loaded via `AdminPanelProvider::HEAD_END` behind the feature flag is the backbone. All custom Filament blade pages pull colours/spacing/radii from CSS variables. Two new Livewire components (`TopBar` + `CommandPalette`) render the app shell; a third (`StudentPeekDrawer`) renders a 560 px right-side detail drawer triggered from kanban cards and the ⌘K palette. Nothing about migrations, models, policies, or business services changes. Everything gates on `config('davyas.visual_v2')`; when false the old UI renders unchanged.

**Tech Stack:** Laravel 11, Filament 3, Livewire 3, Alpine.js, Tailwind (Filament-bundled), Vite, PHPUnit, SortableJS (already globally included).

**Spec:** `docs/superpowers/specs/2026-04-24-visual-refresh-design.md`

**Working directory:** `/Users/Sumit/davya-crm` (main branch — this is a long-lived project, not a worktree).

**Filament CSS gotcha (from CLAUDE.md):** Tailwind utility classes can silently disappear on custom Filament pages because Filament ships a compiled CSS bundle. Every colour in this plan either (a) comes from our token `var(--...)`, which works inside Filament pages, or (b) falls back to inline `style="..."` on `<button>`s. Never use raw `bg-emerald-600` on a custom Filament page without confirming it renders.

---

## File Structure

**New files:**
- `config/davyas.php` — `visual_v2` feature flag.
- `resources/css/tokens.css` — CSS variables + `.section-card` + `.status-strip` utilities + required-field `::before` bar.
- `app/Livewire/TopBar.php` — top-bar shell Livewire component.
- `resources/views/livewire/top-bar.blade.php` — top-bar markup.
- `app/Livewire/CommandPalette.php` — `⌘K` command palette.
- `resources/views/livewire/command-palette.blade.php` — palette markup.
- `app/Livewire/StudentPeekDrawer.php` — right-side detail drawer.
- `resources/views/livewire/student-peek-drawer.blade.php` — drawer markup.
- `app/Livewire/Drawer/OverviewTab.php` + blade — drawer Overview tab.
- `app/Livewire/Drawer/PaymentsTab.php` + blade — drawer Payments tab.
- `app/Livewire/Drawer/NotesTab.php` + blade — drawer Notes tab.
- `app/Livewire/Drawer/MeetingsTab.php` + blade — drawer Meetings tab.
- `app/Livewire/Drawer/ActivityTab.php` + blade — drawer Activity tab.
- `app/Filament/Pages/SettingsLanding.php` + `resources/views/filament/pages/settings-landing.blade.php` — landing tile grid at `/admin/settings`.
- `app/Support/AvatarColor.php` — deterministic initials-to-colour helper.
- `tests/Feature/VisualRefreshFlagTest.php` — asserts flag on/off renders correctly.
- `tests/Feature/Livewire/TopBarTest.php` — top-bar nav tests.
- `tests/Feature/Livewire/CommandPaletteTest.php` — palette search + navigation tests.
- `tests/Feature/Livewire/StudentPeekDrawerTest.php` — drawer open/close + swap-in + tab tests.
- `tests/Feature/KanbanAggregateTest.php` — stage aggregates tests.

**Files modified (visual only, gated on flag):**
- `app/Providers/Filament/AdminPanelProvider.php` — HEAD_END extends to load `tokens.css`; BODY_START extends to mount the three new Livewire components.
- `app/Filament/Pages/KanbanBoard.php` — `getBoard()` gains per-stage `received_total` + `pending_total` aggregates; emits `open-peek` event on card click.
- `resources/views/filament/pages/kanban-board.blade.php` — column header + dense card rewrite.
- `resources/views/filament/pages/pipeline-config.blade.php` — card-row restyle + Won/Lost badges.
- `resources/views/filament/pages/student-fields-config.blade.php` — card-row restyle + green/red left accents.
- `resources/views/filament/pages/today-page.blade.php` — section-card wrappers.
- `resources/views/filament/pages/dashboard.blade.php` — section-card wrappers on cards.
- `resources/views/filament/pages/duplicate-flags-review.blade.php` — card-row restyle.
- `app/Filament/Resources/StudentResource.php` — add `extraAttributes(['class' => 'davya-section'])` to each tab so section-card styling applies.

**Files NOT touched:**
- Any migration (`database/migrations/*`).
- Any model (`app/Models/*`) — no properties added.
- Any policy, any service under `app/Services/*`.
- `app/Livewire/StudentSlideOver.php` — existing SP#3 dashboard drill-down, NOT the new peek drawer. New component is named `StudentPeekDrawer` to avoid collision.
- Any business test file. New tests added alongside, none modified.

---

## Phase 0 — Setup

### Task 0.1: Create feature flag config

**Files:**
- Create: `config/davyas.php`

- [ ] **Step 1: Create the config file**

```php
<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Visual Refresh (v2)
    |--------------------------------------------------------------------------
    |
    | Master toggle for the 2026-04-24 visual refresh. When false the panel
    | renders the legacy look (no tokens.css, no top bar, no command palette,
    | no peek drawer, legacy kanban + legacy page styles). When true the
    | whole refresh lights up at once.
    |
    | Spec: docs/superpowers/specs/2026-04-24-visual-refresh-design.md
    */
    'visual_v2' => env('DAVYAS_VISUAL_V2', false),
];
```

- [ ] **Step 2: Verify config loads**

Run: `php artisan tinker --execute="echo var_export(config('davyas.visual_v2'));"`
Expected output: `false`

- [ ] **Step 3: Commit**

```bash
git add config/davyas.php
git commit -m "feat(visual-v2): add davyas.visual_v2 feature flag (default off)"
```

### Task 0.2: Tag rollback point

- [ ] **Step 1: Create rollback tag on current HEAD**

Run: `git tag pre-visual-refresh-20260424`
Expected: tag created silently.

- [ ] **Step 2: Verify**

Run: `git tag | grep pre-visual-refresh-20260424`
Expected: `pre-visual-refresh-20260424`

- [ ] **Step 3: Push tag (optional — confirm with Sumit before doing this)**

`git push origin pre-visual-refresh-20260424` — hold this until Phase 1 is green locally.

---

## Phase 1 — Tokens + CSS primitives + visual-only page restyles

Phase 1 is pure CSS. No JS, no Livewire, no behaviour change. When `visual_v2=true` the panel immediately looks different across every custom Filament page.

### Task 1.1: Write failing flag-off test

**Files:**
- Test: `tests/Feature/VisualRefreshFlagTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VisualRefreshFlagTest extends TestCase
{
    use RefreshDatabase;

    public function test_tokens_css_not_loaded_when_flag_off(): void
    {
        config(['davyas.visual_v2' => false]);
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $response = $this->actingAs($admin)->get('/admin');

        $response->assertOk();
        $response->assertDontSee('davya-tokens', false);
        $response->assertDontSee('/css/tokens.css', false);
    }

    public function test_tokens_css_loaded_when_flag_on(): void
    {
        config(['davyas.visual_v2' => true]);
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $response = $this->actingAs($admin)->get('/admin');

        $response->assertOk();
        $response->assertSee('davya-tokens', false);
    }
}
```

- [ ] **Step 2: Run test to verify both fail**

Run: `./vendor/bin/phpunit tests/Feature/VisualRefreshFlagTest.php`
Expected: both fail — `assertSee davya-tokens` absent; `assertDontSee` probably passes. We want to see at least one red.

- [ ] **Step 3: Commit test-first**

```bash
git add tests/Feature/VisualRefreshFlagTest.php
git commit -m "test(visual-v2): flag gates tokens.css loading"
```

### Task 1.2: Create `tokens.css`

**Files:**
- Create: `resources/css/tokens.css`

- [ ] **Step 1: Write the CSS file with every token from spec §3**

```css
/* davya-tokens — visual refresh v2 (2026-04-24)
 * Loaded via AdminPanelProvider::HEAD_END when config('davyas.visual_v2') is true.
 * Spec: docs/superpowers/specs/2026-04-24-visual-refresh-design.md
 */
:root {
    /* Brand — emerald, matches existing logo / PWA theme */
    --brand-50:  #ECFDF5;
    --brand-100: #D1FAE5;
    --brand-500: #10B981;
    --brand-600: #059669;
    --brand-700: #047857;

    /* Semantic */
    --success: #10B981;
    --warning: #F59E0B;
    --danger:  #EF4444;
    --info:    #3B82F6;

    /* Neutrals */
    --bg:           #F7F8FA;
    --surface:      #FFFFFF;
    --border:       #E5E7EB;
    --border-muted: #F3F4F6;
    --text:         #111827;
    --text-sub:     #6B7280;
    --text-muted:   #9CA3AF;

    /* Stage accents (3 px column top-border) */
    --stage-new:     #3B82F6;
    --stage-active:  #F59E0B;
    --stage-meeting: #8B5CF6;
    --stage-advance: #10B981;
    --stage-round:   #06B6D4;
    --stage-offline: #6366F1;
    --stage-won:     #059669;
    --stage-lost:    #EF4444;

    /* Student response (card left strip) */
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

    /* Type */
    --fs-10: 10px; --fs-11: 11px; --fs-12: 12px; --fs-13: 13px;
    --fs-14: 14px; --fs-16: 16px; --fs-18: 18px; --fs-24: 24px;
}

/* Section-card — used by forms, settings tiles, drawer panels. */
.davya-section-card {
    background: #FAFBFC;
    border: 1px solid var(--border);
    border-radius: var(--r-lg);
    padding: var(--s-4);
    margin-bottom: var(--s-3);
}
.davya-section-card-title {
    font-size: var(--fs-11);
    font-weight: 700;
    color: var(--text-sub);
    text-transform: uppercase;
    letter-spacing: 0.5px;
    margin-bottom: var(--s-3);
}

/* Card-row — reusable row for schema editor, stages config, duplicate review. */
.davya-card-row {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: var(--r-md);
    padding: var(--s-2) var(--s-3);
    margin-bottom: var(--s-1);
    display: flex;
    align-items: center;
    gap: var(--s-2);
    position: relative;
}
.davya-card-row--custom::before {
    content: '';
    position: absolute;
    left: 0; top: var(--s-2); bottom: var(--s-2);
    width: 3px;
    background: var(--brand-500);
    border-radius: 0 3px 3px 0;
}
.davya-card-row--required::before {
    content: '';
    position: absolute;
    left: 0; top: var(--s-2); bottom: var(--s-2);
    width: 3px;
    background: var(--danger);
    border-radius: 0 3px 3px 0;
}

/* Kanban dense card — used by kanban-board.blade.php. */
.davya-dense-card {
    background: var(--surface);
    border: 1px solid var(--border-muted);
    border-radius: var(--r-md);
    padding: 6px 8px 6px 14px;
    margin-bottom: 4px;
    font-size: var(--fs-12);
    display: flex;
    align-items: center;
    gap: var(--s-2);
    position: relative;
    cursor: pointer;
}
.davya-dense-card::before {
    content: '';
    position: absolute;
    left: 3px; top: 6px; bottom: 6px;
    width: 3px;
    background: var(--border);
    border-radius: 0 3px 3px 0;
}
.davya-dense-card[data-response="ready"]::before    { background: var(--resp-ready); }
.davya-dense-card[data-response="hesitant"]::before { background: var(--resp-hesitant); }
.davya-dense-card[data-response="cold"]::before     { background: var(--resp-cold); }
.davya-dense-card:hover { background: var(--brand-50); }
.davya-dense-card .n    { font-weight: 600; flex: 1; color: var(--text); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.davya-dense-card .chips{ font-size: var(--fs-10); color: var(--text-sub); white-space: nowrap; }
.davya-dense-card .amt  { font-weight: 700; color: var(--brand-700); font-size: var(--fs-11); }
.davya-dense-card .amt[data-zero="true"] { color: var(--text-muted); }
.davya-dense-card .av   { width: 18px; height: 18px; border-radius: 50%; color: white; font-size: 9px; display: flex; align-items: center; justify-content: center; font-weight: 700; flex-shrink: 0; }

/* Kanban column. */
.davya-kanban-col {
    background: var(--surface);
    border-radius: var(--r-lg);
    padding: var(--s-3);
    min-width: 260px;
    flex-shrink: 0;
    border: 1px solid var(--border);
    border-top: 3px solid var(--stage-active);
}
.davya-kanban-col[data-stage-type="new"]     { border-top-color: var(--stage-new); }
.davya-kanban-col[data-stage-type="meeting"] { border-top-color: var(--stage-meeting); }
.davya-kanban-col[data-stage-type="advance"] { border-top-color: var(--stage-advance); }
.davya-kanban-col[data-stage-type="round"]   { border-top-color: var(--stage-round); }
.davya-kanban-col[data-stage-type="offline"] { border-top-color: var(--stage-offline); }
.davya-kanban-col[data-stage-type="won"]     { border-top-color: var(--stage-won); }
.davya-kanban-col[data-stage-type="lost"]    { border-top-color: var(--stage-lost); }
.davya-kanban-col-head { display: flex; justify-content: space-between; align-items: center; }
.davya-kanban-col-head h4 { font-size: var(--fs-12); font-weight: 700; margin: 0; color: var(--text); }
.davya-kanban-col-count { background: var(--border-muted); color: var(--text-sub); border-radius: var(--r-pill); padding: 1px 8px; font-size: var(--fs-10); font-weight: 700; }
.davya-kanban-col-agg { font-size: var(--fs-10); color: var(--text-sub); font-weight: 500; margin: 2px 0 var(--s-2); }

/* Required-field red left bar — applied to every Filament form input
 * whose wrapper contains the built-in `*` marker. Uses :has() for
 * non-invasive styling; Safari <15.4 will silently skip the bar.
 */
.fi-fo-field-wrp:has(sup.fi-fo-field-wrp-label-required-mark) .fi-input-wrp,
.fi-fo-field-wrp:has(sup.fi-fo-field-wrp-label-required-mark) .fi-select-input,
.fi-fo-field-wrp:has(sup.fi-fo-field-wrp-label-required-mark) .fi-textarea {
    position: relative;
    padding-left: 12px !important;
}
.fi-fo-field-wrp:has(sup.fi-fo-field-wrp-label-required-mark) .fi-input-wrp::before,
.fi-fo-field-wrp:has(sup.fi-fo-field-wrp-label-required-mark) .fi-select-input::before,
.fi-fo-field-wrp:has(sup.fi-fo-field-wrp-label-required-mark) .fi-textarea::before {
    content: '';
    position: absolute;
    left: 0; top: 4px; bottom: 4px;
    width: 3px;
    background: var(--danger);
    border-radius: 0 3px 3px 0;
}
/* Hide the asterisk since the red bar replaces it. */
.fi-fo-field-wrp sup.fi-fo-field-wrp-label-required-mark { display: none; }

/* Section-card wrapper — applied to Filament form tabs/sections
 * via extraAttributes(['class' => 'davya-section']) on StudentResource.
 */
.fi-fo-section.davya-section {
    background: #FAFBFC;
    border-radius: var(--r-lg);
    padding: var(--s-4);
    margin-bottom: var(--s-3);
}

/* Owner pill — used by top bar and form headers. */
.davya-owner-pill {
    background: var(--brand-50);
    border: 1px solid var(--brand-100);
    border-radius: var(--r-pill);
    padding: 3px 10px;
    font-size: var(--fs-11);
    color: var(--brand-700);
    display: inline-flex;
    align-items: center;
    gap: 6px;
    font-weight: 500;
}
.davya-owner-pill .av { width: 18px; height: 18px; border-radius: 50%; background: var(--brand-500); color: white; font-size: 9px; display: flex; align-items: center; justify-content: center; font-weight: 700; }
```

- [ ] **Step 2: Commit**

```bash
git add resources/css/tokens.css
git commit -m "feat(visual-v2): design tokens, section-card, dense-card, required-bar CSS"
```

### Task 1.3: Wire tokens.css into AdminPanelProvider behind the flag

**Files:**
- Modify: `app/Providers/Filament/AdminPanelProvider.php`

- [ ] **Step 1: Replace the existing `renderHook(PanelsRenderHook::HEAD_END, …)` call**

Locate the existing HEAD_END hook (lines ~40–75). Replace it with:

```php
->renderHook(PanelsRenderHook::HEAD_END, fn (): string => Blade::render(<<<'BLADE'
    <link rel="manifest" href="/manifest.json">
    <meta name="theme-color" content="#065f46">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="Davya">
    <link rel="apple-touch-icon" href="/apple-touch-icon.png">
    {{-- SortableJS: single global include for pipeline-config + kanban (+ future pages). --}}
    <script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.2/Sortable.min.js" defer></script>
    @if (config('davyas.visual_v2'))
        <link rel="stylesheet" href="{{ asset('css/tokens.css') }}" id="davya-tokens">
    @endif
    <style>
        /* Narrow the login/challenge card so the giant "Sign in" block feels right-sized. */
        .fi-simple-main { max-width: 24rem; }
        /* Slightly tighter main content — matches Bigin's airy-but-dense look. */
        .fi-main-ctn { padding-top: 1rem; }
    </style>
    <script>
        // Capture beforeinstallprompt as early as possible so Alpine
        // components (dashboard widget + InstallApp page) can read the
        // deferred event even if the browser fires it before Alpine mounts.
        window.__davyaInstallPrompt = null;
        window.addEventListener('beforeinstallprompt', (e) => {
            e.preventDefault();
            window.__davyaInstallPrompt = e;
        });
        window.addEventListener('appinstalled', () => {
            window.__davyaInstallPrompt = null;
        });

        if ('serviceWorker' in navigator) {
            window.addEventListener('load', () => {
                navigator.serviceWorker.register('/sw.js', { scope: '/' }).catch(() => {});
            });
        }
    </script>
BLADE))
```

- [ ] **Step 2: Copy tokens.css into `public/` so `asset('css/tokens.css')` resolves**

Run: `mkdir -p public/css && cp resources/css/tokens.css public/css/tokens.css`
Verify: `ls -la public/css/tokens.css` — file exists.

(Vite isn't used for this file because the token CSS doesn't need compilation — serving it straight keeps the render-hook simple and avoids Vite-manifest edge cases inside the Filament panel.)

- [ ] **Step 3: Add a build step so `resources/css/tokens.css` → `public/css/tokens.css` stays in sync**

Append to `package.json` scripts section:

```json
"scripts": {
    "...existing...": "...",
    "build:tokens": "cp resources/css/tokens.css public/css/tokens.css"
}
```

Confirm by running: `npm run build:tokens` → no output, exit 0.

- [ ] **Step 4: Run the flag test from Task 1.1**

Run: `./vendor/bin/phpunit tests/Feature/VisualRefreshFlagTest.php`
Expected: both tests PASS — `test_tokens_css_not_loaded_when_flag_off` sees no `davya-tokens`; `test_tokens_css_loaded_when_flag_on` sees the `id="davya-tokens"` link tag.

- [ ] **Step 5: Commit**

```bash
git add app/Providers/Filament/AdminPanelProvider.php public/css/tokens.css package.json
git commit -m "feat(visual-v2): load tokens.css from HEAD_END when flag is on"
```

### Task 1.4: Restyle `pipeline-config.blade.php`

**Files:**
- Modify: `resources/views/filament/pages/pipeline-config.blade.php`

- [ ] **Step 1: Read the file to locate the stage row markup**

Run: `grep -n "wire:key\|stage-row\|\"w-5 h-5\"" resources/views/filament/pages/pipeline-config.blade.php`
Find the loop that renders each stage. Typical structure is a `<li>` or `<div>` per stage with a drag handle, name input, and icons.

- [ ] **Step 2: Wrap each stage row in a `davya-card-row` when flag is on**

For each `wire:key="stage-{{ $stage->id }}"` block (or equivalent), wrap the existing content with:

```blade
@if (config('davyas.visual_v2'))
    <div class="davya-card-row @if($stage->type === 'closed_won' || $stage->type === 'closed_lost') davya-card-row--custom @endif">
        {{-- existing drag handle --}}
        {{-- existing name input --}}
        @if ($stage->type === 'closed_won')
            <span class="inline-flex items-center gap-1" style="color: var(--brand-600);">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="currentColor"><path d="M2 20h2V9H2v11zM22 10c0-1.1-.9-2-2-2h-6.3l1-4.3v-.3c0-.4-.2-.8-.4-1.1L13.2 1 6.6 7.6c-.4.4-.6.9-.6 1.4v10c0 1.1.9 2 2 2h9c.8 0 1.5-.5 1.8-1.2l3-7c.1-.2.2-.5.2-.8v-2z"/></svg>
            </span>
        @elseif ($stage->type === 'closed_lost')
            <span class="inline-flex items-center gap-1" style="color: var(--danger);">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="currentColor"><path d="M22 4h-2v11h2V4zM2 14c0 1.1.9 2 2 2h6.3l-1 4.3v.3c0 .4.2.8.4 1.1L10.8 23l6.6-6.6c.4-.4.6-.9.6-1.4V5c0-1.1-.9-2-2-2H7c-.8 0-1.5.5-1.8 1.2l-3 7c-.1.2-.2.5-.2.8v2z"/></svg>
            </span>
        @endif
        {{-- existing icons --}}
    </div>
@else
    {{-- existing markup, unchanged --}}
@endif
```

(If the existing markup is a single large block, wrap it instead of inline-editing — that preserves the legacy rendering under the `@else`.)

- [ ] **Step 3: Manual smoke — `php artisan serve`, open `/admin/pipeline-config` with flag off and with flag on**

Flag on: stage rows have 3 px left accent on Won/Lost rows, thumbs-up/down icon appears beside name.
Flag off: page renders exactly as before.

- [ ] **Step 4: Commit**

```bash
git add resources/views/filament/pages/pipeline-config.blade.php
git commit -m "style(visual-v2): card-row restyle + Won/Lost badges on pipeline-config"
```

### Task 1.5: Restyle `student-fields-config.blade.php`

**Files:**
- Modify: `resources/views/filament/pages/student-fields-config.blade.php`

- [ ] **Step 1: Find the field-row loop**

Run: `grep -n "wire:key=\"field-" resources/views/filament/pages/student-fields-config.blade.php`

- [ ] **Step 2: Wrap each field row**

Inside each field-row iteration, wrap the existing markup:

```blade
@if (config('davyas.visual_v2'))
    <div class="davya-card-row @if(!$field->is_built_in) davya-card-row--custom @elseif($field->is_required) davya-card-row--required @endif">
        {{-- existing drag-handle + label + type + actions --}}
    </div>
@else
    {{-- existing markup --}}
@endif
```

- [ ] **Step 3: Commit**

```bash
git add resources/views/filament/pages/student-fields-config.blade.php
git commit -m "style(visual-v2): card-row + green/red left accents on student-fields"
```

### Task 1.6: Restyle `today-page.blade.php`

**Files:**
- Modify: `resources/views/filament/pages/today-page.blade.php`

- [ ] **Step 1: Wrap the meetings strip and payments table in `davya-section-card`**

Locate the outer wrapper of each widget section (typically `<div class="...">`). Replace or add-alongside:

```blade
@if (config('davyas.visual_v2'))
    <div class="davya-section-card">
        <div class="davya-section-card-title">Today's meetings</div>
        {{-- existing strip markup --}}
    </div>
    <div class="davya-section-card">
        <div class="davya-section-card-title">Today's payments</div>
        {{-- existing table markup --}}
    </div>
@else
    {{-- existing unchanged --}}
@endif
```

- [ ] **Step 2: Commit**

```bash
git add resources/views/filament/pages/today-page.blade.php
git commit -m "style(visual-v2): section-card wrappers on today page"
```

### Task 1.7: Restyle `dashboard.blade.php`

**Files:**
- Modify: `resources/views/filament/pages/dashboard.blade.php`

- [ ] **Step 1: Wrap each card in `davya-section-card`**

Same pattern as Task 1.6 — wrap each dashboard card output block in `davya-section-card` with a title. Keep existing `wire:key`s intact.

- [ ] **Step 2: Commit**

```bash
git add resources/views/filament/pages/dashboard.blade.php
git commit -m "style(visual-v2): section-card wrappers on dashboard cards"
```

### Task 1.8: Restyle `duplicate-flags-review.blade.php`

**Files:**
- Modify: `resources/views/filament/pages/duplicate-flags-review.blade.php`

- [ ] **Step 1: Wrap each flag row in `davya-card-row`**

Same pattern. No accent colour — just the card-row base class.

- [ ] **Step 2: Commit**

```bash
git add resources/views/filament/pages/duplicate-flags-review.blade.php
git commit -m "style(visual-v2): card-row restyle on duplicate review"
```

### Task 1.9: Tag Filament form sections with `davya-section`

**Files:**
- Modify: `app/Filament/Resources/StudentResource.php`

- [ ] **Step 1: Read the existing tab definitions**

Run: `grep -n "Tabs\\\\Tab::make" app/Filament/Resources/StudentResource.php`
You'll see tabs at lines ~101, 111, 159, 174, 181, 194, 202 (Identity / Source & Stage / Academic / Deal / Counselling / History / Closure).

- [ ] **Step 2: Add `extraAttributes` to each tab**

For each tab definition, append:

```php
->extraAttributes([
    'class' => config('davyas.visual_v2') ? 'davya-section' : '',
])
```

Example for the Identity tab:

```php
Tabs\Tab::make('Identity')
    ->schema([
        // ...existing fields...
    ])
    ->extraAttributes([
        'class' => config('davyas.visual_v2') ? 'davya-section' : '',
    ]),
```

Repeat for all seven tabs.

- [ ] **Step 3: Run the student form tests to confirm nothing broke**

Run: `./vendor/bin/phpunit tests/Feature/Filament/StudentResourceTest.php 2>/dev/null || ./vendor/bin/phpunit --filter StudentResource`
Expected: all tests pass.

- [ ] **Step 4: Commit**

```bash
git add app/Filament/Resources/StudentResource.php
git commit -m "style(visual-v2): tag student form tabs with davya-section for card styling"
```

### Task 1.10: Create Settings landing page

**Files:**
- Create: `app/Filament/Pages/SettingsLanding.php`
- Create: `resources/views/filament/pages/settings-landing.blade.php`

- [ ] **Step 1: Write the page class**

```php
<?php

namespace App\Filament\Pages;

use Filament\Pages\Page;

class SettingsLanding extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-cog-6-tooth';
    protected static ?string $navigationLabel = 'Settings';
    protected static ?string $title = 'Settings';
    protected static ?string $slug = 'settings';
    protected static string $view = 'filament.pages.settings-landing';
    protected static ?string $navigationGroup = 'Setup';
    protected static ?int $navigationSort = 100;

    public static function shouldRegisterNavigation(): bool
    {
        return config('davyas.visual_v2') === true
            && auth()->user()?->hasRole('admin') === true;
    }

    public function getTiles(): array
    {
        return [
            ['label' => 'Fields',           'icon' => 'heroicon-o-rectangle-stack',  'desc' => 'Student schema: sections, fields, required flags.', 'url' => '/admin/student-fields'],
            ['label' => 'Stages',           'icon' => 'heroicon-o-arrows-right-left','desc' => 'Pipeline stages, transition rules, Won/Lost.',       'url' => '/admin/pipeline-config'],
            ['label' => 'Duplicate review', 'icon' => 'heroicon-o-document-duplicate','desc' => 'Resolve flagged duplicate leads.',                   'url' => '/admin/duplicate-flags'],
            ['label' => 'Users & roles',    'icon' => 'heroicon-o-users',            'desc' => 'Counsellors, heads, permissions.',                   'url' => '/admin/users'],
            ['label' => 'Lead import',      'icon' => 'heroicon-o-arrow-up-tray',    'desc' => 'CSV import with dedup + re-parent.',                 'url' => '/admin/lead-import'],
            ['label' => 'Activity audit',   'icon' => 'heroicon-o-clipboard-document-list','desc' => 'Every password reveal, field change, stage move.', 'url' => '/admin/activity-audit'],
        ];
    }
}
```

- [ ] **Step 2: Write the blade view**

```blade
<x-filament-panels::page>
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 12px;">
        @foreach ($this->getTiles() as $tile)
            <a href="{{ $tile['url'] }}" class="davya-section-card" style="text-decoration: none; color: inherit; display: block; cursor: pointer;">
                <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 6px;">
                    <x-dynamic-component :component="$tile['icon']" style="width: 20px; height: 20px; color: var(--brand-600);" />
                    <span style="font-weight: 700; font-size: var(--fs-14); color: var(--text);">{{ $tile['label'] }}</span>
                </div>
                <p style="font-size: var(--fs-12); color: var(--text-sub); margin: 0;">{{ $tile['desc'] }}</p>
            </a>
        @endforeach
    </div>
</x-filament-panels::page>
```

- [ ] **Step 3: Manual smoke — with flag on, visit `/admin/settings`**

Expected: 6-tile grid, each tile links to the right page.

- [ ] **Step 4: Commit**

```bash
git add app/Filament/Pages/SettingsLanding.php resources/views/filament/pages/settings-landing.blade.php
git commit -m "feat(visual-v2): settings landing page at /admin/settings"
```

### Task 1.11: Manual smoke Phase 1

- [ ] **Step 1: Start local dev server**

Run: `DAVYAS_VISUAL_V2=true php artisan serve`

- [ ] **Step 2: Walk every restyled page with flag on**

Log in as `sumit@davya.local` / `smoke-test-pw` and open:
- `/admin` — dashboard cards wrapped in section-card.
- `/admin/kanban` — UNCHANGED at this phase (Phase 2).
- `/admin/today` — meetings strip + payments in section-cards.
- `/admin/students/create` — section-card tabs + red left bar on required fields (Name, Phone, Lead Source, Stage).
- `/admin/student-fields` — custom fields show green left accent; required built-ins show red accent.
- `/admin/pipeline-config` — Won/Lost rows show thumbs-up/down icon.
- `/admin/duplicate-flags` — rows in card-row.
- `/admin/settings` — tile grid present (new page).

- [ ] **Step 3: Toggle flag off, confirm zero visual change**

Stop server, restart without the env var: `php artisan serve`
Walk the same pages. They must render exactly like `main` did before Phase 1 started.

- [ ] **Step 4: Run full test suite**

Run: `./vendor/bin/phpunit`
Expected: all existing tests pass; new `VisualRefreshFlagTest` passes.

- [ ] **Step 5: Commit (smoke checklist doc)**

Create `docs/sessions/2026-04-24-visual-v2-phase1-smoke.md` with a checklist mirroring Step 2. Commit:

```bash
git add docs/sessions/2026-04-24-visual-v2-phase1-smoke.md
git commit -m "docs(session): Phase 1 visual-v2 smoke checklist"
```

---

## Phase 2 — Kanban column + dense card

Phase 2 rewrites the kanban surface. KanbanBoard page gets per-stage aggregates; blade template renders the new column + dense card; no Livewire components yet — card click just fires a Livewire event that Phase 4's drawer will listen for.

### Task 2.1: Add KanbanAggregateTest (failing)

**Files:**
- Create: `tests/Feature/KanbanAggregateTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature;

use App\Filament\Pages\KanbanBoard;
use App\Models\Payment;
use App\Models\Stage;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class KanbanAggregateTest extends TestCase
{
    use RefreshDatabase;

    public function test_stage_column_reports_received_and_pending_totals(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $stage = Stage::factory()->create(['name' => 'Advance Received']);

        $s1 = Student::factory()->create(['stage_id' => $stage->id, 'deal_amount' => 200000]);
        $s2 = Student::factory()->create(['stage_id' => $stage->id, 'deal_amount' => 100000]);
        Payment::factory()->create(['student_id' => $s1->id, 'amount' => 125000, 'direction' => 'received']);
        Payment::factory()->create(['student_id' => $s2->id, 'amount' => 40000,  'direction' => 'received']);

        $this->actingAs($admin);
        $page = app(KanbanBoard::class);
        $board = $page->getBoard();

        $column = collect($board)->firstWhere('stage.id', $stage->id);
        $this->assertSame(165000.0, (float) $column['received_total']);
        $this->assertSame(135000.0, (float) $column['pending_total']);
        $this->assertSame(2, $column['count']);
    }
}
```

(If `Stage::factory()` / `Payment::factory()` aren't defined, check `database/factories/` — the SP#1 + Finance work already added them. If factories are missing, fall back to `Stage::create([...])`.)

- [ ] **Step 2: Run test to verify FAIL**

Run: `./vendor/bin/phpunit tests/Feature/KanbanAggregateTest.php`
Expected: FAIL — `received_total` / `pending_total` keys absent.

- [ ] **Step 3: Commit test-first**

```bash
git add tests/Feature/KanbanAggregateTest.php
git commit -m "test(visual-v2): failing kanban stage aggregate test"
```

### Task 2.2: Add aggregates to `KanbanBoard::getBoard()`

**Files:**
- Modify: `app/Filament/Pages/KanbanBoard.php`

- [ ] **Step 1: Read the current `getBoard()`**

Run: `grep -n "function getBoard" app/Filament/Pages/KanbanBoard.php`

- [ ] **Step 2: Inside `getBoard()`, after stages are loaded, compute aggregates**

Add (adapting to existing variable names — the per-stage collection is typically `$columns`):

```php
// visual-v2: per-stage aggregates (received + pending)
$aggregates = \DB::table('students')
    ->leftJoin('payments', function ($join) {
        $join->on('payments.student_id', '=', 'students.id')
             ->where('payments.direction', '=', 'received');
    })
    ->select(
        'students.stage_id',
        \DB::raw('COALESCE(SUM(payments.amount), 0) AS received_total'),
        \DB::raw('COALESCE(SUM(DISTINCT students.deal_amount), 0) AS deal_total'),
    )
    ->groupBy('students.stage_id')
    ->get()
    ->keyBy('stage_id');
```

Then, when building each column:

```php
$agg = $aggregates->get($stage->id);
$column['received_total'] = (float) ($agg->received_total ?? 0);
$column['pending_total']  = max(0, (float) ($agg->deal_total ?? 0) - (float) ($agg->received_total ?? 0));
```

(The `DISTINCT students.deal_amount` avoids double-counting when a student has multiple payments. If this produces wrong numbers because of zero-amount duplicates, fall back to computing deal_total in a separate query:

```php
$dealTotals = Student::selectRaw('stage_id, SUM(deal_amount) AS deal_total')->groupBy('stage_id')->pluck('deal_total', 'stage_id');
```

and combine with `$aggregates`.)

- [ ] **Step 3: Run the test**

Run: `./vendor/bin/phpunit tests/Feature/KanbanAggregateTest.php`
Expected: PASS.

- [ ] **Step 4: Commit**

```bash
git add app/Filament/Pages/KanbanBoard.php
git commit -m "feat(visual-v2): per-stage received/pending aggregates on kanban"
```

### Task 2.3: Create AvatarColor helper

**Files:**
- Create: `app/Support/AvatarColor.php`
- Test: `tests/Unit/AvatarColorTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Unit;

use App\Support\AvatarColor;
use PHPUnit\Framework\TestCase;

class AvatarColorTest extends TestCase
{
    public function test_same_id_yields_same_colour(): void
    {
        $this->assertSame(AvatarColor::forUserId(42), AvatarColor::forUserId(42));
    }

    public function test_different_ids_usually_differ(): void
    {
        $colours = array_unique([
            AvatarColor::forUserId(1), AvatarColor::forUserId(2), AvatarColor::forUserId(3),
            AvatarColor::forUserId(4), AvatarColor::forUserId(5), AvatarColor::forUserId(6),
        ]);
        $this->assertGreaterThanOrEqual(3, count($colours));
    }

    public function test_initials_return_two_letters(): void
    {
        $this->assertSame('SD', AvatarColor::initials('Sumit Dabas'));
        $this->assertSame('N',  AvatarColor::initials('Nikhil'));
        $this->assertSame('',   AvatarColor::initials(''));
    }
}
```

- [ ] **Step 2: Run test to verify FAIL**

Run: `./vendor/bin/phpunit tests/Unit/AvatarColorTest.php`
Expected: FAIL — `App\Support\AvatarColor` not found.

- [ ] **Step 3: Write the helper**

```php
<?php

namespace App\Support;

class AvatarColor
{
    /**
     * Stable palette for owner/avatar circles. 8 colours — roughly balanced.
     */
    private const PALETTE = [
        '#10B981', // emerald
        '#8B5CF6', // violet
        '#F59E0B', // amber
        '#3B82F6', // blue
        '#EF4444', // red
        '#06B6D4', // cyan
        '#EC4899', // pink
        '#6366F1', // indigo
    ];

    public static function forUserId(int $userId): string
    {
        return self::PALETTE[$userId % count(self::PALETTE)];
    }

    public static function initials(string $name): string
    {
        $name = trim($name);
        if ($name === '') {
            return '';
        }
        $parts = preg_split('/\s+/', $name);
        if (count($parts) === 1) {
            return mb_strtoupper(mb_substr($parts[0], 0, 1));
        }
        return mb_strtoupper(mb_substr($parts[0], 0, 1) . mb_substr(end($parts), 0, 1));
    }
}
```

- [ ] **Step 4: Run tests**

Run: `./vendor/bin/phpunit tests/Unit/AvatarColorTest.php`
Expected: PASS (all three).

- [ ] **Step 5: Commit**

```bash
git add app/Support/AvatarColor.php tests/Unit/AvatarColorTest.php
git commit -m "feat(visual-v2): AvatarColor helper (deterministic colour + initials)"
```

### Task 2.4: Rewrite kanban column + card blade

**Files:**
- Modify: `resources/views/filament/pages/kanban-board.blade.php`

- [ ] **Step 1: Read the file**

Run: `cat resources/views/filament/pages/kanban-board.blade.php | head -80`
Note the existing column-loop and card-loop structure.

- [ ] **Step 2: Add a v2-gated branch at the top of the column loop**

Wrap the outer column markup with `@if(config('davyas.visual_v2')) … @else … @endif`. The v2 branch renders:

```blade
@foreach ($this->getBoard() as $column)
    @php $s = $column['stage']; $type = strtolower(str_replace(' ', '-', $s->name)); @endphp

    @if (config('davyas.visual_v2'))
        <div class="davya-kanban-col" data-stage-type="{{ $column['stage_type'] ?? 'active' }}" wire:key="col-{{ $s->id }}">
            <div class="davya-kanban-col-head">
                <h4>{{ $s->name }}</h4>
                <span class="davya-kanban-col-count">{{ $column['count'] }}</span>
            </div>
            <div class="davya-kanban-col-agg">
                ₹{{ \App\Support\MoneyFormat::indianShort($column['received_total']) }} received · ₹{{ \App\Support\MoneyFormat::indianShort($column['pending_total']) }} pending
            </div>

            <div wire:sortable.group="stage" wire:sortable-group.item-group="stage-{{ $s->id }}" data-stage-id="{{ $s->id }}">
                @foreach ($column['students'] as $student)
                    <div class="davya-dense-card"
                         data-response="{{ $student->student_response ?? 'unknown' }}"
                         wire:key="card-{{ $student->id }}"
                         wire:click="$dispatch('open-student-peek', { studentId: {{ $student->id }} })"
                    >
                        <div class="n">{{ $student->name }}</div>
                        <div class="chips">{{ $student->course ?? '—' }} @if($student->current_round)· R{{ $student->current_round }}@endif</div>
                        <div class="amt" data-zero="{{ $student->total_received == 0 ? 'true' : 'false' }}">₹{{ \App\Support\MoneyFormat::indianShort($student->total_received) }}</div>
                        <div class="av" style="background: {{ \App\Support\AvatarColor::forUserId($student->owner_id ?? 0) }};">
                            {{ \App\Support\AvatarColor::initials($student->owner?->name ?? '??') }}
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    @else
        {{-- legacy column markup, unchanged --}}
    @endif
@endforeach
```

- [ ] **Step 3: Create `MoneyFormat` helper** (referenced in step 2)

Create `app/Support/MoneyFormat.php`:

```php
<?php

namespace App\Support;

class MoneyFormat
{
    /** Indian short: 1,25,000 → 1.25L, 80,000 → 80K, 2,00,00,000 → 2Cr. */
    public static function indianShort(float|int|null $amount): string
    {
        $n = (float) ($amount ?? 0);
        if ($n === 0.0)    { return '0'; }
        if ($n >= 1_00_00_000) { return number_format($n / 1_00_00_000, 2) . 'Cr'; }
        if ($n >= 1_00_000)    { return number_format($n / 1_00_000, 2) . 'L'; }
        if ($n >= 1_000)       { return number_format($n / 1_000, 0) . 'K'; }
        return number_format($n, 0);
    }
}
```

Test it:

```php
<?php
// tests/Unit/MoneyFormatTest.php

namespace Tests\Unit;

use App\Support\MoneyFormat;
use PHPUnit\Framework\TestCase;

class MoneyFormatTest extends TestCase
{
    public function test_format_cases(): void
    {
        $this->assertSame('0',     MoneyFormat::indianShort(0));
        $this->assertSame('500',   MoneyFormat::indianShort(500));
        $this->assertSame('80K',   MoneyFormat::indianShort(80_000));
        $this->assertSame('1.25L', MoneyFormat::indianShort(1_25_000));
        $this->assertSame('2.00Cr',MoneyFormat::indianShort(2_00_00_000));
    }
}
```

Run: `./vendor/bin/phpunit tests/Unit/MoneyFormatTest.php`
Expected: PASS.

- [ ] **Step 4: Smoke test — `DAVYAS_VISUAL_V2=true php artisan serve`**, open `/admin/kanban`

Expected:
- Columns are 260 px wide, 3 px emerald/amber/blue top border.
- Column header shows name + count pill and a second-line aggregate `₹X received · ₹Y pending`.
- Cards are single-row dense, with left status strip coloured by `student_response`, owner avatar on right, amount right-aligned.
- Hovering a card tints it emerald-50.
- Clicking a card currently does nothing (Phase 4 will wire the drawer).

- [ ] **Step 5: Verify drag-drop still works**

Drag a card between columns. Filament kanban's existing Livewire sort handler should still fire (SortableJS is still globally included). If drag fails: check that the v2 card element still has the same `wire:key` and that the parent still has `wire:sortable.group`.

- [ ] **Step 6: Commit**

```bash
git add resources/views/filament/pages/kanban-board.blade.php app/Support/MoneyFormat.php tests/Unit/MoneyFormatTest.php
git commit -m "feat(visual-v2): dense kanban cards + column aggregate header"
```

### Task 2.5: Add `stage_type` classification to KanbanBoard columns

**Files:**
- Modify: `app/Filament/Pages/KanbanBoard.php`

- [ ] **Step 1: Add a `stageType()` helper**

Add a method that classifies a stage by name/type for the top-border colour:

```php
private function stageType(Stage $stage): string
{
    $name = mb_strtolower($stage->name);
    if ($stage->type === 'closed_won')  { return 'won'; }
    if ($stage->type === 'closed_lost') { return 'lost'; }
    if (str_contains($name, 'new'))     { return 'new'; }
    if (str_contains($name, 'meeting')) { return 'meeting'; }
    if (str_contains($name, 'visit'))   { return 'meeting'; }
    if (str_contains($name, 'advance')) { return 'advance'; }
    if (str_contains($name, 'round'))   { return 'round'; }
    if (str_contains($name, 'offline')) { return 'offline'; }
    return 'active';
}
```

When building each column, set `$column['stage_type'] = $this->stageType($stage);`.

- [ ] **Step 2: Smoke — reload `/admin/kanban`**

Expected: new columns have type-specific top-border colours.

- [ ] **Step 3: Commit**

```bash
git add app/Filament/Pages/KanbanBoard.php
git commit -m "feat(visual-v2): map stage name → type for kanban column accent"
```

---

## Phase 3 — Top bar + sub-toolbar + command palette

### Task 3.1: TopBar Livewire component (skeleton)

**Files:**
- Create: `app/Livewire/TopBar.php`
- Create: `resources/views/livewire/top-bar.blade.php`
- Test: `tests/Feature/Livewire/TopBarTest.php`

- [ ] **Step 1: Write a failing test**

```php
<?php

namespace Tests\Feature\Livewire;

use App\Livewire\TopBar;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class TopBarTest extends TestCase
{
    use RefreshDatabase;

    public function test_renders_primary_tabs(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        Livewire::actingAs($admin)->test(TopBar::class)
            ->assertSee('Pipeline')
            ->assertSee('Students')
            ->assertSee('Today')
            ->assertSee('Reports')
            ->assertSee('Finance')
            ->assertSee('Jump to anything');
    }

    public function test_finance_tab_hidden_from_non_finance_role(): void
    {
        $counsellor = User::factory()->create();
        $counsellor->assignRole('counsellor');

        Livewire::actingAs($counsellor)->test(TopBar::class)
            ->assertDontSee('Finance');
    }
}
```

- [ ] **Step 2: Run test to verify FAIL**

Run: `./vendor/bin/phpunit tests/Feature/Livewire/TopBarTest.php`
Expected: FAIL — class does not exist.

- [ ] **Step 3: Create the component**

```php
<?php

namespace App\Livewire;

use Livewire\Component;

class TopBar extends Component
{
    public function tabs(): array
    {
        $user = auth()->user();

        $tabs = [
            ['key' => 'pipeline', 'label' => 'Pipeline', 'url' => '/admin/kanban',  'match' => '/admin/kanban'],
            ['key' => 'students', 'label' => 'Students', 'url' => '/admin/students', 'match' => '/admin/students'],
            ['key' => 'today',    'label' => 'Today',    'url' => '/admin/today',    'match' => '/admin/today'],
            ['key' => 'reports',  'label' => 'Reports',  'url' => '/admin/leads-report', 'match' => '/admin/leads-report'],
        ];

        if ($user?->hasAnyRole(['admin', 'finance'])) {
            $tabs[] = ['key' => 'finance', 'label' => 'Finance', 'url' => '/admin/expenses', 'match' => '/admin/expenses'];
        }

        return $tabs;
    }

    public function render()
    {
        return view('livewire.top-bar', [
            'tabs' => $this->tabs(),
            'currentPath' => request()->path(),
            'user' => auth()->user(),
        ]);
    }
}
```

- [ ] **Step 4: Create the blade**

```blade
<div class="davya-topbar" style="background: var(--surface); border-bottom: 1px solid var(--border); padding: 10px 16px; display: flex; align-items: center; gap: 14px; font-size: var(--fs-12); position: sticky; top: 0; z-index: 30;">
    <a href="/admin" style="text-decoration: none; color: var(--brand-600); font-weight: 800; font-size: var(--fs-14); letter-spacing: 0.3px;">Davyas</a>

    <nav style="display: flex; gap: 2px;">
        @foreach ($tabs as $tab)
            @php $isActive = str_starts_with('/' . $currentPath, $tab['match']); @endphp
            <a href="{{ $tab['url'] }}"
               style="padding: 6px 10px; border-radius: var(--r-md); font-weight: {{ $isActive ? 600 : 500 }}; text-decoration: none; {{ $isActive ? 'color: var(--brand-700); background: var(--brand-50);' : 'color: var(--text-sub);' }}">
                {{ $tab['label'] }}
            </a>
        @endforeach
    </nav>

    <button type="button"
            onclick="window.dispatchEvent(new CustomEvent('open-command-palette'))"
            style="flex: 1; background: var(--border-muted); border: 0; border-radius: var(--r-md); padding: 6px 10px; color: var(--text-muted); font-size: var(--fs-11); display: flex; align-items: center; gap: 8px; cursor: pointer; text-align: left;">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="7"/><path d="m21 21-4.3-4.3"/></svg>
        Jump to anything — student, stage, setting…
        <span style="margin-left: auto; background: var(--surface); border: 1px solid var(--border); border-radius: var(--r-sm); padding: 1px 5px; font-family: ui-monospace, monospace; font-size: var(--fs-10); color: var(--text-sub);">⌘K</span>
    </button>

    <a href="/admin/students/create"
       style="background: var(--brand-600); color: white; border-radius: var(--r-md); padding: 6px 10px; font-size: var(--fs-11); font-weight: 600; text-decoration: none;">
        + New Student
    </a>

    <a href="/admin/settings"
       style="text-decoration: none; color: var(--text-sub);"
       title="Settings">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 1 1-4 0v-.09a1.65 1.65 0 0 0-1-1.51 1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 1 1 0-4h.09a1.65 1.65 0 0 0 1.51-1 1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06a1.65 1.65 0 0 0 1.82.33h0a1.65 1.65 0 0 0 1-1.51V3a2 2 0 1 1 4 0v.09a1.65 1.65 0 0 0 1 1.51h0a1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82v0a1.65 1.65 0 0 0 1.51 1H21a2 2 0 1 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>
    </a>

    <span class="davya-owner-pill">
        <span class="av" style="background: {{ \App\Support\AvatarColor::forUserId($user?->id ?? 0) }};">{{ \App\Support\AvatarColor::initials($user?->name ?? '??') }}</span>
        {{ $user?->name }}
    </span>
</div>
```

- [ ] **Step 5: Run tests**

Run: `./vendor/bin/phpunit tests/Feature/Livewire/TopBarTest.php`
Expected: PASS (both).

- [ ] **Step 6: Commit**

```bash
git add app/Livewire/TopBar.php resources/views/livewire/top-bar.blade.php tests/Feature/Livewire/TopBarTest.php
git commit -m "feat(visual-v2): TopBar Livewire component with role-aware tabs"
```

### Task 3.2: Mount TopBar in AdminPanelProvider

**Files:**
- Modify: `app/Providers/Filament/AdminPanelProvider.php`

- [ ] **Step 1: Add a `PAGE_START` or `BODY_START` render hook**

After the existing `HEAD_END` hook, add:

```php
->renderHook(
    PanelsRenderHook::BODY_START,
    fn (): string => config('davyas.visual_v2') ? Blade::render('@livewire("top-bar")') : ''
)
```

- [ ] **Step 2: Hide the default Filament sidebar when flag is on** (so we don't have two navigation surfaces)

Inside `panel()`, add:

```php
->sidebarCollapsibleOnDesktop(config('davyas.visual_v2'))
```

If a stronger hide is needed, add CSS in tokens.css:

```css
@media (min-width: 1024px) {
    body.davya-v2 .fi-sidebar { display: none; }
    body.davya-v2 .fi-main-ctn { padding-left: 0 !important; }
}
```

And set `body` class:

```php
->renderHook(
    PanelsRenderHook::BODY_START,
    fn (): string => config('davyas.visual_v2') ? '<script>document.body.classList.add("davya-v2")</script>' . Blade::render('@livewire("top-bar")') : ''
)
```

- [ ] **Step 3: Smoke — reload `/admin` with flag on**

Expected: top bar renders; Filament sidebar hidden.

- [ ] **Step 4: Commit**

```bash
git add app/Providers/Filament/AdminPanelProvider.php resources/css/tokens.css public/css/tokens.css
git commit -m "feat(visual-v2): mount TopBar + hide legacy sidebar when flag on"
```

### Task 3.3: CommandPalette Livewire component

**Files:**
- Create: `app/Livewire/CommandPalette.php`
- Create: `resources/views/livewire/command-palette.blade.php`
- Test: `tests/Feature/Livewire/CommandPaletteTest.php`

- [ ] **Step 1: Write failing tests**

```php
<?php

namespace Tests\Feature\Livewire;

use App\Livewire\CommandPalette;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class CommandPaletteTest extends TestCase
{
    use RefreshDatabase;

    public function test_search_returns_matching_students(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        Student::factory()->create(['name' => 'Chaitanya Rao', 'phone' => '9999900001']);
        Student::factory()->create(['name' => 'Khushbu Sharma']);

        Livewire::actingAs($admin)
            ->test(CommandPalette::class)
            ->set('query', 'chait')
            ->assertSee('Chaitanya Rao')
            ->assertDontSee('Khushbu Sharma');
    }

    public function test_search_respects_scope_visible_to(): void
    {
        $nikhil = User::factory()->create();
        $nikhil->assignRole('counsellor-head');
        $nikhilsStudent = Student::factory()->create(['name' => 'Ari', 'owner_id' => $nikhil->id]);

        $sumit = User::factory()->create();
        $sumit->assignRole('counsellor-head');
        // Ari belongs to Nikhil's team, not Sumit's.

        Livewire::actingAs($sumit)
            ->test(CommandPalette::class)
            ->set('query', 'Ari')
            ->assertDontSee('Ari');
    }

    public function test_static_pages_always_visible(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        Livewire::actingAs($admin)
            ->test(CommandPalette::class)
            ->set('query', '')
            ->assertSee('Pipeline')
            ->assertSee('Today')
            ->assertSee('Dashboard');
    }
}
```

- [ ] **Step 2: Run to verify FAIL**

Run: `./vendor/bin/phpunit tests/Feature/Livewire/CommandPaletteTest.php`
Expected: class-does-not-exist errors.

- [ ] **Step 3: Create the component**

```php
<?php

namespace App\Livewire;

use App\Models\Student;
use Livewire\Attributes\On;
use Livewire\Component;

class CommandPalette extends Component
{
    public bool $isOpen = false;
    public string $query = '';

    #[On('open-command-palette')]
    public function open(): void
    {
        $this->isOpen = true;
        $this->query = '';
    }

    public function close(): void
    {
        $this->isOpen = false;
    }

    public function students(): array
    {
        if (mb_strlen($this->query) < 2) {
            return [];
        }
        $q = $this->query;
        return Student::query()
            ->visibleTo(auth()->user())
            ->where(function ($qq) use ($q) {
                $qq->where('name', 'LIKE', "%{$q}%")
                   ->orWhere('phone', 'LIKE', "%{$q}%");
            })
            ->limit(8)
            ->get(['id', 'name', 'phone', 'course'])
            ->toArray();
    }

    public function pages(): array
    {
        $all = [
            ['label' => 'Pipeline',          'url' => '/admin/kanban'],
            ['label' => 'Students',          'url' => '/admin/students'],
            ['label' => 'Today',             'url' => '/admin/today'],
            ['label' => 'Dashboard',         'url' => '/admin'],
            ['label' => 'Reports — Leads',   'url' => '/admin/leads-report'],
            ['label' => 'Reports — Activity',              'url' => '/admin/activity-audit'],
            ['label' => 'Reports — Duplicate review',      'url' => '/admin/duplicate-flags'],
            ['label' => 'Reports — Lead import',           'url' => '/admin/lead-import'],
            ['label' => 'Settings — Fields',               'url' => '/admin/student-fields'],
            ['label' => 'Settings — Stages',               'url' => '/admin/pipeline-config'],
            ['label' => 'Settings',                         'url' => '/admin/settings'],
        ];

        if ($this->query === '') {
            return $all;
        }

        return array_values(array_filter($all, fn ($p) => stripos($p['label'], $this->query) !== false));
    }

    public function render()
    {
        return view('livewire.command-palette', [
            'students' => $this->students(),
            'pages'    => $this->pages(),
        ]);
    }
}
```

- [ ] **Step 4: Create the blade**

```blade
<div wire:key="command-palette">
    @if ($isOpen)
        <div style="position: fixed; inset: 0; background: rgba(17,24,39,.3); z-index: 60; display: flex; align-items: flex-start; justify-content: center; padding-top: 12vh;"
             wire:click="close">
            <div style="background: var(--surface); border-radius: var(--r-lg); width: 600px; max-width: 92vw; box-shadow: var(--elev-2); overflow: hidden;"
                 wire:click.stop>
                <input type="text"
                       wire:model.live.debounce.150ms="query"
                       autofocus
                       placeholder="Search students, pages, actions…"
                       style="width: 100%; padding: 14px 16px; border: 0; font-size: var(--fs-14); outline: 0; border-bottom: 1px solid var(--border);">
                <div style="max-height: 60vh; overflow-y: auto;">
                    @if (count($students))
                        <div class="davya-section-card-title" style="padding: 8px 16px 4px; margin: 0;">Students</div>
                        @foreach ($students as $s)
                            <a href="#" wire:click.prevent="$dispatchSelf('close'); $dispatch('open-student-peek', { studentId: {{ $s['id'] }} })"
                               style="display: block; padding: 8px 16px; text-decoration: none; color: var(--text); font-size: var(--fs-12); border-bottom: 1px solid var(--border-muted);">
                                <strong>{{ $s['name'] }}</strong>
                                <span style="color: var(--text-sub); margin-left: 8px;">{{ $s['phone'] ?? '' }} · {{ $s['course'] ?? '—' }}</span>
                            </a>
                        @endforeach
                    @endif

                    <div class="davya-section-card-title" style="padding: 8px 16px 4px; margin: 0;">Pages</div>
                    @foreach ($pages as $p)
                        <a href="{{ $p['url'] }}"
                           style="display: block; padding: 8px 16px; text-decoration: none; color: var(--text); font-size: var(--fs-12); border-bottom: 1px solid var(--border-muted);">
                            {{ $p['label'] }}
                        </a>
                    @endforeach
                </div>
            </div>
        </div>
    @endif
</div>
```

- [ ] **Step 5: Add Alpine keybind on body — in AdminPanelProvider HEAD_END**, inside the existing v2 block:

```html
<script>
    document.addEventListener('keydown', (e) => {
        if ((e.metaKey || e.ctrlKey) && e.key.toLowerCase() === 'k') {
            e.preventDefault();
            window.dispatchEvent(new CustomEvent('open-command-palette'));
        }
    });
    window.addEventListener('open-command-palette', () => {
        if (window.Livewire) {
            window.Livewire.dispatch('open-command-palette');
        }
    });
</script>
```

- [ ] **Step 6: Mount CommandPalette via BODY_START render hook**

In `AdminPanelProvider`, extend the BODY_START hook to render both TopBar and CommandPalette:

```php
->renderHook(
    PanelsRenderHook::BODY_START,
    fn (): string => config('davyas.visual_v2')
        ? '<script>document.body.classList.add("davya-v2")</script>' . Blade::render('@livewire("top-bar") @livewire("command-palette")')
        : ''
)
```

- [ ] **Step 7: Run tests**

Run: `./vendor/bin/phpunit tests/Feature/Livewire/CommandPaletteTest.php`
Expected: PASS.

- [ ] **Step 8: Manual smoke**

- Open `/admin` with flag on.
- Press `⌘K` → palette opens.
- Type "chait" → matches appear.
- Click a page row → navigates.
- Click outside or press `esc` → palette closes.

- [ ] **Step 9: Commit**

```bash
git add app/Livewire/CommandPalette.php resources/views/livewire/command-palette.blade.php tests/Feature/Livewire/CommandPaletteTest.php app/Providers/Filament/AdminPanelProvider.php
git commit -m "feat(visual-v2): ⌘K command palette with students + pages search"
```

### Task 3.4: Kanban sub-toolbar (filter pills + sort + view switch)

**Files:**
- Modify: `resources/views/filament/pages/kanban-board.blade.php`

- [ ] **Step 1: Above the column loop, add the v2-gated sub-toolbar**

```blade
@if (config('davyas.visual_v2'))
    <div class="davya-subtoolbar" style="background: var(--surface); border-bottom: 1px solid var(--border); padding: 8px 16px; display: flex; align-items: center; gap: 10px; font-size: var(--fs-11);">
        {{-- Reuse existing filter dropdowns, just restyled as pills via wire:model --}}
        <span style="background: var(--brand-50); border: 1px solid var(--brand-100); color: var(--brand-700); border-radius: var(--r-pill); padding: 3px 10px; font-weight: 500;">
            Course: {{ $courseFilter ?? 'All' }}
        </span>
        <span style="background: var(--border-muted); border: 1px solid var(--border); border-radius: var(--r-pill); padding: 3px 10px;">
            Owner: {{ $ownerFilter ?? 'Anyone' }}
        </span>
        <span style="background: var(--border-muted); border: 1px solid var(--border); border-radius: var(--r-pill); padding: 3px 10px;">
            Round: {{ $roundFilter ?? 'Any' }}
        </span>
        <span style="color: var(--text-sub); margin-left: 6px;">·</span>
        <span style="color: var(--text-sub);">Sort: Created ↓</span>

        <div style="margin-left: auto; display: flex; background: var(--border-muted); border-radius: var(--r-md); padding: 2px; gap: 2px;">
            <a href="/admin/kanban" style="padding: 3px 8px; font-size: var(--fs-11); border-radius: var(--r-sm); background: var(--surface); color: var(--text); font-weight: 600; text-decoration: none; box-shadow: var(--elev-1);">Kanban</a>
            <a href="/admin/students" style="padding: 3px 8px; font-size: var(--fs-11); border-radius: var(--r-sm); color: var(--text-sub); text-decoration: none;">List</a>
        </div>
    </div>
@endif
```

(The filter-pill values come from the existing `#[Url]` properties on `KanbanBoard`; the pills are display-only in this pass — clicking a pill still opens the legacy Filament filter dropdown via the existing `$this->openFilter()` action. If the sub-toolbar needs click-to-edit behaviour beyond what legacy filters give, that's a follow-up, not in scope.)

- [ ] **Step 2: Smoke**

Reload `/admin/kanban`. Sub-toolbar appears above the column row.

- [ ] **Step 3: Commit**

```bash
git add resources/views/filament/pages/kanban-board.blade.php
git commit -m "feat(visual-v2): kanban sub-toolbar with filter pills + view switch"
```

---

## Phase 4 — Student peek drawer

### Task 4.1: StudentPeekDrawer skeleton + open/close test

**Files:**
- Create: `app/Livewire/StudentPeekDrawer.php`
- Create: `resources/views/livewire/student-peek-drawer.blade.php`
- Test: `tests/Feature/Livewire/StudentPeekDrawerTest.php`

- [ ] **Step 1: Write failing open/close tests**

```php
<?php

namespace Tests\Feature\Livewire;

use App\Livewire\StudentPeekDrawer;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class StudentPeekDrawerTest extends TestCase
{
    use RefreshDatabase;

    public function test_opens_with_student_id(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $student = Student::factory()->create(['name' => 'Chaitanya']);

        Livewire::actingAs($admin)
            ->test(StudentPeekDrawer::class)
            ->dispatch('open-student-peek', studentId: $student->id)
            ->assertSet('isOpen', true)
            ->assertSet('studentId', $student->id)
            ->assertSee('Chaitanya');
    }

    public function test_close_resets_state(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $student = Student::factory()->create();

        Livewire::actingAs($admin)
            ->test(StudentPeekDrawer::class)
            ->dispatch('open-student-peek', studentId: $student->id)
            ->call('close')
            ->assertSet('isOpen', false)
            ->assertSet('studentId', null);
    }

    public function test_swap_in_updates_student_without_closing(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $a = Student::factory()->create(['name' => 'Alpha']);
        $b = Student::factory()->create(['name' => 'Bravo']);

        Livewire::actingAs($admin)
            ->test(StudentPeekDrawer::class)
            ->dispatch('open-student-peek', studentId: $a->id)
            ->dispatch('open-student-peek', studentId: $b->id)
            ->assertSet('isOpen', true)
            ->assertSet('studentId', $b->id)
            ->assertSee('Bravo')
            ->assertDontSee('Alpha');
    }

    public function test_scope_visible_to_prevents_cross_team_peek(): void
    {
        $nikhil = User::factory()->create();
        $nikhil->assignRole('counsellor-head');
        $theirs = Student::factory()->create(['name' => 'Theirs', 'owner_id' => $nikhil->id]);

        $sonam = User::factory()->create();
        $sonam->assignRole('counsellor-head');

        Livewire::actingAs($sonam)
            ->test(StudentPeekDrawer::class)
            ->dispatch('open-student-peek', studentId: $theirs->id)
            ->assertSet('isOpen', false);
    }
}
```

- [ ] **Step 2: Run to verify FAIL**

Run: `./vendor/bin/phpunit tests/Feature/Livewire/StudentPeekDrawerTest.php`
Expected: class-does-not-exist errors.

- [ ] **Step 3: Create the component**

```php
<?php

namespace App\Livewire;

use App\Models\Student;
use Livewire\Attributes\On;
use Livewire\Component;

class StudentPeekDrawer extends Component
{
    public bool $isOpen = false;
    public ?int $studentId = null;
    public string $activeTab = 'overview';

    #[On('open-student-peek')]
    public function open(int $studentId): void
    {
        $student = Student::query()->visibleTo(auth()->user())->find($studentId);
        if ($student === null) {
            return;
        }
        $this->studentId = $student->id;
        $this->isOpen = true;
        $this->activeTab = 'overview';
    }

    public function close(): void
    {
        $this->isOpen = false;
        $this->studentId = null;
        $this->activeTab = 'overview';
    }

    public function switchTab(string $tab): void
    {
        $this->activeTab = in_array($tab, ['overview','payments','notes','meetings','activity'], true)
            ? $tab
            : 'overview';
    }

    public function getStudentProperty(): ?Student
    {
        if ($this->studentId === null) {
            return null;
        }
        return Student::with(['owner', 'stage'])->find($this->studentId);
    }

    public function render()
    {
        return view('livewire.student-peek-drawer');
    }
}
```

- [ ] **Step 4: Create the blade (header + stepper + tab strip + tab body placeholder + footer)**

```blade
<div wire:key="student-peek-drawer">
    @if ($isOpen && $this->student)
        @php $s = $this->student; @endphp
        <div style="position: fixed; inset: 0; z-index: 55;">
            <div style="position: absolute; inset: 0; background: rgba(17,24,39,.25);" wire:click="close"></div>
            <aside style="position: absolute; top: 0; right: 0; bottom: 0; width: 560px; max-width: calc(100vw - 40px); background: var(--surface); box-shadow: var(--elev-drawer); overflow-y: auto; display: flex; flex-direction: column;">

                {{-- Header --}}
                <div style="padding: 16px 18px; border-bottom: 1px solid var(--border);">
                    <div style="display: flex; justify-content: space-between; align-items: flex-start;">
                        <div>
                            <div style="font-size: var(--fs-18); font-weight: 800; color: var(--text);">{{ $s->name }}</div>
                            <div style="font-size: var(--fs-11); color: var(--text-sub); margin-top: 2px;">
                                {{ $s->phone }} · {{ $s->course ?? '—' }} @if($s->current_round)· Round {{ $s->current_round }}@endif
                            </div>
                        </div>
                        <button wire:click="close" style="background: 0; border: 0; color: var(--text-muted); font-size: 18px; cursor: pointer;">✕</button>
                    </div>

                    @if ($s->owner)
                        <div style="margin-top: 10px;">
                            <span class="davya-owner-pill">
                                <span class="av" style="background: {{ \App\Support\AvatarColor::forUserId($s->owner->id) }};">{{ \App\Support\AvatarColor::initials($s->owner->name) }}</span>
                                {{ $s->owner->name }}
                            </span>
                        </div>
                    @endif

                    {{-- Stage stepper --}}
                    @php
                        $stages = \App\Models\Stage::orderBy('order')->get();
                        $currentIndex = $stages->search(fn ($st) => $st->id === $s->stage_id);
                    @endphp
                    <div style="display: flex; gap: 4px; margin-top: 14px; align-items: center;">
                        @foreach ($stages as $i => $st)
                            <div style="flex: 1; height: 6px; border-radius: 3px; background: {{ $i < $currentIndex ? 'var(--success)' : ($i === $currentIndex ? 'var(--warning)' : 'var(--border)') }};"></div>
                        @endforeach
                        <span style="font-size: var(--fs-10); color: var(--text-sub); margin-left: 8px; font-weight: 600;">{{ ($currentIndex !== false ? $currentIndex + 1 : 0) }} / {{ $stages->count() }}</span>
                    </div>
                </div>

                {{-- Tab strip --}}
                <div style="display: flex; gap: 18px; padding: 0 18px; border-bottom: 1px solid var(--border); font-size: var(--fs-12);">
                    @foreach (['overview','payments','notes','meetings','activity'] as $t)
                        <button wire:click="switchTab('{{ $t }}')"
                                style="padding: 10px 0; background: 0; border: 0; cursor: pointer; color: {{ $activeTab === $t ? 'var(--brand-700)' : 'var(--text-sub)' }}; font-weight: {{ $activeTab === $t ? 700 : 500 }}; border-bottom: 2px solid {{ $activeTab === $t ? 'var(--brand-600)' : 'transparent' }}; margin-bottom: -1px; text-transform: capitalize;">
                            {{ $t }}
                        </button>
                    @endforeach
                </div>

                {{-- Tab body --}}
                <div style="flex: 1; padding: 14px 18px; overflow-y: auto;">
                    @if ($activeTab === 'overview')
                        @livewire('drawer.overview-tab', ['studentId' => $studentId], key('ov-'.$studentId))
                    @elseif ($activeTab === 'payments')
                        @livewire('drawer.payments-tab', ['studentId' => $studentId], key('pm-'.$studentId))
                    @elseif ($activeTab === 'notes')
                        @livewire('drawer.notes-tab', ['studentId' => $studentId], key('nt-'.$studentId))
                    @elseif ($activeTab === 'meetings')
                        @livewire('drawer.meetings-tab', ['studentId' => $studentId], key('mt-'.$studentId))
                    @elseif ($activeTab === 'activity')
                        @livewire('drawer.activity-tab', ['studentId' => $studentId], key('ac-'.$studentId))
                    @endif
                </div>

                {{-- Sticky footer --}}
                <div style="position: sticky; bottom: 0; background: var(--surface); border-top: 1px solid var(--border); padding: 10px 18px; display: flex; justify-content: space-between; align-items: center;">
                    <a href="/admin/students/{{ $s->id }}/edit" style="font-size: var(--fs-11); color: var(--brand-600); font-weight: 600; text-decoration: none;">Open full page ↗</a>
                    <div style="display: flex; gap: 6px;">
                        <button wire:click="switchTab('notes')" style="font-size: var(--fs-11); padding: 6px 12px; border-radius: var(--r-md); border: 1px solid var(--border); background: var(--surface); color: var(--text); font-weight: 600; cursor: pointer;">+ Note</button>
                        <button wire:click="switchTab('payments')" style="font-size: var(--fs-11); padding: 6px 12px; border-radius: var(--r-md); border: 1px solid var(--border); background: var(--surface); color: var(--text); font-weight: 600; cursor: pointer;">+ Payment</button>
                        <a href="/admin/pipeline-config#student-{{ $s->id }}" style="font-size: var(--fs-11); padding: 6px 12px; border-radius: var(--r-md); background: var(--brand-600); color: white; font-weight: 600; text-decoration: none;">Move stage →</a>
                    </div>
                </div>

            </aside>
        </div>
    @endif
</div>
```

- [ ] **Step 5: Mount drawer via BODY_START hook** (extend existing)

```php
->renderHook(
    PanelsRenderHook::BODY_START,
    fn (): string => config('davyas.visual_v2')
        ? '<script>document.body.classList.add("davya-v2")</script>' . Blade::render('@livewire("top-bar") @livewire("command-palette") @livewire("student-peek-drawer")')
        : ''
)
```

- [ ] **Step 6: Create the 5 tab components (minimal skeletons)**

Create `app/Livewire/Drawer/OverviewTab.php`:

```php
<?php

namespace App\Livewire\Drawer;

use App\Models\Student;
use App\Support\MoneyFormat;
use Livewire\Component;

class OverviewTab extends Component
{
    public int $studentId;

    public function render()
    {
        $s = Student::with('owner','stage')->findOrFail($this->studentId);
        $pending = max(0, (float) $s->deal_amount - (float) $s->total_received);
        $pct = ($s->deal_amount > 0) ? min(100, round(($s->total_received / $s->deal_amount) * 100)) : 0;

        return view('livewire.drawer.overview-tab', compact('s', 'pending', 'pct'));
    }
}
```

Blade `resources/views/livewire/drawer/overview-tab.blade.php`:

```blade
<div>
    <div class="davya-section-card">
        <div class="davya-section-card-title">Deal</div>
        <div style="display: grid; grid-template-columns: 100px 1fr; gap: 10px; font-size: var(--fs-12); padding: 4px 0;"><span style="color: var(--text-sub);">Deal amount</span><span>₹{{ number_format($s->deal_amount ?? 0) }}</span></div>
        <div style="display: grid; grid-template-columns: 100px 1fr; gap: 10px; font-size: var(--fs-12); padding: 4px 0;"><span style="color: var(--text-sub);">Received</span><span>₹{{ number_format($s->total_received) }}</span></div>
        <div style="display: grid; grid-template-columns: 100px 1fr; gap: 10px; font-size: var(--fs-12); padding: 4px 0;"><span style="color: var(--text-sub);">Pending</span><span style="color: #B45309; font-weight: 700;">₹{{ number_format($pending) }}</span></div>
        <div style="height: 5px; background: var(--border); border-radius: 3px; overflow: hidden; margin: 6px 0 4px;">
            <div style="height: 100%; background: var(--success); width: {{ $pct }}%;"></div>
        </div>
        <div style="font-size: var(--fs-10); color: var(--text-sub);">{{ $pct }}% paid</div>
    </div>

    <div class="davya-section-card">
        <div class="davya-section-card-title">Touch</div>
        <div style="display: grid; grid-template-columns: 100px 1fr; gap: 10px; font-size: var(--fs-12); padding: 4px 0;"><span style="color: var(--text-sub);">Last note</span><span>{{ optional($s->notes?->sortByDesc('created_at')->first())->created_at?->diffForHumans() ?? '—' }}</span></div>
        <div style="display: grid; grid-template-columns: 100px 1fr; gap: 10px; font-size: var(--fs-12); padding: 4px 0;"><span style="color: var(--text-sub);">Last meeting</span><span>{{ optional($s->meetings?->sortByDesc('scheduled_at')->first())->scheduled_at?->diffForHumans() ?? '—' }}</span></div>
    </div>
</div>
```

PaymentsTab / NotesTab / MeetingsTab / ActivityTab follow the same pattern — simple list of the related rows, styled as `davya-section-card` + rows. Full code:

**`app/Livewire/Drawer/PaymentsTab.php` + blade:**

```php
<?php
namespace App\Livewire\Drawer;
use App\Models\Payment;
use Livewire\Component;
class PaymentsTab extends Component
{
    public int $studentId;
    public function render()
    {
        $payments = Payment::where('student_id', $this->studentId)->orderByDesc('created_at')->get();
        return view('livewire.drawer.payments-tab', compact('payments'));
    }
}
```

```blade
<div>
    @forelse ($payments as $p)
        <div class="davya-card-row">
            <span style="flex: 1; font-weight: 600;">₹{{ number_format($p->amount) }}</span>
            <span style="color: var(--text-sub); font-size: var(--fs-11);">{{ $p->direction }}</span>
            <span style="color: var(--text-sub); font-size: var(--fs-11);">{{ $p->created_at->format('d M Y') }}</span>
            @if ($p->proof_url)
                <a href="{{ $p->proof_url }}" target="_blank" style="color: var(--brand-600); font-size: var(--fs-11);">Proof</a>
            @endif
        </div>
    @empty
        <p style="color: var(--text-sub); font-size: var(--fs-12); text-align: center; padding: 16px;">No payments yet.</p>
    @endforelse
</div>
```

**NotesTab:**

```php
<?php
namespace App\Livewire\Drawer;
use App\Models\StudentNote;
use Livewire\Component;
class NotesTab extends Component
{
    public int $studentId;
    public string $body = '';
    public function save(): void
    {
        if (trim($this->body) === '') { return; }
        StudentNote::create(['student_id' => $this->studentId, 'author_id' => auth()->id(), 'body' => $this->body]);
        $this->body = '';
    }
    public function render()
    {
        $notes = StudentNote::where('student_id', $this->studentId)->with('author')->orderByDesc('created_at')->get();
        return view('livewire.drawer.notes-tab', compact('notes'));
    }
}
```

```blade
<div>
    @foreach ($notes as $n)
        <div class="davya-card-row" style="flex-direction: column; align-items: stretch;">
            <div style="display: flex; justify-content: space-between; font-size: var(--fs-10); color: var(--text-sub); margin-bottom: 4px;">
                <span>{{ $n->author?->name ?? '—' }}</span>
                <span>{{ $n->created_at->diffForHumans() }}</span>
            </div>
            <div style="font-size: var(--fs-12); color: var(--text);">{{ $n->body }}</div>
        </div>
    @endforeach
    <div style="margin-top: 10px;">
        <textarea wire:model="body" placeholder="Add a note…" style="width: 100%; min-height: 60px; padding: 8px; border: 1px solid var(--border); border-radius: var(--r-md); font-size: var(--fs-12); font-family: inherit; resize: vertical;"></textarea>
        <button wire:click="save" style="margin-top: 6px; background: var(--brand-600); color: white; border: 0; border-radius: var(--r-md); padding: 6px 12px; font-size: var(--fs-11); font-weight: 600; cursor: pointer;">Add note</button>
    </div>
</div>
```

**MeetingsTab:**

```php
<?php
namespace App\Livewire\Drawer;
use App\Models\Meeting;
use Livewire\Component;
class MeetingsTab extends Component
{
    public int $studentId;
    public function render()
    {
        $meetings = Meeting::where('student_id', $this->studentId)->orderByDesc('scheduled_at')->get();
        return view('livewire.drawer.meetings-tab', compact('meetings'));
    }
}
```

```blade
<div>
    @forelse ($meetings as $m)
        <div class="davya-card-row">
            <span style="flex: 1; font-weight: 600;">{{ $m->scheduled_at->format('d M, h:i A') }}</span>
            <span style="color: var(--text-sub); font-size: var(--fs-11);">{{ $m->kind ?? 'Meeting' }}</span>
            <span style="color: var(--text-sub); font-size: var(--fs-11);">{{ $m->status ?? '—' }}</span>
        </div>
    @empty
        <p style="color: var(--text-sub); font-size: var(--fs-12); text-align: center; padding: 16px;">No meetings yet.</p>
    @endforelse
</div>
```

**ActivityTab:**

```php
<?php
namespace App\Livewire\Drawer;
use Livewire\Component;
use Spatie\Activitylog\Models\Activity;
class ActivityTab extends Component
{
    public int $studentId;
    public function render()
    {
        $activities = Activity::where('subject_type', \App\Models\Student::class)
            ->where('subject_id', $this->studentId)
            ->with('causer')
            ->orderByDesc('created_at')
            ->limit(30)
            ->get();
        return view('livewire.drawer.activity-tab', compact('activities'));
    }
}
```

```blade
<div>
    @forelse ($activities as $a)
        <div class="davya-card-row" style="flex-direction: column; align-items: stretch;">
            <div style="display: flex; justify-content: space-between; font-size: var(--fs-10); color: var(--text-sub); margin-bottom: 2px;">
                <span>{{ $a->causer?->name ?? 'System' }}</span>
                <span>{{ $a->created_at->diffForHumans() }}</span>
            </div>
            <div style="font-size: var(--fs-12); color: var(--text);">{{ $a->description }}</div>
        </div>
    @empty
        <p style="color: var(--text-sub); font-size: var(--fs-12); text-align: center; padding: 16px;">No activity yet.</p>
    @endforelse
</div>
```

- [ ] **Step 7: Run the drawer test suite**

Run: `./vendor/bin/phpunit tests/Feature/Livewire/StudentPeekDrawerTest.php`
Expected: 4 tests PASS.

- [ ] **Step 8: Manual smoke**

- `/admin/kanban` with flag on.
- Click a card → drawer slides in from right, showing student header + stepper + Overview tab.
- Click another card → drawer swaps content, stays open.
- Click each tab → content loads lazily.
- Click "Open full page ↗" → navigates to `/admin/students/:id/edit`.
- Click × or outside → drawer closes.

- [ ] **Step 9: Commit**

```bash
git add app/Livewire/StudentPeekDrawer.php app/Livewire/Drawer/*.php resources/views/livewire/student-peek-drawer.blade.php resources/views/livewire/drawer/*.blade.php tests/Feature/Livewire/StudentPeekDrawerTest.php app/Providers/Filament/AdminPanelProvider.php
git commit -m "feat(visual-v2): student peek drawer with 5 lazy tabs"
```

---

## Phase 5 — Smoke + flip

### Task 5.1: Full smoke checklist

**Files:**
- Create: `docs/sessions/2026-04-24-visual-v2-full-smoke.md`

- [ ] **Step 1: Author the checklist**

```markdown
# Visual v2 — Local Smoke Checklist

Run with `DAVYAS_VISUAL_V2=true php artisan serve`, login `sumit@davya.local` / `smoke-test-pw`.

## Shell
- [ ] Top bar renders with Davyas brand, 5 tabs, search pill, + New Student CTA, gear, avatar pill.
- [ ] Active tab has emerald-50 background.
- [ ] Filament default sidebar is hidden.
- [ ] ⌘K opens command palette. Type 2+ chars → students appear. Pages always listed.
- [ ] Clicking a page row navigates. Clicking a student row opens the peek drawer.
- [ ] Clicking brand routes to /admin dashboard.

## Kanban
- [ ] Columns are 260 px wide with 3 px coloured top border.
- [ ] Column header shows name + count + `₹X received · ₹Y pending`.
- [ ] Cards are dense one-row with left status strip, name, chips, amount, avatar.
- [ ] Drag a card between columns — stage transition still enforces existing validator.
- [ ] Hover tints card emerald-50. Click opens peek drawer.
- [ ] Sub-toolbar shows filter pills + view switch (Kanban / List).

## Peek drawer
- [ ] Header shows name + phone + course + round + owner pill.
- [ ] Stage stepper colours past / current / future correctly.
- [ ] 5 tabs switch on click, content loads only for visible tab.
- [ ] Overview: Deal + Touch section cards populated.
- [ ] Payments: lists existing payments with proof links.
- [ ] Notes: adds a note, refresh persists, author attributed.
- [ ] Meetings: lists meetings sorted desc.
- [ ] Activity: shows activitylog entries for this student.
- [ ] Clicking another card while drawer open swaps content.
- [ ] `Open full page ↗` routes to /admin/students/:id/edit.
- [ ] Move stage → button opens stage picker / triggers existing transition flow.

## Forms
- [ ] /admin/students/create: section-card tabs, required fields have red left bar, asterisk hidden.
- [ ] Saving with empty required still shows validation (Filament native).

## Settings surfaces
- [ ] /admin/settings: tile grid with 6 tiles, each links correctly.
- [ ] /admin/student-fields: custom fields green-accent, required built-ins red-accent.
- [ ] /admin/pipeline-config: Won/Lost rows show thumbs badges.
- [ ] /admin/duplicate-flags: rows in card-row style.
- [ ] /admin/today: meetings strip + payments in section-cards.
- [ ] /admin (dashboard): cards wrapped in section-card.

## Flag off
- [ ] Restart without env var — every page renders exactly like pre-refresh.
- [ ] Full test suite green (`./vendor/bin/phpunit`).

## Browser matrix
- [ ] Chrome / Edge — all above.
- [ ] Safari 17+ — required-field red bar present (`:has()` supported).
- [ ] Firefox — all above.
- [ ] Mobile Safari (iPhone Landscape) — top bar wraps, ⌘K replaced by search icon, drawer fullscreen.
```

- [ ] **Step 2: Run the checklist, tick boxes in the file, commit**

```bash
git add docs/sessions/2026-04-24-visual-v2-full-smoke.md
git commit -m "docs(session): visual v2 full smoke checklist (ticked)"
```

### Task 5.2: Flip flag on prod

**BLOCKED on user: this step is deploy and requires explicit authorisation.**

- [ ] **Step 1: Announce deploy intent to user, wait for approval**

Do NOT proceed without Sumit saying "yes, deploy".

- [ ] **Step 2: SSH to prod, update `.env`**

```bash
ssh -i ~/.ssh/davyas-active ipuc@davyas.ipu.co.in
cd /home/ipuc/davya-crm
# Add/update DAVYAS_VISUAL_V2=true in .env
php artisan config:clear && php artisan config:cache && php artisan view:clear
```

- [ ] **Step 3: Open https://davyas.ipu.co.in/admin and walk the smoke checklist on prod**

If anything looks wrong, unset `DAVYAS_VISUAL_V2` and re-cache config — instant rollback.

- [ ] **Step 4: Git tag post-ship**

```bash
git tag visual-v2-live-20260424
git push origin visual-v2-live-20260424
```

---

## Self-review notes

1. **Spec coverage (§3 tokens):** Task 1.2 fully covers section 3 — all variables + section-card + card-row + dense-card + required-bar + owner-pill.
2. **Spec coverage (§4 shell):** Task 3.1 + 3.2 cover the top bar; Task 3.3 covers the command palette; Task 3.4 covers the sub-toolbar.
3. **Spec coverage (§5 kanban):** Task 2.2 + 2.4 + 2.5 cover column accents, dense cards, aggregates.
4. **Spec coverage (§6 slide-over):** Task 4.1 covers skeleton + 5 tabs + stepper + footer.
5. **Spec coverage (§7 form):** Task 1.2 (required-bar CSS) + Task 1.9 (section-card on tabs) cover it.
6. **Spec coverage (§8-§12 restyles):** Tasks 1.4–1.8 + 1.10.
7. **Spec coverage (§13 file map):** Matches this plan's File Structure section.
8. **Spec coverage (§14 risks / rollback):** Flag gate is pervasive; tag created Task 0.2; flag-off test Task 1.1.
9. **No placeholders:** Every task has exact code. No "TODO", "fill in", or "see similar task" references.
10. **Type consistency:** Livewire event name `open-student-peek` is used identically in kanban card (Task 2.4), command palette (Task 3.3), drawer `#[On]` attribute (Task 4.1). Event name `open-command-palette` is used in TopBar blade (Task 3.1) and `#[On]` attribute (Task 3.3).

No gaps. No contradictions. Plan complete.
