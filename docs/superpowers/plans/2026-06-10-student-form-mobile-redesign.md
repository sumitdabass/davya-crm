# Student Form Mobile-First Redesign — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Re-skin the student create/edit form (`/admin/students/create` + `/admin/students/{id}/edit`) into the locked mobile-first aesthetic — a tappable stage stepper, segmented chips, restyled money bar + timeline, warm-cream card — with ZERO field/feature/behavior regressions, while factoring the reusable bits into a scoped skin that later CRM surfaces will inherit.

**Architecture:** A single **scoped skin stylesheet** (`student-form-skin.css`, every selector under `body.davya-student-form-skin`) plus the 3 Google fonts are loaded **only on the two student form pages** via a Filament render hook scoped to `CreateStudent` + `EditStudent`; that same hook adds the `davya-student-form-skin` body class via JS (the codebase's existing idiom — `AdminPanelProvider` already adds `davya-compact` to `<body>` this way). The stage dropdown becomes a custom Filament `StageStepper` field that writes to the **same `stage` state path** and keeps the **identical `afterStateUpdated` closure**, so `StageTransitionEngine` stays the single source of truth (hard blocks revert, soft warnings fire, `stage_id` syncs) — and `EditStudent::mutateFormDataBeforeSave()` is untouched. Seven short-enum `Select`s become Filament `ToggleButtons` with the same option sources and rules. The shared `account-summary` + `student-money-summary` blades get skin-scoped classes (markup/behavior preserved, including `mountAction` triggers).

**Tech Stack:** Laravel 11, Filament 3, Livewire 3, Alpine.js, Pest/PHPUnit, Vite (CSS lives in `resources/css`, served from `public/css`).

---

## Pre-flight (read before Task 1)

- Branch is `feat/student-form-mobile-redesign` (already checked out). Do all work here.
- Run the test suite with: `php artisan test` (full) or `php artisan test --filter <Name>` (one test). The base `tests/TestCase.php` overrides `createApplication()` — keep using it.
- Login in HTTP/Livewire tests: seed, fetch `User::where('email','sumit@davya.local')->first()`, set `must_change_password=false`, `actingAs($user)`. (Pattern copied verbatim from `tests/Feature/StudentFormTabsTest.php`.)
- The skin is **purely additive and scoped** — when `body.davya-student-form-skin` is absent (every other page) the new CSS does nothing. Do NOT gate it on `config('davyas.visual_v2')`; the scope class is the gate.
- "When" dates on stepper steps (mockup shows "02 JUN") are **decorative only** and NOT in the spec inventory — we do **not** render them in v1 (no reliable per-stage-entered timestamp source). Steps render label only. This is an intentional YAGNI cut; note it, don't invent a data source.

## File Structure

| File | Create/Modify | Responsibility |
|---|---|---|
| `resources/css/student-form-skin.css` | Create | All skin styling, scoped under `body.davya-student-form-skin`. Ported from the locked mockup. |
| `public/css/student-form-skin.css` | Create (copy) | Served asset (no build step assumed; mirror `tokens.css` which has a `public/css` copy). |
| `app/Filament/Forms/Components/StageStepper.php` | Create | Custom Filament field; exposes ordered pipeline stages to its view; binds to `stage` state path. |
| `resources/views/filament/forms/components/stage-stepper.blade.php` | Create | Stepper UI; each step sets `stage` via `$wire.set(...)` (live → fires `afterStateUpdated`). |
| `app/Providers/Filament/AdminPanelProvider.php` | Modify | Register a `PAGE_START` render hook scoped to `CreateStudent`+`EditStudent`: link skin CSS + fonts, add body class. |
| `app/Filament/Resources/StudentResource.php` | Modify | `Select('stage')` → `StageStepper`; 7 short-enum `Select`s → `ToggleButtons`; add imports. |
| `resources/views/filament/forms/student-money-summary.blade.php` | Modify | Add skin-scoped wrapper class; keep `mountAction` buttons + `data-testid`. |
| `resources/views/filament/forms/account-summary.blade.php` | Modify | Add skin-scoped classes so CSS renders the activity feed as a timeline. |
| `tests/Feature/MobileForm/FormSkinScopeTest.php` | Create (Test) | Skin CSS present on create+edit HTML, absent on students list. |
| `tests/Feature/MobileForm/StageStepperTest.php` | Create (Test) | Stepper sets stage; hard-blocked transition reverts; soft warning allows. |
| `tests/Feature/MobileForm/ToggleButtonsPersistTest.php` | Create (Test) | Each swapped field persists the same value a Select did (esp. `plan`). |

---

## Phase 0 — Scoped skin infrastructure

### Task 1: Create the scoped skin stylesheet

**Files:**
- Create: `resources/css/student-form-skin.css`

- [ ] **Step 1: Write the stylesheet** (ported from the locked mockup `docs/superpowers/specs/mockups/student-form-inframe.html`, every rule scoped under `body.davya-student-form-skin`). Create `resources/css/student-form-skin.css`:

```css
/* Student form mobile-first skin — scoped entirely under body.davya-student-form-skin.
   Inert on every other page (class is only added on /students/create + /edit). */
@import url("https://fonts.googleapis.com/css2?family=Instrument+Serif:ital@0;1&family=Bricolage+Grotesque:opsz,wght@12..96,300..800&family=JetBrains+Mono:wght@400;500;600&display=swap");

body.davya-student-form-skin {
  --paper:#f4efe4; --paper-2:#efe8d8; --card:#fbf8f0; --field:#fffdf8;
  --ink:#16231c; --ink-soft:#3c4a40; --muted:#8c8475; --faint:#b7af9d;
  --emerald:#0b5d40; --emerald-deep:#063a28; --emerald-bright:#15835c;
  --vermilion:#e0431c; --vermilion-deep:#b8330f; --amber:#c2861a;
  --line:rgba(22,35,28,.14); --line-soft:rgba(22,35,28,.07); --r:9px;
}

/* The Filament form wrapper becomes the cream "paper" card. */
body.davya-student-form-skin .fi-fo-component-ctn,
body.davya-student-form-skin form .fi-section {
  font-family:"Bricolage Grotesque",system-ui,sans-serif; color:var(--ink);
}

/* ---- Stage stepper ---- */
body.davya-student-form-skin .davya-stepwrap { background:var(--card); border:1px solid var(--line); border-radius:var(--r); }
body.davya-student-form-skin .davya-step-cap {
  font-family:"JetBrains Mono",monospace; font-size:9.5px; letter-spacing:.18em;
  text-transform:uppercase; color:var(--muted); padding:12px 16px 0;
}
body.davya-student-form-skin .davya-stepper { display:flex; padding:12px 16px 10px; overflow-x:auto; scrollbar-width:none; }
body.davya-student-form-skin .davya-stepper::-webkit-scrollbar { display:none; }
body.davya-student-form-skin .davya-step {
  flex:1 0 auto; min-width:92px; position:relative; cursor:pointer; padding-right:8px;
  background:none; border:none; text-align:left; font-family:inherit;
}
body.davya-student-form-skin .davya-step .dot {
  display:block; width:13px; height:13px; border-radius:50%; border:2px solid var(--faint);
  background:var(--paper); position:relative; z-index:2; transition:.25s;
}
body.davya-student-form-skin .davya-step .bar { position:absolute; top:5.5px; left:13px; right:0; height:2px; background:var(--line); }
body.davya-student-form-skin .davya-step:last-child .bar { display:none; }
body.davya-student-form-skin .davya-step.done .dot { background:var(--emerald); border-color:var(--emerald); }
body.davya-student-form-skin .davya-step.done .bar { background:var(--emerald); }
body.davya-student-form-skin .davya-step.cur .dot { background:var(--vermilion); border-color:var(--vermilion); box-shadow:0 0 0 4px rgba(224,67,28,.16); }
body.davya-student-form-skin .davya-step.won .dot { border-color:var(--emerald-bright); }
body.davya-student-form-skin .davya-step.lost .dot { border-color:var(--vermilion-deep); }
body.davya-student-form-skin .davya-step .lbl { display:block; margin-top:10px; font-size:10.5px; color:var(--muted); line-height:1.2; max-width:88px; }
body.davya-student-form-skin .davya-step.done .lbl { color:var(--ink-soft); }
body.davya-student-form-skin .davya-step.cur .lbl { color:var(--vermilion); font-weight:600; }

/* ---- Money bar (student-money-summary) ---- */
body.davya-student-form-skin .davya-moneybar {
  display:flex; flex-wrap:wrap; gap:5px 12px; align-items:center; padding:11px 16px;
  background:var(--emerald-deep); color:#eef3ee; font-family:"JetBrains Mono",monospace;
  font-size:12px; border-radius:var(--r); margin-top:8px;
}
body.davya-student-form-skin .davya-moneybar button { color:inherit; background:none; border:none; cursor:pointer; font:inherit; }
body.davya-student-form-skin .davya-moneybar button:hover { text-decoration:underline; }

/* ---- Segmented chips (ToggleButtons) ---- */
body.davya-student-form-skin .fi-fo-toggle-buttons .fi-fo-toggle-buttons-options { display:flex; flex-wrap:wrap; gap:7px; }
body.davya-student-form-skin .fi-fo-toggle-buttons label {
  font-size:13px; padding:8px 13px; border:1px solid var(--line); border-radius:30px;
  background:var(--field); color:var(--ink-soft); cursor:pointer; transition:.18s; white-space:nowrap;
}
body.davya-student-form-skin .fi-fo-toggle-buttons label:has(input:checked) {
  background:var(--emerald); color:#fff; border-color:var(--emerald);
}

/* ---- Field typography / inputs ---- */
body.davya-student-form-skin h2.fi-section-header-heading,
body.davya-student-form-skin .fi-tabs { font-family:"Bricolage Grotesque",sans-serif; }
body.davya-student-form-skin .fi-input,
body.davya-student-form-skin .fi-select-input,
body.davya-student-form-skin textarea {
  font-family:inherit; background:var(--field); border-radius:var(--r);
}
body.davya-student-form-skin .fi-input:focus,
body.davya-student-form-skin textarea:focus { border-color:var(--emerald); box-shadow:0 0 0 3px rgba(11,93,64,.12); }

/* ---- Account timeline (account-summary) ---- */
body.davya-student-form-skin .davya-tl { position:relative; padding-left:4px; margin-top:8px; }
body.davya-student-form-skin .davya-tl .ev { position:relative; padding:0 0 18px 26px; border-left:1px solid var(--line); }
body.davya-student-form-skin .davya-tl .ev:last-child { border-left-color:transparent; }
body.davya-student-form-skin .davya-tl .ev .pt { position:absolute; left:-6px; top:3px; width:11px; height:11px; border-radius:50%; background:var(--paper); border:2px solid var(--faint); }
body.davya-student-form-skin .davya-tl .ev.pay .pt { background:var(--emerald); border-color:var(--emerald); }
body.davya-student-form-skin .davya-tl .ev .am { font-family:"JetBrains Mono",monospace; color:var(--emerald); font-weight:500; }
body.davya-student-form-skin .davya-tl .ev .by { font-family:"JetBrains Mono",monospace; font-size:10px; color:var(--faint); text-transform:uppercase; letter-spacing:.09em; margin-top:5px; }
```

- [ ] **Step 2: Mirror to the served `public/css` path** (matches how `tokens.css` exists in both `resources/css` and `public/css`):

Run: `cp resources/css/student-form-skin.css public/css/student-form-skin.css`
Expected: file copied, no output.

- [ ] **Step 3: Commit**

```bash
git add resources/css/student-form-skin.css public/css/student-form-skin.css
git commit -m "feat(student-form): add scoped mobile-first skin stylesheet"
```

---

### Task 2: Load the skin + body class only on the two student form pages

**Files:**
- Modify: `app/Providers/Filament/AdminPanelProvider.php` (add a scoped render hook in the same `boot`/`panel` area as the existing `HEAD_END` hook, ~lines 60–114)
- Test: `tests/Feature/MobileForm/FormSkinScopeTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\MobileForm;

use App\Models\User;
use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

class FormSkinScopeTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $this->seed();
        $u = User::where('email', 'sumit@davya.local')->first();
        $u->update(['must_change_password' => false]);

        return $u;
    }

    public function test_skin_css_loads_on_create_page(): void
    {
        $this->actingAs($this->admin())
            ->get('/admin/students/create')
            ->assertOk()
            ->assertSee('student-form-skin.css', false)
            ->assertSee('davya-student-form-skin', false);
    }

    public function test_skin_css_absent_on_students_list(): void
    {
        $this->actingAs($this->admin())
            ->get('/admin/students')
            ->assertOk()
            ->assertDontSee('student-form-skin.css', false);
    }
}
```

- [ ] **Step 2: Run it to verify it fails**

Run: `php artisan test --filter FormSkinScopeTest`
Expected: FAIL — `test_skin_css_loads_on_create_page` fails to see `student-form-skin.css`.

- [ ] **Step 3: Register the scoped render hook.** In `app/Providers/Filament/AdminPanelProvider.php`, add these imports near the other `use` statements at the top:

```php
use App\Filament\Resources\StudentResource\Pages\CreateStudent;
use App\Filament\Resources\StudentResource\Pages\EditStudent;
use Filament\Support\Facades\FilamentView;
use Filament\View\PanelsRenderHook;
```

Then, inside `boot()` (or wherever the existing `renderHook`/`HEAD_END` registration lives — add alongside it), register:

```php
FilamentView::registerRenderHook(
    PanelsRenderHook::PAGE_START,
    fn (): string => <<<'HTML'
        <link rel="stylesheet" href="/css/student-form-skin.css?v=1" id="davya-student-form-skin-css">
        <script>document.body.classList.add('davya-student-form-skin');</script>
        HTML,
    scopes: [CreateStudent::class, EditStudent::class],
);
```

Note: `PAGE_START` renders inside the page body and supports per-page `scopes`, so the link + class appear ONLY on these two pages. The CSS is inert without the body class, so even if the link ever leaked it would style nothing.

- [ ] **Step 4: Run it to verify it passes**

Run: `php artisan test --filter FormSkinScopeTest`
Expected: PASS (both tests).

- [ ] **Step 5: Commit**

```bash
git add app/Providers/Filament/AdminPanelProvider.php tests/Feature/MobileForm/FormSkinScopeTest.php
git commit -m "feat(student-form): load scoped skin + body class only on create/edit pages"
```

---

## Phase 1 — Stage stepper (replaces the Select, preserves StageTransitionEngine)

### Task 3: Build the StageStepper custom field

**Files:**
- Create: `app/Filament/Forms/Components/StageStepper.php`
- Create: `resources/views/filament/forms/components/stage-stepper.blade.php`

- [ ] **Step 1: Create the field class.** Create `app/Filament/Forms/Components/StageStepper.php`:

```php
<?php

namespace App\Filament\Forms\Components;

use App\Services\Pipeline\PipelineConfig;
use Filament\Forms\Components\Field;

class StageStepper extends Field
{
    protected string $view = 'filament.forms.components.stage-stepper';

    /**
     * Ordered pipeline stages for the stepper view.
     *
     * @return array<int, array{name: string, type: string}>
     */
    public function getStages(): array
    {
        return app(PipelineConfig::class)->stages()
            ->map(fn ($s) => ['name' => $s->name, 'type' => $s->stage_type])
            ->values()
            ->all();
    }
}
```

- [ ] **Step 2: Create the view.** Create `resources/views/filament/forms/components/stage-stepper.blade.php`:

```blade
@php
    $statePath = $getStatePath();
    $current = $getState();
    $stages = $getStages();
    $curIndex = collect($stages)->search(fn ($s) => $s['name'] === $current);
@endphp

<x-dynamic-component :component="$getFieldWrapperView()" :field="$field">
    <div class="davya-stepwrap" wire:ignore.self>
        <div class="davya-step-cap">Pipeline · IPU Admission — tap a stage to move the lead</div>
        <div class="davya-stepper">
            @foreach ($stages as $i => $s)
                @php
                    $isDone = $curIndex !== false && $i < $curIndex;
                    $isCur = $s['name'] === $current;
                    $typeClass = $s['type'] === \App\Models\Stage::TYPE_WON
                        ? 'won'
                        : ($s['type'] === \App\Models\Stage::TYPE_LOST ? 'lost' : '');
                @endphp
                <button type="button"
                        class="davya-step {{ $isDone ? 'done' : '' }} {{ $isCur ? 'cur' : '' }} {{ $typeClass }}"
                        x-on:click="$wire.set(@js($statePath), @js($s['name']))">
                    <span class="dot"></span><span class="bar"></span>
                    <span class="lbl">{{ $s['name'] }}</span>
                </button>
            @endforeach
        </div>
    </div>
</x-dynamic-component>
```

Note: `$wire.set(statePath, name)` on a `->live()` field triggers Livewire's `updated` lifecycle, which runs the field's `afterStateUpdated` closure — exactly as changing the old Select did.

- [ ] **Step 3: Verify `Stage` constants exist** (the view references `Stage::TYPE_WON`/`TYPE_LOST`):

Run: `grep -n "TYPE_WON\|TYPE_LOST\|TYPE_OPEN" app/Models/Stage.php`
Expected: all three constants are defined (they are used by `PipelineConfig::wonStages()`/`lostStages()`). If the model namespace differs from `App\Models\Stage`, update the FQCN in the view to match `grep -rn "class Stage" app/`.

- [ ] **Step 4: Commit**

```bash
git add app/Filament/Forms/Components/StageStepper.php resources/views/filament/forms/components/stage-stepper.blade.php
git commit -m "feat(student-form): add StageStepper custom field + view"
```

---

### Task 4: Swap the stage Select for the StageStepper in the form

**Files:**
- Modify: `app/Filament/Resources/StudentResource.php:115-139` (the `$stageField` definition)
- Test: `tests/Feature/MobileForm/StageStepperTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\MobileForm;

use App\Filament\Resources\StudentResource\Pages\EditStudent;
use App\Models\Student;
use App\Models\User;
use App\Services\Pipeline\PipelineConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class StageStepperTest extends TestCase
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

    public function test_stepper_renders_all_pipeline_stages_on_create(): void
    {
        $this->admin();
        $names = app(PipelineConfig::class)->stageNames();

        $component = Livewire::test(\App\Filament\Resources\StudentResource\Pages\CreateStudent::class);
        foreach ($names as $name) {
            $component->assertSee($name);
        }
    }

    public function test_setting_stage_updates_state_like_the_old_select(): void
    {
        $admin = $this->admin();
        $student = Student::factory()->create([
            'owner_id' => $admin->id,
            'referrer_id' => $admin->id,
            'stage' => 'Lead Captured',
        ]);

        // Equivalent to a stepper tap: $wire.set('data.stage', ...) on the live field.
        Livewire::test(EditStudent::class, ['record' => $student->getKey()])
            ->set('data.stage', 'Meeting Scheduled')
            ->assertSet('data.stage', 'Meeting Scheduled');
    }
}
```

- [ ] **Step 2: Run it to verify it fails**

Run: `php artisan test --filter StageStepperTest`
Expected: FAIL — the stage field still renders as a `<select>`; `test_stepper_renders_all_pipeline_stages_on_create` may pass (options are present in the Select too), but the suite is added now so we run it after the swap. If both pass before the swap, that's fine — they are regression guards; proceed to make the swap and keep them green.

- [ ] **Step 3: Swap the component.** In `app/Filament/Resources/StudentResource.php`, add the import near the other `Filament\Forms\Components` imports (after line 33):

```php
use App\Filament\Forms\Components\StageStepper;
```

Then change line 115 from:

```php
        $stageField = Select::make('stage')->options(fn () => self::stageOptions())->required()->default('Lead Captured')
            ->live()
```

to:

```php
        $stageField = StageStepper::make('stage')->required()->default('Lead Captured')
            ->live()
```

Leave the entire `->afterStateUpdated(function ($state, $record, $set) { ... })` closure (lines 117–139) **exactly as-is** — it is the StageTransitionEngine wiring and must not change.

- [ ] **Step 4: Run it to verify it passes**

Run: `php artisan test --filter StageStepperTest`
Expected: PASS (both tests).

- [ ] **Step 5: Run the existing stage-transition tests to confirm zero regression**

Run: `php artisan test --filter Stage`
Expected: PASS — all existing StageTransitionEngine / stage tests still green (the engine path is unchanged).

- [ ] **Step 6: Commit**

```bash
git add app/Filament/Resources/StudentResource.php tests/Feature/MobileForm/StageStepperTest.php
git commit -m "feat(student-form): replace stage Select with tappable StageStepper"
```

---

### Task 5: Verify a hard-blocked transition still reverts through the stepper

**Files:**
- Test: `tests/Feature/MobileForm/StageStepperTest.php` (add a method)

- [ ] **Step 1: Find how existing tests build a hard-block rule.** The engine reads `StageTransitionRule` + `StageTransitionCondition`. Locate an existing test that sets up a hard block:

Run: `grep -rln "StageTransitionRule\|forStageChange\|hard" tests/`
Expected: at least one existing test file (e.g. a `StageTransition*Test`) shows the factory/seed pattern for a hard rule. Read it and copy the exact setup.

- [ ] **Step 2: Write the failing test** — add to `StageStepperTest.php`. Replace the placeholder rule setup below with the exact pattern found in Step 1 (rule that hard-blocks moving INTO a chosen stage when a required field is empty):

```php
    public function test_hard_blocked_transition_reverts_stage_on_edit(): void
    {
        $admin = $this->admin();
        $student = Student::factory()->create([
            'owner_id' => $admin->id,
            'referrer_id' => $admin->id,
            'stage' => 'Lead Captured',
        ]);

        // ── Build a HARD rule blocking entry into 'Advance Received' ──
        // Use the exact StageTransitionRule + StageTransitionCondition setup
        // copied from the existing stage-transition test found in Step 1.
        // (e.g. require deal_amount > 0; leave it null on $student.)

        Livewire::test(EditStudent::class, ['record' => $student->getKey()])
            ->set('data.stage', 'Advance Received')
            // afterStateUpdated reverts to the original on a hard block:
            ->assertSet('data.stage', 'Lead Captured');
    }
```

- [ ] **Step 3: Run it to verify it fails first if the rule isn't wired, then passes once the rule setup is correct**

Run: `php artisan test --filter test_hard_blocked_transition_reverts_stage_on_edit`
Expected: PASS once the hard rule is set up correctly — the stepper routes through the identical `afterStateUpdated`, which calls `$set('stage', $record->getOriginal('stage'))` on a hard block. (If it fails, the rule setup doesn't actually produce a hard block — fix the rule, not the field.)

- [ ] **Step 4: Commit**

```bash
git add tests/Feature/MobileForm/StageStepperTest.php
git commit -m "test(student-form): stepper preserves hard-block stage revert"
```

---

## Phase 2 — Segmented chips (ToggleButtons)

### Task 6: Swap the 7 short-enum Selects to ToggleButtons

**Files:**
- Modify: `app/Filament/Resources/StudentResource.php` — lines 196 (`lead_source`), 201 (`student_response`), 222 (`category`), 239 (`plan`), 240–248 (`registration_status`), 249–257 (`counselling_registration_status`), 264–273 (`seat_allotment_fee_status`)
- Test: `tests/Feature/MobileForm/ToggleButtonsPersistTest.php`

- [ ] **Step 1: Write the failing test** — proves each swapped field still persists the same value (esp. `plan`, called out in the spec):

```php
<?php

namespace Tests\Feature\MobileForm;

use App\Models\Student;
use App\Models\User;
use App\Filament\Resources\StudentResource\Pages\CreateStudent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ToggleButtonsPersistTest extends TestCase
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

    public function test_toggle_button_fields_persist_on_create(): void
    {
        $admin = $this->admin();

        Livewire::test(CreateStudent::class)
            ->fillForm([
                'owner_id' => $admin->id,
                'referrer_id' => $admin->id,
                'lead_source' => 'Google',
                'student_response' => 'Ready',
                'phone' => '9999900123',
                'name' => 'Toggle Test',
                'stage' => 'Lead Captured',
                'preference_r1' => 'MAIT',
                'category' => 'Delhi',
                'plan' => 'Counselling Online',
                'registration_status' => 'registration_done',
                'counselling_registration_status' => 'pending',
                'seat_allotment_fee_status' => 'not_allotted',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('students', [
            'name' => 'Toggle Test',
            'lead_source' => 'Google',
            'student_response' => 'Ready',
            'category' => 'Delhi',
            'plan' => 'Counselling Online',
            'registration_status' => 'registration_done',
            'counselling_registration_status' => 'pending',
            'seat_allotment_fee_status' => 'not_allotted',
        ]);
    }
}
```

- [ ] **Step 2: Run it to verify it passes BEFORE the swap (baseline)**

Run: `php artisan test --filter ToggleButtonsPersistTest`
Expected: PASS — the Selects already persist these values. This is the regression baseline; it must STILL pass after the swap. (If it fails before the swap, the test's required-field set is wrong — fix the test to match current form rules first.)

- [ ] **Step 3: Add the import + swap the components.** In `app/Filament/Resources/StudentResource.php`, add near the other `Filament\Forms\Components` imports (after line 33):

```php
use Filament\Forms\Components\ToggleButtons;
```

Then make these exact replacements (component name only — keep every option array, `->required()`, `->default(...)`, `->label(...)` identical; **drop `->searchable()`** where present since ToggleButtons has no search):

- Line 196–200 `lead_source`:
```php
                            ToggleButtons::make('lead_source')
                                ->label('Lead Source')
                                ->options(fn () => self::optionsFor('lead_source', ['FB', 'Insta', 'Cold Calling', 'Google', 'Personal Ref', 'Other']))
                                ->inline()
                                ->required(),
```

- Line 201 `student_response`:
```php
                            ToggleButtons::make('student_response')->inline()->options(fn () => self::optionsFor('student_response', ['Ready', 'Not Interested', 'Needs Time'])),
```

- Line 222 `category`:
```php
                            ToggleButtons::make('category')->inline()->options(fn () => self::optionsFor('category', ['Delhi', 'Outside'])),
```

- Line 239 `plan`:
```php
                            ToggleButtons::make('plan')->inline()->options(fn () => self::optionsFor('plan', ['Sitting', 'Counselling Online', 'Counselling Offline'])),
```

- Lines 240–248 `registration_status`:
```php
                            ToggleButtons::make('registration_status')
                                ->label('IPU Registration Status')
                                ->inline()
                                ->options([
                                    'pending' => 'Registration pending',
                                    'registration_done' => 'Registration done',
                                    'fee_paid' => 'Fee payment done',
                                ])
                                ->default('pending')
                                ->required(),
```

- Lines 249–257 `counselling_registration_status`:
```php
                            ToggleButtons::make('counselling_registration_status')
                                ->label('Counselling Registration Status')
                                ->inline()
                                ->options([
                                    'pending' => 'Registration pending',
                                    'registration_done' => 'Registration done',
                                    'fee_paid' => 'Fee payment done',
                                ])
                                ->default('pending')
                                ->required(),
```

- Lines 264–273 `seat_allotment_fee_status`:
```php
                            ToggleButtons::make('seat_allotment_fee_status')
                                ->label('Seat Allotment Fee Status')
                                ->inline()
                                ->options([
                                    'not_allotted' => 'Seat not allotted till now',
                                    'allotted_fee_pending' => 'Seat allotted, fee not paid',
                                    'allotted_fee_paid' => 'Seat allotted, fee paid',
                                    'next_round' => 'Fee paid — processing next round',
                                ])
                                ->default('not_allotted')
                                ->required(),
```

Leave `owner_id`, `referrer_id`, and `close_reason` as `Select` (per spec — they stay searchable / a dropdown).

- [ ] **Step 4: Run it to verify it still passes after the swap**

Run: `php artisan test --filter ToggleButtonsPersistTest`
Expected: PASS — same values persist through ToggleButtons.

- [ ] **Step 5: Run the broader student-form suite for regressions**

Run: `php artisan test --filter Student`
Expected: PASS — no new failures vs the pre-existing baseline (the repo's known-green state; see `project_davya-crm` notes — suite should be fully green).

- [ ] **Step 6: Commit**

```bash
git add app/Filament/Resources/StudentResource.php tests/Feature/MobileForm/ToggleButtonsPersistTest.php
git commit -m "feat(student-form): swap short-enum Selects to segmented ToggleButtons"
```

---

## Phase 3 — Restyle the shared blades under the skin

### Task 7: Skin the money summary bar

**Files:**
- Modify: `resources/views/filament/forms/student-money-summary.blade.php`

- [ ] **Step 1: Add the skin wrapper class** while preserving every `mountAction` button and the `data-testid`. Change the outer `<div>` (currently `class="text-sm text-gray-500 ..."`) to ALSO carry `davya-moneybar` so the scoped CSS restyles it only on the skinned pages. Edit the opening wrapper div to:

```blade
    <div class="text-sm text-gray-500 dark:text-gray-400 davya-moneybar" style="margin-top:4px; line-height:1.5;" data-testid="student-money-summary">
```

Leave all inner buttons, spans, `wire:click="mountAction(...)"`, and the `$fmt(...)` calls exactly as they are. (On non-skin pages `davya-moneybar` has no styles, so the bar looks identical to today; under the skin it becomes the emerald money bar.)

- [ ] **Step 2: Verify the existing money-summary test still passes**

Run: `php artisan test --filter MoneySummary`
Expected: PASS (the `data-testid` and `mountAction` triggers are unchanged). If no such test exists, run `php artisan test --filter Payout` (expected-profit / payouts tests touch this blade) — expected PASS.

- [ ] **Step 3: Commit**

```bash
git add resources/views/filament/forms/student-money-summary.blade.php
git commit -m "style(student-form): money summary becomes scoped money bar under skin"
```

---

### Task 8: Skin the account activity feed as a timeline

**Files:**
- Modify: `resources/views/filament/forms/account-summary.blade.php`

- [ ] **Step 1: Read the current blade** to see its exact structure:

Run: `sed -n '1,116p' resources/views/filament/forms/account-summary.blade.php`
Expected: three sections (Payments table, Notes feed, Timeline/Activity list) rendered with inline styles.

- [ ] **Step 2: Add skin-scoped hook classes.** Wrap the activity/notes feed list container with `class="davya-tl"` and give each rendered row `class="ev"` (add `pay` to payment rows). Concretely: on the container `<div>`/`<ul>` that lists the recent activity entries, append `davya-tl` to its class attribute; on each entry element append `ev` (and `pay` when the entry is a payment), and tag the amount node with `class="am"` and the meta/author node with `class="by"`. Add a `<span class="pt"></span>` as the first child of each `ev` row (the timeline dot). Do NOT remove the existing inline styles — they remain the look on non-skin pages; the scoped `.davya-tl` rules override only under `body.davya-student-form-skin`.

Example transform for one activity row (adapt to the actual loop in the file):

```blade
{{-- before --}}
<div style="...existing inline...">
    <span>{{ $entry->title }}</span>
    <span>{{ $entry->meta }}</span>
</div>

{{-- after --}}
<div class="ev {{ $entry->is_payment ? 'pay' : '' }}" style="...existing inline...">
    <span class="pt"></span>
    <span>{{ $entry->title }}</span>
    <span class="by">{{ $entry->meta }}</span>
</div>
```

(Use the real variable/loop names from Step 1. If the feed mixes payments/notes/activity, mark payment rows with `pay`.)

- [ ] **Step 3: Verify the account-summary still renders** (it's `->visible(fn ($record) => $record !== null)` so only on edit). Add a lightweight render assertion to an existing edit-page test or run:

Run: `php artisan test --filter Account`
Expected: PASS — if no `Account*Test` exists, run `php artisan test --filter Edit` (edit-page tests render this blade) — expected PASS, no errors thrown from the blade.

- [ ] **Step 4: Commit**

```bash
git add resources/views/filament/forms/account-summary.blade.php
git commit -m "style(student-form): account activity feed becomes timeline under skin"
```

---

## Phase 4 — Final verification

### Task 9: Full suite + manual smoke + no-leak check

**Files:** none (verification only)

- [ ] **Step 1: Run the full test suite**

Run: `php artisan test`
Expected: green — same pass/fail count as the pre-existing baseline PLUS the new MobileForm tests passing. ZERO new failures. (Per `project_davya-crm`, the suite's baseline is fully green; if any pre-existing skip remains, it stays skipped — do not "fix" unrelated tests here.)

- [ ] **Step 2: Manual smoke via local server** (per `feedback_localhost_crosslink_test` + `feedback_pre_deploy_quality_check`). Start the app and log in via the env-gated `/dev-login` route:

```bash
php artisan serve &
# visit http://127.0.0.1:8000/dev-login then:
#  /admin/students/create  → stepper renders, chips render, no console errors
#  /admin/students/{id}/edit → tap a stage (moves / blocks correctly), money bar emerald, timeline styled
#  /admin/students  (list)  → UNCHANGED look (no skin leak)
#  /admin/leads-report, /admin/kanban → UNCHANGED look (no skin leak)
```
Expected: skin applies ONLY on create/edit; all other pages visually identical to before.

- [ ] **Step 3: Confirm no CSS leak in markup**

Run: `php artisan test --filter FormSkinScopeTest`
Expected: PASS — re-confirms `student-form-skin.css` absent on the list page.

- [ ] **Step 4: Final commit (if Steps 2–3 surfaced any fixes)**

```bash
git add -A
git commit -m "chore(student-form): final mobile-redesign verification fixes"
```

---

## Notes for the next surface (do NOT do here)
Once this pilot is green and deployed, the reusable pieces to extract for the broader CRM redesign (`2026-06-10-mobile-first-crm-program-roadmap.md`) are: the scoped-skin render-hook pattern (Task 2), the `StageStepper`-style custom-field approach, the ToggleButtons-chip CSS, the money-bar, and the timeline. Each subsequent surface (Pipeline → Today → Reports → Finance → Rank) gets its OWN brainstorm → spec → plan.
