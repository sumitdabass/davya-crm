# Smart Rank Prediction Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Upgrade the Rank Lookup page in davya-crm to (a) accept a multi-select branch-family filter, (b) sort eligible colleges by IPU's student-demand order (USICT → MAIT → MSIT → BVP → BPIT → VIPS → GTBIT → Dr Akhilesh → HMR → …), (c) bucket each branch as Safe/Probable/Reach, (d) drop branches whose cushion exceeds 50% (over-qualified clutter), (e) cap default view to 7 colleges with a "Show more" expander, and (f) generate 1–2 sentence Gemini-powered counselling notes per college (lazy-loaded for the expander).

**Architecture:** Pure logic moves out of `RankLookup.php` into 3 small services — `BranchFamilies` (taxonomy), `CollegePreferenceOrder` (priority sort key), `RankPredictor` (bucket math + eligibility). A separate `GeminiCounsellor` wraps the Gemini SDK with a per-college DB cache. The Filament page becomes thin glue: form → predictor → AI notes → Livewire-driven progressive reveal of "Show more".

**Tech Stack:** Laravel 11 + Filament 3, MySQL `ranks` connection, Spatie Permission (already wired), `google/generative-ai-php` (already installed for Finance assistant via `App\Services\Finance\GeminiClient`), PHPUnit. View: Blade in `resources/views/filament/pages/rank/`.

---

## File structure

| Path | What it owns |
|---|---|
| `app/Services/Rank/BranchFamilies.php` | Family taxonomy constants (`cs_it`, `electronics`, `mechanical`, `civil_arch`, `chem_energy`) + branch-name → family substring matcher + helpers used by both Form (build select options) and Logic (resolve "selected family" → list of branch IDs). |
| `app/Services/Rank/CollegePreferenceOrder.php` | The hard-coded student-demand priority list and a `sortKey(string $instituteName): int` helper. |
| `app/Services/Rank/RankPredictor.php` | Pure logic: `bucket()`, `cushionPct()`, `isEligible()` (rank in [min,max] AND cushion ≤ 50%), `yoyDeltaPct()` for sliding 2024 vs 2026 volatility. No DB, no auth, fully unit-testable. |
| `app/Services/Rank/GeminiCounsellor.php` | Calls Gemini once per (institute, year, region, branchFilterHash, rankBucketOf1000) — caches via `Cache::remember(...)` with 24h TTL. Builds prompt from college's eligible branches + signals. Returns `string\|null` (null on any error so UI never 500s). |
| `app/Filament/Pages/Rank/RankLookup.php` | Thin Filament Page. Form fields (existing + new branch multi-select). Computed `getResultsProperty()` orchestrates: query cutoffs → `RankPredictor::filter()` → `BranchFamilies::expand()` → group by college → `CollegePreferenceOrder::sortKey()` → top-7 + remaining. Public Livewire actions: `showMore()` (sets `$showAll = true` and triggers AI notes for newly visible). |
| `resources/views/filament/pages/rank/rank-lookup.blade.php` | Render: form, results grouped per college (institute header → italic AI note → branch rows). Per-row: `# / Branch (+ shift) / Bucket badge / Prediction max / Cushion% / Seats / R1 / R2 / R3 / Sliding`. "Show N more colleges →" link below the 7th when `$showAll === false && additionalCount > 0`. |
| `tests/Unit/Rank/BranchFamiliesTest.php` | Verify each family's substring matches, expansion to branch IDs, unknown branches fall through. |
| `tests/Unit/Rank/CollegePreferenceOrderTest.php` | Verify priority weights, fallback to 999, alphabetical tie-break. |
| `tests/Unit/Rank/RankPredictorTest.php` | Bucket boundaries, cushion math, >50% cap, min-rank check, YoY delta with missing 2024. |
| `tests/Feature/Rank/RankLookupTest.php` | End-to-end Filament-Livewire test: branch filter narrows results, top-7 cap honored, `showMore` reveals rest, AI-off path skips Gemini, AI-on path calls cached counsellor. |

`config/database.php` and the `ranks` connection are unchanged. Migrations are unchanged. Existing data (1,081 cutoffs from `rank:import-from-predictor`) is the test fixture for feature tests.

---

## Task 1: BranchFamilies taxonomy

**Files:**
- Create: `app/Services/Rank/BranchFamilies.php`
- Test: `tests/Unit/Rank/BranchFamiliesTest.php`

- [ ] **Step 1.1: Write failing test**

```php
<?php

namespace Tests\Unit\Rank;

use App\Services\Rank\BranchFamilies;
use Tests\TestCase;

class BranchFamiliesTest extends TestCase
{
    /** @test */
    public function it_lists_all_5_families(): void
    {
        $families = BranchFamilies::all();

        $this->assertSame(
            ['cs_it', 'electronics', 'mechanical', 'civil_arch', 'chem_energy'],
            array_keys($families),
        );
        $this->assertSame('Computer / IT', $families['cs_it']);
    }

    /** @test */
    public function it_matches_branch_names_to_families(): void
    {
        $cases = [
            'Computer Science & Engineering' => 'cs_it',
            'Computer Science & Engineering - AIML' => 'cs_it',
            'CSE-DS' => 'cs_it',
            'Information Technology' => 'cs_it',
            'AI & Data Science' => 'cs_it',
            'Electronics & Communication Engineering' => 'electronics',
            'Electrical & Electronics Engineering' => 'electronics',
            'VLSI Design & Technology' => 'electronics',
            'Industrial Internet of Things' => 'electronics',
            'Mechanical Engineering' => 'mechanical',
            'Mechatronics' => 'mechanical',
            'Automation & Robotics' => 'mechanical',
            'Civil Engineering' => 'civil_arch',
            'B.Tech. (Architecture & interior Decoration)' => 'civil_arch',
            'Chemical Engineering' => 'chem_energy',
            'B. Tech. (Energy)' => 'chem_energy',
            'Some random new branch' => null,
        ];

        foreach ($cases as $branch => $expected) {
            $this->assertSame($expected, BranchFamilies::familyFor($branch), "branch=$branch");
        }
    }

    /** @test */
    public function expand_resolves_family_keys_into_branch_ids_for_a_course(): void
    {
        // Seeds in setUp would clutter; reuse the imported 2026 data already in ranks DB.
        $course = \App\Models\Rank\Course::where('name', 'B.Tech')->firstOrFail();

        $ids = BranchFamilies::expandToBranchIds(['cs_it'], $course->id);
        $this->assertNotEmpty($ids);
        $branchNames = \App\Models\Rank\Branch::whereIn('id', $ids)->pluck('name')->all();
        // Sanity: at least one CSE-named branch is in the result.
        $this->assertTrue(collect($branchNames)->contains(fn ($n) => str_contains(strtolower($n), 'computer science')));
        // Sanity: a Mechanical branch is NOT in the result.
        $this->assertFalse(collect($branchNames)->contains(fn ($n) => str_contains(strtolower($n), 'mechanical')));
    }
}
```

- [ ] **Step 1.2: Run test to verify it fails**

Run: `php artisan test --filter BranchFamiliesTest`
Expected: FAIL with "Class App\Services\Rank\BranchFamilies does not exist".

- [ ] **Step 1.3: Implement BranchFamilies**

```php
<?php

namespace App\Filament\Pages\Rank;

use App\Models\Rank\Branch;

class BranchFamilies
{
    /**
     * Family code → display label. Order is the order shown in the picker.
     */
    private const FAMILIES = [
        'cs_it' => 'Computer / IT',
        'electronics' => 'Electronics',
        'mechanical' => 'Mechanical',
        'civil_arch' => 'Civil / Architecture',
        'chem_energy' => 'Chemical / Energy',
    ];

    /**
     * Family code → list of lowercase substrings. A branch belongs to a family if
     * its lowercase name contains ANY of the family's substrings.
     */
    private const SUBSTRINGS = [
        'cs_it' => [
            'computer science', 'cse', 'cs/', 'cs (', 'cs-',
            'information technology', '(it)', ' it ', 'it (dual',
            'ai &', 'ai&', 'artificial intelligence',
            'data science', 'computer applications', 'cyber security',
        ],
        'electronics' => [
            'electronics & communication', 'ece', 'electrical', 'vlsi',
            'instrumentation', 'industrial internet of things', 'advance comm',
        ],
        'mechanical' => [
            'mechanical', 'mechatronics', 'automation & robotics', 'robotics & artificial',
        ],
        'civil_arch' => [
            'civil', 'architecture', '3d modelling', '3d modeling',
        ],
        'chem_energy' => [
            'chemical', 'energy', 'nanoscience',
        ],
    ];

    /** @return array<string, string> Family code → label, in display order. */
    public static function all(): array
    {
        return self::FAMILIES;
    }

    public static function familyFor(string $branchName): ?string
    {
        $lc = strtolower($branchName);
        foreach (self::SUBSTRINGS as $code => $needles) {
            foreach ($needles as $needle) {
                if (str_contains($lc, $needle)) {
                    return $code;
                }
            }
        }

        return null;
    }

    /**
     * @param  array<int,string>  $familyCodes
     * @return array<int,int>  Branch IDs whose family is in $familyCodes, scoped to the given course.
     */
    public static function expandToBranchIds(array $familyCodes, int $courseId): array
    {
        $rows = Branch::where('course_id', $courseId)->get(['id', 'name']);
        $ids = [];
        foreach ($rows as $b) {
            if (in_array(self::familyFor($b->name), $familyCodes, true)) {
                $ids[] = $b->id;
            }
        }

        return $ids;
    }
}
```

- [ ] **Step 1.4: Run tests to verify pass**

Run: `php artisan test --filter BranchFamiliesTest`
Expected: PASS — 3 tests, no failures.

- [ ] **Step 1.5: Commit**

```bash
git add app/Services/Rank/BranchFamilies.php tests/Unit/Rank/BranchFamiliesTest.php
git commit -m "feat(rank): branch family taxonomy + matching helper"
```

---

## Task 2: CollegePreferenceOrder

**Files:**
- Create: `app/Services/Rank/CollegePreferenceOrder.php`
- Test: `tests/Unit/Rank/CollegePreferenceOrderTest.php`

- [ ] **Step 2.1: Write failing test**

```php
<?php

namespace Tests\Unit\Rank;

use App\Services\Rank\CollegePreferenceOrder;
use Tests\TestCase;

class CollegePreferenceOrderTest extends TestCase
{
    /** @test */
    public function it_ranks_in_the_expected_order(): void
    {
        $names = [
            'Bharati Vidyapeeth\'s College of Engineering',
            'Maharaja Agrasen Institute of Technology',
            'University School of Information & Communication Technology',
            'HMR Institute of Technology & Management',
            'Maharaja Surajmal Institute of Technology',
            'Some Random College Not In List',
            'Bhagwan Parshuram Institute of Technology',
            'Vivekananda Institute of Professional Studies',
            'Guru Teg Bahadur Institute of Technology',
            'Dr. Akhilesh Das Gupta Institute',
        ];

        usort($names, fn ($a, $b) => CollegePreferenceOrder::sortKey($a) <=> CollegePreferenceOrder::sortKey($b)
            ?: strcasecmp($a, $b));

        $this->assertSame([
            'University School of Information & Communication Technology', // 1 USICT
            'Maharaja Agrasen Institute of Technology',                    // 2 MAIT
            'Maharaja Surajmal Institute of Technology',                   // 3 MSIT
            'Bharati Vidyapeeth\'s College of Engineering',                // 4 BVP
            'Bhagwan Parshuram Institute of Technology',                   // 5 BPIT
            'Vivekananda Institute of Professional Studies',               // 6 VIPS
            'Guru Teg Bahadur Institute of Technology',                    // 7 GTBIT
            'Dr. Akhilesh Das Gupta Institute',                            // 8 Dr Akhilesh
            'HMR Institute of Technology & Management',                    // 9 HMR
            'Some Random College Not In List',                             // fallback alphabetical
        ], $names);
    }

    /** @test */
    public function unknown_colleges_get_fallback_weight(): void
    {
        $this->assertSame(999, CollegePreferenceOrder::sortKey('Foo Institute'));
        $this->assertLessThan(999, CollegePreferenceOrder::sortKey('Maharaja Agrasen Institute of Technology'));
    }
}
```

- [ ] **Step 2.2: Run test, verify fail**

Run: `php artisan test --filter CollegePreferenceOrderTest`
Expected: FAIL — class doesn't exist.

- [ ] **Step 2.3: Implement**

```php
<?php

namespace App\Filament\Pages\Rank;

class CollegePreferenceOrder
{
    /**
     * Lowercase substring → priority weight. Lower = appears first.
     * Edit this list to reorder, add, or remove preferred colleges.
     */
    private const PRIORITIES = [
        'university school of information' => 1,    // USICT
        'maharaja agrasen' => 2,                    // MAIT
        'maharaja surajmal' => 3,                   // MSIT
        'bharati vidyapeeth' => 4,                  // BVP
        'bhagwan parshuram' => 5,                   // BPIT
        'vivekananda institute of professional' => 6, // VIPS
        'guru teg bahadur institute' => 7,          // GTBIT
        'akhilesh das gupta' => 8,                  // Dr Akhilesh
        'hmr institute' => 9,                       // HMR
        'guru tegh bahadur 4th centenary' => 10,    // GTB 4th Centenary
        'university school of automation' => 11,    // USAR
        'university school of chemical' => 12,      // USCT
    ];

    public static function sortKey(string $instituteName): int
    {
        $lc = strtolower($instituteName);
        foreach (self::PRIORITIES as $needle => $weight) {
            if (str_contains($lc, $needle)) {
                return $weight;
            }
        }

        return 999;
    }
}
```

- [ ] **Step 2.4: Run tests, verify pass**

Run: `php artisan test --filter CollegePreferenceOrderTest`
Expected: PASS — 2 tests.

- [ ] **Step 2.5: Commit**

```bash
git add app/Services/Rank/CollegePreferenceOrder.php tests/Unit/Rank/CollegePreferenceOrderTest.php
git commit -m "feat(rank): student-demand college priority sort key"
```

---

## Task 3: RankPredictor service (bucket + cushion + filter)

**Files:**
- Create: `app/Services/Rank/RankPredictor.php`
- Test: `tests/Unit/Rank/RankPredictorTest.php`

- [ ] **Step 3.1: Write failing test**

```php
<?php

namespace Tests\Unit\Rank;

use App\Services\Rank\RankPredictor;
use Tests\TestCase;

class RankPredictorTest extends TestCase
{
    private RankPredictor $p;

    protected function setUp(): void
    {
        parent::setUp();
        $this->p = new RankPredictor;
    }

    /** @test */
    public function cushion_pct_is_signed_percent_of_max(): void
    {
        // rank 50000, max 100000 → (100000-50000)/100000 = 50%
        $this->assertSame(50, $this->p->cushionPct(50000, 100000));
        // rank 80000, max 100000 → 20%
        $this->assertSame(20, $this->p->cushionPct(80000, 100000));
        // rank 110000, max 100000 → -10%  (rank can't get in)
        $this->assertSame(-10, $this->p->cushionPct(110000, 100000));
    }

    /** @test */
    public function bucket_classifies_by_cushion(): void
    {
        // safe: cushion 25–50
        $this->assertSame('safe', $this->p->bucket(60000, 100000));     // 40%
        $this->assertSame('safe', $this->p->bucket(75000, 100000));     // 25%
        // probable: cushion 10–25
        $this->assertSame('probable', $this->p->bucket(80000, 100000)); // 20%
        $this->assertSame('probable', $this->p->bucket(90000, 100000)); // 10%
        // reach: cushion 0–10
        $this->assertSame('reach', $this->p->bucket(95000, 100000));    // 5%
        $this->assertSame('reach', $this->p->bucket(100000, 100000));   // 0%
    }

    /** @test */
    public function is_eligible_drops_overqualified_and_underqualified(): void
    {
        // Within band, cushion ≤ 50: eligible
        $this->assertTrue($this->p->isEligible(50000, ['min' => 30000, 'max' => 100000])); // cushion 50%
        $this->assertTrue($this->p->isEligible(80000, ['min' => 30000, 'max' => 100000])); // cushion 20%
        // rank > max: not eligible (can't get in)
        $this->assertFalse($this->p->isEligible(120000, ['min' => 30000, 'max' => 100000]));
        // rank < min: not eligible (over-competitive, would pick a better college)
        $this->assertFalse($this->p->isEligible(10000, ['min' => 30000, 'max' => 100000]));
        // cushion > 50: not eligible (clutter — student wouldn't pick this)
        $this->assertFalse($this->p->isEligible(40000, ['min' => 30000, 'max' => 100000])); // cushion 60%
    }

    /** @test */
    public function yoy_delta_pct_compares_two_max_ranks(): void
    {
        // 2024 max 100000 → 2026 max 130000 = +30%
        $this->assertSame(30, $this->p->yoyDeltaPct(['max' => 100000], ['max' => 130000]));
        // 2024 max 200000 → 2026 max 100000 = -50%
        $this->assertSame(-50, $this->p->yoyDeltaPct(['max' => 200000], ['max' => 100000]));
        // missing year → null
        $this->assertNull($this->p->yoyDeltaPct(null, ['max' => 100000]));
        $this->assertNull($this->p->yoyDeltaPct(['max' => 100000], null));
    }
}
```

- [ ] **Step 3.2: Run test, verify fail**

Run: `php artisan test --filter RankPredictorTest`
Expected: FAIL — class doesn't exist.

- [ ] **Step 3.3: Implement**

```php
<?php

namespace App\Services\Rank;

class RankPredictor
{
    public function cushionPct(int $rank, int $max): int
    {
        if ($max <= 0) {
            return 0;
        }

        return (int) round(($max - $rank) / $max * 100);
    }

    /**
     * @return 'safe'|'probable'|'reach'
     */
    public function bucket(int $rank, int $max): string
    {
        $cushion = $this->cushionPct($rank, $max);
        if ($cushion >= 25) {
            return 'safe';
        }
        if ($cushion >= 10) {
            return 'probable';
        }

        return 'reach';
    }

    /**
     * @param  array{min:int,max:int}  $cell
     */
    public function isEligible(int $rank, array $cell): bool
    {
        if ($rank < $cell['min'] || $rank > $cell['max']) {
            return false;
        }
        if ($this->cushionPct($rank, $cell['max']) > 50) {
            return false;
        }

        return true;
    }

    /**
     * @param  array{max:int}|null  $earlier
     * @param  array{max:int}|null  $later
     */
    public function yoyDeltaPct(?array $earlier, ?array $later): ?int
    {
        if (! $earlier || ! $later || $earlier['max'] <= 0) {
            return null;
        }

        return (int) round(($later['max'] - $earlier['max']) / $earlier['max'] * 100);
    }
}
```

- [ ] **Step 3.4: Run tests, verify pass**

Run: `php artisan test --filter RankPredictorTest`
Expected: PASS — 4 tests.

- [ ] **Step 3.5: Commit**

```bash
git add app/Services/Rank/RankPredictor.php tests/Unit/Rank/RankPredictorTest.php
git commit -m "feat(rank): predictor pure logic — cushion, bucket, eligibility, YoY"
```

---

## Task 4: GeminiCounsellor service (with cache + graceful failure)

**Files:**
- Create: `app/Services/Rank/GeminiCounsellor.php`
- Modify: `config/finance.php` (or create `config/rank.php`) — confirm Gemini API key is reachable
- Test: covered indirectly by Feature test in Task 9 (we mock the SDK there); no dedicated unit test for the wrapper to avoid mocking the Gemini SDK shape twice.

- [ ] **Step 4.1: Inspect existing Gemini integration**

Read `app/Services/Finance/GeminiClient.php` (constructed in `AppServiceProvider::register`) to confirm SDK class + method signature. The expected pattern is something like:

```php
$response = $this->client->generateContent([
    'model' => $this->model,
    'contents' => [['role' => 'user', 'parts' => [['text' => $prompt]]]],
]);
$text = $response->text() ?? '';
```

Match whichever idiom exists. If `GeminiClient` exposes a `generate(string $prompt): string` method, reuse it directly; do NOT create a competing wrapper.

- [ ] **Step 4.2: Write the counsellor**

```php
<?php

namespace App\Services\Rank;

use App\Services\Finance\GeminiClient;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

class GeminiCounsellor
{
    public function __construct(private GeminiClient $gemini) {}

    /**
     * Generate a 1–2 sentence counselling note for one college.
     *
     * @param  array{
     *     institute_name: string,
     *     rank: int,
     *     region: string,
     *     year: int,
     *     branches: array<int, array{branch_name: string, shift: ?string, bucket: string, cushion_pct: int, prediction_max: int, sliding_max: ?int, r3_max: ?int, yoy_delta_pct: ?int, seat_count: ?int}>,
     *     branch_filter_hash: string,
     * }  $ctx
     */
    public function note(array $ctx): ?string
    {
        $rankBucket = (int) floor($ctx['rank'] / 1000);
        $cacheKey = sprintf(
            'rank_note:%s:%d:%s:%s:%d',
            md5(strtolower($ctx['institute_name'])),
            $ctx['year'],
            $ctx['region'],
            $ctx['branch_filter_hash'],
            $rankBucket,
        );

        return Cache::remember($cacheKey, now()->addHours(24), function () use ($ctx) {
            try {
                return $this->callGemini($ctx);
            } catch (Throwable $e) {
                Log::warning('rank counsellor gemini failed', ['err' => $e->getMessage()]);

                return null;
            }
        });
    }

    private function callGemini(array $ctx): ?string
    {
        $branches = collect($ctx['branches'])->map(function ($b) {
            $shift = $b['shift'] ? "Shift {$b['shift']}" : 'no shift';
            $yoy = $b['yoy_delta_pct'] !== null ? "{$b['yoy_delta_pct']}% YoY" : 'no YoY data';
            $seats = $b['seat_count'] !== null ? "{$b['seat_count']} seats" : 'seats unknown';

            return sprintf(
                '- %s (%s, %s): %s, cushion %d%%, max %d, %s, %s',
                $b['branch_name'], $shift, ucfirst($b['bucket']),
                $b['bucket'], $b['cushion_pct'], $b['prediction_max'], $yoy, $seats,
            );
        })->implode("\n");

        $prompt = <<<PROMPT
You are an IPU B.Tech admission counsellor in Delhi. A student with rank {$ctx['rank']} ({$ctx['region']}) is asking about one college: {$ctx['institute_name']} (year {$ctx['year']}). Their eligible branches sorted by safety:

{$branches}

Write 1–2 plain-English sentences advising the student on this college: which branch to prefer, any volatility risk (>30% YoY), seat-count caveats. No markdown, no emojis, no college name repetition, no hype.
PROMPT;

        $text = $this->gemini->generate($prompt);
        $text = trim((string) $text);

        return $text === '' ? null : $text;
    }
}
```

- [ ] **Step 4.3: If `GeminiClient::generate(string)` doesn't exist**

Add it to `app/Services/Finance/GeminiClient.php` as a thin wrapper that calls `generateContent(...)` with a single user prompt and returns `$response->text() ?? ''`. Do **not** redesign the existing finance flow.

- [ ] **Step 4.4: Smoke check**

```bash
php artisan tinker --execute="echo app(\App\Services\Rank\GeminiCounsellor::class)::class;"
```

Expected: `App\Services\Rank\GeminiCounsellor`. (Container can resolve it.)

- [ ] **Step 4.5: Commit**

```bash
git add app/Services/Rank/GeminiCounsellor.php app/Services/Finance/GeminiClient.php
git commit -m "feat(rank): GeminiCounsellor wrapper with 24h cache + graceful fallback"
```

---

## Task 5: RankLookup form additions (branch multi-select)

**Files:**
- Modify: `app/Filament/Pages/Rank/RankLookup.php`

- [ ] **Step 5.1: Add the new form field**

In `RankLookup::form()`, insert this Select between the Region and Your Rank fields:

```php
use App\Services\Rank\BranchFamilies;
use Filament\Forms\Components\Select as FormSelect;

// inside the schema array, after the 'region' Select:
FormSelect::make('branch_families')
    ->label('Preferred branch families')
    ->multiple()
    ->options(BranchFamilies::all())
    ->placeholder('All branches (no filter)')
    ->helperText('Pick one or more — selecting Computer / IT auto-includes CSE, AI, ML, IT, etc. Leave empty to see everything.')
    ->columnSpan(2),
```

Update the `mount()` defaults to include:

```php
'branch_families' => [],
```

Confirm by visiting `/admin/rank-lookup` (logged in) — the new picker should appear and accept multi-select.

- [ ] **Step 5.2: Add `$showAll` Livewire property**

In the class body:

```php
public bool $showAll = false;
```

And a public action:

```php
public function showMore(): void
{
    $this->showAll = true;
}
```

This is the trigger for the lazy AI-note generation (Task 7).

- [ ] **Step 5.3: Commit**

```bash
git add app/Filament/Pages/Rank/RankLookup.php
git commit -m "feat(rank): branch family picker + show-more state on RankLookup"
```

---

## Task 6: RankLookup query rewrite — use predictor + family filter + cushion cap

**Files:**
- Modify: `app/Filament/Pages/Rank/RankLookup.php`

- [ ] **Step 6.1: Rewrite `getResultsProperty()`**

Replace the existing method body with:

```php
use App\Services\Rank\BranchFamilies;
use App\Services\Rank\CollegePreferenceOrder;
use App\Services\Rank\RankPredictor;

public function getResultsProperty(): array
{
    if (empty($this->data['user_rank']) || empty($this->data['university_id'])) {
        return ['rows' => collect(), 'rank' => null, 'prediction_round' => null];
    }

    $rank = (int) $this->data['user_rank'];
    $userRegion = $this->data['region'];
    $predictionRound = $userRegion === 'delhi' ? 'sliding' : '3';
    $predictionRegion = 'delhi';
    $predictor = new RankPredictor;

    $branchIds = null;
    if (! empty($this->data['branch_families'])) {
        $branchIds = BranchFamilies::expandToBranchIds(
            $this->data['branch_families'],
            (int) $this->data['course_id'],
        );
        if (empty($branchIds)) {
            return ['rows' => collect(), 'rank' => $rank, 'prediction_round' => $predictionRound, 'user_region' => $userRegion, 'prediction_region' => $predictionRegion];
        }
    }

    $cutoffsQuery = \App\Models\Rank\Cutoff::with(['institute', 'branch'])
        ->where('university_id', $this->data['university_id'])
        ->where('course_id', $this->data['course_id'])
        ->where('qualifying_exam_id', $this->data['qualifying_exam_id'])
        ->where('year', $this->data['year'])
        ->where('region', $predictionRegion);
    if ($branchIds !== null) {
        $cutoffsQuery->whereIn('branch_id', $branchIds);
    }
    $cutoffs = $cutoffsQuery->get();

    $byKey = [];
    foreach ($cutoffs as $c) {
        $key = $c->institute_id.'|'.$c->branch_id.'|'.($c->shift ?? '');
        if (! isset($byKey[$key])) {
            $byKey[$key] = [
                'institute' => $c->institute->name,
                'branch' => $c->branch->name,
                'shift' => $c->shift,
                'institute_id' => $c->institute_id,
                'branch_id' => $c->branch_id,
                'rounds' => ['1' => null, '2' => null, '3' => null, 'sliding' => null],
                'seat_count' => null,
            ];
        }
        $byKey[$key]['rounds'][$c->round] = ['min' => $c->min_rank, 'max' => $c->max_rank];
    }

    // Drop branches whose prediction-round cell isn't eligible (no cell, out of band, or cushion > 50%).
    $byKey = array_filter($byKey, function ($row) use ($rank, $predictionRound, $predictor) {
        $cell = $row['rounds'][$predictionRound] ?? null;

        return $cell && $predictor->isEligible($rank, $cell);
    });

    if (! empty($byKey)) {
        $instIds = array_unique(array_column($byKey, 'institute_id'));
        $branchIdsAll = array_unique(array_column($byKey, 'branch_id'));
        $seats = \App\Models\Rank\Seat::where('university_id', $this->data['university_id'])
            ->where('course_id', $this->data['course_id'])
            ->where('year', $this->data['year'])
            ->whereIn('institute_id', $instIds)
            ->whereIn('branch_id', $branchIdsAll)
            ->get()
            ->keyBy(fn ($s) => $s->institute_id.'|'.$s->branch_id);

        foreach ($byKey as $key => &$row) {
            $cell = $row['rounds'][$predictionRound];
            $seat = $seats->get($row['institute_id'].'|'.$row['branch_id']);
            $row['seat_count'] = $seat?->seat_count;
            $row['prediction_max'] = $cell['max'];
            $row['cushion_pct'] = $predictor->cushionPct($rank, $cell['max']);
            $row['bucket'] = $predictor->bucket($rank, $cell['max']);
        }
        unset($row);
    }

    // Group rows by college, sort branches inside by prediction max ASC, sort colleges by demand priority.
    $colleges = collect($byKey)
        ->groupBy('institute')
        ->map(fn ($branches, $institute) => [
            'institute' => $institute,
            'priority' => CollegePreferenceOrder::sortKey($institute),
            'branches' => $branches->sortBy('prediction_max')->values()->all(),
        ])
        ->sort(fn ($a, $b) => $a['priority'] <=> $b['priority'] ?: strcasecmp($a['institute'], $b['institute']))
        ->values();

    return [
        'rank' => $rank,
        'prediction_round' => $predictionRound,
        'prediction_region' => $predictionRegion,
        'user_region' => $userRegion,
        'colleges' => $colleges,
        'visible_count' => $this->showAll ? $colleges->count() : min(7, $colleges->count()),
    ];
}
```

- [ ] **Step 6.2: Smoke check via tinker**

```bash
php artisan tinker --execute="
\$u = \App\Models\User::where('email','sumitdabass@gmail.com')->first();
auth()->login(\$u);
\$page = new \App\Filament\Pages\Rank\RankLookup;
\$page->mount();
\$page->data['user_rank'] = 138000;
\$page->data['region'] = 'delhi';
\$page->data['year'] = 2026;
\$res = \$page->getResultsProperty();
echo 'colleges=' . \$res['colleges']->count() . PHP_EOL;
echo 'first=' . \$res['colleges']->first()['institute'] . PHP_EOL;
"
```

Expected: `colleges=7`, `first=Maharaja Agrasen Institute of Technology`. (Confirms cushion cap + priority sort.)

- [ ] **Step 6.3: Commit**

```bash
git add app/Filament/Pages/Rank/RankLookup.php
git commit -m "feat(rank): predictor-driven query with family filter + cushion cap + demand sort"
```

---

## Task 7: AI notes — eager top-7, lazy on Show more

**Files:**
- Modify: `app/Filament/Pages/Rank/RankLookup.php`

- [ ] **Step 7.1: Add `notesGeneratedFor` state + `aiOn` toggle**

In the class:

```php
public bool $aiOn = true;
public array $notesGeneratedFor = []; // institute_ids that have notes computed
public array $notes = []; // institute_id => string|null
```

Update `mount()` defaults to include `'aiOn' => true`.

Add a Form `Toggle` for "Generate AI notes" (default on) — between the rank input and submit:

```php
use Filament\Forms\Components\Toggle;

Toggle::make('aiOn')->label('Generate AI counselling notes')->default(true)->columnSpan(2),
```

- [ ] **Step 7.2: Generate notes for currently-visible colleges**

Add method:

```php
use App\Services\Rank\GeminiCounsellor;

private function generateMissingNotes(\Illuminate\Support\Collection $colleges, int $upTo, int $rank, string $userRegion, int $year): void
{
    if (! $this->aiOn) {
        return;
    }
    $counsellor = app(GeminiCounsellor::class);
    $branchFilterHash = md5(json_encode($this->data['branch_families'] ?? []));

    foreach ($colleges->take($upTo) as $col) {
        $instId = $col['branches'][0]['institute_id'] ?? null;
        if (! $instId || in_array($instId, $this->notesGeneratedFor, true)) {
            continue;
        }
        $note = $counsellor->note([
            'institute_name' => $col['institute'],
            'rank' => $rank,
            'region' => $userRegion,
            'year' => $year,
            'branch_filter_hash' => $branchFilterHash,
            'branches' => array_map(fn ($b) => [
                'branch_name' => $b['branch'],
                'shift' => $b['shift'],
                'bucket' => $b['bucket'],
                'cushion_pct' => $b['cushion_pct'],
                'prediction_max' => $b['prediction_max'],
                'sliding_max' => $b['rounds']['sliding']['max'] ?? null,
                'r3_max' => $b['rounds']['3']['max'] ?? null,
                'yoy_delta_pct' => null, // wired in Task 8 if 2024 data exists
                'seat_count' => $b['seat_count'],
            ], $col['branches']),
        ]);
        $this->notes[$instId] = $note;
        $this->notesGeneratedFor[] = $instId;
    }
}
```

- [ ] **Step 7.3: Hook generation into `getResultsProperty()`**

Right before the `return [...]`:

```php
$this->generateMissingNotes(
    $colleges,
    $this->showAll ? $colleges->count() : min(7, $colleges->count()),
    $rank,
    $userRegion,
    (int) $this->data['year'],
);
```

And expose `'notes' => $this->notes` in the returned array.

- [ ] **Step 7.4: Hook into `showMore()`**

`showMore()` currently just sets `$showAll = true`. Livewire will re-render → `getResultsProperty` runs → notes for newly visible colleges are generated. No extra wiring needed.

- [ ] **Step 7.5: Commit**

```bash
git add app/Filament/Pages/Rank/RankLookup.php
git commit -m "feat(rank): eager AI notes for top-7, lazy load on Show more"
```

---

## Task 8: Blade UI — bucket badge column, college groups, AI notes, Show more

**Files:**
- Modify: `resources/views/filament/pages/rank/rank-lookup.blade.php`

- [ ] **Step 8.1: Replace the table body with college-grouped layout**

```blade
<x-filament-panels::page>
    <style>
        .fits-cell { background-color: rgb(220 252 231); font-weight: 600; }
        .dark .fits-cell { background-color: rgba(34,197,94,0.25); }
        .badge-safe     { background:#dcfce7; color:#15803d; padding:2px 8px; border-radius:4px; font-size:11px; font-weight:600; }
        .badge-probable { background:#fef3c7; color:#a16207; padding:2px 8px; border-radius:4px; font-size:11px; font-weight:600; }
        .badge-reach    { background:#fee2e2; color:#b91c1c; padding:2px 8px; border-radius:4px; font-size:11px; font-weight:600; }
        @media print {
            .fi-sidebar, .fi-topbar, .fi-page-header, form, .no-print { display: none !important; }
            .fi-main-ctn { padding: 0 !important; }
            body { background: #fff !important; }
            table { font-size: 11px; }
        }
    </style>

    @php $result = $this->results; @endphp

    <div class="no-print">
        <form wire:submit="submit" class="mb-6">
            {{ $this->form }}
            <div class="mt-4 flex gap-3">
                <x-filament::button type="submit" icon="heroicon-o-magnifying-glass">Look up</x-filament::button>
                <x-filament::button type="button" color="gray" icon="heroicon-o-printer" onclick="window.print()">Print</x-filament::button>
            </div>
        </form>
    </div>

    @if (! empty($result['rank']))
        @php
            $predLabel = $result['prediction_round'] === 'sliding' ? 'Sliding (R4) Delhi' : 'R3 Delhi';
            $regionLabel = ucfirst(str_replace('_', ' ', $result['user_region'] ?? ''));
            $colleges = $result['colleges'];
            $visible = $result['visible_count'];
            $hidden = max(0, $colleges->count() - $visible);
        @endphp

        <div class="mb-4">
            <h2 style="font-size:18px; font-weight:bold; margin:0;">Rank Lookup Results</h2>
            <p style="margin:4px 0 0 0; font-size: 13px; color: var(--text-sub, #4b5563);">
                Rank: <strong>{{ number_format($result['rank']) }}</strong> ·
                Student region: <strong>{{ $regionLabel }}</strong> ·
                Year: <strong>{{ $this->data['year'] ?? '' }}</strong> ·
                Prediction basis: <strong>{{ $predLabel }}</strong> ·
                Showing <strong>{{ $visible }}</strong> of <strong>{{ $colleges->count() }}</strong> eligible colleges
            </p>
        </div>

        @if ($colleges->isEmpty())
            <div class="rounded-lg border border-gray-200 bg-white p-6 text-center text-gray-600 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-400">
                No matching cutoffs for rank {{ number_format($result['rank']) }} under the current filter.
            </div>
        @else
            <div class="overflow-x-auto rounded-lg border border-gray-200 bg-white dark:border-gray-700 dark:bg-gray-900">
                <table class="min-w-full text-sm">
                    <thead class="bg-gray-50 dark:bg-gray-800">
                    <tr>
                        <th class="px-3 py-2 text-left">#</th>
                        <th class="px-3 py-2 text-left">Branch</th>
                        <th class="px-3 py-2 text-left">Bucket</th>
                        <th class="px-3 py-2 text-right">{{ $predLabel }} Max</th>
                        <th class="px-3 py-2 text-right">Cushion</th>
                        <th class="px-3 py-2 text-right">Seats</th>
                        <th class="px-3 py-2 text-right">R1</th>
                        <th class="px-3 py-2 text-right">R2</th>
                        <th class="px-3 py-2 text-right">R3</th>
                        <th class="px-3 py-2 text-right">Sliding</th>
                    </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                    @foreach ($colleges->take($visible) as $idx => $col)
                        @php
                            $instId = $col['branches'][0]['institute_id'] ?? null;
                            $note = $result['notes'][$instId] ?? null;
                        @endphp
                        <tr style="background:#f1f5f9;">
                            <td colspan="10" class="px-3 py-2">
                                <strong style="font-size:14px;">{{ $idx + 1 }}. {{ $col['institute'] }}</strong>
                                @if ($note)
                                    <div style="margin-top:2px; font-style:italic; font-size:12px; color:#475569;">{{ $note }}</div>
                                @endif
                            </td>
                        </tr>
                        @foreach ($col['branches'] as $b)
                            @php
                                $shift = $b['shift'] ? ' (Shift '.$b['shift'].')' : '';
                                $r1 = $b['rounds']['1']['max'] ?? null;
                                $r2 = $b['rounds']['2']['max'] ?? null;
                                $r3 = $b['rounds']['3']['max'] ?? null;
                                $sl = $b['rounds']['sliding']['max'] ?? null;
                            @endphp
                            <tr>
                                <td class="px-3 py-2"></td>
                                <td class="px-3 py-2">{{ $b['branch'] }}{{ $shift }}</td>
                                <td class="px-3 py-2"><span class="badge-{{ $b['bucket'] }}">{{ ucfirst($b['bucket']) }}</span></td>
                                <td class="px-3 py-2 text-right fits-cell">{{ number_format($b['prediction_max']) }}</td>
                                <td class="px-3 py-2 text-right">{{ ($b['cushion_pct'] >= 0 ? '+' : '').$b['cushion_pct'] }}%</td>
                                <td class="px-3 py-2 text-right">{{ $b['seat_count'] !== null ? number_format($b['seat_count']) : '—' }}</td>
                                <td class="px-3 py-2 text-right">{{ $r1 ? number_format($r1) : '—' }}</td>
                                <td class="px-3 py-2 text-right">{{ $r2 ? number_format($r2) : '—' }}</td>
                                <td class="px-3 py-2 text-right">{{ $r3 ? number_format($r3) : '—' }}</td>
                                <td class="px-3 py-2 text-right">{{ $sl ? number_format($sl) : '—' }}</td>
                            </tr>
                        @endforeach
                    @endforeach
                    </tbody>
                </table>
            </div>

            @if ($hidden > 0)
                <div class="no-print mt-4 text-center">
                    <x-filament::button wire:click="showMore" color="gray" icon="heroicon-o-chevron-down">
                        Show {{ $hidden }} more college{{ $hidden === 1 ? '' : 's' }}
                    </x-filament::button>
                </div>
            @endif
        @endif
    @endif
</x-filament-panels::page>
```

- [ ] **Step 8.2: Clear view cache**

Run: `php artisan view:clear`

- [ ] **Step 8.3: Browser smoke**

Visit `/admin/rank-lookup`, log in if needed. Try `Year 2026, Region Delhi, Rank 138000` → should render exactly 7 college groups, each with grouped header row + branch rows + AI note line under header.

Try `Region Outside Delhi, Rank 97000` → should show 4 college groups, no Show more button.

- [ ] **Step 8.4: Commit**

```bash
git add resources/views/filament/pages/rank/rank-lookup.blade.php
git commit -m "feat(rank): grouped college blocks + bucket badges + AI note row + show-more"
```

---

## Task 9: Feature test — RankLookup end-to-end

**Files:**
- Create: `tests/Feature/Rank/RankLookupTest.php`

- [ ] **Step 9.1: Write the test**

```php
<?php

namespace Tests\Feature\Rank;

use App\Filament\Pages\Rank\RankLookup;
use App\Models\User;
use App\Services\Rank\GeminiCounsellor;
use Livewire\Livewire;
use Mockery;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class RankLookupTest extends TestCase
{
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
        $this->admin = User::factory()->create(['is_active' => true]);
        $this->admin->assignRole('admin');
        $this->actingAs($this->admin);
    }

    /** @test */
    public function delhi_rank_138000_shows_top_7_colleges_in_demand_order(): void
    {
        $this->bindCounsellorMock(); // returns canned note

        $component = Livewire::test(RankLookup::class)
            ->set('data.user_rank', 138000)
            ->set('data.region', 'delhi')
            ->set('data.year', 2026);

        $result = $component->instance()->getResultsProperty();
        $this->assertSame(2026, (int) $component->get('data.year'));
        $names = $result['colleges']->pluck('institute')->all();
        $this->assertSame(7, $result['visible_count']);
        $this->assertSame('Maharaja Agrasen Institute of Technology', $names[0]);
        $this->assertSame('Maharaja Surajmal Institute of Technology', $names[1]);
        $this->assertSame('Bharati Vidyapeeth\'s College of Engineering', $names[2]);
    }

    /** @test */
    public function outside_delhi_rank_97000_returns_4_colleges_no_show_more(): void
    {
        $this->bindCounsellorMock();

        $component = Livewire::test(RankLookup::class)
            ->set('data.user_rank', 97000)
            ->set('data.region', 'outside_delhi')
            ->set('data.year', 2026);

        $result = $component->instance()->getResultsProperty();
        $this->assertSame('3', $result['prediction_round']);
        $this->assertSame(4, $result['colleges']->count());
        $this->assertSame(4, $result['visible_count']); // no hidden — fewer than 7 total
    }

    /** @test */
    public function branch_family_filter_narrows_results(): void
    {
        $this->bindCounsellorMock();

        $unfiltered = Livewire::test(RankLookup::class)
            ->set('data.user_rank', 138000)
            ->set('data.region', 'delhi')
            ->set('data.year', 2026)
            ->instance()->getResultsProperty();

        $cs_only = Livewire::test(RankLookup::class)
            ->set('data.user_rank', 138000)
            ->set('data.region', 'delhi')
            ->set('data.year', 2026)
            ->set('data.branch_families', ['cs_it'])
            ->instance()->getResultsProperty();

        // CS-only should be ≤ unfiltered.
        $this->assertLessThanOrEqual(
            $unfiltered['colleges']->sum(fn ($c) => count($c['branches'])),
            $cs_only['colleges']->sum(fn ($c) => count($c['branches'])),
        );
        // Every branch in cs_only must be a CS-family branch.
        foreach ($cs_only['colleges'] as $col) {
            foreach ($col['branches'] as $b) {
                $this->assertSame('cs_it', \App\Services\Rank\BranchFamilies::familyFor($b['branch']));
            }
        }
    }

    /** @test */
    public function show_more_expands_visible_count(): void
    {
        $this->bindCounsellorMock();

        $component = Livewire::test(RankLookup::class)
            ->set('data.user_rank', 138000)
            ->set('data.region', 'delhi')
            ->set('data.year', 2026);

        $beforeVisible = $component->instance()->getResultsProperty()['visible_count'];
        $beforeTotal = $component->instance()->getResultsProperty()['colleges']->count();
        $this->assertSame(min(7, $beforeTotal), $beforeVisible);

        $component->call('showMore');

        $after = $component->instance()->getResultsProperty();
        $this->assertSame($after['colleges']->count(), $after['visible_count']);
    }

    /** @test */
    public function ai_off_skips_gemini_and_leaves_notes_empty(): void
    {
        $mock = Mockery::mock(GeminiCounsellor::class);
        $mock->shouldNotReceive('note'); // strict — must NOT be called when aiOn=false
        $this->app->instance(GeminiCounsellor::class, $mock);

        $result = Livewire::test(RankLookup::class)
            ->set('data.user_rank', 138000)
            ->set('data.region', 'delhi')
            ->set('data.year', 2026)
            ->set('aiOn', false)
            ->instance()->getResultsProperty();

        $this->assertNotEmpty($result['colleges']);
        $this->assertEmpty($result['notes'] ?? []);
    }

    private function bindCounsellorMock(): void
    {
        $mock = Mockery::mock(GeminiCounsellor::class);
        $mock->shouldReceive('note')->andReturn('Test note from mock.');
        $this->app->instance(GeminiCounsellor::class, $mock);
    }
}
```

- [ ] **Step 9.2: Run the feature tests**

```bash
php artisan test --filter RankLookupTest
```

Expected: 5 tests pass. If any fail, fix the implementation, **not the test** — the test encodes the spec.

- [ ] **Step 9.3: Run the full Rank suite to make sure nothing else broke**

```bash
php artisan test --filter Rank
```

Expected: all green (Unit + Feature + the existing migration sanity, if any).

- [ ] **Step 9.4: Commit**

```bash
git add tests/Feature/Rank/RankLookupTest.php
git commit -m "test(rank): feature tests for branch filter, top-7 cap, show more, AI-off path"
```

---

## Task 10: Lint, smoke, finalize

- [ ] **Step 10.1: Lint clean**

```bash
./vendor/bin/pint app/Filament/Pages/Rank app/Services/Rank tests/Unit/Rank tests/Feature/Rank resources/views/filament/pages/rank
./vendor/bin/pint --test app/Filament/Pages/Rank app/Services/Rank tests/Unit/Rank tests/Feature/Rank
```

Expected: `{"result":"pass"}` on the test pass.

- [ ] **Step 10.2: Visual smoke**

Restart `php artisan serve` if needed. Visit `/admin/rank-lookup`. Walk through all four scenarios:

| Inputs | Expected |
|---|---|
| Rank 138,000 · Delhi · 2026 | 7 college groups, MAIT first, MSIT 2nd, BVP 3rd; bucket badges colored; AI notes appear under each header row |
| Same + branch family = "Computer / IT" | All branch rows are CS-family only; college order preserved |
| Same + AI toggle off | No AI notes; everything else identical |
| Rank 97,000 · Outside Delhi · 2026 | 4 colleges, no "Show more", prediction basis says "R3 Delhi" |
| Rank 50,000 · Delhi · 2026 | 7 visible + "Show N more" link; click reveals rest with notes loaded lazily |

Any visual issue → fix the blade and re-test before moving on.

- [ ] **Step 10.3: Final commit + push (paused for Sumit's go-ahead)**

The plan stops here. Sumit reviews the local install, then we push to GitHub and pull on Hostinger per `DEPLOY.md`.

---

## Self-review checklist

Spec coverage:
- ✅ Branch family multi-select with hybrid UX → Tasks 1, 5
- ✅ Top 7 + "Show more" expander, no hard cap → Tasks 5 (state), 6 (visible_count), 8 (button)
- ✅ Cushion > 50% drop → Task 3 (predictor) + Task 6 (filter)
- ✅ Min-rank check kept → Task 3
- ✅ College demand sort → Tasks 2, 6
- ✅ Bucket badges Safe / Probable / Reach + cushion% + max-rank side by side → Task 8
- ✅ AI notes via Gemini, eager top-7 + lazy on Show more, with cache → Tasks 4, 7
- ✅ Group rows under college header with italic AI note → Task 8
- ✅ Print stylesheet keeps notes + badges → Task 8 (`@media print` block)
- ✅ Feature tests covering branch filter, top-7, show-more, AI-off → Task 9

Placeholders: none.

Type consistency: `BranchFamilies::familyFor` returns `?string`, `expandToBranchIds` returns `array<int,int>`, `RankPredictor::isEligible` takes `['min','max']`, `getResultsProperty()` returns the structure consumed by the blade — all aligned across tasks.
