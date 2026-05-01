# Mobile UX Pass — Visual v2 — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Eliminate field/value/button overlap on phone-width viewports across 5 high-traffic Filament surfaces in davya-crm Visual v2.

**Architecture:** Hybrid — Filament's responsive grid API (`columns(['default' => 1, 'md' => N])`) for surfaces that ARE Filament forms (1, 2). CSS-only fixes — extending the existing `@media (max-width: 768px)` block in `resources/css/tokens.css` — for custom blades (3, 5). Surface 4 (kanban) is investigative — likely a no-op based on existing CSS analysis but verified at the start of that task.

**Tech Stack:** Laravel 11 + Filament 3, custom Visual v2 CSS at `resources/css/tokens.css` (synced to `public/css/tokens.css` via `npm run build:tokens` which is just `cp`), Pest test suite (run via `php artisan test`).

**Spec:** `docs/superpowers/specs/2026-05-01-mobile-ux-pass-design.md`

---

## Task 1: StudentResource form responsive grid

**Files:**
- Modify: `app/Filament/Resources/StudentResource.php` (lines 147, 169, 187, 211)

- [ ] **Step 1.1: Read the file to confirm columns() call-sites**

```bash
grep -n "->columns(" app/Filament/Resources/StudentResource.php
```

Expected output (matches the 4 form-tab call-sites + 1 unrelated table-columns call):
```
147:                        ], self::customFieldsForSection('Source & Stage')))->columns(2)
169:                        ]))->columns(3)
187:                        ], self::customFieldsForSection('Deal'), self::customFieldsForSection('Counselling')))->columns(2)
211:                        ], self::customFieldsForSection('Closure')))->columns(2)
304:            ->columns(array_merge($baseColumns, (new DynamicTableColumns())->build()))
```

Lines 147, 169, 187, 211 are tab grids → fix. Line 304 is the table builder → leave alone.

- [ ] **Step 1.2: Edit line 147 (Identity tab — 2-col)**

Use Edit tool to change:

```php
                        ], self::customFieldsForSection('Source & Stage')))->columns(2)
```

To:

```php
                        ], self::customFieldsForSection('Source & Stage')))->columns(['default' => 1, 'md' => 2])
```

- [ ] **Step 1.3: Edit line 169 (Academic tab — 3-col)**

Change:

```php
                        ]))->columns(3)
```

To:

```php
                        ]))->columns(['default' => 1, 'md' => 3])
```

- [ ] **Step 1.4: Edit line 187 (Deal & Counselling tab — 2-col)**

Change:

```php
                        ], self::customFieldsForSection('Deal'), self::customFieldsForSection('Counselling')))->columns(2)
```

To:

```php
                        ], self::customFieldsForSection('Deal'), self::customFieldsForSection('Counselling')))->columns(['default' => 1, 'md' => 2])
```

- [ ] **Step 1.5: Edit line 211 (Closure tab — 2-col)**

Change:

```php
                        ], self::customFieldsForSection('Closure')))->columns(2)
```

To:

```php
                        ], self::customFieldsForSection('Closure')))->columns(['default' => 1, 'md' => 2])
```

- [ ] **Step 1.6: Verify all 4 sites changed**

```bash
grep -n "->columns(" app/Filament/Resources/StudentResource.php
```

Expected output: 4 sites with `['default' => 1, 'md' => N]` syntax + line 304 (table builder, unchanged).

- [ ] **Step 1.7: Run regression tests**

```bash
php artisan test --filter=StudentResource
```

Expected: PASS — no test should fail. Form rendering smoke is exercised by existing Filament test scaffolding.

- [ ] **Step 1.8: Commit**

```bash
git add app/Filament/Resources/StudentResource.php
git commit -m "feat(mobile): StudentResource form responsive grid — single col below md"
```

---

## Task 2: PaymentReport filter row responsive grid

**Files:**
- Modify: `app/Filament/Pages/PaymentReport.php:57`

- [ ] **Step 2.1: Read the form() method to confirm**

```bash
grep -n "->columns(" app/Filament/Pages/PaymentReport.php
```

Expected: line 57 → `->columns(3)`.

- [ ] **Step 2.2: Edit the columns() call**

Use Edit tool. Change:

```php
            ->columns(3)
            ->statePath('data');
```

To:

```php
            ->columns(['default' => 1, 'md' => 3])
            ->statePath('data');
```

- [ ] **Step 2.3: Run regression tests**

```bash
php artisan test --filter=PaymentReport
```

Expected: PASS.

- [ ] **Step 2.4: Commit**

```bash
git add app/Filament/Pages/PaymentReport.php
git commit -m "feat(mobile): PaymentReport filter row responsive grid"
```

---

## Task 3: Peek drawer mobile — blade hooks + CSS

**Files:**
- Modify: `resources/views/livewire/student-peek-drawer.blade.php` (lines 46, 69)
- Modify: `resources/css/tokens.css` (extend existing `@media (max-width: 768px)` block at line 266)

- [ ] **Step 3.1: Add `davya-drawer-tabs` class hook to the tab strip (line 46)**

Use Edit tool. Find this line in `resources/views/livewire/student-peek-drawer.blade.php`:

```blade
                <div style="display: flex; gap: 18px; padding: 0 18px; border-bottom: 1px solid var(--border); font-size: var(--fs-12);">
```

Change to:

```blade
                <div class="davya-drawer-tabs" style="display: flex; gap: 18px; padding: 0 18px; border-bottom: 1px solid var(--border); font-size: var(--fs-12);">
```

- [ ] **Step 3.2: Add `davya-drawer-footer` class hook to the footer (line 69)**

Find:

```blade
                <div style="position: sticky; bottom: 0; background: var(--surface); border-top: 1px solid var(--border); padding: 10px 18px; display: flex; justify-content: space-between; align-items: center;">
```

Change to:

```blade
                <div class="davya-drawer-footer" style="position: sticky; bottom: 0; background: var(--surface); border-top: 1px solid var(--border); padding: 10px 18px; display: flex; justify-content: space-between; align-items: center;">
```

- [ ] **Step 3.3: Add CSS rules to `resources/css/tokens.css`**

Use Edit tool. Find the closing `}` of the `@media (max-width: 768px)` block — currently around line 329 (just before line 331's `@media (max-width: 480px)`). The last existing rule in that block is:

```css
    /* Pipeline-config: stack action buttons in stage rows instead of overflowing */
    body.davya-v2 .davya-card-row { flex-wrap: wrap; }
}
```

Change to (insert new rules before the closing `}`):

```css
    /* Pipeline-config: stack action buttons in stage rows instead of overflowing */
    body.davya-v2 .davya-card-row { flex-wrap: wrap; }

    /* Peek drawer tab strip — horizontal scroll on phones */
    body.davya-v2 .davya-drawer-tabs {
        overflow-x: auto;
        flex-wrap: nowrap;
        scrollbar-width: none;
        -webkit-overflow-scrolling: touch;
    }
    body.davya-v2 .davya-drawer-tabs::-webkit-scrollbar { display: none; }

    /* Peek drawer footer — wrap CTAs to full-width row */
    body.davya-v2 .davya-drawer-footer {
        flex-wrap: wrap;
        gap: 8px;
    }
    body.davya-v2 .davya-drawer-footer > div {
        width: 100%;
        justify-content: flex-start;
    }
}
```

- [ ] **Step 3.4: Sync tokens.css to public/**

```bash
npm run build:tokens
```

This runs `cp resources/css/tokens.css public/css/tokens.css`. Expected: silent success.

Verify:

```bash
diff resources/css/tokens.css public/css/tokens.css && echo "synced"
```

Expected: prints `synced` (zero diff).

- [ ] **Step 3.5: Browser smoke at 360px**

Start local server if not running:

```bash
/opt/alt/php84/usr/bin/php artisan serve
```

In Chrome DevTools, set device to 360×800. Visit `/admin/kanban`, click any kanban card to open peek drawer.

Verify:
- 5 tabs (Overview / Payments / Notes / Meetings / Activity) all visible — strip horizontally scrolls if they overflow.
- Footer "Update Information" + "+ Note" + "+ Payment" wrap to two rows, all buttons readable.

If broken, fix CSS and re-test before committing.

- [ ] **Step 3.6: Commit**

```bash
git add resources/views/livewire/student-peek-drawer.blade.php resources/css/tokens.css public/css/tokens.css
git commit -m "feat(mobile): peek drawer tab strip + footer wrap on phones"
```

---

## Task 4: Kanban card investigation

**Files:**
- Read: `resources/css/tokens.css:111-140` (already read; `.davya-dense-card` is `display: flex; align-items: center; gap: var(--s-2);` with `.n` having `flex: 1` + `text-overflow: ellipsis` and `.av` having `flex-shrink: 0`)
- Possibly modify: `resources/css/tokens.css` (mobile @media block)

- [ ] **Step 4.1: Browser smoke at 360px on /admin/kanban**

Start server (if not running). DevTools at 360×800. Visit `/admin/kanban`.

Visual check on dense kanban cards:
- Does the student name truncate cleanly with ellipsis (expected)?
- Do the chips, amount, and avatar stay on one row without overlap (expected)?
- Is the column width 240px (set by existing `tokens.css:317`)?

- [ ] **Step 4.2: Decision branch**

**Branch A: No overlap reproduced (expected outcome).**

Skip this surface. Append a note to `docs/sessions/2026-05-01-mobile-ux-pass.md` (created in Task 6):

```markdown
## Surface 4 — Kanban cards: SKIPPED

Visual smoke at 360px showed no overlap. `.davya-dense-card` already uses
`flex: 1` + `text-overflow: ellipsis` on `.n` and `flex-shrink: 0` on `.av`,
so the layout truncates the name rather than overlapping siblings. No fix
needed.
```

Skip to Task 5.

**Branch B: Overlap is reproducible.**

Add this rule to `resources/css/tokens.css` inside the `@media (max-width: 768px)` block (alongside the drawer rules from Task 3):

```css
    /* Kanban dense card — wrap children if column gets very narrow */
    body.davya-v2 .davya-dense-card {
        flex-wrap: wrap;
        row-gap: 4px;
    }
```

Then `npm run build:tokens` to sync, browser smoke again to confirm fix.

If overlap persists or markup needs structural changes (e.g., absolute positioning to remove), STOP and update the spec before continuing.

- [ ] **Step 4.3: Commit (Branch B only)**

```bash
git add resources/css/tokens.css public/css/tokens.css
git commit -m "feat(mobile): kanban dense card wraps children on narrow widths"
```

If Branch A: no commit needed for this task.

---

## Task 5: Dashboard custom-table widget tables

**Files:**
- Modify: `resources/views/filament/widgets/today-payments-widget.blade.php`
- Modify: `resources/views/filament/widgets/today-meetings-widget.blade.php`
- Modify: `resources/css/tokens.css` (add a single non-conditional rule)

- [ ] **Step 5.1: Wrap `today-payments-widget.blade.php` table**

Use Edit tool. Find:

```blade
        @if(count($this->rows) === 0)
            <div class="text-sm text-gray-400">No payments yet today.</div>
        @else
            <table class="w-full text-sm">
```

Change to:

```blade
        @if(count($this->rows) === 0)
            <div class="text-sm text-gray-400">No payments yet today.</div>
        @else
            <div class="davya-table-scroll">
            <table class="w-full text-sm">
```

Then find the closing `</table>` tag (last instance in this file) and change:

```blade
            </table>
        @endif
```

To:

```blade
            </table>
            </div>
        @endif
```

- [ ] **Step 5.2: Wrap `today-meetings-widget.blade.php` table**

First, read the file to find the `<table>` and `</table>`:

```bash
grep -n "<table\|</table>" resources/views/filament/widgets/today-meetings-widget.blade.php
```

Apply the same wrap pattern: insert `<div class="davya-table-scroll">` immediately before `<table` and `</div>` immediately after `</table>`. Match indentation of the surrounding context.

- [ ] **Step 5.3: Add the universal rule to tokens.css**

Append at the very bottom of `resources/css/tokens.css` (after the `@media (max-width: 480px)` block — last line currently 333):

```css

/* Horizontal-scroll wrapper for hand-rolled <table> blocks inside cards */
.davya-table-scroll {
    overflow-x: auto;
    -webkit-overflow-scrolling: touch;
}
```

Note the leading blank line for readability.

- [ ] **Step 5.4: Sync tokens.css to public/**

```bash
npm run build:tokens
diff resources/css/tokens.css public/css/tokens.css && echo "synced"
```

Expected: `synced`.

- [ ] **Step 5.5: Browser smoke at 360px**

DevTools 360×800. Visit `/admin` (dashboard). Today Payments and Today Meetings cards should:
- Show full table with horizontal scroll if needed
- No content clipped at the card edge
- Total row at bottom still readable

- [ ] **Step 5.6: Commit**

```bash
git add resources/views/filament/widgets/today-payments-widget.blade.php resources/views/filament/widgets/today-meetings-widget.blade.php resources/css/tokens.css public/css/tokens.css
git commit -m "feat(mobile): horizontal-scroll wrapper for dashboard custom-table widgets"
```

---

## Task 6: Final smoke + regression + session log

**Files:**
- Create: `docs/sessions/2026-05-01-mobile-ux-pass.md`

- [ ] **Step 6.1: Run full test suite**

```bash
php artisan test
```

Expected: 590 tests pass (the existing baseline). Any new failure must be addressed before continuing.

- [ ] **Step 6.2: Run pint to confirm clean lint**

```bash
./vendor/bin/pint --test app/Filament/Resources/StudentResource.php app/Filament/Pages/PaymentReport.php
```

Expected: `{"result":"pass"}`. If fail, run without `--test` to autofix, then re-test.

- [ ] **Step 6.3: Create session log file**

Use Write tool to create `docs/sessions/2026-05-01-mobile-ux-pass.md`:

```markdown
# Mobile UX Pass — Visual v2 — Session Log

**Date:** 2026-05-01
**Spec:** `docs/superpowers/specs/2026-05-01-mobile-ux-pass-design.md`
**Plan:** `docs/superpowers/plans/2026-05-01-mobile-ux-pass.md`

## Surfaces fixed

1. **StudentResource form** — 4 tabs converted to responsive grid (`columns(['default' => 1, 'md' => N])`).
2. **PaymentReport filter row** — From/To/Owner row collapses to single column below md.
3. **Peek drawer** — tab strip horizontally scrolls; footer CTAs wrap to full-width row below md.
4. **Kanban cards** — [BRANCH A: SKIPPED — no overlap reproducible] OR [BRANCH B: wrap rule added].
5. **Dashboard custom-table widgets** — Today Payments + Today Meetings tables wrapped in `.davya-table-scroll`.

## Manual smoke matrix

Tested at widths 360 / 390 / 414 / 768 px in Chrome DevTools device emulator.

| Surface | 360 | 390 | 414 | 768 |
|---|---|---|---|---|
| StudentResource Create | OK | OK | OK | OK |
| StudentResource Edit | OK | OK | OK | OK |
| PaymentReport filters | OK | OK | OK | OK |
| Peek drawer (open from kanban) | OK | OK | OK | OK |
| Peek drawer footer CTAs | OK | OK | OK | OK |
| Kanban /admin/kanban | OK | OK | OK | OK |
| Today Payments card | OK | OK | OK | OK |
| Today Meetings card | OK | OK | OK | OK |

## Test suite

- `php artisan test` — 590/590 passing post-change.
- `./vendor/bin/pint --test` — clean.

## Known follow-ups

- (Audit item #4) inline-style sprawl in v2 blades — separate spec.
- (Audit item #3) Filament tailwind utility colors not reaching admin pages — separate spec.

## Deploy

Local-only as of this commit. Sumit to confirm via local IP serve on phone before push.
```

If Task 4 went down Branch B (kanban fix needed), edit the line "4. **Kanban cards** — ..." accordingly to mention the wrap rule added.

- [ ] **Step 6.4: Manual smoke matrix execution**

Walk through each cell of the matrix above in DevTools. For each: load the page, set viewport to the listed width, visually confirm no overlap. Update the cell with OK or FAIL. If any FAIL, STOP and revisit the relevant Task.

- [ ] **Step 6.5: Capture before/after screenshots (optional, recommended)**

For each fixed surface, capture 2 screenshots at 360×800: one before the fix (use `git stash` to revert temporarily), one after (`git stash pop`). Save to `docs/sessions/screenshots/2026-05-01-mobile-ux-pass/`. Reference them in the session log.

This step is optional — visual proof for the user but not blocking.

- [ ] **Step 6.6: Commit session log**

```bash
git add docs/sessions/2026-05-01-mobile-ux-pass.md
git add docs/sessions/screenshots/2026-05-01-mobile-ux-pass/ 2>/dev/null || true
git commit -m "docs(sessions): mobile UX pass smoke results"
```

- [ ] **Step 6.7: STOP — hand off to user**

The plan ends here. Sumit smoke-tests on his actual phone (local IP serve), confirms each surface looks right, then we push to GitHub and pull on Hostinger per `DEPLOY.md`. No prod migration, no asset build (tokens.css already published with `?v={filemtime}` cache-bust per Visual v2).

Do **NOT** push to remote or pull on prod from inside this plan. Wait for Sumit's confirmation.

---

## Self-review

**Spec coverage:**
- Surface 1 (StudentResource form) → Task 1 ✅
- Surface 2 (PaymentReport filters) → Task 2 ✅
- Surface 3 (Peek drawer) → Task 3 ✅
- Surface 4 (Kanban cards) → Task 4 (with branched outcome) ✅
- Surface 5 (Dashboard custom-table widgets) → Task 5 ✅
- Manual smoke at 4 breakpoints → Task 6 ✅
- Test suite no-regression → Task 6.1 ✅
- Pint clean → Task 6.2 ✅
- Session log → Task 6.3 ✅
- Deploy gate (local-only, await Sumit) → Task 6.7 ✅

**Placeholder scan:** None. Every step has either exact code, exact command, or a branch decision with both outcomes specified.

**Type consistency:** Filament `columns(['default' => 1, 'md' => N])` syntax used identically across Tasks 1, 2. Class names `davya-drawer-tabs`, `davya-drawer-footer`, `davya-table-scroll` are unique and used identically in blade and CSS. CSS rules nested correctly inside the existing `@media (max-width: 768px)` block (Task 3, Task 4 Branch B) versus outside it for the universal `.davya-table-scroll` (Task 5).

**Plan length:** ~250 LoC of changes across 6 PHP/blade/CSS files. ~2 hours of work as estimated in the spec.
