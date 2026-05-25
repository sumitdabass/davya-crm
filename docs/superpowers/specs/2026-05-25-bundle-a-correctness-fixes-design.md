# Bundle A — Correctness Bug Fixes (Design)

**Date:** 2026-05-25
**Branch:** main → feat/bundle-a-correctness-fixes (or similar)
**Origin:** Full davya-crm audit 2026-05-25 surfaced 4 real bugs + 2 candidate access-control gaps. One gap (Rank resources `canViewAny`) was verified false during spec drafting and removed from scope.

---

## Goal

Close 4 correctness defects and 1 access-control gap in davya-crm. Each fix is independent and ships its own regression test. No migrations, no schema changes, no FTP deploy from inside this work — deploy is the standard manual recipe after suite is green.

## Scope (in)

- **B1** `LeadIntakeService::reparentChildren()` omits `Meeting` — MERGE demotion orphans meetings (FK to deleted student).
- **B2** Five files reference `students.final_college` / `final_course` / `admission_date` columns that were dropped in migration `2026_04_24_000300_drop_final_allotment_columns_from_students.php`. CSV exports + dashboard cards + drill-downs silently emit empty strings for "closed" students.
- **B3** `Fraunces` serif is hardcoded 7 times in `resources/css/tokens.css` but never imported — every reference falls back to Georgia in production.
- **B4** `Storage::disk('drive')->url($path)` in `PaymentFormSchema` has no error handling — Drive auth expiry → 500 on Payment save.
- **AC1** `KanbanBoard` page has no `canAccess()` override, so freelancers (referrer-only role) can reach `/admin/kanban`.

## Scope (out)

- AC2 Rank resources `canViewAny` — **verified already protected** during spec drafting via `RestrictsToRankRoles::canAccess()` trait + Filament `CanAuthorizeResourceAccess`. No action needed.
- Perf hotpaths, dead-code purge, visual cohesion, test tooling, coverage gaps — separate bundles (B/C/D/E/F).
- Any new feature work or refactoring outside the six file edits.
- Backfilling historic data — no migration, no `students.*` column changes, no schema work.

---

## Architecture

Application-layer only. One new accessor on `Student`, six bug fixes across six files, plus regression tests. No new services, no new routes, no new tables.

```
app/
  Models/Student.php
    + latestAdmittedRound() HasOne   ← new (single source for closed-admission data)
  Services/LeadIntakeService.php     ← B1: add Meeting to reparentChildren()
  Dashboard/RowFormatter.php         ← B2: use latestAdmittedRound
  Dashboard/Cards/Stat/
    AdmissionsClosedTodayCard.php    ← B2: count via whereHas(latestAdmittedRound)
    TeamStatCard.php                 ← B2: same pattern
  Filament/Resources/StudentResource/Pages/
    ListStudents.php                 ← B2: CSV export eager-loads + emits from accessor
  Filament/Pages/
    KanbanBoard.php                  ← B2 (drop dead condition keys) + AC1 (canAccess)
  Filament/Resources/Shared/
    PaymentFormSchema.php            ← B4: Drive try/catch
resources/css/tokens.css             ← B3: 7× Fraunces → var(--font-display)
public/css/tokens.css                ← B3: mirror (tokens.css drift rule)
```

---

## Per-fix detail

### B1 — Meeting reparent on MERGE demotion

**File:** `app/Services/LeadIntakeService.php:137-142`

The `reparentChildren()` helper currently moves `Payment`, `StudentNote`, `RoundHistory` from the demoted student to the winner, but skips `Meeting`. On a Sumit-vs-head MERGE demotion, the meeting rows are FK-orphaned when the demoted student is deleted.

**Fix:** add one line in the same pattern as the existing three:

```php
\App\Models\Meeting::where('student_id', $loser->id)
    ->update(['student_id' => $winner->id]);
```

**Test:** new case in `tests/Feature/LeadIntakeServiceParityTest.php` (or matching file — confirm during plan) — create demoted student with 1 meeting → ingest winner → assert `Meeting::where('student_id', $winnerId)->count() === 1` and `Meeting::where('student_id', $loserId)->count() === 0`.

### B2 — Strip dropped-column refs, source admission data from RoundHistory

**Step 1 — add accessor on `app/Models/Student.php`**

```php
use Illuminate\Database\Eloquent\Relations\HasOne;

public function latestAdmittedRound(): HasOne
{
    return $this->hasOne(RoundHistory::class)
        ->where('seat_fee_paid', true)
        ->latestOfMany('fee_paid_at');
}
```

`RoundHistory` columns: `allotted_college` (note: "allotted" not "allocated"), `allotted_course`, `fee_paid_at`, `seat_fee_paid` boolean. Confirmed via `database/migrations/2026_04_17_092756_create_round_history_table.php`.

**Step 2 — rewire 5 callers:**

| File | Current dead ref | Replacement |
|---|---|---|
| `app/Dashboard/RowFormatter.php:17` | `$student->final_college / final_course / admission_date` | `$student->latestAdmittedRound?->allotted_college / allotted_course / fee_paid_at` |
| `Dashboard/Cards/Stat/AdmissionsClosedTodayCard.php:39` | `whereDate('admission_date', today())` | `whereHas('latestAdmittedRound', fn($q) => $q->whereDate('fee_paid_at', today()))` |
| `Dashboard/Cards/Stat/TeamStatCard.php:84` | same pattern as AdmissionsClosedToday | same replacement |
| `Filament/Resources/StudentResource/Pages/ListStudents.php:78-80` (CSV export) | bare attribute reads on dropped cols | eager-load `with('latestAdmittedRound')`, emit `$student->latestAdmittedRound?->...` |
| `app/Filament/Pages/KanbanBoard.php:310` | 3 dead condition keys in pipeline-rule builder dropdown | remove the 3 keys (they are config builder options that reference dropped columns — no replacement, the rule builder simply no longer offers them) |

**Test:**
- `tests/Unit/StudentLatestAdmittedRoundTest.php` — accessor returns latest paid round, null when none.
- `tests/Feature/Dashboard/AdmissionsClosedTodayCardTest.php` — card counts students whose latest paid round is today, not students with dropped column set.
- `tests/Feature/StudentCsvExportTest.php` — CSV rows for closed students contain the RoundHistory data in the 3 admission columns.

### B3 — Fraunces → `var(--font-display)`

**Files (both, drift trap):** `resources/css/tokens.css`, `public/css/tokens.css`

7 hardcoded occurrences of `'Fraunces', Georgia, serif` at approximate lines `1017, 1096, 1154, 1243, 1251, 1276, 1327` (AI drawer, role labels, empty states). No `@import` or `@font-face` for Fraunces exists — production renders Georgia.

**Fix:** replace each with `var(--font-display)` (currently Bricolage Grotesque per 2026-05-25 italic-drop direction). Also remove or update the stale comment at lines `1547-1549` referencing "Instrument Serif via --font-display".

**Verification:** the v3 typography uplift commit `80f4fed` set `--font-display: 'Bricolage Grotesque', ui-serif, Georgia, serif` per the tokens file header — Bricolage is already imported. No new font load required.

**Test:** manual smoke — open `/admin/ai-conversations`, `/admin/users/{id}/edit`, an empty-state page; visually confirm headlines render Bricolage not Georgia. No automated test for CSS choice; covered by visual review.

### B4 — Drive `Storage::url()` try/catch

**File:** `app/Filament/Resources/Shared/PaymentFormSchema.php:88` (or nearest line where `Storage::disk('drive')->url($path)` is called)

```php
try {
    $url = Storage::disk('drive')->url($path);
} catch (\Throwable $e) {
    report($e);
    $url = null; // form falls back to "Proof attached (preview unavailable)"
}
```

**Test:** new feature test in `tests/Feature/Resources/Payment/ProofUrlResilienceTest.php` — mock `Storage::disk('drive')` to throw `\RuntimeException`, assert form still renders (no 500) and the proof link is null.

### AC1 — KanbanBoard `canAccess()`

**File:** `app/Filament/Pages/KanbanBoard.php`

Filament's `Pages\Concerns\CanAuthorizeAccess` default returns `true`. The page inherits no override, so every authenticated user (including `freelancer`) reaches `/admin/kanban`. The existing `visibleTo()` scope filters rows but doesn't gate the page itself.

**Fix:**

```php
public static function canAccess(): bool
{
    $user = auth()->user();
    return $user && $user->hasAnyRole(['admin', 'super_admin', 'head', 'counsellor']);
}
```

Freelancers and guests blocked. `visibleTo()` scope stays in place for row-level filtering on top.

**Test:** new `tests/Feature/Filament/KanbanBoardAccessTest.php` — for each of admin/super_admin/head/counsellor, GET `/admin/kanban` → 200. For freelancer → 403. Unauthenticated → redirect to login.

---

## Test strategy

7 new test cases total. All use `RefreshDatabase` trait via the project's base `TestCase`. SQLite `:memory:` per `phpunit.xml` — no `migrate:fresh` against MySQL (per `feedback_subagent_env_inference_trap`).

| Test | Bug | File |
|---|---|---|
| Meeting reparented on MERGE | B1 | `tests/Feature/LeadIntakeServiceParityTest.php` (new case) |
| `latestAdmittedRound` accessor | B2 | `tests/Unit/StudentLatestAdmittedRoundTest.php` (new) |
| AdmissionsClosedToday counts via accessor | B2 | `tests/Feature/Dashboard/AdmissionsClosedTodayCardTest.php` (new) |
| TeamStatCard counts via accessor | B2 | `tests/Feature/Dashboard/TeamStatCardTest.php` (new or extend) |
| Student CSV export emits admission data | B2 | `tests/Feature/StudentCsvExportTest.php` (new) |
| Drive URL failure is graceful | B4 | `tests/Feature/Resources/Payment/ProofUrlResilienceTest.php` (new) |
| KanbanBoard role gate | AC1 | `tests/Feature/Filament/KanbanBoardAccessTest.php` (new) |

B3 is CSS-only — covered by manual visual smoke, not automated.

Baseline suite: 870 passed / 1 skipped (verified 2026-05-25 via `php -d memory_limit=2048M vendor/bin/phpunit`). Expected after bundle: ~877 passed / 1 skipped.

---

## Sequencing

Each fix lands as its own commit so partial progress is reviewable. Order chosen by independence first (CSS / no deps) and then by dependency (B2 accessor before B2 callers).

1. **B3** — `resources/css/tokens.css` + `public/css/tokens.css` (independent, CSS only)
2. **B1** — `LeadIntakeService::reparentChildren()` + regression test
3. **B2.1** — `Student::latestAdmittedRound()` accessor + unit test
4. **B2.2** — `RowFormatter.php` + `AdmissionsClosedTodayCard.php` + `TeamStatCard.php` + tests
5. **B2.3** — `ListStudents.php` CSV export + test
6. **B2.4** — `KanbanBoard.php:310` drop dead condition keys (combined with AC1 below since same file)
7. **B4** — `PaymentFormSchema.php` Drive try/catch + test
8. **AC1** — `KanbanBoard::canAccess()` + test (bundled with B2.4 in one commit)
9. **Verification** — `php -d memory_limit=2048M vendor/bin/phpunit`, `./vendor/bin/pint --test`, curl smoke on `/admin/login` + `/admin/kanban` + `/admin/dashboard` + `/admin/students`

---

## Deploy

Out of bundle scope. After all 8 steps green locally:

1. `git push origin <branch>` and open PR (if branch-based)
2. Or merge to `main`
3. Full Hostinger deploy recipe (per `feedback_full_deploy_recipe_no_shortcuts`):
   `ssh ipuc@ipu.co.in → cd davya-crm → git pull → composer install --no-dev --optimize-autoloader → php artisan migrate (none expected) → 3 rank seeders (idempotent) → config:cache + route:cache + view:cache`
4. Curl verify: `/admin/login = 200`, `/admin/kanban = 302` (unauth redirect)
5. Browser smoke: log in, open Dashboard → AdmissionsClosedToday card renders correctly; open /admin/kanban as freelancer → 403; open AI drawer → fonts render Bricolage.

---

## Risks + mitigations

- **B2 RoundHistory data quality** — if production has closed-admission students whose `RoundHistory` rows do NOT have `seat_fee_paid=true` (e.g. data entered before round_history existed), the new accessor returns `null` and cards under-count. **Mitigation:** ListCard tests use realistic fixtures; a smoke check on prod after deploy confirms numbers are plausible. If a backfill is needed it lands as a follow-up data migration, not in this bundle.
- **B3 cache** — Hostinger serves `tokens.css` with `max-age=604800`. The cache-bust query `?v=filemtime` was added 2026-04-24 per memory — verify the AdminPanelProvider HEAD_END still appends `?v={mtime}`.
- **AC1 freelancer breakage** — if any freelancer workflow today actually uses Kanban (e.g. as a read-only board), this fix blocks them. **Mitigation:** confirm with Sumit during plan-writing that freelancer kanban access is intentional to remove.
- **Suite memory** — `php artisan test --parallel` crashes paratest at default 128MB. Must run plain `phpunit` with `-d memory_limit=2048M` until that's fixed (separate bundle E).

---

## Memory cross-refs

- `feedback_subagent_env_inference_trap.md` — spec is authoritative on conflicts
- `reference_davya-crm_tokens_css_drift.md` — every CSS edit hits both files
- `feedback_full_deploy_recipe_no_shortcuts.md` — full recipe on deploy, no shortcuts
- `feedback_subagent_driven_dev_no_dual_review.md` — implementer-only during execution, no spec/quality reviewer dispatches
- `feedback_plan_execution_autorun.md` — run tasks back-to-back; pause only on BLOCKED
