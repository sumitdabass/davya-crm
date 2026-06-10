# Pipeline (kanban) Mobile-First Redesign — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make `/admin/kanban` mobile-first in the davya cream skin — desktop keeps the multi-column drag board (re-skinned only); phones get a stage-pill switcher + a Guided "⤳ Move" bottom sheet (tap-to-move) — with ZERO feature/data regressions.

**Architecture:** One Livewire page (`App\Filament\Pages\KanbanBoard`), one `getBoard()` payload, two presentations swapped by a CSS `@media (max-width: 767px)` breakpoint. Desktop = the existing `.davya-kanban-col` board + Sortable.js (cream-skinned via scoped CSS). Mobile = new Alpine-driven blocks (pill switcher showing one stage's cards at a time, a ⤳ Move pill per card opening a Guided action sheet, and a Filters bottom sheet) — all rendered from the same `$board` data and reusing the existing `moveStudentToStage()` / `fixAndMove()` / `open-fix-modal` flow. No new queries, no DB changes.

**Tech Stack:** Laravel 11, Filament 3.3.50, Livewire 3, Alpine.js, Sortable.js, Pest/PHPUnit. Prod has `DAVYAS_VISUAL_V2=true` (verified) so the v2 board is the active path.

---

## Pre-flight (read before Task 1)

- Branch: create `feat/pipeline-mobile-redesign` off `main` before Task 1.
- Run tests with `php -d memory_limit=2048M vendor/bin/phpunit` (the env OOMs `php artisan test` at 128M). One test: `php -d memory_limit=1024M vendor/bin/phpunit --filter <Name>`. "deprecated"/PDO markers are env noise — only `failed`/`errored` counts matter.
- Login in tests: `$this->seed();` then `User::where('email','sumit@davya.local')->first()`, set `must_change_password=false`, `actingAs(...)` (copy from `tests/Feature/KanbanBoardTest.php`).
- The skin is purely additive + scoped under `body.davya-pipeline-skin`; the body class loads only on `/admin/kanban`. Do NOT gate on `config('davyas.visual_v2')` — the scope class is the gate. (`visual_v2` is already true in prod; the existing v2 board markup is what we skin.)
- This is the 2nd consumer of the mobile-first kit (pilot = student form). Reuse the pilot's render-hook + cream-token + chip patterns; see `resources/css/student-form-skin.css`, `app/Providers/Filament/AdminPanelProvider.php` (the `PAGE_START` hook scoped to CreateStudent/EditStudent), and `reference_filament_togglebuttons_selected_css` (chip selected-state must use `input:checked + label.fi-btn`).

## File Structure

| File | Create/Modify | Responsibility |
|---|---|---|
| `resources/css/pipeline-skin.css` | Create | All Pipeline skin styling, scoped under `body.davya-pipeline-skin`: desktop board cream restyle + mobile switcher/card/sheet styles + the `@media (max-width:767px)` desktop/mobile toggle. |
| `public/css/pipeline-skin.css` | Create (copy) | Served asset (mirror `student-form-skin.css`). |
| `app/Providers/Filament/AdminPanelProvider.php` | Modify | Add a `PAGE_START` render hook scoped to `[KanbanBoard::class]`: link pipeline-skin.css + add the `davya-pipeline-skin` body class. |
| `app/Filament/Pages/KanbanBoard.php` | Modify | Add `orderedStageNames(): array` (display-order stage names, for the mobile switcher + Guided-sheet next/prev). |
| `resources/views/filament/pages/kanban-board.blade.php` | Modify | Add mobile blocks (pill switcher, single-stage card list with ⤳ Move pill, Guided move sheet, Filters bottom sheet) + viewport classes; guard Sortable init to desktop only. Desktop board markup unchanged. |
| `tests/Feature/MobilePipeline/PipelineSkinScopeTest.php` | Create (Test) | Skin CSS + body class present on `/admin/kanban`, absent on another admin page. |
| `tests/Feature/MobilePipeline/OrderedStagesTest.php` | Create (Test) | `orderedStageNames()` returns all stages in `display_order`. |
| `tests/Feature/MobilePipeline/MobilePipelineRenderTest.php` | Create (Test) | Mobile pill switcher renders every stage with its count; move-via-page still routes through StageTransitionEngine (hard block still blocks). |

---

## Phase 0 — Scoped skin infrastructure

### Task 1: Branch + create the scoped skin stylesheet skeleton

**Files:**
- Create: `resources/css/pipeline-skin.css`

- [ ] **Step 1: Create the branch**

Run: `git checkout main && git checkout -b feat/pipeline-mobile-redesign`
Expected: switched to a new branch.

- [ ] **Step 2: Write the skin stylesheet** — Create `resources/css/pipeline-skin.css`. The cream token block is copied from `student-form-skin.css` (shared kit); the rest is Pipeline-specific. (Mobile component rules are filled in Tasks 5–7; this establishes the file + tokens + desktop restyle.)

```css
/* Pipeline (kanban) mobile-first skin — every rule scoped under body.davya-pipeline-skin.
   Inert on every other page (class added only on /admin/kanban). */
@import url("https://fonts.googleapis.com/css2?family=Instrument+Serif:ital@0;1&family=Bricolage+Grotesque:opsz,wght@12..96,300..800&family=JetBrains+Mono:wght@400;500;600&display=swap");

body.davya-pipeline-skin {
  --paper:#f4efe4; --paper-2:#efe8d8; --card:#fbf8f0; --field:#fffdf8;
  --ink:#16231c; --ink-soft:#3c4a40; --muted:#8c8475; --faint:#b7af9d;
  --emerald:#0b5d40; --emerald-deep:#063a28; --emerald-bright:#15835c;
  --vermilion:#e0431c; --vermilion-deep:#b8330f; --amber:#c2861a;
  --line:rgba(22,35,28,.14); --line-soft:rgba(22,35,28,.07); --r:9px;
}

/* ---- Desktop board cream restyle (layout unchanged; existing .davya-kanban-col / .davya-dense-card) ---- */
body.davya-pipeline-skin .fi-kanban-scroll { background:var(--paper); border-radius:14px; padding:12px; }
body.davya-pipeline-skin .davya-kanban-col {
  background:var(--card); border:1px solid var(--line); border-radius:var(--r);
}
body.davya-pipeline-skin .davya-kanban-col-head h4 { font-family:"Instrument Serif",serif; font-weight:400; font-size:18px; color:var(--ink); }
body.davya-pipeline-skin .davya-kanban-col-count { font-family:"JetBrains Mono",monospace; }
body.davya-pipeline-skin .davya-kanban-col-agg { font-family:"JetBrains Mono",monospace; color:var(--muted); }
body.davya-pipeline-skin .davya-dense-card { background:var(--field); border-color:#eee4d2; }
body.davya-pipeline-skin .davya-dense-card .amt { font-family:"JetBrains Mono",monospace; color:var(--emerald); }

/* ---- Mobile/desktop visibility toggle ---- */
body.davya-pipeline-skin .pl-mobile { display:none; }
@media (max-width: 767px) {
  body.davya-pipeline-skin .fi-kanban-scroll { display:none; }   /* hide desktop drag board */
  body.davya-pipeline-skin .davya-subtoolbar { display:none; }    /* hide wide toolbar */
  body.davya-pipeline-skin .pl-mobile { display:block; }          /* show mobile blocks */
}

/* ---- Mobile component styles are added in Tasks 5–7 below this line ---- */
```

- [ ] **Step 3: Mirror to served path**

Run: `cp resources/css/pipeline-skin.css public/css/pipeline-skin.css`
Expected: copied, no output.

- [ ] **Step 4: Commit**

```bash
git add resources/css/pipeline-skin.css public/css/pipeline-skin.css
git commit -m "feat(pipeline): add scoped mobile-first skin stylesheet skeleton"
```

---

### Task 2: Load the skin + body class only on the kanban page

**Files:**
- Modify: `app/Providers/Filament/AdminPanelProvider.php` (next to the existing student-form `PAGE_START` hook, ~lines 62–69)
- Test: `tests/Feature/MobilePipeline/PipelineSkinScopeTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\MobilePipeline;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PipelineSkinScopeTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $this->seed();
        $u = User::where('email', 'sumit@davya.local')->first();
        $u->update(['must_change_password' => false]);

        return $u;
    }

    public function test_skin_loads_on_kanban_page(): void
    {
        $this->actingAs($this->admin())
            ->get('/admin/kanban')
            ->assertOk()
            ->assertSee('pipeline-skin.css', false)
            ->assertSee('davya-pipeline-skin', false);
    }

    public function test_skin_absent_on_students_list(): void
    {
        $this->actingAs($this->admin())
            ->get('/admin/students')
            ->assertOk()
            ->assertDontSee('pipeline-skin.css', false);
    }
}
```

- [ ] **Step 2: Run it to verify it fails**

Run: `php -d memory_limit=1024M vendor/bin/phpunit --filter PipelineSkinScopeTest`
Expected: FAIL — `test_skin_loads_on_kanban_page` doesn't see `pipeline-skin.css`.

- [ ] **Step 3: Register the scoped render hook.** In `app/Providers/Filament/AdminPanelProvider.php`, add the import near the other page imports:

```php
use App\Filament\Pages\KanbanBoard;
```

Then add a second `->renderHook(...)` immediately after the existing student-form hook (the one scoped to `[CreateStudent::class, EditStudent::class]`):

```php
            ->renderHook(
                PanelsRenderHook::PAGE_START,
                fn (): string => <<<'HTML'
                    <link rel="stylesheet" href="/css/pipeline-skin.css?v=1" id="davya-pipeline-skin-css">
                    <script>document.body.classList.add('davya-pipeline-skin');</script>
                    HTML,
                scopes: [KanbanBoard::class],
            )
```

- [ ] **Step 4: Run it to verify it passes**

Run: `php -d memory_limit=1024M vendor/bin/phpunit --filter PipelineSkinScopeTest`
Expected: PASS (both tests).

- [ ] **Step 5: Commit**

```bash
git add app/Providers/Filament/AdminPanelProvider.php tests/Feature/MobilePipeline/PipelineSkinScopeTest.php
git commit -m "feat(pipeline): load scoped skin + body class only on /admin/kanban"
```

---

## Phase 1 — Desktop re-skin verification

### Task 3: Confirm the desktop board still works under the skin

**Files:** none (verification only — the cream restyle CSS shipped in Task 1; this task proves no regression).

- [ ] **Step 1: Run the full existing kanban suite**

Run: `php -d memory_limit=2048M vendor/bin/phpunit --filter Kanban`
Expected: PASS — KanbanBoardTest, KanbanAggregateTest, KanbanSoftWarningsTest, KanbanBoardAccessTest, KanbanDynamicStagesTest all green (the skin is CSS-only; no PHP touched yet beyond the render hook).

- [ ] **Step 2: Manual desktop smoke** (≥768px viewport)

```bash
php artisan serve &
# visit http://127.0.0.1:8000/dev-login then /admin/kanban at desktop width:
#  - board renders cream, columns + cards styled, aggregates in mono
#  - drag a card between columns still moves it (Sortable works)
#  - hard-block move still opens the fix-up modal
```
Expected: board visually re-skinned, drag-to-move + fix modal unchanged. Note any issues; if the cream restyle breaks layout, fix the CSS in `pipeline-skin.css` (+ mirror to public) and re-smoke.

- [ ] **Step 3: Commit (only if Step 2 required CSS fixes)**

```bash
git add resources/css/pipeline-skin.css public/css/pipeline-skin.css
git commit -m "style(pipeline): desktop board cream restyle fixes"
```

---

## Phase 2 — Ordered-stage helper

### Task 4: Add `orderedStageNames()` to KanbanBoard

**Files:**
- Modify: `app/Filament/Pages/KanbanBoard.php` (add a public method)
- Test: `tests/Feature/MobilePipeline/OrderedStagesTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\MobilePipeline;

use App\Filament\Pages\KanbanBoard;
use App\Services\Pipeline\PipelineConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrderedStagesTest extends TestCase
{
    use RefreshDatabase;

    public function test_ordered_stage_names_match_pipeline_display_order(): void
    {
        $this->seed();

        $expected = app(PipelineConfig::class)->stageNames(); // already display_order

        $names = (new KanbanBoard())->orderedStageNames();

        $this->assertSame($expected, $names);
        $this->assertNotEmpty($names);
        // The Guided sheet computes next = index+1, back = index-1 in JS from this array,
        // so display order is the contract this test guards.
    }
}
```

- [ ] **Step 2: Run it to verify it fails**

Run: `php -d memory_limit=1024M vendor/bin/phpunit --filter OrderedStagesTest`
Expected: FAIL — `orderedStageNames()` does not exist.

- [ ] **Step 3: Add the method.** In `app/Filament/Pages/KanbanBoard.php`, add a public method (near `getBoard()`):

```php
    /**
     * Pipeline stage names in display order — drives the mobile stage-pill switcher
     * and the Guided move sheet's next/back resolution (next = index+1, back = index-1).
     *
     * @return string[]
     */
    public function orderedStageNames(): array
    {
        return app(\App\Services\Pipeline\PipelineConfig::class)->stageNames();
    }
```

- [ ] **Step 4: Run it to verify it passes**

Run: `php -d memory_limit=1024M vendor/bin/phpunit --filter OrderedStagesTest`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Filament/Pages/KanbanBoard.php tests/Feature/MobilePipeline/OrderedStagesTest.php
git commit -m "feat(pipeline): orderedStageNames() helper for mobile switcher"
```

---

## Phase 3 — Mobile stage switcher

### Task 5: Mobile pill switcher + single-stage card list

**Files:**
- Modify: `resources/views/filament/pages/kanban-board.blade.php` (add a mobile block inside the `@if (config('davyas.visual_v2'))` region, AFTER the existing `.fi-kanban-scroll` desktop board `</div>`; and guard Sortable to desktop)
- Modify: `resources/css/pipeline-skin.css` (+ public mirror) — add switcher styles
- Test: `tests/Feature/MobilePipeline/MobilePipelineRenderTest.php`

- [ ] **Step 1: Write the failing test** (asserts the mobile switcher renders every stage + its count)

```php
<?php

namespace Tests\Feature\MobilePipeline;

use App\Models\Student;
use App\Models\User;
use App\Services\Pipeline\PipelineConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MobilePipelineRenderTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $this->seed();
        $u = User::where('email', 'sumit@davya.local')->first();
        $u->update(['must_change_password' => false]);
        $this->actingAs($u);

        return $u;
    }

    public function test_mobile_switcher_renders_every_stage(): void
    {
        $this->admin();
        $html = $this->get('/admin/kanban')->assertOk()->getContent();

        // The mobile switcher block is present (CSS hides it >=768px, but it's in the DOM).
        $this->assertStringContainsString('pl-switcher', $html);
        foreach (app(PipelineConfig::class)->stageNames() as $name) {
            $this->assertStringContainsString($name, $html);
        }
    }
}
```

- [ ] **Step 2: Run it to verify it fails**

Run: `php -d memory_limit=1024M vendor/bin/phpunit --filter MobilePipelineRenderTest`
Expected: FAIL — `pl-switcher` not found (stage names already appear in the desktop board, but the `pl-switcher` assertion fails).

- [ ] **Step 3: Add the mobile block to the blade.** In `resources/views/filament/pages/kanban-board.blade.php`, locate the desktop board container `<div class="fi-kanban-scroll overflow-x-auto pb-4" ... x-data ...>` (line ~174) and its closing `</div>`. Immediately AFTER that closing `</div>` (still inside the `@if (config('davyas.visual_v2'))` block), insert the mobile block:

```blade
        {{-- ===== MOBILE (<768px): stage switcher + tap-to-move. Same $board data. ===== --}}
        <div class="pl-mobile" x-data="kanbanMobile(@js($this->orderedStageNames()))">
            <div class="pl-switcher" x-ref="switcher">
                @foreach ($board as $col)
                    <button type="button"
                            class="pl-pill"
                            data-stage="{{ $col['stage'] }}"
                            :class="active === @js($col['stage']) ? 'on' : ''"
                            x-on:click="setActive(@js($col['stage']), $event.target)">
                        {{ $col['stage'] }}
                        <span class="c">{{ $col['count'] }}</span>
                    </button>
                @endforeach
            </div>

            @foreach ($board as $col)
                <div class="pl-stage" data-stage="{{ $col['stage'] }}" x-show="active === @js($col['stage'])" x-cloak>
                    <div class="pl-agg">
                        ₹{{ \App\Support\MoneyFormat::indianShort($col['deal']) }} deal ·
                        ₹{{ \App\Support\MoneyFormat::indianShort($col['received_total']) }} recd ·
                        ₹{{ \App\Support\MoneyFormat::indianShort($col['pending_total']) }} pend
                    </div>

                    @forelse ($col['students'] as $s)
                        @php($age = (int) ($s['days_in_stage'] ?? 0))
                        @php($ageDotColor = $age <= 3 ? '#10B981' : ($age <= 14 ? '#F59E0B' : '#EF4444'))
                        <div class="pl-card" data-response="{{ $s['student_response'] ?? 'unknown' }}"
                             wire:key="m-card-{{ $s['id'] }}">
                            <div class="pl-card-main"
                                 wire:click="$dispatch('open-student-peek', { studentId: {{ $s['id'] }} })">
                                <div class="nm"><span class="dot" style="background: {{ $ageDotColor }};"></span>{{ $s['name'] }}</div>
                                <div class="sub">{{ $s['course'] ?? '—' }}@if($s['current_round']) · R{{ $s['current_round'] }}@endif
                                    <span class="amt">₹{{ \App\Support\MoneyFormat::indianShort($s['received'] ?? 0) }}</span></div>
                            </div>
                            <button type="button" class="pl-move"
                                    x-on:click.stop="openMove({{ $s['id'] }}, @js($s['name']), @js($col['stage']))">⤳ Move</button>
                        </div>
                    @empty
                        <div class="pl-empty">No leads in this stage.</div>
                    @endforelse
                </div>
            @endforeach

            @include('filament.pages.partials.kanban-move-sheet')
        </div>
```

(`kanban-move-sheet` is created in Task 6. For now create an empty partial so the include resolves: `resources/views/filament/pages/partials/kanban-move-sheet.blade.php` containing only a blade comment `{{-- move sheet added in Task 6 --}}`.)

- [ ] **Step 4: Add the Alpine component + guard Sortable to desktop.** In the same blade's `<script>` block (where `wireKanban` is defined, ~line 335), add at the TOP of `wireKanban(root, wire)` a viewport guard so touch drag never binds on mobile:

```javascript
        function wireKanban(root, wire) {
            if (window.matchMedia('(max-width: 767px)').matches) { return; } // mobile uses the move sheet, not drag
            root.querySelectorAll('.fi-kanban-col-items').forEach((el) => {
```

Then add the `kanbanMobile` Alpine factory in the same `<script>` block (after `wireKanban`):

```javascript
        function kanbanMobile(stages) {
            return {
                stages: stages,              // ordered stage names
                active: stages[0] ?? null,
                move: { open: false, id: null, name: '', from: '', next: null, prev: null },
                setActive(stage, btn) {
                    this.active = stage;
                    if (btn && btn.scrollIntoView) btn.scrollIntoView({ inline: 'center', block: 'nearest', behavior: 'smooth' });
                },
                openMove(id, name, from) {
                    const i = this.stages.indexOf(from);
                    this.move = {
                        open: true, id, name, from,
                        next: (i >= 0 && i < this.stages.length - 1) ? this.stages[i + 1] : null,
                        prev: (i > 0) ? this.stages[i - 1] : null,
                    };
                },
                async go(target) {
                    const res = await this.$wire.call('moveStudentToStage', this.move.id, target);
                    this.move.open = false;
                    if (res && !res.ok && res.missing_fields && res.missing_fields.length > 0) {
                        window.dispatchEvent(new CustomEvent('open-fix-modal', { detail: {
                            studentId: res.student_id, studentName: res.student_name,
                            targetStage: res.target_stage, missingFields: res.missing_fields,
                        }}));
                    }
                },
            };
        }
```

(The `go()` result-handling mirrors the existing desktop `onEnd` logic — same `moveStudentToStage` call, same `open-fix-modal` dispatch shape, reusing the existing fix-up modal. A successful move re-renders the board via Livewire.)

- [ ] **Step 5: Add mobile switcher CSS.** Append to `resources/css/pipeline-skin.css` (below the "Tasks 5–7" marker):

```css
[x-cloak] { display:none !important; }
body.davya-pipeline-skin .pl-switcher { display:flex; gap:6px; overflow-x:auto; scrollbar-width:none; padding:4px 0 10px; }
body.davya-pipeline-skin .pl-switcher::-webkit-scrollbar { display:none; }
body.davya-pipeline-skin .pl-pill {
  flex:0 0 auto; font:600 11px/1 "JetBrains Mono",monospace; white-space:nowrap;
  padding:8px 12px; border-radius:20px; border:1px solid var(--line); background:var(--card); color:var(--muted);
}
body.davya-pipeline-skin .pl-pill.on { background:var(--ink); color:var(--paper); border-color:var(--ink); }
body.davya-pipeline-skin .pl-pill .c { margin-left:5px; color:var(--faint); }
body.davya-pipeline-skin .pl-pill.on .c { color:var(--paper-2); }
body.davya-pipeline-skin .pl-agg { font:500 11px/1.5 "JetBrains Mono",monospace; color:var(--muted); padding:2px 2px 10px; }
body.davya-pipeline-skin .pl-card { display:flex; align-items:stretch; gap:8px; background:var(--field); border:1px solid #eee4d2; border-left:3px solid var(--emerald-bright); border-radius:var(--r); margin-bottom:8px; }
body.davya-pipeline-skin .pl-card[data-response="Not Interested"] { border-left-color:#EF4444; }
body.davya-pipeline-skin .pl-card[data-response="Needs Time"] { border-left-color:#F59E0B; }
body.davya-pipeline-skin .pl-card-main { flex:1; padding:10px 12px; min-width:0; }
body.davya-pipeline-skin .pl-card .nm { font:600 14px/1.2 "Bricolage Grotesque",sans-serif; color:var(--ink); display:flex; align-items:center; gap:7px; }
body.davya-pipeline-skin .pl-card .dot { width:7px; height:7px; border-radius:50%; flex:0 0 auto; }
body.davya-pipeline-skin .pl-card .sub { font:500 11px/1 "JetBrains Mono",monospace; color:var(--muted); margin-top:5px; display:flex; gap:8px; }
body.davya-pipeline-skin .pl-card .amt { color:var(--emerald); }
body.davya-pipeline-skin .pl-move { flex:0 0 auto; font:600 10px/1 "JetBrains Mono",monospace; color:var(--vermilion); background:transparent; border:none; border-left:1px solid var(--line); padding:0 12px; cursor:pointer; }
body.davya-pipeline-skin .pl-empty { font:italic 400 13px/1 "Instrument Serif",serif; color:var(--muted); padding:18px 2px; }
```

Then mirror: `cp resources/css/pipeline-skin.css public/css/pipeline-skin.css`

- [ ] **Step 6: Run the render test + full kanban suite**

Run: `php -d memory_limit=1024M vendor/bin/phpunit --filter MobilePipelineRenderTest`
Expected: PASS — `pl-switcher` + every stage name present.
Run: `php -d memory_limit=2048M vendor/bin/phpunit --filter Kanban`
Expected: PASS — no regression in the existing board/move/access tests.

- [ ] **Step 7: Commit**

```bash
git add resources/views/filament/pages/kanban-board.blade.php resources/views/filament/pages/partials/kanban-move-sheet.blade.php resources/css/pipeline-skin.css public/css/pipeline-skin.css tests/Feature/MobilePipeline/MobilePipelineRenderTest.php
git commit -m "feat(pipeline): mobile stage-pill switcher + single-stage card list"
```

---

## Phase 4 — Guided move sheet

### Task 6: Build the Guided move sheet partial

**Files:**
- Modify: `resources/views/filament/pages/partials/kanban-move-sheet.blade.php` (replace the placeholder created in Task 5)
- Modify: `resources/css/pipeline-skin.css` (+ public mirror) — add sheet styles

- [ ] **Step 1: Write the sheet markup.** Replace the contents of `resources/views/filament/pages/partials/kanban-move-sheet.blade.php` with (it lives inside the `kanbanMobile` Alpine scope from Task 5, so it reads `move.*` and calls `go()`):

```blade
{{-- Guided move sheet — inside the kanbanMobile() Alpine scope (Task 5). --}}
<div class="pl-sheet-backdrop" x-show="move.open" x-cloak x-on:click="move.open = false" x-transition.opacity></div>
<div class="pl-sheet" x-show="move.open" x-cloak x-transition>
    <div class="pl-sheet-h">Move <span x-text="move.name"></span> forward</div>

    <template x-if="move.next">
        <button type="button" class="pl-sheet-fwd" x-on:click="go(move.next)">
            → <span x-text="move.next"></span>
        </button>
    </template>

    <template x-if="move.prev">
        <button type="button" class="pl-sheet-row" x-on:click="go(move.prev)">
            ⤺ Back to <span x-text="move.prev"></span>
        </button>
    </template>

    <details class="pl-sheet-any">
        <summary>▾ Any stage</summary>
        <template x-for="st in stages" :key="st">
            <button type="button" class="pl-sheet-row"
                    :class="st === move.from ? 'cur' : ''"
                    :disabled="st === move.from"
                    x-on:click="go(st)">
                <span x-text="st"></span>
                <span class="c" x-show="st === move.from">current</span>
            </button>
        </template>
    </details>

    <button type="button" class="pl-sheet-cancel" x-on:click="move.open = false">Cancel</button>
</div>
```

- [ ] **Step 2: Add the sheet CSS.** Append to `resources/css/pipeline-skin.css`:

```css
body.davya-pipeline-skin .pl-sheet-backdrop { position:fixed; inset:0; background:rgba(22,35,28,.4); z-index:60; }
body.davya-pipeline-skin .pl-sheet {
  position:fixed; left:0; right:0; bottom:0; z-index:61; background:var(--card);
  border-top:1px solid var(--line); border-radius:16px 16px 0 0; padding:16px 16px calc(16px + env(safe-area-inset-bottom));
  box-shadow:0 -12px 30px -18px rgba(22,35,28,.5); max-height:80vh; overflow-y:auto;
}
body.davya-pipeline-skin .pl-sheet-h { font:italic 400 18px/1 "Instrument Serif",serif; color:var(--ink); margin-bottom:14px; }
body.davya-pipeline-skin .pl-sheet-fwd { display:block; width:100%; text-align:center; padding:14px; border:none; border-radius:10px; background:var(--emerald); color:#fff; font:600 15px/1 "Bricolage Grotesque",sans-serif; margin-bottom:8px; cursor:pointer; }
body.davya-pipeline-skin .pl-sheet-row { display:flex; width:100%; justify-content:space-between; align-items:center; padding:12px 14px; border:1px solid var(--line); border-radius:9px; background:var(--field); color:var(--ink); font:500 14px/1 "Bricolage Grotesque",sans-serif; margin-bottom:6px; cursor:pointer; text-align:left; }
body.davya-pipeline-skin .pl-sheet-row.cur { opacity:.5; }
body.davya-pipeline-skin .pl-sheet-row .c { font:500 10px/1 "JetBrains Mono",monospace; color:var(--muted); }
body.davya-pipeline-skin .pl-sheet-any { margin:6px 0; }
body.davya-pipeline-skin .pl-sheet-any summary { font:600 11px/1 "JetBrains Mono",monospace; text-transform:uppercase; letter-spacing:.1em; color:var(--muted); padding:10px 2px; cursor:pointer; }
body.davya-pipeline-skin .pl-sheet-cancel { display:block; width:100%; padding:12px; border:none; background:transparent; color:var(--muted); font:600 12px/1 "JetBrains Mono",monospace; text-transform:uppercase; letter-spacing:.08em; margin-top:6px; cursor:pointer; }
```

Then mirror: `cp resources/css/pipeline-skin.css public/css/pipeline-skin.css`

- [ ] **Step 3: Add a move-through-engine regression test** to `tests/Feature/MobilePipeline/MobilePipelineRenderTest.php` (the sheet's `go()` calls the same `moveStudentToStage` the desktop drag uses, so we assert that path still enforces hard blocks):

```php
    public function test_move_via_page_method_still_blocks_on_hard_rule(): void
    {
        $admin = $this->admin();
        $student = Student::factory()->create([
            'owner_id' => $admin->id, 'referrer_id' => $admin->id, 'stage' => 'Lead Captured',
        ]);

        // Reuse the SAME hard-block setup pattern as KanbanSoftWarningsTest
        // (a hard rule into 'Closed' requiring close_reason). Copy that test's
        // rule/condition creation verbatim, then:
        $res = \Livewire\Livewire::test(\App\Filament\Pages\KanbanBoard::class)
            ->call('moveStudentToStage', $student->id, 'Closed');

        $payload = $res->returnValue ?? $res->effects['returns'] ?? null; // Livewire test return access
        // The move must be blocked + surface missing_fields (mobile sheet relies on this exact shape).
        $student->refresh();
        $this->assertSame('Lead Captured', $student->stage);
    }
```

Note: open `tests/Feature/KanbanSoftWarningsTest.php` first and copy its exact hard-rule setup (`test_drag_to_closed_without_reason_blocks_and_surfaces_missing_fields`) into this test before the `Livewire::test(...)` call. Use the same assertion style that file uses to read the returned array if you assert on `missing_fields`; the stage-unchanged assertion above is the minimum.

- [ ] **Step 4: Run the tests**

Run: `php -d memory_limit=1024M vendor/bin/phpunit --filter MobilePipelineRenderTest`
Expected: PASS (all methods).

- [ ] **Step 5: Commit**

```bash
git add resources/views/filament/pages/partials/kanban-move-sheet.blade.php resources/css/pipeline-skin.css public/css/pipeline-skin.css tests/Feature/MobilePipeline/MobilePipelineRenderTest.php
git commit -m "feat(pipeline): Guided move sheet (forward/back/any-stage) wired to engine"
```

---

## Phase 5 — Mobile filters

### Task 7: Filters bottom sheet + inline quick chips

**Files:**
- Modify: `resources/views/filament/pages/kanban-board.blade.php` (add a mobile filters trigger + sheet at the TOP of the `.pl-mobile` block from Task 5)
- Modify: `resources/css/pipeline-skin.css` (+ public mirror)

- [ ] **Step 1: Add the mobile filter UI.** At the very TOP of the `.pl-mobile` `<div ... x-data="kanbanMobile(...)">` block (before `.pl-switcher`), add a second Alpine state for the filter sheet and the markup. Change the opening tag to merge state:

```blade
        <div class="pl-mobile" x-data="{ ...kanbanMobile(@js($this->orderedStageNames())), filtersOpen: false }">
            <div class="pl-fbar">
                <button type="button" class="pl-filters-btn" x-on:click="filtersOpen = true">
                    Filters @if ($activeFilters)<span class="b">{{ $activeFilters }}</span>@endif
                </button>
                <button type="button" class="pl-qchip {{ $this->filterStuck ? 'on' : '' }}" wire:click="$toggle('filterStuck')">Stuck</button>
                <button type="button" class="pl-qchip {{ $this->filterSeatFeePending ? 'on' : '' }}" wire:click="$toggle('filterSeatFeePending')">Seat-fee</button>
                <button type="button" class="pl-qchip {{ $this->filterReEntry ? 'on' : '' }}" wire:click="$toggle('filterReEntry')">Re-entry</button>
            </div>

            <div class="pl-sheet-backdrop" x-show="filtersOpen" x-cloak x-on:click="filtersOpen = false" x-transition.opacity></div>
            <div class="pl-sheet" x-show="filtersOpen" x-cloak x-transition>
                <div class="pl-sheet-h">Filters</div>
                <select class="pl-fsel" wire:model.live="filterOwner"><option value="">Owner · Anyone</option>@foreach ($opts['owners'] as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach</select>
                <select class="pl-fsel" wire:model.live="filterCourse"><option value="">Course · All</option>@foreach ($opts['courses'] as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach</select>
                <select class="pl-fsel" wire:model.live="filterRound"><option value="">Round · Any</option>@foreach ($opts['rounds'] as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach</select>
                <select class="pl-fsel" wire:model.live="filterLeadSource"><option value="">Source · All</option>@foreach ($opts['sources'] as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach</select>
                <select class="pl-fsel" wire:model.live="filterPlan"><option value="">Plan · All</option>@foreach ($opts['plans'] as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach</select>
                <select class="pl-fsel" wire:model.live="filterCategory"><option value="">Category · All</option>@foreach ($opts['categories'] as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach</select>
                <select class="pl-fsel" wire:model.live="filterResponse"><option value="">Response · All</option>@foreach ($opts['responses'] as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach</select>
                <label class="pl-fcheck"><input type="checkbox" wire:model.live="filterHasPending"> Has pending amount</label>
                @if ($activeFilters)<button type="button" class="pl-sheet-row" wire:click="resetFilters">Clear all filters</button>@endif
                <button type="button" class="pl-sheet-cancel" x-on:click="filtersOpen = false">Done</button>
            </div>
```

(Note: the opening `<div class="pl-mobile" ...>` that previously started Task 5's block is REPLACED by this one — i.e. merge the `filtersOpen` state into the existing `x-data` and put this filter UI first. The `.pl-switcher` and stage blocks from Task 5 follow unchanged.)

- [ ] **Step 2: Add filter-bar CSS.** Append to `resources/css/pipeline-skin.css`:

```css
body.davya-pipeline-skin .pl-fbar { display:flex; gap:6px; align-items:center; flex-wrap:wrap; padding:2px 0 10px; }
body.davya-pipeline-skin .pl-filters-btn { font:600 12px/1 "Bricolage Grotesque",sans-serif; color:var(--ink); background:var(--card); border:1px solid var(--line); border-radius:8px; padding:8px 12px; cursor:pointer; }
body.davya-pipeline-skin .pl-filters-btn .b { background:var(--vermilion); color:#fff; border-radius:9px; font:600 9px/1 "JetBrains Mono",monospace; padding:2px 5px; margin-left:6px; }
body.davya-pipeline-skin .pl-qchip { font:600 10px/1 "JetBrains Mono",monospace; text-transform:uppercase; letter-spacing:.05em; color:var(--muted); background:transparent; border:1px dashed var(--line); border-radius:20px; padding:7px 10px; cursor:pointer; }
body.davya-pipeline-skin .pl-qchip.on { color:var(--vermilion); border-color:var(--vermilion); border-style:solid; }
body.davya-pipeline-skin .pl-fsel { width:100%; font:500 14px/1 "Bricolage Grotesque",sans-serif; color:var(--ink); background:var(--field); border:1px solid var(--line); border-radius:8px; padding:11px 12px; margin-bottom:8px; }
body.davya-pipeline-skin .pl-fcheck { display:flex; align-items:center; gap:8px; font:500 13px/1 "Bricolage Grotesque",sans-serif; color:var(--ink-soft); padding:8px 2px; }
```

Then mirror: `cp resources/css/pipeline-skin.css public/css/pipeline-skin.css`

- [ ] **Step 3: Verify the filter bindings still work** (the sheet reuses the same `wire:model.live` props the desktop toolbar uses)

Run: `php -d memory_limit=2048M vendor/bin/phpunit --filter Kanban`
Expected: PASS — filter logic untouched (same Livewire props), board tests green.
Run: `php -d memory_limit=1024M vendor/bin/phpunit --filter MobilePipeline`
Expected: PASS.

- [ ] **Step 4: Commit**

```bash
git add resources/views/filament/pages/kanban-board.blade.php resources/css/pipeline-skin.css public/css/pipeline-skin.css
git commit -m "feat(pipeline): mobile Filters bottom sheet + inline quick chips"
```

---

## Phase 6 — Final verification

### Task 8: Full suite + mobile smoke + leak check

**Files:** none (verification only)

- [ ] **Step 1: Full suite**

Run: `php -d memory_limit=2048M vendor/bin/phpunit`
Expected: green — baseline count + the new MobilePipeline tests; ZERO new failures (1 pre-existing skip stays skipped).

- [ ] **Step 2: Mobile smoke** (use a narrow viewport / devtools device mode)

```bash
php artisan serve &
# http://127.0.0.1:8000/dev-login then /admin/kanban at <768px:
#  - stage pills render with counts; tapping a pill swaps the visible stage; active auto-scrolls into view
#  - card body tap opens the peek drawer; ⤳ Move opens the Guided sheet
#  - tap "→ <next stage>" moves the lead (board refreshes); a hard-blocked move opens the fix-up sheet
#  - Filters button opens the sheet; selecting a filter narrows the board; Stuck/Seat-fee/Re-entry chips toggle
#  - resize to ≥768px: desktop drag board returns, mobile blocks hidden, drag-to-move works (no double-binding)
```
Expected: all pass. Fix CSS/blade + re-smoke if needed.

- [ ] **Step 3: Leak check + cross-page**

Run: `php -d memory_limit=1024M vendor/bin/phpunit --filter PipelineSkinScopeTest`
Expected: PASS (skin absent on students list). Also eyeball `/admin/students`, `/admin/leads-report` at desktop width — visually unchanged.

- [ ] **Step 4: Final commit (only if Steps 2–3 surfaced fixes)**

```bash
git add -A
git commit -m "chore(pipeline): mobile redesign final verification fixes"
```

---

## Notes
- Per `feedback_pre_deploy_quality_check` + `feedback_full_deploy_recipe_no_shortcuts`: before prod, run pint on changed PHP, do the browser mobile smoke, and deploy via the full recipe (git pull → composer → migrate → rank seeders → 3 caches). Bump the `pipeline-skin.css` cache-buster (`?v=N`) on every CSS edit.
- Deferred (not this plan): extracting the shared cream-token block out of `student-form-skin.css` + `pipeline-skin.css` into a common partial — do it when a 3rd surface lands.
- Next surface after this ships: **Today** (own brainstorm → spec → plan).
```
