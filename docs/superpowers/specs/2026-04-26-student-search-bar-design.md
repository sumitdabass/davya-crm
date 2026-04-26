# Student search bar — design

**Date:** 2026-04-26
**Branch:** `feature/student-search-bar`
**Status:** Approved by Sumit after live preview at `http://127.0.0.1:8000/admin` (2026-04-26 ~13:40 IST)

## Problem

The visual-v2 topbar (shipped 2026-04-24) replaced Filament's stock global-search box with a `⌘K` command-palette modal. The palette:

- Searches only `name` and `phone`.
- Opens a peek drawer on student-row click instead of going to the edit page.
- Mixes student results with page-navigation results in one modal.

User feedback (2026-04-26): "remove existing its too complex i just want simply type and it give me results … just type phone number, name, or any information from student segment". Requested behaviour: same position, plain typeahead, click → student edit page.

## Goal

Replace the `⌘K` palette with an inline typeahead in the same topbar slot. Searches across the same fields the Filament global-search config already lists, and clicks navigate directly to the student edit page.

Non-goals: keyboard arrow navigation between results, fuzzy matching, recent-searches list, page-navigation search (it dies with the palette).

## UX

| Aspect | Behaviour |
|---|---|
| Position | Same flex slot in `top-bar.blade.php` where the `⌘K`-trigger button currently lives. |
| Trigger | Focus the input or type into it. No keyboard shortcut. |
| Min query | 2 chars (server-side gate; under 2 chars the dropdown is not rendered at all). |
| Debounce | 200 ms via `wire:model.live.debounce.200ms`. |
| Search fields | `name`, `phone`, `phone_2`, `email`, `father_name`, `ipu_user_id`, `course`. Single `OR` of `LIKE %q%` against each. Case-insensitive on the DB collation we already use. |
| Order | `updated_at desc`, limit 8. |
| Result row | `Name` (bold) · `phone` · `course` · `stage` (right-aligned, muted). |
| Click target | `<a href="/admin/students/{id}/edit">` — full-page navigation, no JS. |
| Empty state | Plain text "No students match \"{query}\"." |
| Clear | An ❎ button in the input clears the query (`wire:click="$set('query', '')"`) when query is non-empty. |
| Close behaviour | Alpine `@click.outside` and `@keydown.escape.window` set `visible=false`. Re-focus or re-click input sets `visible=true`. |
| Visibility scope | `Student::scopeVisibleTo(auth()->user())` — admin sees all, head/staff see only their visible students. Identical to the palette's existing scope. |

## Files

### Add
- `app/Livewire/StudentSearch.php` — Livewire 3 component. Single public string `$query`; `students()` method returns up to 8 matches as plain arrays; renders `livewire.student-search`.
- `resources/views/livewire/student-search.blade.php` — Alpine `x-data="{ visible: true }"`, the input, server-rendered dropdown gated by `mb_strlen(trim($query)) >= 2`.
- `tests/Feature/StudentSearchTest.php` — covers: render, ≥2-char gate, hits each searchable field, `visibleTo` scope respected for non-admin, click target URL.

### Modify
- `resources/views/livewire/top-bar.blade.php` — replace the `⌘K`-button block (current lines 11–17) with `<livewire:student-search />`.
- `app/Providers/Filament/AdminPanelProvider.php` — drop the `@livewire("command-palette")` from the render-hook string. (Line 44, the `Blade::render('@livewire("top-bar") @livewire("command-palette") @livewire("student-peek-drawer")')` literal.)

### Delete
- `app/Livewire/CommandPalette.php`
- `resources/views/livewire/command-palette.blade.php`
- Any `⌘K` keybinding JS / `open-command-palette` event listeners in `resources/js/` if present (sweep before delete).

### Keep (unchanged)
- `app/Livewire/Drawer/StudentPeekDrawer.php` and the `open-student-peek` event — kanban cards still use the drawer.
- `StudentResource::getGloballySearchableAttributes()` etc. — Filament's stock global-search hooks remain for future use; we just don't surface them in visual-v2.

## Architecture sketch

```
TopBar (Livewire)
└── student-search.blade.php (Livewire)
    ├── <input wire:model.live.debounce.200ms="query">
    └── @if (strlen >= 2)
        Alpine x-show="visible"
        ├── @if no matches → empty-state text
        └── @foreach $students → <a href="/admin/students/{id}/edit">
```

Data flow: keystroke → debounce 200 ms → Livewire round-trip → `students()` runs scoped query → blade re-renders dropdown. No JS state to keep in sync.

## Permissions

Inherits the existing `scopeVisibleTo` pattern. No new gates. Admin role sees every student matching the query; head/staff see only what they could already see in the kanban / students list. No information disclosure surface that didn't already exist.

## Performance

- 7 columns × `LIKE %q%` is non-trivial but bounded by the `limit 8`. With ≤ 5k students (current prod count is in single digits after the 2026-04-26 purge), this is sub-100 ms even without indexes.
- Future work, not in scope: a generated `searchable_blob` column with a fulltext index if the table grows past ~20k rows.
- Debounce keeps round-trips under 5/s for fast typists.

## Edge cases

| Case | Behaviour |
|---|---|
| Query is whitespace only | `trim()` collapses to empty → no dropdown rendered. |
| Query exactly 1 char | Dropdown not rendered (server-side gate). |
| Query 2 chars, no matches | "No students match \"xx\"." rendered. |
| User clears via ❎ then types again | Same component, no remount; state resets correctly. |
| User clicks a result on a slow connection | Native `<a>` navigation — browser handles, no JS race. |
| Topbar is mounted on `/admin/login` | Component renders but is invisible (login page CSS hides chrome). Confirmed harmless during preview. |

## Tests (to add)

`tests/Feature/StudentSearchTest.php` (Pest or PHPUnit, follow repo convention):

1. `it_renders_the_topbar_search_input` — `livewire(StudentSearch::class)->assertSee('Search students')`.
2. `it_returns_no_results_under_2_chars` — set `query='a'`, assert `students()` is empty.
3. `it_matches_by_each_searchable_field` — seed a student with distinctive `email`, `father_name`, `ipu_user_id`, etc. Assert each field independently surfaces the row.
4. `it_respects_visible_to_scope` — log in as a non-admin user; assert students they can't see are filtered out.
5. `it_renders_correct_edit_url_in_results` — the dropdown row's `href` equals `/admin/students/{id}/edit`.

## Acceptance

Owner verifies on staging or local that:

- Topbar shows the input in the same slot as before.
- Typing 2+ chars yields ≤ 8 matches across name / phone / phone_2 / email / father_name / ipu_user_id / course.
- Clicking a result lands on `/admin/students/{id}/edit`.
- ⌘K does nothing (palette removed).
- Kanban → click card still opens the peek drawer.
- All existing tests pass; new `StudentSearchTest` is green.

## Out of scope

- Mobile-specific layout (existing topbar already responsive).
- Pagination / "show more" beyond 8 results.
- Recent-searches dropdown.
- Search analytics or telemetry.
- Replacing the peek drawer or kanban search.

## Rollout

- Commit on `feature/student-search-bar`.
- Open PR; review focus = the deletes (CommandPalette + JS), the field list, and the test coverage.
- Squash-merge to `main`.
- No migration, no cache flush needed in prod beyond the standard `php artisan view:clear`.
- Risk = low; revert by reverting the merge commit.
