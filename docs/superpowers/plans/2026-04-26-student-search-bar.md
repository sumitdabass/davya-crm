# Student Search Bar Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the visual-v2 ⌘K command palette with an inline typeahead search bar in the same topbar slot. Users type any student detail (name, phone, email, father name, IPU user id, course) and click a result to land directly on `/admin/students/{id}/edit`.

**Architecture:** A new Livewire 3 component `StudentSearch` mounted by `top-bar.blade.php`. Server-side rendering for the dropdown (gated by `mb_strlen(trim($query)) >= 2`). Alpine handles only show/hide on focus + click-outside. The existing `CommandPalette` component, its blade view, and its mount in `AdminPanelProvider` are deleted.

**Tech Stack:** Laravel 11, Livewire 3.7, Alpine.js, Filament 3, MySQL, PHPUnit (class-based Feature tests).

**Spec:** `docs/superpowers/specs/2026-04-26-student-search-bar-design.md`

**Branch:** `feature/student-search-bar`

---

## File map

| File | Action | Purpose |
|---|---|---|
| `app/Livewire/StudentSearch.php` | create | Livewire component: query state, scoped search, returns up to 8 students. |
| `resources/views/livewire/student-search.blade.php` | create | Input + server-rendered dropdown, Alpine show/hide. |
| `resources/views/livewire/top-bar.blade.php` | modify | Replace lines 11–17 (the ⌘K-trigger button) with `<livewire:student-search />`. |
| `app/Providers/Filament/AdminPanelProvider.php` | modify | Drop `@livewire("command-palette")` from the render-hook string at line ~44. |
| `app/Livewire/CommandPalette.php` | delete | Replaced by StudentSearch. |
| `resources/views/livewire/command-palette.blade.php` | delete | Replaced by student-search.blade.php. |
| `tests/Feature/StudentSearchTest.php` | create | 5 Feature tests covering render, min-char gate, field coverage, visibleTo scope, edit URL. |

---

## Task 1 — Pre-flight

**Files:** none

- [ ] **Step 1: Verify branch + clean working tree (preview drafts may be present)**

Run:
```bash
cd /Users/Sumit/davya-crm
git status -sb
git rev-parse --abbrev-ref HEAD
```
Expected branch: `feature/student-search-bar`. The `docs/superpowers/specs/...` commit (`19cf0b7`) should already be on this branch.

If preview drafts are present (`?? app/Livewire/StudentSearch.php`, `M resources/views/livewire/top-bar.blade.php`, `?? resources/views/livewire/student-search.blade.php`) from the live-preview session: leave them in place — Task 7 / Task 8 produce the same files and the steps will simply confirm content matches (no-op edits) or amend if the preview drifted from spec.

- [ ] **Step 2: Confirm visual-v2 flag in local `.env`**

Run:
```bash
grep DAVYAS_VISUAL_V2 /Users/Sumit/davya-crm/.env || echo "MISSING — add DAVYAS_VISUAL_V2=true"
php artisan config:clear
```
Expected: `DAVYAS_VISUAL_V2=true`.

- [ ] **Step 3: Confirm dev DB has at least one Student fixture**

Run:
```bash
php artisan tinker --execute='echo App\Models\Student::count().PHP_EOL;'
```
Expected: ≥1. If 0, seed via Task 2's seeder helper (defined inline in tests using `factory()`/`Student::create()`).

---

## Task 2 — Add the failing Feature tests

**Files:**
- Create: `tests/Feature/StudentSearchTest.php`

- [ ] **Step 1: Write the test file**

Create `/Users/Sumit/davya-crm/tests/Feature/StudentSearchTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Livewire\StudentSearch;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class StudentSearchTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Role::findOrCreate('admin');
        Role::findOrCreate('head');

        $this->admin = User::factory()->create(['name' => 'Admin User']);
        $this->admin->assignRole('admin');
        $this->actingAs($this->admin);
    }

    /** @test */
    public function it_renders_the_search_input(): void
    {
        Livewire::test(StudentSearch::class)
            ->assertSee('Search students by name, phone, email');
    }

    /** @test */
    public function it_returns_no_results_under_two_characters(): void
    {
        Student::factory()->create(['name' => 'Aarav Sharma', 'phone' => '9810000001']);

        Livewire::test(StudentSearch::class)
            ->set('query', 'a')
            ->assertDontSee('Aarav Sharma');
    }

    /** @test */
    public function it_matches_by_each_searchable_field(): void
    {
        $student = Student::factory()->create([
            'name'          => 'Priya Verma',
            'phone'         => '9810000002',
            'phone_2'       => '9810099999',
            'email'         => 'priya@example.com',
            'father_name'   => 'Suresh Verma',
            'ipu_user_id'   => 'IPU2026X042',
            'course'        => 'BBA',
        ]);

        $cases = [
            ['Priya',           'name'],
            ['9810000002',      'phone'],
            ['9810099999',      'phone_2'],
            ['priya@example',   'email'],
            ['Suresh',          'father_name'],
            ['IPU2026X042',     'ipu_user_id'],
            ['BBA',             'course'],
        ];

        foreach ($cases as [$query, $field]) {
            Livewire::test(StudentSearch::class)
                ->set('query', $query)
                ->assertSee('Priya Verma', false, "search by {$field} did not surface the student");
        }
    }

    /** @test */
    public function it_respects_visible_to_scope_for_non_admin(): void
    {
        $head = User::factory()->create(['name' => 'Sonam Sumit']);
        $head->assignRole('head');

        $hidden = Student::factory()->create([
            'name' => 'NotVisibleStudent',
            'phone' => '9999999991',
            'owner_id' => $this->admin->id, // admin-owned, not visible to head
        ]);

        $this->actingAs($head);

        Livewire::test(StudentSearch::class)
            ->set('query', 'NotVisible')
            ->assertDontSee('NotVisibleStudent');
    }

    /** @test */
    public function it_renders_correct_edit_url_in_results(): void
    {
        $student = Student::factory()->create([
            'name'  => 'Kabir Singh',
            'phone' => '9810000005',
        ]);

        Livewire::test(StudentSearch::class)
            ->set('query', 'Kabir')
            ->assertSee('/admin/students/'.$student->id.'/edit', false);
    }
}
```

- [ ] **Step 2: Run the tests — expect failure (component doesn't exist yet)**

Run:
```bash
cd /Users/Sumit/davya-crm
php artisan test --filter=StudentSearchTest 2>&1 | tail -30
```
Expected: 5 tests fail. Most likely error: `Class "App\Livewire\StudentSearch" not found` (or component-not-registered).

If preview code from the live-preview session is on disk (`app/Livewire/StudentSearch.php`), tests for `it_renders_the_search_input`, `it_returns_no_results_under_two_characters`, `it_matches_by_each_searchable_field`, and `it_renders_correct_edit_url_in_results` should pass; only the `visibleTo` test may pass or fail depending on whether `Student::scopeVisibleTo` exists. Verify the count of pass/fail and continue to Task 3.

- [ ] **Step 3: Commit failing tests**

Run:
```bash
git add tests/Feature/StudentSearchTest.php
git commit -m "test(student-search): failing tests for typeahead behavior"
```

---

## Task 3 — Implement `StudentSearch` Livewire component

**Files:**
- Create: `app/Livewire/StudentSearch.php`

- [ ] **Step 1: Write/confirm the component**

Create or overwrite `/Users/Sumit/davya-crm/app/Livewire/StudentSearch.php`:

```php
<?php

namespace App\Livewire;

use App\Models\Student;
use Livewire\Component;

class StudentSearch extends Component
{
    public string $query = '';

    public function students(): array
    {
        $q = trim($this->query);
        if (mb_strlen($q) < 2) {
            return [];
        }

        $builder = Student::query();
        if (method_exists(Student::class, 'scopeVisibleTo')) {
            $builder = $builder->visibleTo(auth()->user());
        }

        $like = "%{$q}%";

        return $builder
            ->where(function ($qq) use ($like) {
                $qq->where('name', 'LIKE', $like)
                   ->orWhere('phone', 'LIKE', $like)
                   ->orWhere('phone_2', 'LIKE', $like)
                   ->orWhere('email', 'LIKE', $like)
                   ->orWhere('father_name', 'LIKE', $like)
                   ->orWhere('ipu_user_id', 'LIKE', $like)
                   ->orWhere('course', 'LIKE', $like);
            })
            ->orderBy('updated_at', 'desc')
            ->limit(8)
            ->get(['id', 'name', 'phone', 'course', 'stage'])
            ->toArray();
    }

    public function render()
    {
        return view('livewire.student-search', [
            'students' => $this->students(),
        ]);
    }
}
```

- [ ] **Step 2: Verify class loads**

Run:
```bash
php artisan tinker --execute='echo class_exists(\App\Livewire\StudentSearch::class) ? "ok".PHP_EOL : "missing".PHP_EOL;'
```
Expected: `ok`

---

## Task 4 — Implement the blade view

**Files:**
- Create: `resources/views/livewire/student-search.blade.php`

- [ ] **Step 1: Write/confirm the view**

Create or overwrite `/Users/Sumit/davya-crm/resources/views/livewire/student-search.blade.php`:

```blade
<div x-data="{ visible: true }"
     @click.outside="visible = false"
     @focusin="visible = true"
     @keydown.escape.window="visible = false"
     style="flex: 1; position: relative;">
    <div style="display: flex; align-items: center; gap: 8px; background: var(--border-muted); border-radius: var(--r-md); padding: 6px 10px;">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="color: var(--text-muted); flex-shrink: 0;"><circle cx="11" cy="11" r="7"/><path d="m21 21-4.3-4.3"/></svg>
        <input type="text"
               wire:model.live.debounce.200ms="query"
               placeholder="Search students by name, phone, email…"
               style="flex: 1; border: 0; background: transparent; outline: 0; color: var(--text); font-size: var(--fs-12);">
        @if (strlen(trim($query)) > 0)
            <button type="button"
                    wire:click="$set('query', '')"
                    style="border: 0; background: transparent; color: var(--text-muted); cursor: pointer; padding: 0; line-height: 0;"
                    title="Clear">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
            </button>
        @endif
    </div>

    @if (mb_strlen(trim($query)) >= 2)
        <div x-show="visible" x-cloak
             style="position: absolute; top: calc(100% + 6px); left: 0; right: 0; background: var(--surface); border: 1px solid var(--border); border-radius: var(--r-md); box-shadow: 0 6px 20px rgba(0,0,0,0.08); z-index: 50; max-height: 60vh; overflow-y: auto;">
            @if (count($students) === 0)
                <div style="padding: 12px 14px; color: var(--text-muted); font-size: var(--fs-12);">
                    No students match "{{ $query }}".
                </div>
            @else
                @foreach ($students as $s)
                    <a href="{{ url('/admin/students/' . $s['id'] . '/edit') }}"
                       style="display: flex; align-items: baseline; gap: 8px; padding: 10px 14px; border-bottom: 1px solid var(--border-muted); text-decoration: none; color: var(--text); font-size: var(--fs-12);"
                       onmouseover="this.style.background='var(--border-muted)'"
                       onmouseout="this.style.background=''">
                        <strong style="font-size: var(--fs-13);">{{ $s['name'] ?: '—' }}</strong>
                        <span style="color: var(--text-sub);">{{ $s['phone'] ?? '' }}</span>
                        <span style="color: var(--text-muted);">·</span>
                        <span style="color: var(--text-sub);">{{ $s['course'] ?: '—' }}</span>
                        <span style="margin-left: auto; color: var(--text-muted); font-size: var(--fs-11);">{{ $s['stage'] }}</span>
                    </a>
                @endforeach
            @endif
        </div>
    @endif
</div>
```

- [ ] **Step 2: Run the new tests — expect green**

Run:
```bash
php artisan view:clear
php artisan test --filter=StudentSearchTest 2>&1 | tail -15
```
Expected: `Tests: 5 passed`.

If any test fails, read the error and fix the component or view in place. Re-run until green.

- [ ] **Step 3: Commit**

Run:
```bash
git add app/Livewire/StudentSearch.php resources/views/livewire/student-search.blade.php
git commit -m "feat(student-search): inline typeahead Livewire component"
```

---

## Task 5 — Mount in topbar

**Files:**
- Modify: `resources/views/livewire/top-bar.blade.php` (lines 11–17)

- [ ] **Step 1: Replace the ⌘K button with the component**

Edit `/Users/Sumit/davya-crm/resources/views/livewire/top-bar.blade.php`. Replace the seven-line block beginning with `<button type="button"` and ending with `</button>` (the one with `onclick="window.dispatchEvent(new CustomEvent('open-command-palette'))"`) with this single line:

```blade
    <livewire:student-search />
```

- [ ] **Step 2: Manual smoke check via dev server**

Start Laravel + Vite if not running, then visit `http://127.0.0.1:8000/admin` after login. Confirm:
- Search input is in the same flex slot where the ⌘K button used to be.
- Typing 2+ chars produces a dropdown.
- Clicking a row navigates to `/admin/students/{id}/edit`.

- [ ] **Step 3: Commit**

Run:
```bash
git add resources/views/livewire/top-bar.blade.php
git commit -m "feat(topbar): mount student-search in place of palette trigger"
```

---

## Task 6 — Drop `@livewire("command-palette")` from the panel render hook

**Files:**
- Modify: `app/Providers/Filament/AdminPanelProvider.php` (around line 44)

- [ ] **Step 1: Edit the render hook string**

Open `/Users/Sumit/davya-crm/app/Providers/Filament/AdminPanelProvider.php`. Find the line containing:

```php
. Blade::render('@livewire("top-bar") @livewire("command-palette") @livewire("student-peek-drawer")')
```

Change to:

```php
. Blade::render('@livewire("top-bar") @livewire("student-peek-drawer")')
```

- [ ] **Step 2: Verify no other references to `command-palette`**

Run:
```bash
grep -rn "command-palette\|CommandPalette" app/ resources/ tests/ 2>/dev/null | grep -v vendor
```
Expected output: only the file paths we are about to delete (`app/Livewire/CommandPalette.php`, `resources/views/livewire/command-palette.blade.php`). If anything else surfaces (e.g., a test or another view referencing it) — stop and address.

- [ ] **Step 3: Commit**

Run:
```bash
git add app/Providers/Filament/AdminPanelProvider.php
git commit -m "refactor(panel): drop command-palette mount from render hook"
```

---

## Task 7 — Delete the old palette files

**Files:**
- Delete: `app/Livewire/CommandPalette.php`
- Delete: `resources/views/livewire/command-palette.blade.php`

- [ ] **Step 1: Delete the files**

Run:
```bash
cd /Users/Sumit/davya-crm
git rm app/Livewire/CommandPalette.php resources/views/livewire/command-palette.blade.php
```

- [ ] **Step 2: Re-run the targeted tests + the full Feature suite**

Run:
```bash
php artisan view:clear
php artisan test --filter=StudentSearchTest 2>&1 | tail -10
php artisan test 2>&1 | tail -20
```
Expected: `StudentSearchTest`: all 5 green. Full suite: no NEW failures introduced. (Pre-existing failures unrelated to this change are out of scope; note them but don't fix here.)

- [ ] **Step 3: Commit**

Run:
```bash
git commit -m "refactor(palette): remove obsolete CommandPalette component + view"
```

---

## Task 8 — Final verification + push + PR

**Files:** none (verification only)

- [ ] **Step 1: Visual smoke at `/admin`**

Reload `http://127.0.0.1:8000/admin` (hard reload). Walk through the spec's Acceptance section:
- Topbar shows the input in the same slot as before — ✓
- Typing 2+ chars yields ≤ 8 matches across all 7 fields — ✓ (try `aar`, `9810000003`, `priya@`, `Singh`, `BCA`)
- Clicking a result lands on `/admin/students/{id}/edit` — ✓
- Kanban → click card still opens the peek drawer — ✓
- No console errors in browser DevTools — ✓

If any step fails, fix and add a regression test to `StudentSearchTest.php` before pushing.

- [ ] **Step 2: Push branch**

Run:
```bash
git push -u origin feature/student-search-bar
```

- [ ] **Step 3: Open PR with `gh`**

Run:
```bash
gh pr create --title "Student search bar (replaces ⌘K palette)" --body "$(cat <<'EOF'
## Summary
- Inline typeahead in the topbar slot where the ⌘K palette trigger lived.
- Searches across `name`, `phone`, `phone_2`, `email`, `father_name`, `ipu_user_id`, `course`.
- Clicking a result navigates straight to `/admin/students/{id}/edit`.
- Honors `Student::scopeVisibleTo` (admin sees all, head/staff see only theirs).
- Removes obsolete `CommandPalette` component + view + render-hook mount.

Spec: `docs/superpowers/specs/2026-04-26-student-search-bar-design.md`

## Test plan
- [x] `php artisan test --filter=StudentSearchTest` — 5 tests green
- [x] `php artisan test` — no NEW failures
- [x] Manual smoke at `/admin` (logged in as admin and as a head role)
- [x] Click-through to edit page works
- [x] Kanban peek drawer still functions

🤖 Generated with [Claude Code](https://claude.com/claude-code)
EOF
)"
```

- [ ] **Step 4: Done — link the PR URL in the session log**

Output: PR URL.

---

## Self-review (run before handing off)

**Spec coverage check** — every spec section maps to a task:

| Spec section | Covered by |
|---|---|
| UX (position, debounce, fields, click target, empty state, scope) | Tasks 3, 4 (component + view) |
| Files Add | Tasks 2, 3, 4 |
| Files Modify | Tasks 5, 6 |
| Files Delete | Task 7 |
| Tests (5 tests) | Task 2 |
| Acceptance | Task 8 |
| Rollout (push, PR) | Task 8 |

**Placeholder scan:** No TBDs/TODOs/"add appropriate handling" — all code blocks complete, all paths absolute.

**Type/symbol consistency:** `query` (string), `students()` (array of arrays with keys `id, name, phone, course, stage`), component class `App\Livewire\StudentSearch`, blade `livewire.student-search`, view path `resources/views/livewire/student-search.blade.php` — used identically across Tasks 2, 3, 4, 5, 8 and the spec.

**Note vs. spec:** the spec mentioned "Any ⌘K keybinding JS / open-command-palette event listeners in resources/js/ if present (sweep before delete)". A repo grep at planning time showed **no global keyboard binding for ⌘K exists** — the visual ⌘K hint in the original button was aspirational, never wired. So no JS-cleanup task was needed; this is documented here so the implementer doesn't go hunting.
