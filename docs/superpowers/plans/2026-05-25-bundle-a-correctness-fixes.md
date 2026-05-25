# Bundle A — Correctness Fixes Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ship 4 correctness bug fixes (Meeting reparent on MERGE, 5 dropped-column refs, Fraunces font fallback, Drive URL crash) plus 1 access-control gap (KanbanBoard freelancer reach) — application layer only, no migrations.

**Architecture:** One new `HasOne` accessor on `Student` (`latestAdmittedRound`) as single source of truth for closed-admission data; six file edits route stale `final_college` / `final_course` / `admission_date` reads through it. CSS edits hit both `resources/css/tokens.css` and `public/css/tokens.css` per the drift gotcha. No schema, no routes, no migrations.

**Tech Stack:** Laravel 11, Filament 3, PHP 8.5 CLI, MySQL prod / SQLite `:memory:` tests, PHPUnit 11 (Pest is NOT installed — this codebase uses bare PHPUnit + the `tests/` autoloader; do not write `it(...)` syntax).

**Spec:** `docs/superpowers/specs/2026-05-25-bundle-a-correctness-fixes-design.md`

---

## File Structure

**New files (4):**
- `tests/Unit/Models/StudentLatestAdmittedRoundTest.php` — unit test for the new accessor
- `tests/Feature/StudentCsvExportTest.php` — CSV export emits RoundHistory data in admission columns
- `tests/Feature/Resources/Payment/ProofUrlResilienceTest.php` — Drive URL failure is graceful
- `tests/Feature/Filament/KanbanBoardAccessTest.php` — role gate enforcement

**Modified files (10):**
- `resources/css/tokens.css` — 7× `'Fraunces', Georgia, serif` → `var(--font-display)`; remove stale comment block
- `public/css/tokens.css` — mirror identical change (drift trap)
- `app/Models/Student.php` — add `use HasOne` + `latestAdmittedRound()` relation
- `app/Services/LeadIntakeService.php:137-142` — add `Meeting::where(...)->update(...)` line
- `app/Dashboard/RowFormatter.php:17` — `final_college` key now reads via `latestAdmittedRound`
- `app/Dashboard/Cards/Stat/AdmissionsClosedTodayCard.php:49-56` — `baseQuery()` eager-loads `latestAdmittedRound`
- `app/Dashboard/Cards/Stat/TeamStatCard.php:108-128` — admissions metric eager-loads `latestAdmittedRound`
- `app/Filament/Resources/StudentResource/Pages/ListStudents.php:38,75-80` — eager-load + read accessor
- `app/Filament/Pages/KanbanBoard.php:20-32,307-311` — add `canAccess()` + drop 3 dead keys from `$allowed` whitelist
- `app/Filament/Resources/Shared/PaymentFormSchema.php:87-91` — wrap `Storage::disk('drive')->url(...)` in try/catch
- `tests/Feature/LeadIntakeServiceParityTest.php` — append B1 regression test (existing file)

**Sequencing rationale:** B3 first (CSS-only, totally independent), then B1 (small isolated fix), then B2 inside-out (accessor → cards → CSV → KanbanBoard whitelist), then B4 (Drive), then AC1 (KanbanBoard gate). Each step commits independently so partial progress is reviewable.

---

## Pre-flight

- [ ] **Step 0.1: Confirm branch and clean tree**

```bash
git status
git branch --show-current
```

Expected: branch is `main` (or a feature branch you opened for this bundle), working tree clean, HEAD at `da8ce24` or later (the spec commit).

- [ ] **Step 0.2: Confirm baseline suite is green**

```bash
php -d memory_limit=2048M vendor/bin/phpunit 2>&1 | tail -10
```

Expected: `Tests: 870, ..., Skipped: 1`. (Do NOT use `php artisan test --parallel` — paratest crashes at default 128MB on this codebase; the memory bump is the audited workaround. Fixing the artisan recipe is a separate bundle.)

If failing on un-touched tests, **STOP** and investigate before plan execution.

- [ ] **Step 0.3: Confirm spec is committed**

```bash
git log --oneline docs/superpowers/specs/2026-05-25-bundle-a-correctness-fixes-design.md
```

Expected: at least one commit `da8ce24 docs(spec): Bundle A — Correctness Fixes design`.

---

## Sub-Bundle 1 — B3 Fraunces → Bricolage (CSS only, independent)

### Task 1: Replace 7× Fraunces in `resources/css/tokens.css`

**Files:**
- Modify: `resources/css/tokens.css` (lines 1017, 1096, 1154, 1243, 1251, 1276, 1327)

- [ ] **Step 1.1: Confirm the 7 hardcoded occurrences exist**

```bash
grep -n "'Fraunces', Georgia, serif" resources/css/tokens.css
```

Expected output (exact 7 lines):

```
1017:    font-family: 'Fraunces', Georgia, serif;
1096:    font-family: 'Fraunces', Georgia, serif;
1154:    font-family: 'Fraunces', Georgia, serif;
1243:    font-family: 'Fraunces', Georgia, serif;
1251:    font-family: 'Fraunces', Georgia, serif;
1276:    font-family: 'Fraunces', Georgia, serif;
1327:    font-family: 'Fraunces', Georgia, serif;
```

If the count is not exactly 7, STOP — re-verify against current code before editing.

- [ ] **Step 1.2: Replace every occurrence with `var(--font-display)`**

Use `sed` for a single pass:

```bash
sed -i.bak "s/font-family: 'Fraunces', Georgia, serif;/font-family: var(--font-display);/g" resources/css/tokens.css
rm resources/css/tokens.css.bak
```

- [ ] **Step 1.3: Verify zero remaining hardcoded Fraunces font-family rules**

```bash
grep -n "'Fraunces', Georgia, serif" resources/css/tokens.css
```

Expected: no output (exit code 1 is fine — that means "no matches"). Leave the **comment-only** occurrences at lines 604, 631, 991 untouched for now; they are descriptive prose explaining historical intent and will get a single sweep at Step 1.6.

- [ ] **Step 1.4: Update the stale prose comment at lines ~1547-1549**

Read `resources/css/tokens.css` around line 1547 to find the comment referencing "Instrument Serif via --font-display". Replace any such language with "Bricolage Grotesque via --font-display (italic display dropped 2026-05-25, commit a3f2c20)". If the comment is gone or differently worded, skip this step — do NOT invent text.

```bash
grep -n "Instrument Serif" resources/css/tokens.css
```

If output exists, edit each occurrence using the `Edit` tool with the exact line as `old_string`.

- [ ] **Step 1.5: Verify the file still parses as CSS**

```bash
node -e "console.log(require('fs').readFileSync('resources/css/tokens.css','utf8').length)" 2>&1 || wc -c resources/css/tokens.css
```

Expected: a byte count (no parse error). File size should be within ~200 bytes of the original.

- [ ] **Step 1.6: Sweep prose `Fraunces` mentions in the comments**

The 3 remaining `Fraunces` mentions at approximately lines 604, 631, 991 are header comments describing visual intent. Update them to read `Bricolage display` (or similar) so future readers aren't misled. Example:

```css
/* === Display face (Fraunces) — applied sparingly to identity surfaces. === */
```

becomes:

```css
/* === Display face (Bricolage) — applied sparingly to identity surfaces. === */
```

Apply the equivalent rewording to lines ~631 and ~991. If you find that a comment is more elaborate ("Fraunces italic display + emerald hairline..."), keep the structure and just swap the font name; don't reword the whole block.

- [ ] **Step 1.7: Run the test suite — no CSS-related failures**

```bash
php -d memory_limit=2048M vendor/bin/phpunit 2>&1 | tail -5
```

Expected: `Tests: 870, ..., Skipped: 1`. CSS isn't covered by automated tests so this is just a regression check that nothing else broke.

### Task 2: Mirror `public/css/tokens.css`

**Files:**
- Modify: `public/css/tokens.css` (same 7 Fraunces occurrences + same comment sweep)

- [ ] **Step 2.1: Confirm public file currently matches resources file**

```bash
diff resources/css/tokens.css public/css/tokens.css
```

Expected at this point: there IS a diff (you just edited `resources/`, not `public/`). The diff should be ONLY the 7 font-family lines + the comment edits from Task 1. Any other diff = STOP and reconcile.

- [ ] **Step 2.2: Copy resources → public to enforce parity**

```bash
cp resources/css/tokens.css public/css/tokens.css
```

This satisfies the `reference_davya-crm_tokens_css_drift.md` invariant — both files must stay byte-identical.

- [ ] **Step 2.3: Verify diff is now clean**

```bash
diff resources/css/tokens.css public/css/tokens.css
echo "exit=$?"
```

Expected: no output, `exit=0`.

### Task 3: Commit B3

- [ ] **Step 3.1: Stage and commit**

```bash
git add resources/css/tokens.css public/css/tokens.css
git status --short
```

Expected: only the two CSS files staged.

```bash
git commit -m "$(cat <<'EOF'
fix(css): Fraunces → var(--font-display) — 7 silent Georgia fallbacks

Fraunces was hardcoded 7× across tokens.css (AI drawer, role
labels, empty states) but never imported, so production rendered
Georgia for every Fraunces reference. Route them through
--font-display (Bricolage Grotesque, already loaded) per the
2026-05-25 italic-drop direction.

Mirrors both tokens.css files per drift trap.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Sub-Bundle 2 — B1 Meeting reparent on MERGE demotion

### Task 4: Failing regression test in `LeadIntakeServiceParityTest`

**Files:**
- Modify: `tests/Feature/LeadIntakeServiceParityTest.php` (append new test method)

- [ ] **Step 4.1: Read the existing file to find a good insertion point**

```bash
head -60 tests/Feature/LeadIntakeServiceParityTest.php
```

Expected: PHPUnit-style class extending `Tests\TestCase` with `use RefreshDatabase;` trait. Locate the last `public function test_...` method in the class so the new test goes after it (inside the class, before the closing `}`).

- [ ] **Step 4.2: Add the failing test**

Append this test method inside the test class (just before the closing `}`):

```php
public function test_merge_demotion_reparents_meetings(): void
{
    $sumit = User::where('email', 'sumit@davya.local')->first();
    $sonam = User::factory()->create(['name' => 'Sonam']);
    $sonam->assignRole('head');

    // Sumit-owned lead lands first; later Sonam re-ingests the same phone.
    // Per LeadPriority Sonam > Sumit, so Sumit's existing row is demoted.
    $sumitStudent = \App\Models\Student::create([
        'phone' => '9444000999',
        'name' => 'Walk-in',
        'owner_id' => $sumit->id,
        'lead_source' => 'Walk-in',
        'stage' => 'New',
    ]);

    \App\Models\Meeting::create([
        'student_id' => $sumitStudent->id,
        'scheduled_at' => now()->addDay(),
        'subject' => 'Initial chat',
        'status' => 'scheduled',
    ]);

    app(\App\Services\LeadIntakeService::class)->ingest([
        'phone' => '9444000999',
        'name' => 'Walk-in',
        'owner_name' => 'Sonam',
        'source' => 'Sheet:Sonam',
    ]);

    $winner = \App\Models\Student::where('owner_id', $sonam->id)
        ->where('phone', '9444000999')->first();
    $this->assertNotNull($winner, 'Sonam-owned winner row must exist after MERGE.');

    $this->assertSame(
        1,
        \App\Models\Meeting::where('student_id', $winner->id)->count(),
        'Meeting must reparent from demoted Sumit row to Sonam winner row.'
    );
    $this->assertSame(
        0,
        \App\Models\Meeting::where('student_id', $sumitStudent->id)->count(),
        'Demoted Sumit row must no longer own the meeting.'
    );
}
```

If the file does NOT already `use App\Models\User;` or has fewer imports than the test needs, add the missing `use` statements at the top of the file alongside existing ones.

- [ ] **Step 4.3: Run the new test — expect failure**

```bash
php -d memory_limit=2048M vendor/bin/phpunit --filter test_merge_demotion_reparents_meetings 2>&1 | tail -15
```

Expected: 1 test, 1 assertion failure. The failure message should say either:
- `Meeting must reparent from demoted Sumit row to Sonam winner row.` (assertion fail), OR
- `Failed asserting that 1 is identical to 0.` (because the meeting is still on the demoted row)

If the test passes immediately, STOP — that means either the bug is already fixed or the test isn't exercising the MERGE path. Investigate before continuing.

### Task 5: Add Meeting to `reparentChildren()`

**Files:**
- Modify: `app/Services/LeadIntakeService.php:5-12` (add `use App\Models\Meeting;`) + `:137-142` (add Meeting reparent line)

- [ ] **Step 5.1: Add the Meeting import**

In `app/Services/LeadIntakeService.php`, the existing imports are at lines 5-12. Add `use App\Models\Meeting;` to keep alphabetical order — insert after `use App\Models\DuplicateFlag;` (line 5). The new block:

```php
use App\Models\DuplicateFlag;
use App\Models\Meeting;
use App\Models\Payment;
use App\Models\RoundHistory;
use App\Models\Student;
use App\Models\StudentNote;
use App\Models\User;
use App\Services\LeadImport\ImportAction;
use Illuminate\Support\Facades\DB;
```

- [ ] **Step 5.2: Add the Meeting reparent line**

In the `reparentChildren()` method at line 137, add a 4th update inside the method body. Final method:

```php
private function reparentChildren(Student $from, Student $to): void
{
    Payment::where('student_id', $from->id)->update(['student_id' => $to->id]);
    StudentNote::where('student_id', $from->id)->update(['student_id' => $to->id]);
    RoundHistory::where('student_id', $from->id)->update(['student_id' => $to->id]);
    Meeting::where('student_id', $from->id)->update(['student_id' => $to->id]);
}
```

- [ ] **Step 5.3: Run the regression test — expect green**

```bash
php -d memory_limit=2048M vendor/bin/phpunit --filter test_merge_demotion_reparents_meetings 2>&1 | tail -10
```

Expected: `OK (1 test, 3 assertions)`.

- [ ] **Step 5.4: Run the entire LeadIntakeService class — expect green**

```bash
php -d memory_limit=2048M vendor/bin/phpunit tests/Feature/LeadIntakeServiceParityTest.php tests/Unit/LeadIntakeServiceTest.php 2>&1 | tail -5
```

Expected: all tests pass.

### Task 6: Commit B1

- [ ] **Step 6.1: Stage and commit**

```bash
git add app/Services/LeadIntakeService.php tests/Feature/LeadIntakeServiceParityTest.php
git commit -m "$(cat <<'EOF'
fix(leads): reparent Meetings on MERGE demotion

LeadIntakeService::reparentChildren() moved Payment/StudentNote/
RoundHistory to the winner row but skipped Meeting, leaving FK
orphans whenever a demoted student row was deleted (Sumit-vs-head
or head-vs-head MERGE per LeadPriority). Add Meeting to the same
update pattern + regression test.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Sub-Bundle 3 — B2.1 `Student::latestAdmittedRound()` accessor

### Task 7: Failing unit test for the accessor

**Files:**
- Create: `tests/Unit/Models/StudentLatestAdmittedRoundTest.php`

- [ ] **Step 7.1: Create the test file**

```bash
mkdir -p tests/Unit/Models
```

```php
<?php

namespace Tests\Unit\Models;

use App\Models\RoundHistory;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StudentLatestAdmittedRoundTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    public function test_returns_null_when_no_paid_round(): void
    {
        $student = $this->makeStudent('9111000001');
        $this->assertNull($student->latestAdmittedRound);

        // Unpaid round shouldn't qualify.
        RoundHistory::create([
            'student_id' => $student->id,
            'round_name' => 'Round 1',
            'allotted_college' => 'X',
            'allotted_course' => 'Y',
            'seat_fee_paid' => false,
            'outcome' => 'Allotted — Fee Pending',
        ]);
        $this->assertNull($student->fresh()->latestAdmittedRound);
    }

    public function test_returns_latest_paid_round_by_fee_paid_at(): void
    {
        $student = $this->makeStudent('9111000002');

        $older = RoundHistory::create([
            'student_id' => $student->id,
            'round_name' => 'Round 1',
            'allotted_college' => 'Old College',
            'allotted_course' => 'Old Course',
            'seat_fee_paid' => true,
            'fee_paid_at' => now()->subDays(10),
            'outcome' => 'Admitted',
        ]);

        $newer = RoundHistory::create([
            'student_id' => $student->id,
            'round_name' => 'Round 2',
            'allotted_college' => 'New College',
            'allotted_course' => 'New Course',
            'seat_fee_paid' => true,
            'fee_paid_at' => now()->subDay(),
            'outcome' => 'Admitted',
        ]);

        $latest = $student->fresh()->latestAdmittedRound;
        $this->assertNotNull($latest);
        $this->assertSame($newer->id, $latest->id);
        $this->assertSame('New College', $latest->allotted_college);
    }

    private function makeStudent(string $phone): Student
    {
        $admin = User::where('email', 'sumit@davya.local')->first();
        return Student::create([
            'phone' => $phone,
            'name' => 'Test',
            'owner_id' => $admin->id,
            'lead_source' => 'Walk-in',
            'stage' => 'New',
        ]);
    }
}
```

- [ ] **Step 7.2: Run — expect failure**

```bash
php -d memory_limit=2048M vendor/bin/phpunit tests/Unit/Models/StudentLatestAdmittedRoundTest.php 2>&1 | tail -10
```

Expected: error or fail mentioning `latestAdmittedRound` not defined on `Student`.

### Task 8: Implement the accessor

**Files:**
- Modify: `app/Models/Student.php` (add `HasOne` import + new relation method)

- [ ] **Step 8.1: Add `HasOne` import**

In `app/Models/Student.php`, the existing imports include `HasMany` at line 9. Add `use Illuminate\Database\Eloquent\Relations\HasOne;` directly after it. Final import block (lines 5-11):

```php
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Activitylog\LogOptions;
```

- [ ] **Step 8.2: Add the relation method**

In `app/Models/Student.php`, locate the existing relation cluster around line 75-80 (where `owner()`, `referrer()`, `payments()`, `roundHistory()`, `notes()`, `meetings()` are defined). Add this method directly after `meetings()`:

```php
public function latestAdmittedRound(): HasOne
{
    return $this->hasOne(RoundHistory::class)
        ->where('seat_fee_paid', true)
        ->latestOfMany('fee_paid_at');
}
```

- [ ] **Step 8.3: Run the unit test — expect green**

```bash
php -d memory_limit=2048M vendor/bin/phpunit tests/Unit/Models/StudentLatestAdmittedRoundTest.php 2>&1 | tail -10
```

Expected: `OK (2 tests, 6 assertions)`.

- [ ] **Step 8.4: Run the broader Student test surface to catch regressions**

```bash
php -d memory_limit=2048M vendor/bin/phpunit --filter Student 2>&1 | tail -10
```

Expected: existing Student tests still pass.

### Task 9: Commit B2.1

- [ ] **Step 9.1: Stage and commit**

```bash
git add app/Models/Student.php tests/Unit/Models/StudentLatestAdmittedRoundTest.php
git commit -m "$(cat <<'EOF'
feat(student): latestAdmittedRound() HasOne accessor

Single source of truth for closed-admission data — returns the
latest seat_fee_paid=true RoundHistory row, ordered by fee_paid_at.
Replaces 5 broken references to dropped students.final_college /
final_course / admission_date columns (next commits).

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Sub-Bundle 4 — B2.2 RowFormatter + dashboard cards

### Task 10: Update `RowFormatter` to source `final_college` via accessor

**Files:**
- Modify: `app/Dashboard/RowFormatter.php` (rewrite the `final_college` case + add `final_course` + `admission_date` keys)

- [ ] **Step 10.1: Update the formatter to handle 3 closed-admission keys via the accessor**

Open `app/Dashboard/RowFormatter.php` and replace the existing `'final_college'` case (currently line 17) plus add 2 new cases (`final_course`, `admission_date`). New match arms:

```php
'final_college' => $row instanceof Student
    ? (string) ($row->latestAdmittedRound?->allotted_college ?? '—')
    : '—',
'final_course' => $row instanceof Student
    ? (string) ($row->latestAdmittedRound?->allotted_course ?? '—')
    : '—',
'admission_date' => $row instanceof Student
    ? ($row->latestAdmittedRound?->fee_paid_at?->setTimezone('Asia/Kolkata')->format('Y-m-d') ?? '—')
    : '—',
```

Insert them in the same alphabetical-ish spot the current `final_college` occupies. The full updated `match` block should keep the existing keys untouched; only this group changes.

- [ ] **Step 10.2: Run RowFormatter-related tests + dashboard tests**

```bash
php -d memory_limit=2048M vendor/bin/phpunit tests/Feature/Dashboard 2>&1 | tail -10
```

Expected: still green (these tests assert COUNT, not column content yet — column-content tests come in Task 12 + 14).

### Task 11: Eager-load `latestAdmittedRound` in `AdmissionsClosedTodayCard`

**Files:**
- Modify: `app/Dashboard/Cards/Stat/AdmissionsClosedTodayCard.php:49-56`

- [ ] **Step 11.1: Add eager-load to `baseQuery()`**

The existing `baseQuery(User $viewer)` (line 49-56) returns a Student query. Add `->with(['latestAdmittedRound'])` to the chain — no signature change. Final method body:

```php
private function baseQuery(User $viewer)
{
    return Student::query()
        ->where('stage', 'Closed')
        ->where('close_reason', 'Completed')
        ->whereBetween('updated_at', [now()->startOfDay(), now()->endOfDay()])
        ->with(['latestAdmittedRound'])
        ->visibleTo($viewer);
}
```

No caller changes needed — the callers (`render()` line 20 and `drillDown()` line 34) already pass `$viewer`.

- [ ] **Step 11.2: Run the card test**

```bash
php -d memory_limit=2048M vendor/bin/phpunit tests/Feature/Dashboard/AdmissionsClosedTodayCardTest.php 2>&1 | tail -10
```

Expected: still green.

### Task 12: Add drill-down content assertion to `AdmissionsClosedTodayCardTest`

**Files:**
- Modify: `tests/Feature/Dashboard/AdmissionsClosedTodayCardTest.php` (append a new test method)

- [ ] **Step 12.1: Append new test asserting drill-down column resolves via accessor**

```php
public function test_drilldown_emits_admitted_college_from_latest_paid_round(): void
{
    $admin = User::where('email', 'sumit@davya.local')->first();
    $closedStageId = Stage::where('name', 'Closed')->value('id');

    $student = Student::create([
        'phone' => '9333000001',
        'name' => 'Admitted via Round',
        'owner_id' => $admin->id,
        'lead_source' => 'Website',
        'stage' => 'Closed',
        'close_reason' => 'Completed',
        'stage_id' => $closedStageId,
    ]);

    \App\Models\RoundHistory::create([
        'student_id' => $student->id,
        'round_name' => 'Round 2',
        'allotted_college' => 'MAIT',
        'allotted_course' => 'B.Tech CSE',
        'seat_fee_paid' => true,
        'fee_paid_at' => now(),
        'outcome' => 'Admitted',
    ]);

    $card = new AdmissionsClosedTodayCard();
    $payload = $card->drillDown($admin);

    $this->assertNotNull($payload);
    $rows = $payload->query->get();
    $this->assertGreaterThanOrEqual(1, $rows->count());

    // Find our specific student in the result set.
    $found = $rows->firstWhere('id', $student->id);
    $this->assertNotNull($found, 'Drill-down query must include the admitted student.');

    // Now resolve the 'final_college' column via RowFormatter and assert it picks up MAIT.
    $rendered = \App\Dashboard\RowFormatter::format($found, 'final_college');
    $this->assertSame('MAIT', $rendered);
}
```

- [ ] **Step 12.2: Run the test**

```bash
php -d memory_limit=2048M vendor/bin/phpunit tests/Feature/Dashboard/AdmissionsClosedTodayCardTest.php --filter test_drilldown_emits_admitted_college_from_latest_paid_round 2>&1 | tail -10
```

Expected: `OK (1 test, ...)`.

### Task 13: Eager-load `latestAdmittedRound` in `TeamStatCard`

**Files:**
- Modify: `app/Dashboard/Cards/Stat/TeamStatCard.php:108-128` (admissions metric only)

- [ ] **Step 13.1: Add eager-load to the admissions-closed branch of `baseQuery()`**

In the `match` block at line 113-128, the `self::METRIC_ADMISSIONS_CLOSED` branch (line 123-127) builds a Student query without eager-loading. Update it to:

```php
self::METRIC_ADMISSIONS_CLOSED => Student::query()
    ->where('stage', 'Closed')
    ->where('close_reason', 'Completed')
    ->whereBetween('updated_at', $todayRange)
    ->whereIn('owner_id', $teamIds)
    ->with(['latestAdmittedRound']),
```

The other two branches (LEADS_CAPTURED, MEETINGS_HELD) are untouched — they don't render `final_college` so they don't need the eager-load.

- [ ] **Step 13.2: Run TeamStatCardTest**

```bash
php -d memory_limit=2048M vendor/bin/phpunit tests/Feature/Dashboard/TeamStatCardTest.php 2>&1 | tail -10
```

Expected: still green.

### Task 14: Add drill-down content assertion to `TeamStatCardTest`

**Files:**
- Modify: `tests/Feature/Dashboard/TeamStatCardTest.php` (append a new test method)

- [ ] **Step 14.1: Append the test**

```php
public function test_admissions_drilldown_emits_allotted_college_via_accessor(): void
{
    $admin = User::where('email', 'sumit@davya.local')->first();
    $head = User::factory()->create(['name' => 'Sonam']);
    $head->assignRole('head');

    $student = Student::create([
        'phone' => '9555000777',
        'name' => 'Team Admit',
        'owner_id' => $head->id,
        'lead_source' => 'Sheet:Sonam',
        'stage' => 'Closed',
        'close_reason' => 'Completed',
    ]);

    \App\Models\RoundHistory::create([
        'student_id' => $student->id,
        'round_name' => 'Round 1',
        'allotted_college' => 'BVCOE',
        'allotted_course' => 'B.Tech IT',
        'seat_fee_paid' => true,
        'fee_paid_at' => now(),
        'outcome' => 'Admitted',
    ]);

    $card = new TeamStatCard($head, TeamStatCard::METRIC_ADMISSIONS_CLOSED);
    $payload = $card->drillDown($admin);

    $row = $payload->query->get()->firstWhere('id', $student->id);
    $this->assertNotNull($row);

    $rendered = \App\Dashboard\RowFormatter::format($row, 'final_college');
    $this->assertSame('BVCOE', $rendered);
}
```

You'll need to add `use App\Models\Student;`, `use App\Models\User;`, and `use App\Dashboard\Cards\Stat\TeamStatCard;` to the imports if they're not already present. Check `head -15 tests/Feature/Dashboard/TeamStatCardTest.php` first.

- [ ] **Step 14.2: Run the test**

```bash
php -d memory_limit=2048M vendor/bin/phpunit tests/Feature/Dashboard/TeamStatCardTest.php 2>&1 | tail -10
```

Expected: all green.

### Task 15: Commit B2.2

- [ ] **Step 15.1: Stage and commit**

```bash
git add app/Dashboard/RowFormatter.php \
        app/Dashboard/Cards/Stat/AdmissionsClosedTodayCard.php \
        app/Dashboard/Cards/Stat/TeamStatCard.php \
        tests/Feature/Dashboard/AdmissionsClosedTodayCardTest.php \
        tests/Feature/Dashboard/TeamStatCardTest.php

git commit -m "$(cat <<'EOF'
fix(dashboard): drill-down final_college via latestAdmittedRound

Five files referenced students.final_college / final_course /
admission_date which were dropped 2026-04-24 — drill-down CSVs
silently emitted empty strings. Route RowFormatter through the
new accessor + eager-load in Admissions/Team stat cards. Two new
column-content tests (AdmissionsClosed + Team).

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Sub-Bundle 5 — B2.3 StudentResource CSV export

### Task 16: Failing CSV export test

**Files:**
- Create: `tests/Feature/StudentCsvExportTest.php`

- [ ] **Step 16.1: Write the failing test**

```php
<?php

namespace Tests\Feature;

use App\Filament\Resources\StudentResource\Pages\ListStudents;
use App\Models\RoundHistory;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class StudentCsvExportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    public function test_csv_emits_admitted_data_from_latest_paid_round(): void
    {
        $admin = User::where('email', 'sumit@davya.local')->first();
        $this->actingAs($admin);

        $student = Student::create([
            'phone' => '9666000111',
            'name' => 'CSV Admit',
            'owner_id' => $admin->id,
            'lead_source' => 'Website',
            'stage' => 'Closed',
            'close_reason' => 'Completed',
            'deal_amount' => 100000,
        ]);

        RoundHistory::create([
            'student_id' => $student->id,
            'round_name' => 'Round 1',
            'allotted_college' => 'IGDTUW',
            'allotted_course' => 'B.Tech CSE',
            'seat_fee_paid' => true,
            'fee_paid_at' => now()->setTime(10, 30),
            'outcome' => 'Admitted',
        ]);

        $response = Livewire::test(ListStudents::class)
            ->call('exportCsv');

        // Livewire returns the response object; pull the streamed body.
        ob_start();
        $response->baseResponse->sendContent();
        $csv = ob_get_clean();

        $this->assertStringContainsString('CSV Admit', $csv);
        $this->assertStringContainsString('IGDTUW', $csv,
            'Final College column must read from RoundHistory.allotted_college.');
        $this->assertStringContainsString('B.Tech CSE', $csv,
            'Final Course column must read from RoundHistory.allotted_course.');
        $this->assertStringContainsString(now()->format('Y-m-d'), $csv,
            'Admission Date column must read from RoundHistory.fee_paid_at.');
    }
}
```

- [ ] **Step 16.2: Run — expect failure**

```bash
php -d memory_limit=2048M vendor/bin/phpunit tests/Feature/StudentCsvExportTest.php 2>&1 | tail -15
```

Expected: failure on `Failed asserting that '...' contains "IGDTUW"` — the CSV currently emits blank cells where it tries to read `$s->final_college`.

(If the test fails with an "exportCsv method not found" or Livewire bootstrapping error, the test setup needs adjustment. Read the existing `tests/Feature/Filament/` directory for a working Livewire test pattern and mirror it.)

### Task 17: Rewire `ListStudents::exportCsv()`

**Files:**
- Modify: `app/Filament/Resources/StudentResource/Pages/ListStudents.php:38,78-80`

- [ ] **Step 17.1: Add eager-load + swap the dead column reads**

In `app/Filament/Resources/StudentResource/Pages/ListStudents.php`, line 38 currently reads:

```php
$query = static::getResource()::getEloquentQuery()->with('owner:id,name');
```

Change to:

```php
$query = static::getResource()::getEloquentQuery()
    ->with(['owner:id,name', 'latestAdmittedRound']);
```

Then in the `fputcsv` row block (lines 78-80), replace the three lines reading dropped attributes:

```php
$s->final_college,
$s->final_course,
optional($s->admission_date)->format('Y-m-d'),
```

with:

```php
$s->latestAdmittedRound?->allotted_college,
$s->latestAdmittedRound?->allotted_course,
optional($s->latestAdmittedRound?->fee_paid_at)->format('Y-m-d'),
```

- [ ] **Step 17.2: Run the CSV test — expect green**

```bash
php -d memory_limit=2048M vendor/bin/phpunit tests/Feature/StudentCsvExportTest.php 2>&1 | tail -10
```

Expected: `OK (1 test, ...)`.

- [ ] **Step 17.3: Run the wider StudentResource test surface**

```bash
php -d memory_limit=2048M vendor/bin/phpunit --filter Student 2>&1 | tail -10
```

Expected: all green.

### Task 18: Commit B2.3

- [ ] **Step 18.1: Stage and commit**

```bash
git add app/Filament/Resources/StudentResource/Pages/ListStudents.php tests/Feature/StudentCsvExportTest.php
git commit -m "$(cat <<'EOF'
fix(students): CSV export reads admission data from RoundHistory

The 'Final College / Final Course / Admission Date' CSV columns
read \$student->final_college etc., but those columns were dropped
2026-04-24, so every closed-admission row exported blank cells.
Eager-load latestAdmittedRound and emit allotted_college /
allotted_course / fee_paid_at instead. New regression test
exercises the streamed CSV body.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Sub-Bundle 6 — B2.4 + AC1 KanbanBoard (whitelist clean-up + role gate)

### Task 19: Failing access-control test

**Files:**
- Create: `tests/Feature/Filament/KanbanBoardAccessTest.php`

- [ ] **Step 19.1: Write the test**

```bash
mkdir -p tests/Feature/Filament
```

```php
<?php

namespace Tests\Feature\Filament;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class KanbanBoardAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    public function test_admin_can_access_kanban(): void
    {
        $admin = User::where('email', 'sumit@davya.local')->first();
        $this->actingAs($admin)
            ->get('/admin/kanban')
            ->assertStatus(200);
    }

    public function test_head_can_access_kanban(): void
    {
        $head = User::factory()->create();
        $head->assignRole('head');
        $this->actingAs($head)
            ->get('/admin/kanban')
            ->assertStatus(200);
    }

    public function test_counsellor_can_access_kanban(): void
    {
        $counsellor = User::factory()->create();
        $counsellor->assignRole('member');
        $this->actingAs($counsellor)
            ->get('/admin/kanban')
            ->assertStatus(200);
    }

    public function test_freelancer_cannot_access_kanban(): void
    {
        $freelancer = User::factory()->create();
        $freelancer->assignRole('freelancer');
        $this->actingAs($freelancer)
            ->get('/admin/kanban')
            ->assertForbidden();
    }

    public function test_unauthenticated_redirects_to_login(): void
    {
        $this->get('/admin/kanban')
            ->assertRedirect('/admin/login');
    }
}
```

**Role naming caveat:** the codebase uses `member` for "counsellor" / team-member in some places. Confirm via `grep -n "assignRole\|hasRole" app/Models/Student.php app/Policies/StudentPolicy.php` before running — if a role named `counsellor` exists in `RolesSeeder.php`, use that name; otherwise stick to `member`.

- [ ] **Step 19.2: Run — expect freelancer test to fail (it gets 200, not 403)**

```bash
php -d memory_limit=2048M vendor/bin/phpunit tests/Feature/Filament/KanbanBoardAccessTest.php 2>&1 | tail -10
```

Expected: `test_freelancer_cannot_access_kanban` FAILS (returns 200 because KanbanBoard inherits Filament's default `canAccess() => true`). The other 4 tests should pass — confirm if you see different failures.

### Task 20: Add `canAccess()` + drop the 3 dead whitelist keys

**Files:**
- Modify: `app/Filament/Pages/KanbanBoard.php:20-32` (add canAccess method) + `:307-312` (clean whitelist)

- [ ] **Step 20.1: Add `canAccess()` static method**

In `app/Filament/Pages/KanbanBoard.php`, immediately after the existing static properties block (after `protected static ?string $slug = 'kanban';` at line 32), add:

```php
public static function canAccess(): bool
{
    $user = auth()->user();
    return $user instanceof User
        && $user->hasAnyRole(['admin', 'super_admin', 'head', 'member']);
}
```

The `App\Models\User` is already imported at line 10. If the codebase has a `counsellor` role distinct from `member`, append it (`['admin', 'super_admin', 'head', 'member', 'counsellor']`); otherwise `member` covers the counsellor surface per existing scope code.

- [ ] **Step 20.2: Drop the 3 dead keys from the `$allowed` whitelist**

In `fixAndMove()` (line 307-312), the `$allowed` array currently includes `'final_college', 'final_course', 'admission_date'`. Remove those three entries. Final array:

```php
$allowed = [
    'close_reason', 're_entry_reason', 'student_response', 'deal_amount', 'course',
    'category', 'plan', 'meeting_date', 'meeting_location', 'current_round',
    'is_ipu_registered',
    'ipu_login_code', 'father_name', 'twelfth_marks', 'exam_appeared', 'refund_amount',
];
```

(Per memory `feedback_subagent_env_inference_trap.md`: the spec is authoritative — these three keys were write targets for dropped columns and silently break the save when submitted. Removing them aligns the whitelist with the actual schema.)

- [ ] **Step 20.3: Run the access tests — expect all green**

```bash
php -d memory_limit=2048M vendor/bin/phpunit tests/Feature/Filament/KanbanBoardAccessTest.php 2>&1 | tail -10
```

Expected: `OK (5 tests, 5 assertions)`.

- [ ] **Step 20.4: Run the wider Kanban test surface**

```bash
php -d memory_limit=2048M vendor/bin/phpunit --filter Kanban 2>&1 | tail -10
```

Expected: existing Kanban tests still pass. If any existing test was relying on a freelancer reaching the page, flip it to assert 403 instead.

### Task 21: Commit B2.4 + AC1

- [ ] **Step 21.1: Stage and commit**

```bash
git add app/Filament/Pages/KanbanBoard.php tests/Feature/Filament/KanbanBoardAccessTest.php
git commit -m "$(cat <<'EOF'
fix(kanban): role gate + drop dead whitelist keys

KanbanBoard inherited Filament's default canAccess()=true, so
freelancers reached /admin/kanban (only row scope was filtering
content). Gate to admin/super_admin/head/member.

Also drop final_college/final_course/admission_date from the
fixAndMove() whitelist — those columns were dropped 2026-04-24,
and submitting them via the fix-up modal would 500 on save.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Sub-Bundle 7 — B4 Drive Storage URL resilience

### Task 22: Failing resilience test

**Files:**
- Create: `tests/Feature/Resources/Payment/ProofUrlResilienceTest.php`

- [ ] **Step 22.1: Write the test**

```bash
mkdir -p tests/Feature/Resources/Payment
```

```php
<?php

namespace Tests\Feature\Resources\Payment;

use App\Filament\Resources\Shared\PaymentFormSchema;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ProofUrlResilienceTest extends TestCase
{
    use RefreshDatabase;

    public function test_resolveProofUpload_returns_null_when_drive_throws(): void
    {
        $fake = $this->createMock(Filesystem::class);
        $fake->method('url')
             ->willThrowException(new \RuntimeException('Drive auth expired'));

        Storage::set(PaymentFormSchema::DRIVE_DISK, $fake);

        $result = PaymentFormSchema::resolveProofUpload([
            'proof_upload' => 'pending/abc123.pdf',
            'amount' => 1000,
        ]);

        $this->assertArrayNotHasKey('proof_upload', $result, 'transient key must be stripped');
        $this->assertNull($result['proof_url'], 'proof_url must be null on Drive failure');
    }

    public function test_resolveProofUpload_keeps_existing_proof_url_when_no_new_upload(): void
    {
        $result = PaymentFormSchema::resolveProofUpload([
            'proof_url' => 'https://drive.google.com/existing.pdf',
        ]);
        $this->assertSame('https://drive.google.com/existing.pdf', $result['proof_url']);
    }
}
```

The `Storage::set(...)` call binds a custom filesystem instance to the named disk for the duration of the test — Laravel's `Storage` facade resolves to the manager which honors `set()`.

- [ ] **Step 22.2: Run — expect failure**

```bash
php -d memory_limit=2048M vendor/bin/phpunit tests/Feature/Resources/Payment/ProofUrlResilienceTest.php 2>&1 | tail -15
```

Expected: first test FAILS with the `RuntimeException` bubbling up.

### Task 23: Wrap `Storage::url()` in try/catch

**Files:**
- Modify: `app/Filament/Resources/Shared/PaymentFormSchema.php:87-91`

- [ ] **Step 23.1: Replace the URL resolution block**

The current code (line 87-91):

```php
if (is_string($uploadPath) && $uploadPath !== '') {
    $data['proof_url'] = Storage::disk(self::DRIVE_DISK)->url($uploadPath);
} elseif (! array_key_exists('proof_url', $data)) {
    $data['proof_url'] = null;
}
```

becomes:

```php
if (is_string($uploadPath) && $uploadPath !== '') {
    try {
        $data['proof_url'] = Storage::disk(self::DRIVE_DISK)->url($uploadPath);
    } catch (\Throwable $e) {
        report($e);
        $data['proof_url'] = null;
    }
} elseif (! array_key_exists('proof_url', $data)) {
    $data['proof_url'] = null;
}
```

- [ ] **Step 23.2: Run the resilience test — expect green**

```bash
php -d memory_limit=2048M vendor/bin/phpunit tests/Feature/Resources/Payment/ProofUrlResilienceTest.php 2>&1 | tail -10
```

Expected: `OK (2 tests, 3 assertions)`.

- [ ] **Step 23.3: Run the wider PaymentFormSchema test surface**

```bash
php -d memory_limit=2048M vendor/bin/phpunit --filter Payment 2>&1 | tail -10
```

Expected: all green.

### Task 24: Commit B4

- [ ] **Step 24.1: Stage and commit**

```bash
git add app/Filament/Resources/Shared/PaymentFormSchema.php tests/Feature/Resources/Payment/ProofUrlResilienceTest.php
git commit -m "$(cat <<'EOF'
fix(payments): graceful Drive URL failure in PaymentFormSchema

Storage::disk('drive')->url() can throw when the OAuth token
expires or Google API returns 5xx. The Payment save form would
500 with no rollback. Wrap in try/catch, report() the exception,
and set proof_url=null so the form completes (admin can re-upload).

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Post-implementation verification

### Task 25: Full suite + lint + curl smoke

- [ ] **Step 25.1: Run the entire test suite**

```bash
php -d memory_limit=2048M vendor/bin/phpunit 2>&1 | tail -10
```

Expected: `Tests: ~877, ..., Skipped: 1`. Zero failures. Deprecation count unchanged (4 PHP + 24 PHPUnit — those are pre-existing, separate bundle).

- [ ] **Step 25.2: Pint lint check (no auto-fix)**

```bash
./vendor/bin/pint --test 2>&1 | tail -20
```

Expected: no formatting drift on any of the modified files. If pint flags an edit, fix it with `./vendor/bin/pint <file>` and commit as a separate `style:` commit.

- [ ] **Step 25.3: Curl smoke each touched route**

```bash
php artisan serve --port=8000 &
SERVER_PID=$!
sleep 2

for route in \
    /admin/login \
    /admin/kanban \
    /admin/dashboard \
    /admin/students \
    /admin/payments-report \
    ; do
  echo "GET $route → $(curl -s -o /dev/null -w '%{http_code}' http://127.0.0.1:8000$route)"
done

kill $SERVER_PID
```

Expected: `/admin/login = 200`, every other route = `302` (unauth redirect; absence of 500 is the win).

- [ ] **Step 25.4: Commit any pint fixes**

If Step 25.2 flagged drift and you ran `./vendor/bin/pint`:

```bash
git add -p && git commit -m "style: pint auto-formatting"
```

Otherwise skip.

---

## Out of scope for this plan

- Backfilling production data — no migration, no `students` column changes.
- The 4 audit-surfaced perf hotpaths (PaymentReport byOwner, Books rollups, LeadsReport spark, StageStatCard) — Bundle B.
- Dead-code purge (Gemini stack, orphan backfill commands, dormant `services.php` blocks, 6 unused `preference_r{n}_college/_branch` columns) — Bundle C.
- Visual cohesion + a11y (`aria-label` sweep, peek drawer focus trap, `<x-button>` extract, widget reskin) — Bundle D.
- Test tooling (`php artisan test --parallel` memory_limit fix, PDO 8.5 deprecation, `@test` → `#[Test]` migration) — Bundle E.
- Coverage gaps (DashboardDrillDownCsv, 3 Finance controllers, 3 ListCards, RankBulkParser) — Bundle F.

---

## Hand-off note for the implementing agent

- **Spec is authoritative.** Where this plan and the spec disagree, follow the spec and flag the drift. (Memory: `feedback_subagent_env_inference_trap.md`.)
- **DB safety**: tests run on SQLite `:memory:` per `phpunit.xml`. **DO NOT** run `migrate:fresh` against the local MySQL connection mid-execution. Use `RefreshDatabase` trait per test class (already standard in this codebase).
- **tokens.css drift**: every CSS change MUST go to both `resources/css/tokens.css` AND `public/css/tokens.css`. After editing the first, `cp` to the second to enforce parity. (Memory: `reference_davya-crm_tokens_css_drift.md`.)
- **No FTP push in this plan.** Deploy is a separate manual step that runs the full recipe (git pull + composer + migrate (none expected) + 3 rank seeders + 3 caches). Don't bake deploy into any task. (Memory: `feedback_full_deploy_recipe_no_shortcuts.md`.)
- **Suite runner:** use `php -d memory_limit=2048M vendor/bin/phpunit` — `php artisan test --parallel` crashes paratest at 128MB on this codebase; that's a separate Bundle E concern.
- **Role names:** confirm `member` vs `counsellor` against `database/seeders/RolesSeeder.php` before writing the AC1 access test. The codebase historically uses `member` for team-member/counsellor.
