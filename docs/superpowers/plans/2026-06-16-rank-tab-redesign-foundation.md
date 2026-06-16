# Rank Tab Redesign — Plan 1: Foundation Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make the Rank backend multi-dataset: add category/sub-category/gender dimensions, dataset+category-aware benchmark rounds, four scoped roles, and load the JAC Delhi (DTU/NSUT/IGDTUW) 2025 cutoffs — all behind tests, with the existing IPU behaviour preserved.

**Architecture:** Extend the existing `ranks`-connection schema (cutoffs gains `category`/`sub_category`; round enum gains `4`/`5`). A `RankDataset` map ties dataset tokens (`ipu`/`dtu`) to university codes. A `BenchmarkRoundStrategy` picks which round's closing rank is the prediction benchmark (IPU general→sliding, reserved→R3; DTU→final round). `RankPredictor` gains a SAFE→UNLIKELY chance scale alongside its legacy bucket logic. A `JacCutoffImporter` loads the parsed CSV. Four Spatie roles (`rank-{ipu,dtu}-{predict,analyse}`) + `User` helpers gate access (consumed by Plan 2's UI).

**Tech Stack:** Laravel 11, Filament 3, Spatie laravel-permission, PHPUnit 11, MySQL/MariaDB (two connections: default + `ranks`).

---

## HARD RULE — IPU and DTU are totally separate (never merge)

IPU and DTU are independent datasets and must stay that way end to end:

- **Separate universities:** IPU (`code=IPU`) and JAC Delhi (`code=JAC`) are distinct `universities` rows. No row, query, or result ever spans both.
- **Every query is dataset-scoped:** all cutoff/seat reads filter by the dataset's `university_id` (resolved from the dataset token via `RankDataset`). There is no "all datasets" query.
- **No combined results:** a prediction run is for exactly one dataset; IPU and DTU options are never shown in the same list. The UI (Plan 2) renders them under separate cards/pages gated by separate roles.
- **Separate access:** `rank-ipu-*` and `rank-dtu-*` roles are independent; an IPU-only user can never reach DTU data, and vice-versa.
- **Student auto-probability is IPU-only:** `StudentChoicePredictor::topChoices(Student)` (observer/peek drawer) predicts against IPU only — davya-crm students are IPU leads. DTU is a separate analysis and is **not** wired into student records.
- **Shared code ≠ merged data:** `RankPredictor` (chance math), `PredictorContext`, and `BenchmarkRoundStrategy` are generic utilities reused by both; they always receive the dataset token and operate on one dataset's data at a time. Reusing a function does not mix the datasets.

Any task that appears to blur this line is a bug — stop and flag it.

## Conventions

- Run tests with: `php artisan test --filter <Name>` (PHPUnit, `/** @test */` annotations, `RefreshDatabase`).
- Rank models use `protected $connection = 'ranks'`. Migrations for rank tables set `public $connection = 'ranks'`.
- Commit after each task. Branch is `feat/rank-tab-redesign` (already created).
- Category vocabulary: `general`, `ews`, `obc`, `sc`, `st` (reserved = anything ≠ `general`).
- Sub-category vocabulary: `gender_neutral`, `girl`, `single_girl`, `pwd`, `defense_cw`, `kashmiri_migrant`.
- Dataset tokens: `ipu` (university code `IPU`), `dtu` (university code `JAC`).

---

## Task 1: Migration — add category/sub_category + widen round enum on `cutoffs`

**Files:**
- Create: `database/migrations/ranks/2026_06_16_000001_add_category_to_cutoffs.php`
- Test: `tests/Feature/Rank/CutoffSchemaTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Rank;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class CutoffSchemaTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function cutoffs_has_category_subcategory_and_extended_rounds(): void
    {
        $conn = Schema::connection('ranks');
        $this->assertTrue($conn->hasColumn('cutoffs', 'category'));
        $this->assertTrue($conn->hasColumn('cutoffs', 'sub_category'));

        // round '5' must now be insertable (JAC has rounds 1-5)
        $type = $conn->getConnection()
            ->selectOne("SHOW COLUMNS FROM cutoffs WHERE Field = 'round'");
        $this->assertStringContainsString("'5'", $type->Type);
        $this->assertStringContainsString("'4'", $type->Type);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter CutoffSchemaTest`
Expected: FAIL — `cutoffs` has no `category` column.

- [ ] **Step 3: Write the migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public $connection = 'ranks';

    public function up(): void
    {
        // 1. Add nullable category dimensions (IPU rows stay valid until category data lands).
        Schema::connection($this->connection)->table('cutoffs', function (Blueprint $table) {
            $table->string('category', 16)->nullable()->after('region');
            $table->string('sub_category', 24)->nullable()->after('category');
        });

        // 2. Widen the round enum to include JAC rounds 4 and 5.
        DB::connection($this->connection)->statement(
            "ALTER TABLE cutoffs MODIFY COLUMN round ENUM('1','2','3','4','5','sliding') NOT NULL"
        );

        // 3. Rebuild the unique index to include the new dimensions.
        //    NOTE: `cutoffs_unique` leads with `university_id` and is the supporting
        //    index for the university FK, so MySQL won't drop it (errno 1553) until
        //    the FK is dropped. Drop FK -> swap unique -> re-add FK (cascadeOnDelete,
        //    matching the original create migration).
        Schema::connection($this->connection)->table('cutoffs', function (Blueprint $table) {
            $table->dropForeign('cutoffs_university_id_foreign');
            $table->dropUnique('cutoffs_unique');
            $table->unique(
                ['university_id', 'course_id', 'qualifying_exam_id', 'admission_process_id',
                 'year', 'round', 'institute_id', 'branch_id', 'shift', 'region',
                 'category', 'sub_category'],
                'cutoffs_unique'
            );
            $table->foreign('university_id')->references('id')->on('universities')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->table('cutoffs', function (Blueprint $table) {
            $table->dropForeign('cutoffs_university_id_foreign');
            $table->dropUnique('cutoffs_unique');
            $table->dropColumn(['category', 'sub_category']);
            $table->unique(
                ['university_id', 'course_id', 'qualifying_exam_id', 'admission_process_id',
                 'year', 'round', 'institute_id', 'branch_id', 'shift', 'region'],
                'cutoffs_unique'
            );
            $table->foreign('university_id')->references('id')->on('universities')->cascadeOnDelete();
        });
        DB::connection($this->connection)->statement(
            "ALTER TABLE cutoffs MODIFY COLUMN round ENUM('1','2','3','sliding') NOT NULL"
        );
    }
};
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter CutoffSchemaTest`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add database/migrations/ranks/2026_06_16_000001_add_category_to_cutoffs.php tests/Feature/Rank/CutoffSchemaTest.php
git commit -m "feat(rank): add category/sub_category + rounds 4-5 to cutoffs"
```

---

## Task 2: Migration — add gender + reservation_category to `students`

**Files:**
- Create: `database/migrations/2026_06_16_000002_add_gender_reservation_to_students.php`
- Test: `tests/Feature/Rank/StudentRankFieldsTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Rank;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class StudentRankFieldsTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function students_have_gender_and_reservation_category(): void
    {
        $this->assertTrue(Schema::hasColumn('students', 'gender'));
        $this->assertTrue(Schema::hasColumn('students', 'reservation_category'));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter StudentRankFieldsTest`
Expected: FAIL — column missing.

- [ ] **Step 3: Write the migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('students', function (Blueprint $table) {
            $table->enum('gender', ['male', 'female', 'other'])->nullable()->after('category');
            $table->string('reservation_category', 16)->nullable()->after('gender');
        });
    }

    public function down(): void
    {
        Schema::table('students', function (Blueprint $table) {
            $table->dropColumn(['gender', 'reservation_category']);
        });
    }
};
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter StudentRankFieldsTest`
Expected: PASS.

- [ ] **Step 5: Update model fillable**

Modify `app/Models/Student.php` — add `'gender'` and `'reservation_category'` to the `$fillable` array (locate the existing `protected $fillable = [` block and append the two strings).

- [ ] **Step 6: Commit**

```bash
git add database/migrations/2026_06_16_000002_add_gender_reservation_to_students.php tests/Feature/Rank/StudentRankFieldsTest.php app/Models/Student.php
git commit -m "feat(rank): add gender + reservation_category to students"
```

---

## Task 3: Update `Cutoff` model fillable

**Files:**
- Modify: `app/Models/Rank/Cutoff.php:15-19`

- [ ] **Step 1: Add the new fields to `$fillable`**

In `app/Models/Rank/Cutoff.php`, change the `$fillable` array to include `'category'` and `'sub_category'`:

```php
    protected $fillable = [
        'university_id', 'course_id', 'qualifying_exam_id', 'admission_process_id',
        'year', 'round', 'institute_id', 'branch_id', 'shift', 'region',
        'category', 'sub_category',
        'min_rank', 'max_rank', 'source', 'created_by', 'updated_by',
    ];
```

- [ ] **Step 2: Commit**

```bash
git add app/Models/Rank/Cutoff.php
git commit -m "feat(rank): allow category/sub_category mass-assignment on Cutoff"
```

---

## Task 4: `RankDataset` map (token ↔ university codes ↔ labels)

**Files:**
- Create: `app/Rank/RankDataset.php`
- Test: `tests/Unit/Rank/RankDatasetTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Unit\Rank;

use App\Rank\RankDataset;
use Tests\TestCase;

class RankDatasetTest extends TestCase
{
    /** @test */
    public function maps_tokens_to_university_codes_and_labels(): void
    {
        $this->assertSame(['IPU'], RankDataset::universityCodes('ipu'));
        $this->assertSame(['JAC'], RankDataset::universityCodes('dtu'));
        $this->assertSame('IPU', RankDataset::label('ipu'));
        $this->assertSame('DTU', RankDataset::label('dtu'));
        $this->assertTrue(RankDataset::courseFixedToBtech('dtu'));
        $this->assertFalse(RankDataset::courseFixedToBtech('ipu'));
        $this->assertSame(['ipu', 'dtu'], RankDataset::tokens());
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter RankDatasetTest`
Expected: FAIL — class not found.

- [ ] **Step 3: Write the class**

```php
<?php

namespace App\Rank;

class RankDataset
{
    /** @var array<string, array{label:string, codes:array<int,string>, btech_only:bool}> */
    private const MAP = [
        'ipu' => ['label' => 'IPU',  'codes' => ['IPU'], 'btech_only' => false],
        'dtu' => ['label' => 'DTU',  'codes' => ['JAC'], 'btech_only' => true],
    ];

    /** @return array<int,string> */
    public static function tokens(): array
    {
        return array_keys(self::MAP);
    }

    /** @return array<int,string> */
    public static function universityCodes(string $token): array
    {
        return self::MAP[$token]['codes'] ?? [];
    }

    public static function label(string $token): string
    {
        return self::MAP[$token]['label'] ?? strtoupper($token);
    }

    public static function courseFixedToBtech(string $token): bool
    {
        return self::MAP[$token]['btech_only'] ?? false;
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter RankDatasetTest`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Rank/RankDataset.php tests/Unit/Rank/RankDatasetTest.php
git commit -m "feat(rank): RankDataset token map (ipu/dtu)"
```

---

## Task 5: `BenchmarkRoundStrategy` — pick the prediction round

**Files:**
- Create: `app/Services/Rank/BenchmarkRoundStrategy.php`
- Test: `tests/Unit/Rank/BenchmarkRoundStrategyTest.php`

The benchmark round is the round whose closing rank we score against. IPU: General→`sliding` (fallback to highest numeric present), reserved→`3` (fallback to highest present ≤3). DTU: highest numeric round present (final round). Input is the set of rounds actually present for a cell.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Unit\Rank;

use App\Services\Rank\BenchmarkRoundStrategy;
use Tests\TestCase;

class BenchmarkRoundStrategyTest extends TestCase
{
    private BenchmarkRoundStrategy $s;

    protected function setUp(): void
    {
        parent::setUp();
        $this->s = new BenchmarkRoundStrategy;
    }

    /** @test */
    public function ipu_general_uses_sliding(): void
    {
        $this->assertSame('sliding', $this->s->pick('ipu', 'general', ['1', '3', 'sliding']));
    }

    /** @test */
    public function ipu_general_falls_back_to_highest_numeric_when_no_sliding(): void
    {
        $this->assertSame('3', $this->s->pick('ipu', 'general', ['1', '2', '3']));
    }

    /** @test */
    public function ipu_reserved_uses_round_3(): void
    {
        $this->assertSame('3', $this->s->pick('ipu', 'sc', ['1', '2', '3', 'sliding']));
    }

    /** @test */
    public function ipu_reserved_falls_back_to_highest_present_at_most_3(): void
    {
        $this->assertSame('2', $this->s->pick('ipu', 'obc', ['1', '2']));
    }

    /** @test */
    public function dtu_uses_highest_numeric_round(): void
    {
        $this->assertSame('5', $this->s->pick('dtu', 'general', ['1', '2', '5']));
        $this->assertSame('4', $this->s->pick('dtu', 'sc', ['1', '4']));
    }

    /** @test */
    public function returns_null_when_no_rounds_present(): void
    {
        $this->assertNull($this->s->pick('dtu', 'general', []));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter BenchmarkRoundStrategyTest`
Expected: FAIL — class not found.

- [ ] **Step 3: Write the class**

```php
<?php

namespace App\Services\Rank;

class BenchmarkRoundStrategy
{
    /**
     * @param  string  $datasetToken  'ipu' | 'dtu'
     * @param  string  $category      'general' | 'ews' | 'obc' | 'sc' | 'st' | ...
     * @param  array<int,string>  $available  round keys present for this cell
     * @return string|null  chosen round key, or null if none available
     */
    public function pick(string $datasetToken, string $category, array $available): ?string
    {
        if ($available === []) {
            return null;
        }

        $isGeneral = mb_strtolower(trim($category)) === 'general';

        if ($datasetToken === 'ipu') {
            if ($isGeneral) {
                if (in_array('sliding', $available, true)) {
                    return 'sliding';
                }
                return $this->highestNumeric($available);
            }
            // reserved → prefer round 3, else highest numeric ≤ 3
            if (in_array('3', $available, true)) {
                return '3';
            }
            return $this->highestNumeric($available, 3);
        }

        // dtu (and any other dataset): final = highest numeric round present
        return $this->highestNumeric($available);
    }

    /** Highest numeric round (optionally capped), ignoring 'sliding'. */
    private function highestNumeric(array $available, ?int $cap = null): ?string
    {
        $nums = [];
        foreach ($available as $r) {
            if (is_numeric($r) && ($cap === null || (int) $r <= $cap)) {
                $nums[] = (int) $r;
            }
        }
        if ($nums === []) {
            return null;
        }
        rsort($nums);

        return (string) $nums[0];
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter BenchmarkRoundStrategyTest`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Services/Rank/BenchmarkRoundStrategy.php tests/Unit/Rank/BenchmarkRoundStrategyTest.php
git commit -m "feat(rank): BenchmarkRoundStrategy (IPU general->sliding, reserved->R3; DTU->final)"
```

---

## Task 6: `RankPredictor` chance scale (additive — keep legacy methods)

**Files:**
- Modify: `app/Services/Rank/RankPredictor.php`
- Test: `tests/Unit/Rank/RankPredictorChanceTest.php`

The legacy `bucket`/`isEligible`/`cushionPct`/`yoyDeltaPct` stay (the observer + peek drawer use them). We **add** a `chance()` method (the validated SAFE→UNLIKELY scale measured off the closing rank) and a `withinReach()` helper.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Unit\Rank;

use App\Services\Rank\RankPredictor;
use Tests\TestCase;

class RankPredictorChanceTest extends TestCase
{
    private RankPredictor $p;

    protected function setUp(): void
    {
        parent::setUp();
        $this->p = new RankPredictor;
    }

    /** @test */
    public function chance_labels_by_ratio_to_closing_rank(): void
    {
        $cr = 100000;
        $this->assertSame('SAFE',       $this->p->chance(80000, $cr));   // 0.80 ≤ 0.85
        $this->assertSame('SAFE',       $this->p->chance(85000, $cr));   // 0.85
        $this->assertSame('LIKELY',     $this->p->chance(100000, $cr));  // 1.00
        $this->assertSame('BORDERLINE', $this->p->chance(108000, $cr));  // 1.08
        $this->assertSame('STRETCH',    $this->p->chance(125000, $cr));  // 1.25
        $this->assertSame('UNLIKELY',   $this->p->chance(125001, $cr));  // > 1.25
    }

    /** @test */
    public function within_reach_is_anything_but_unlikely(): void
    {
        $this->assertTrue($this->p->withinReach(125000, 100000));
        $this->assertFalse($this->p->withinReach(200000, 100000));
    }

    /** @test */
    public function chance_handles_zero_closing(): void
    {
        $this->assertSame('UNLIKELY', $this->p->chance(50000, 0));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter RankPredictorChanceTest`
Expected: FAIL — `chance()` undefined.

- [ ] **Step 3: Add the methods** (append inside the `RankPredictor` class, before the closing brace)

```php
    /**
     * Admission chance measured off the closing rank (last admitted).
     * Lower rank number = stronger candidate.
     *
     * @return 'SAFE'|'LIKELY'|'BORDERLINE'|'STRETCH'|'UNLIKELY'
     */
    public function chance(int $rank, int $closingRank): string
    {
        if ($closingRank <= 0) {
            return 'UNLIKELY';
        }
        $ratio = $rank / $closingRank;
        if ($ratio <= 0.85) return 'SAFE';
        if ($ratio <= 1.00) return 'LIKELY';
        if ($ratio <= 1.08) return 'BORDERLINE';
        if ($ratio <= 1.25) return 'STRETCH';

        return 'UNLIKELY';
    }

    public function withinReach(int $rank, int $closingRank): bool
    {
        return $this->chance($rank, $closingRank) !== 'UNLIKELY';
    }
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter RankPredictorChanceTest`
Expected: PASS. Also run the legacy test to confirm no regression: `php artisan test --filter RankPredictorTest` → PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Services/Rank/RankPredictor.php tests/Unit/Rank/RankPredictorChanceTest.php
git commit -m "feat(rank): add SAFE..UNLIKELY chance scale to RankPredictor"
```

---

## Task 7: `PredictorContext` DTO

**Files:**
- Create: `app/Services/Rank/PredictorContext.php`
- Test: `tests/Unit/Rank/PredictorContextTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Unit\Rank;

use App\Services\Rank\PredictorContext;
use Tests\TestCase;

class PredictorContextTest extends TestCase
{
    /** @test */
    public function holds_prediction_inputs_with_defaults(): void
    {
        $ctx = new PredictorContext(
            datasetToken: 'dtu',
            rank: 45000,
            region: 'delhi',
            category: 'sc',
            subCategory: 'gender_neutral',
            gender: 'male',
        );

        $this->assertSame('dtu', $ctx->datasetToken);
        $this->assertSame(45000, $ctx->rank);
        $this->assertSame('sc', $ctx->category);
        $this->assertFalse($ctx->isGeneral());
        $this->assertTrue((new PredictorContext('ipu', 1000, 'delhi', 'general'))->isGeneral());
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter PredictorContextTest`
Expected: FAIL — class not found.

- [ ] **Step 3: Write the class**

```php
<?php

namespace App\Services\Rank;

class PredictorContext
{
    /**
     * @param  array<int,int>|null  $branchIds
     */
    public function __construct(
        public string $datasetToken,
        public int $rank,
        public string $region = 'delhi',
        public string $category = 'general',
        public ?string $subCategory = null,
        public ?string $gender = null,
        public ?int $courseId = null,
        public ?int $year = null,
        public ?array $branchIds = null,
    ) {}

    public function isGeneral(): bool
    {
        return mb_strtolower(trim($this->category)) === 'general';
    }

    public function isMale(): bool
    {
        return mb_strtolower((string) $this->gender) === 'male';
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter PredictorContextTest`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Services/Rank/PredictorContext.php tests/Unit/Rank/PredictorContextTest.php
git commit -m "feat(rank): PredictorContext DTO"
```

---

## Task 8: Extend `RankRoleSeeder` — four scoped roles + permissions

**Files:**
- Modify: `database/seeders/Rank/RankRoleSeeder.php`
- Test: `tests/Feature/Rank/RankRolesSeederTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Rank;

use Database\Seeders\Rank\RankRoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class RankRolesSeederTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function seeds_four_scoped_roles_and_permissions(): void
    {
        $this->seed(RankRoleSeeder::class);

        foreach (['rank.ipu.predict', 'rank.ipu.analyse', 'rank.dtu.predict', 'rank.dtu.analyse'] as $perm) {
            $this->assertNotNull(Permission::where('name', $perm)->first(), "missing $perm");
        }
        foreach (['rank-ipu-predict', 'rank-ipu-analyse', 'rank-dtu-predict', 'rank-dtu-analyse'] as $role) {
            $this->assertNotNull(Role::where('name', $role)->first(), "missing $role");
        }

        $this->assertTrue(
            Role::where('name', 'rank-dtu-analyse')->first()->hasPermissionTo('rank.dtu.analyse')
        );
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter RankRolesSeederTest`
Expected: FAIL — permissions/roles missing.

- [ ] **Step 3: Rewrite the seeder**

```php
<?php

namespace Database\Seeders\Rank;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class RankRoleSeeder extends Seeder
{
    public function run(): void
    {
        // Legacy permissions kept for back-compat.
        $all = ['rank.view', 'rank.manage'];

        // New scoped permissions: dataset x capability.
        $scoped = [
            'rank.ipu.predict', 'rank.ipu.analyse',
            'rank.dtu.predict', 'rank.dtu.analyse',
        ];

        foreach (array_merge($all, $scoped) as $perm) {
            Permission::firstOrCreate(['name' => $perm, 'guard_name' => 'web']);
        }

        $matrix = [
            'rank-ipu-predict' => ['rank.ipu.predict'],
            'rank-ipu-analyse' => ['rank.ipu.analyse'],
            'rank-dtu-predict' => ['rank.dtu.predict'],
            'rank-dtu-analyse' => ['rank.dtu.analyse'],
        ];
        foreach ($matrix as $roleName => $perms) {
            Role::firstOrCreate(['name' => $roleName, 'guard_name' => 'web'])->givePermissionTo($perms);
        }

        // Back-compat: legacy rank-admin and admin/super_admin get everything.
        $superPerms = array_merge($all, $scoped);
        foreach (['rank-admin', 'admin', 'super_admin'] as $roleName) {
            $role = Role::firstOrCreate(['name' => $roleName, 'guard_name' => 'web']);
            $role->givePermissionTo($superPerms);
        }

        $this->command?->info('Rank roles + scoped permissions seeded.');
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter RankRolesSeederTest`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add database/seeders/Rank/RankRoleSeeder.php tests/Feature/Rank/RankRolesSeederTest.php
git commit -m "feat(rank): seed 4 scoped rank roles (ipu/dtu x predict/analyse)"
```

---

## Task 9: `User` access helpers

**Files:**
- Modify: `app/Models/User.php`
- Test: `tests/Feature/Rank/RankAccessHelpersTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Rank;

use App\Models\User;
use Database\Seeders\Rank\RankRoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RankAccessHelpersTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RankRoleSeeder::class);
    }

    /** @test */
    public function predict_only_ipu_user_can_predict_ipu_not_dtu(): void
    {
        $u = User::factory()->create();
        $u->assignRole('rank-ipu-predict');

        $this->assertTrue($u->canRankPredict('ipu'));
        $this->assertFalse($u->canRankAnalyse('ipu'));
        $this->assertFalse($u->canRankPredict('dtu'));
        $this->assertSame(['ipu'], $u->rankDatasets());
    }

    /** @test */
    public function dtu_analyse_user_sees_only_dtu(): void
    {
        $u = User::factory()->create();
        $u->assignRole('rank-dtu-analyse');

        $this->assertTrue($u->canRankAnalyse('dtu'));
        $this->assertFalse($u->canRankPredict('dtu'));   // analyse role does not grant predict
        $this->assertSame(['dtu'], $u->rankDatasets());
    }

    /** @test */
    public function user_with_both_datasets_lists_both(): void
    {
        $u = User::factory()->create();
        $u->assignRole(['rank-ipu-predict', 'rank-dtu-analyse']);

        $this->assertSame(['ipu', 'dtu'], $u->rankDatasets());
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter RankAccessHelpersTest`
Expected: FAIL — `canRankPredict` undefined.

- [ ] **Step 3: Add helpers to `User`** (add these methods inside the `User` class, e.g. after `isSuperAdmin()`)

```php
    public function canRankPredict(string $dataset): bool
    {
        return $this->hasPermissionTo("rank.{$dataset}.predict");
    }

    public function canRankAnalyse(string $dataset): bool
    {
        return $this->hasPermissionTo("rank.{$dataset}.analyse");
    }

    /** @return array<int,string> datasets visible to this user, in canonical order */
    public function rankDatasets(): array
    {
        return array_values(array_filter(
            \App\Rank\RankDataset::tokens(),
            fn (string $t) => $this->canRankPredict($t) || $this->canRankAnalyse($t),
        ));
    }
```

Note: `hasPermissionTo` throws if the permission does not exist. Since the seeder creates all four, that is safe in-app; tests seed first. If a defensive guard is wanted, wrap in `Permission::where('name',$p)->exists()` — not required here because the seeder is authoritative.

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter RankAccessHelpersTest`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Models/User.php tests/Feature/Rank/RankAccessHelpersTest.php
git commit -m "feat(rank): User rank access helpers (canRankPredict/Analyse, rankDatasets)"
```

---

## Task 10: Refactor `StudentChoicePredictor` to use context + strategy (preserve observer behaviour)

**Files:**
- Modify: `app/Services/Rank/StudentChoicePredictor.php`
- Test: `tests/Feature/Rank/StudentChoicePredictorRoundTest.php`

Goal: the existing `topChoices(Student)` keeps working for the observer/peek drawer, but the round is now chosen by `BenchmarkRoundStrategy` keyed on the student's `reservation_category` (falling back to `general` when null). This wires the new IPU rule (general→sliding, reserved→R3) into the student path without changing the public method shape.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Rank;

use App\Models\Rank\AdmissionProcess;
use App\Models\Rank\Branch;
use App\Models\Rank\Course;
use App\Models\Rank\Cutoff;
use App\Models\Rank\Institute;
use App\Models\Rank\QualifyingExam;
use App\Models\Rank\University;
use App\Models\Student;
use App\Services\Rank\StudentChoicePredictor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StudentChoicePredictorRoundTest extends TestCase
{
    use RefreshDatabase;

    private function seedIpuCell(string $round, int $max): array
    {
        $u = University::create(['name' => 'IPU', 'code' => 'IPU']);
        $c = Course::create(['university_id' => $u->id, 'name' => 'B.Tech']);
        $e = QualifyingExam::firstOrCreate(['code' => 'JEE_MAIN'], ['name' => 'JEE Main']);
        $p = AdmissionProcess::firstOrCreate(['code' => 'COUNSELLING'], ['name' => 'Counselling']);
        $i = Institute::create(['university_id' => $u->id, 'name' => 'USICT']);
        $b = Branch::create(['course_id' => $c->id, 'name' => 'CSE']);
        Cutoff::create([
            'university_id' => $u->id, 'course_id' => $c->id, 'qualifying_exam_id' => $e->id,
            'admission_process_id' => $p->id, 'year' => 2026, 'round' => $round,
            'institute_id' => $i->id, 'branch_id' => $b->id, 'region' => 'delhi',
            'min_rank' => 0, 'max_rank' => $max, 'source' => 'official',
        ]);

        return compact('u', 'c', 'e', 'p', 'i', 'b');
    }

    /** @test */
    public function general_student_is_scored_against_sliding_round(): void
    {
        $this->seedIpuCell('sliding', 120000);
        $student = new Student(['rank' => '100000', 'category' => 'Delhi', 'reservation_category' => 'general']);

        $choices = (new StudentChoicePredictor)->topChoices($student, 3);

        $this->assertNotEmpty($choices);              // sliding cell used → eligible
        $this->assertSame('USICT', $choices[0]['college']);
    }

    /** @test */
    public function reserved_student_is_scored_against_round_3(): void
    {
        $this->seedIpuCell('3', 120000);
        // also seed a sliding cell that would NOT match (too tough) to prove R3 is used
        $student = new Student(['rank' => '100000', 'category' => 'Delhi', 'reservation_category' => 'sc']);

        $choices = (new StudentChoicePredictor)->topChoices($student, 3);

        $this->assertNotEmpty($choices);              // round-3 cell used
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter StudentChoicePredictorRoundTest`
Expected: FAIL — current code keys the round off region (`delhi`→sliding) and ignores `reservation_category`, so the reserved/R3 case finds no sliding cell and returns empty.

- [ ] **Step 3: Update `topChoices`** — replace the round-selection block (lines 25-26) and the per-row eligibility (around lines 62-67) to use the strategy + chance scale.

Replace lines 25-27:

```php
        $category = mb_strtolower(trim((string) ($student->reservation_category ?? 'general'))) ?: 'general';
        $region = $this->mapRegion($student->category);
        $predictionRegion = 'delhi'; // delhi cutoffs are the predictor signal (unchanged convention)
        $strategy = new BenchmarkRoundStrategy;
```

Replace the eligibility loop (lines 62-78) with strategy-driven round selection:

```php
        $eligible = [];
        foreach ($byKey as $row) {
            $present = array_keys(array_filter($row['rounds'], fn ($c) => $c !== null));
            $round = $strategy->pick('ipu', $category, $present);
            $cell = $round ? $row['rounds'][$round] : null;
            if (! $cell || $rank > $cell['max']) {
                continue; // not reachable: ranked worse than the closing rank
            }
            $cushion = $this->predictor->cushionPct($rank, $cell['max']);
            $eligible[] = [
                'institute'        => $row['institute'],
                'branch'           => $row['branch'],
                'prediction_max'   => $cell['max'],
                'cushion_pct'      => $cushion,
                'bucket'           => $this->predictor->bucket($rank, $cell['max']),
                'priority'         => CollegePreferenceOrder::sortKey($row['institute']),
                'probability_pct'  => $this->probabilityFromCushion(max(0, $cushion)),
            ];
        }
```

Add the import at the top of the file (with the other `use` statements):

```php
use App\Services\Rank\BenchmarkRoundStrategy;
```

Note: this also fixes the "hidden safe options" bug — strong students (rank far below max) are no longer excluded; only `rank > max` (genuinely out of reach) is dropped.

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter StudentChoicePredictorRoundTest`
Expected: PASS. Then run the existing observer + lookup tests to check for regressions:
Run: `php artisan test --filter "StudentRankProbabilityObserverTest|RankLookupTest"`
Expected: PASS (if `RankLookupTest`'s fixture relies on the old "cushion ≤ 50 / rank ≥ min" filtering and now sees more colleges, update its expected counts as part of this task — adjust the asserted `visible_count`/names to match the new reachable set, keeping the demand-order assertions).

- [ ] **Step 5: Commit**

```bash
git add app/Services/Rank/StudentChoicePredictor.php tests/Feature/Rank/StudentChoicePredictorRoundTest.php tests/Feature/Rank/RankLookupTest.php
git commit -m "feat(rank): category-aware benchmark round + show safe options in student predictor"
```

---

## Task 11: JAC Delhi seeder (university / course / institutes / process)

**Files:**
- Create: `database/seeders/Rank/JacDelhiSeeder.php`
- Test: `tests/Feature/Rank/JacDelhiSeederTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Rank;

use App\Models\Rank\AdmissionProcess;
use App\Models\Rank\Course;
use App\Models\Rank\Institute;
use App\Models\Rank\University;
use Database\Seeders\Rank\JacDelhiSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class JacDelhiSeederTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function seeds_jac_university_btech_and_five_institutes(): void
    {
        $this->seed(JacDelhiSeeder::class);

        $jac = University::where('code', 'JAC')->first();
        $this->assertNotNull($jac);
        $this->assertNotNull(Course::where('university_id', $jac->id)->where('name', 'B.Tech')->first());
        $this->assertNotNull(AdmissionProcess::where('code', 'JAC')->first());

        $names = Institute::where('university_id', $jac->id)->pluck('name')->all();
        foreach (['DTU', 'NSUT Main (Dwarka)', 'NSUT East Campus', 'NSUT West Campus', 'IGDTUW'] as $n) {
            $this->assertContains($n, $names, "missing institute $n");
        }
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter JacDelhiSeederTest`
Expected: FAIL — class not found.

- [ ] **Step 3: Write the seeder**

```php
<?php

namespace Database\Seeders\Rank;

use App\Models\Rank\AdmissionProcess;
use App\Models\Rank\Course;
use App\Models\Rank\Institute;
use App\Models\Rank\QualifyingExam;
use App\Models\Rank\University;
use Illuminate\Database\Seeder;

class JacDelhiSeeder extends Seeder
{
    public function run(): void
    {
        $jac = University::firstOrCreate(
            ['code' => 'JAC'],
            ['name' => 'JAC Delhi', 'country' => 'India', 'state' => 'Delhi']
        );

        Course::firstOrCreate(['university_id' => $jac->id, 'name' => 'B.Tech'], ['code' => 'BTECH']);
        QualifyingExam::firstOrCreate(['code' => 'JEE_MAIN'], ['name' => 'JEE Main']);
        AdmissionProcess::firstOrCreate(['code' => 'JAC'], ['name' => 'JAC Delhi Counselling']);

        foreach ([
            'DTU' => 'New Delhi',
            'NSUT Main (Dwarka)' => 'New Delhi',
            'NSUT East Campus' => 'New Delhi',
            'NSUT West Campus' => 'New Delhi',
            'IGDTUW' => 'New Delhi',
        ] as $name => $city) {
            Institute::firstOrCreate(['university_id' => $jac->id, 'name' => $name], ['city' => $city]);
        }

        $this->command?->info('JAC Delhi university, B.Tech, process + institutes seeded.');
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter JacDelhiSeederTest`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add database/seeders/Rank/JacDelhiSeeder.php tests/Feature/Rank/JacDelhiSeederTest.php
git commit -m "feat(rank): JAC Delhi seeder (university, B.Tech, 5 institutes, JAC process)"
```

---

## Task 12: `JacCutoffImporter` — load the parsed CSV into `cutoffs`

**Files:**
- Create: `app/Services/Rank/JacCutoffImporter.php`
- Test: `tests/Feature/Rank/JacCutoffImporterTest.php`

CSV columns (from the offline parser): `institute,year,round,round_label,region,branch,category,sub_category,closing_rank,source_file`. The importer maps:
- `institute` → one of the 5 JAC institutes (NSUT branch rows already carry the campus via a mapping table inside the importer; see `INSTITUTE_MAP`).
- `category` (e.g. `General`) → lowercased token; `sub_category` (`Gender-Neutral`) → snake token.
- `region` (`Delhi`/`Outside-Delhi`) → `delhi`/`outside_delhi`.
- `round` (`R5`) → `5`. `closing_rank` → `max_rank`, `min_rank` = 0.
- Skips IIITD and any `arch` branch (defensive; the CSV already excludes them).

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Rank;

use App\Models\Rank\Cutoff;
use App\Models\Rank\Institute;
use App\Models\Rank\University;
use App\Services\Rank\JacCutoffImporter;
use Database\Seeders\Rank\JacDelhiSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class JacCutoffImporterTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function imports_rows_mapping_campus_category_region_and_round(): void
    {
        $this->seed(JacDelhiSeeder::class);

        $csv = implode("\n", [
            'institute,year,round,round_label,region,branch,category,sub_category,closing_rank,source_file',
            'DTU,2025,R5,Round5 2025,Delhi,Computer Science and Engineering,General,Gender-Neutral,11352,DTU_Round5_2025.pdf',
            'NSUT,2025,R5,Round5 2025,Outside-Delhi,Civil Engineering,SC,Girl,200000,NSUT_Round5_2025.pdf',
            'IIITD,2025,R5,Round5 2025,Delhi,CSE,General,Gender-Neutral,9999,IIITD_Round5_2025.pdf',
        ]);
        $path = tempnam(sys_get_temp_dir(), 'jac').'.csv';
        file_put_contents($path, $csv);

        $summary = (new JacCutoffImporter)->import($path, 2025);

        $this->assertSame(2, $summary['imported']);   // IIITD skipped
        $this->assertSame(1, $summary['skipped']);

        $dtu = Cutoff::whereHas('institute', fn ($q) => $q->where('name', 'DTU'))->first();
        $this->assertSame('5', $dtu->round);
        $this->assertSame('delhi', $dtu->region);
        $this->assertSame('general', $dtu->category);
        $this->assertSame('gender_neutral', $dtu->sub_category);
        $this->assertSame(11352, $dtu->max_rank);
        $this->assertSame(0, $dtu->min_rank);

        // NSUT campus mapping: a generic "NSUT" row lands on NSUT Main (Dwarka) by default
        $this->assertNotNull(
            Cutoff::whereHas('institute', fn ($q) => $q->where('name', 'NSUT Main (Dwarka)'))->first()
        );

        unlink($path);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter JacCutoffImporterTest`
Expected: FAIL — class not found.

- [ ] **Step 3: Write the importer**

```php
<?php

namespace App\Services\Rank;

use App\Models\Rank\AdmissionProcess;
use App\Models\Rank\Branch;
use App\Models\Rank\Course;
use App\Models\Rank\Cutoff;
use App\Models\Rank\Institute;
use App\Models\Rank\QualifyingExam;
use App\Models\Rank\University;
use RuntimeException;

class JacCutoffImporter
{
    private const CATEGORY = [
        'general' => 'general', 'ews' => 'ews', 'obc' => 'obc', 'sc' => 'sc', 'st' => 'st',
    ];

    private const SUBCATEGORY = [
        'gender-neutral' => 'gender_neutral', 'girl' => 'girl', 'single-girl' => 'single_girl',
        'pwd' => 'pwd', 'defense-cw' => 'defense_cw', 'kashmiri-migrant' => 'kashmiri_migrant',
    ];

    /**
     * Map a CSV "institute" cell to the seeded institute name.
     * NSUT campus is encoded in the branch name in the source; the parser already
     * split campuses into the institute column for East/West, but a bare "NSUT"
     * defaults to Main (Dwarka).
     */
    private function instituteName(string $raw): string
    {
        $r = trim($raw);

        return match (true) {
            str_contains(strtolower($r), 'east')  => 'NSUT East Campus',
            str_contains(strtolower($r), 'west')  => 'NSUT West Campus',
            strtolower($r) === 'nsut'              => 'NSUT Main (Dwarka)',
            default                                 => $r,   // DTU, IGDTUW, NSUT Main (Dwarka)
        };
    }

    /**
     * @return array{imported:int, skipped:int}
     */
    public function import(string $path, int $year): array
    {
        if (! is_readable($path)) {
            throw new RuntimeException("CSV not readable: {$path}");
        }

        $jac = University::where('code', 'JAC')->firstOrFail();
        $course = Course::where('university_id', $jac->id)->where('name', 'B.Tech')->firstOrFail();
        $exam = QualifyingExam::where('code', 'JEE_MAIN')->firstOrFail();
        $process = AdmissionProcess::where('code', 'JAC')->firstOrFail();

        $institutes = Institute::where('university_id', $jac->id)->get()->keyBy('name');

        $fh = fopen($path, 'r');
        $header = fgetcsv($fh);
        $idx = array_flip($header);

        $imported = 0;
        $skipped = 0;

        while (($row = fgetcsv($fh)) !== false) {
            $instRaw = $row[$idx['institute']] ?? '';
            $branchName = trim($row[$idx['branch']] ?? '');

            // Defensive skips (CSV already excludes these, but be safe).
            if (strtoupper($instRaw) === 'IIITD' || stripos($branchName, 'arch') !== false) {
                $skipped++;
                continue;
            }

            $instName = $this->instituteName($instRaw);
            $institute = $institutes->get($instName);
            if (! $institute) {
                $institute = Institute::create(['university_id' => $jac->id, 'name' => $instName]);
                $institutes->put($instName, $institute);
            }

            $branch = Branch::firstOrCreate(['course_id' => $course->id, 'name' => $branchName]);

            $round = ltrim((string) ($row[$idx['round']] ?? ''), 'Rr'); // "R5" -> "5"
            $region = str_contains(strtolower($row[$idx['region']] ?? ''), 'outside')
                ? 'outside_delhi' : 'delhi';
            $category = self::CATEGORY[strtolower(trim($row[$idx['category']] ?? ''))] ?? null;
            $sub = self::SUBCATEGORY[strtolower(trim($row[$idx['sub_category']] ?? ''))] ?? null;
            $closing = (int) ($row[$idx['closing_rank']] ?? 0);
            if ($closing <= 0 || ! in_array($round, ['1', '2', '3', '4', '5'], true)) {
                $skipped++;
                continue;
            }

            Cutoff::updateOrCreate(
                [
                    'university_id' => $jac->id, 'course_id' => $course->id,
                    'qualifying_exam_id' => $exam->id, 'admission_process_id' => $process->id,
                    'year' => $year, 'round' => $round,
                    'institute_id' => $institute->id, 'branch_id' => $branch->id,
                    'shift' => null, 'region' => $region,
                    'category' => $category, 'sub_category' => $sub,
                ],
                ['min_rank' => 0, 'max_rank' => $closing, 'source' => 'official']
            );
            $imported++;
        }
        fclose($fh);

        return ['imported' => $imported, 'skipped' => $skipped];
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter JacCutoffImporterTest`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Services/Rank/JacCutoffImporter.php tests/Feature/Rank/JacCutoffImporterTest.php
git commit -m "feat(rank): JacCutoffImporter (CSV -> cutoffs, campus/category/region mapping)"
```

---

## Task 13: `rank:import-jac` artisan command

**Files:**
- Create: `app/Console/Commands/Rank/ImportJacCutoffs.php`
- Test: `tests/Feature/Rank/ImportJacCommandTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Rank;

use App\Models\Rank\Cutoff;
use Database\Seeders\Rank\JacDelhiSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ImportJacCommandTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function command_imports_csv(): void
    {
        $this->seed(JacDelhiSeeder::class);
        $csv = "institute,year,round,round_label,region,branch,category,sub_category,closing_rank,source_file\n"
            ."DTU,2025,R5,x,Delhi,Computer Science and Engineering,General,Gender-Neutral,11352,f.pdf\n";
        $path = tempnam(sys_get_temp_dir(), 'jac').'.csv';
        file_put_contents($path, $csv);

        $this->artisan("rank:import-jac --file={$path} --year=2025")
            ->expectsOutputToContain('Imported')
            ->assertExitCode(0);

        $this->assertSame(1, Cutoff::count());
        unlink($path);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter ImportJacCommandTest`
Expected: FAIL — command not found.

- [ ] **Step 3: Write the command**

```php
<?php

namespace App\Console\Commands\Rank;

use App\Services\Rank\JacCutoffImporter;
use Illuminate\Console\Command;

class ImportJacCutoffs extends Command
{
    protected $signature = 'rank:import-jac {--file= : Path to the parsed JAC cutoffs CSV} {--year= : Admission year}';

    protected $description = 'Import JAC Delhi (DTU/NSUT/IGDTUW) cutoffs from a parsed CSV into the rank cutoffs table.';

    public function handle(JacCutoffImporter $importer): int
    {
        $file = (string) $this->option('file');
        $year = (int) $this->option('year');
        if ($file === '' || $year === 0) {
            $this->error('Both --file and --year are required.');

            return self::FAILURE;
        }

        $summary = $importer->import($file, $year);
        $this->info("Imported {$summary['imported']} cutoffs, skipped {$summary['skipped']}.");

        return self::SUCCESS;
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter ImportJacCommandTest`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Console/Commands/Rank/ImportJacCutoffs.php tests/Feature/Rank/ImportJacCommandTest.php
git commit -m "feat(rank): rank:import-jac command"
```

---

## Task 14: Load the real 2025 dataset + verify (manual data step)

**Files:**
- Modify: `database/seeders/DatabaseSeeder.php` (register `JacDelhiSeeder` + ensure `RankRoleSeeder` runs) — only if not already chained.
- Data: copy `/Users/Sumit/jacdelhi_orcr_2025/jacdelhi_orcr_cutoffs.csv` into the repo at `storage/app/rank/jacdelhi_orcr_cutoffs_2025.csv`.

- [ ] **Step 1: Register seeders** — in `database/seeders/DatabaseSeeder.php`, ensure these run (add to the `call([...])` list if absent):

```php
        $this->call([
            \Database\Seeders\Rank\RankRoleSeeder::class,
            \Database\Seeders\Rank\JacDelhiSeeder::class,
        ]);
```

- [ ] **Step 2: Seed roles + JAC reference data (local)**

Run:
```bash
php artisan db:seed --class=Database\\Seeders\\Rank\\RankRoleSeeder
php artisan db:seed --class=Database\\Seeders\\Rank\\JacDelhiSeeder
```
Expected: "Rank roles + scoped permissions seeded." and "JAC Delhi … seeded."

- [ ] **Step 3: Copy + import the dataset (local)**

Run:
```bash
mkdir -p storage/app/rank
cp /Users/Sumit/jacdelhi_orcr_2025/jacdelhi_orcr_cutoffs.csv storage/app/rank/jacdelhi_orcr_cutoffs_2025.csv
php artisan rank:import-jac --file=storage/app/rank/jacdelhi_orcr_cutoffs_2025.csv --year=2025
```
Expected: "Imported NNNN cutoffs, skipped MM." (NNNN in the low thousands; IIITD rows skipped).

- [ ] **Step 4: Verify counts**

Run (Tinker one-liner):
```bash
php artisan tinker --execute="use App\Models\Rank\Cutoff; use App\Models\Rank\University; \$j=University::where('code','JAC')->first(); echo Cutoff::where('university_id',\$j->id)->count().' JAC cutoffs; rounds: '.Cutoff::where('university_id',\$j->id)->distinct()->pluck('round')->implode(',').'; cats: '.Cutoff::where('university_id',\$j->id)->distinct()->pluck('category')->implode(',');"
```
Expected: a few thousand JAC cutoffs; rounds include `1..5`; categories include `general,ews,obc,sc,st`.

- [ ] **Step 5: Commit**

```bash
git add database/seeders/DatabaseSeeder.php storage/app/rank/jacdelhi_orcr_cutoffs_2025.csv
git commit -m "chore(rank): register JAC seeders + add 2025 cutoff dataset"
```

---

## Self-Review Notes (author)

- **Spec coverage:** §5.1 cutoffs category/sub_category → Task 1; §5.3 students fields → Task 2; §3 dataset map + access helpers → Tasks 4, 9; §6.2 chance scale → Task 6; §6.2a benchmark round → Tasks 5, 10; §3/§8 roles → Task 8; §5.4 JAC seed → Task 11; §7 importer + command + load → Tasks 12-14. **Deferred to Plan 2:** predictor UI pages, IPU course selector, GeminiCounsellor parameterization, RankLanding role-gating, resource query-scoping, trend analytics, print/PDF.
- **Round enum:** widened to include `4`,`5` (Task 1) — required before JAC import (Task 12).
- **Back-compat:** legacy `bucket`/`isEligible` kept; `rank-admin`/`admin`/`super_admin` granted all scoped perms (Task 8); observer path preserved (Task 10) with the bug-fix that safe options are no longer hidden — `RankLookupTest` expectations updated in the same task.
- **Type consistency:** `chance()`, `withinReach()`, `pick()`, `RankDataset::universityCodes/label/courseFixedToBtech/tokens`, `canRankPredict/canRankAnalyse/rankDatasets`, `JacCutoffImporter::import(): {imported,skipped}` are referenced consistently across tasks.

## Plan 2 preview (not part of this plan)

Predictor Livewire page (shared, dataset-configured) with gender→category→sub-category→region dropdowns, chance chips, NSUT campus column, "within reach only" toggle, print/PDF; IPU course selector; `GeminiCounsellor` parameterized; `RankAccess` + role-gated `RankLanding` (IPU/DTU × Predict/Analyse cards); Filament resource query-scoping per dataset; `RankTrends` analytics page with CSV export.
