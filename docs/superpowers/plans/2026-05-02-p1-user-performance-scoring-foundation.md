# User Performance Scoring P1 — Foundation Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Lay the data + observer + backfill foundation for user performance scoring. After P1, the database has the cached `students.rank_prob_first_choice` column populated for all 533 existing students, the `user_performance_scores` table exists, and the `UserPerformanceScore` model + factory + config + observer are all wired and tested. P2 (the scorer service) and later phases plug into this foundation.

**Architecture:** Two migrations (column + table) + one Eloquent model + one observer + one backfill command + one config file. All changes are additive — no existing tables, models, or observers are modified beyond a single `->observe(...)` registration line. Tests use PHPUnit class style (matching the repo convention) with `RefreshDatabase`.

**Tech Stack:** Laravel 11, PHP 8.4, MySQL 8, Filament 3 (no Filament UI in P1), Pest 3 runner over PHPUnit-style classes, existing `StudentChoicePredictor` service.

**Spec reference:** `docs/superpowers/specs/2026-05-02-user-performance-scoring-design.md` §3.3, §4, §5.2 (signal #3), §8.

**Definition of done:**
- `students.rank_prob_first_choice` column exists, nullable TINYINT 0-100
- `user_performance_scores` table exists with composite unique on `(user_id, period_start)`
- `App\Models\UserPerformanceScore` model + factory exist and work end-to-end
- `App\Observers\StudentRankProbabilityObserver` fires on create/update of `rank | category | preference_r1` and writes the cached probability via `StudentChoicePredictor::topChoices($student, 1)[0]['probability_pct']`
- Observer is registered in `app/Providers/AppServiceProvider.php`
- `php artisan performance:backfill-rank-probabilities` populates the column for every student with a non-null `rank`
- `config/performance.php` exists with all weights, tiers, terminal-stages, sub-formula constants
- All new tests green; existing tests still green; `php artisan migrate:fresh --seed` runs cleanly; rollback also works

---

## File Structure

**New files:**

```
app/
  Models/
    UserPerformanceScore.php                                 -- Eloquent model
  Observers/
    StudentRankProbabilityObserver.php                       -- maintains rank_prob_first_choice cache
  Console/
    Commands/
      BackfillRankProbabilityCommand.php                     -- one-time backfill for existing students

database/
  migrations/
    2026_05_02_120000_add_rank_prob_first_choice_to_students.php
    2026_05_02_120100_create_user_performance_scores_table.php
  factories/
    UserPerformanceScoreFactory.php

config/
  performance.php                                            -- weights, tiers, terminal stages, sub-formula constants

tests/
  Feature/
    Performance/
      RankProbColumnMigrationTest.php
      UserPerformanceScoresTableMigrationTest.php
      UserPerformanceScoreModelTest.php
      StudentRankProbabilityObserverTest.php
      BackfillRankProbabilityCommandTest.php
      PerformanceConfigTest.php
```

**Modified files:**

```
app/Providers/AppServiceProvider.php                         -- register observer (one line in boot())
```

**No other files modified.** No service registration, no routes, no Filament resources — those land in later phases.

---

## Task 1 — Add `config/performance.php`

**Files:**
- Create: `config/performance.php`
- Test: `tests/Feature/Performance/PerformanceConfigTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php
// tests/Feature/Performance/PerformanceConfigTest.php

namespace Tests\Feature\Performance;

use Tests\TestCase;

class PerformanceConfigTest extends TestCase
{
    public function test_config_file_exposes_terminal_stages(): void
    {
        $stages = config('performance.terminal_stages');
        $this->assertEquals(['Admission Confirmed', 'Closed'], $stages);
    }

    public function test_weights_sum_to_one(): void
    {
        $weights = config('performance.weights');
        $sum = array_sum($weights);
        $this->assertEqualsWithDelta(1.0, $sum, 0.0001, 'Weights must sum to 1.0; got '.$sum);
    }

    public function test_tier_cutoffs_are_descending(): void
    {
        $tiers = config('performance.tiers');
        $mins = array_column($tiers, 'min');
        $sorted = $mins;
        rsort($sorted);
        $this->assertEquals($sorted, $mins, 'Tiers must be ordered highest→lowest cutoff');
    }

    public function test_min_sample_floor_and_stale_threshold_present(): void
    {
        $this->assertSame(3, config('performance.min_sample_floor'));
        $this->assertSame(60, config('performance.stale_threshold_days'));
    }

    public function test_pipeline_health_constants_present(): void
    {
        $ph = config('performance.pipeline_health');
        $this->assertSame(30, $ph['balance_penalty_factor']);
        $this->assertSame(50, $ph['balance_penalty_cap']);
        $this->assertSame(5,  $ph['stale_penalty_per_lead']);
        $this->assertSame(20, $ph['stale_penalty_cap']);
        $this->assertSame(1,  $ph['open_bonus_per_two']);
        $this->assertSame(10, $ph['open_bonus_cap']);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=PerformanceConfigTest`
Expected: 5 failures, all "config('performance.*') returned null".

- [ ] **Step 3: Create config file**

```php
<?php
// config/performance.php

return [
    'terminal_stages' => ['Admission Confirmed', 'Closed'],

    'tiers' => [
        ['min' => 85, 'label' => 'Star'],
        ['min' => 70, 'label' => 'Strong'],
        ['min' => 55, 'label' => 'Solid'],
        ['min' => 40, 'label' => 'Growth'],
        ['min' =>  0, 'label' => 'Coaching'],
    ],

    'weights' => [
        'closed_won'        => 0.25,
        'deal_won_amount'   => 0.25,
        'rank_prob_avg'     => 0.15,
        'advance_received'  => 0.10,
        'conversion_rate'   => 0.10,
        'meeting_win_rate'  => 0.05,
        'pipeline_health'   => 0.10,
    ],

    'pipeline_health' => [
        'balance_penalty_factor' => 30,
        'balance_penalty_cap'    => 50,
        'stale_penalty_per_lead' => 5,
        'stale_penalty_cap'      => 20,
        'open_bonus_per_two'     => 1,
        'open_bonus_cap'         => 10,
    ],

    'min_sample_floor' => 3,

    'stale_threshold_days' => 60,
];
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter=PerformanceConfigTest`
Expected: 5 passing.

- [ ] **Step 5: Commit**

```bash
git add config/performance.php tests/Feature/Performance/PerformanceConfigTest.php
git commit -m "feat(performance): add performance config with weights + tiers + sub-formula constants"
```

---

## Task 2 — Migration: `students.rank_prob_first_choice` column

**Files:**
- Create: `database/migrations/2026_05_02_120000_add_rank_prob_first_choice_to_students.php`
- Test: `tests/Feature/Performance/RankProbColumnMigrationTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php
// tests/Feature/Performance/RankProbColumnMigrationTest.php

namespace Tests\Feature\Performance;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class RankProbColumnMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_column_exists_and_is_nullable_tinyint(): void
    {
        $this->assertTrue(Schema::hasColumn('students', 'rank_prob_first_choice'));

        $type = Schema::getColumnType('students', 'rank_prob_first_choice');
        $this->assertSame('tinyint', $type);
    }

    public function test_column_round_trips_values_0_to_100(): void
    {
        $id = DB::table('students')->insertGetId([
            'name' => 'Probe Student',
            'phone' => '9000000001',
            'stage' => 'Lead Captured',
            'lead_source' => 'Test',
            'owner_id' => 1,
            'referrer_id' => 1,
            'rank_prob_first_choice' => 87,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $row = DB::table('students')->where('id', $id)->first();
        $this->assertSame(87, (int) $row->rank_prob_first_choice);
    }

    public function test_column_accepts_null(): void
    {
        $id = DB::table('students')->insertGetId([
            'name' => 'Null Probe',
            'phone' => '9000000002',
            'stage' => 'Lead Captured',
            'lead_source' => 'Test',
            'owner_id' => 1,
            'referrer_id' => 1,
            'rank_prob_first_choice' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $row = DB::table('students')->where('id', $id)->first();
        $this->assertNull($row->rank_prob_first_choice);
    }
}
```

Note: `owner_id` and `referrer_id` reference `users` — the test uses `id=1` because `RefreshDatabase` + the existing `DatabaseSeeder` should create user 1 via the demo seeder if `$this->seed()` is called. If the test's setup doesn't call `$this->seed()`, add `$this->seed();` at the start of each test method, OR insert a minimal user via `DB::table('users')->insertGetId([...])` at the top of the test.

Verify the seeder's first user exists by running `php artisan tinker --execute="echo \App\Models\User::first()?->id;"` after a fresh migrate+seed before writing the test.

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=RankProbColumnMigrationTest`
Expected: All 3 fail with "Unknown column 'rank_prob_first_choice'".

- [ ] **Step 3: Write the migration**

```php
<?php
// database/migrations/2026_05_02_120000_add_rank_prob_first_choice_to_students.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('students', function (Blueprint $table) {
            $table->unsignedTinyInteger('rank_prob_first_choice')
                ->nullable()
                ->after('rank');
        });
    }

    public function down(): void
    {
        Schema::table('students', function (Blueprint $table) {
            $table->dropColumn('rank_prob_first_choice');
        });
    }
};
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter=RankProbColumnMigrationTest`
Expected: 3 passing.

- [ ] **Step 5: Verify rollback works**

Run: `php artisan migrate:rollback --step=1 && php artisan migrate`
Expected: rollback removes the column, re-migrate adds it back, no errors.

- [ ] **Step 6: Commit**

```bash
git add database/migrations/2026_05_02_120000_add_rank_prob_first_choice_to_students.php tests/Feature/Performance/RankProbColumnMigrationTest.php
git commit -m "feat(performance): add rank_prob_first_choice column to students"
```

---

## Task 3 — Migration: `user_performance_scores` table

**Files:**
- Create: `database/migrations/2026_05_02_120100_create_user_performance_scores_table.php`
- Test: `tests/Feature/Performance/UserPerformanceScoresTableMigrationTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php
// tests/Feature/Performance/UserPerformanceScoresTableMigrationTest.php

namespace Tests\Feature\Performance;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class UserPerformanceScoresTableMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_table_and_columns_exist(): void
    {
        $this->assertTrue(Schema::hasTable('user_performance_scores'));

        foreach ([
            'id', 'user_id', 'period_start', 'period_end',
            'score', 'tier', 'signal_breakdown', 'team_max_snapshot',
            'calculated_at', 'created_at', 'updated_at',
        ] as $col) {
            $this->assertTrue(
                Schema::hasColumn('user_performance_scores', $col),
                "Column $col missing"
            );
        }
    }

    public function test_unique_constraint_on_user_and_period_start(): void
    {
        $this->seed();
        $userId = DB::table('users')->value('id');

        DB::table('user_performance_scores')->insert([
            'user_id' => $userId,
            'period_start' => '2026-05-01',
            'period_end' => '2026-05-31',
            'score' => 70,
            'tier' => 'Strong',
            'signal_breakdown' => json_encode(['closed_won' => 5]),
            'team_max_snapshot' => json_encode(['closed_won' => 10]),
            'calculated_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->expectException(QueryException::class);

        DB::table('user_performance_scores')->insert([
            'user_id' => $userId,
            'period_start' => '2026-05-01',
            'period_end' => '2026-05-31',
            'score' => 80,
            'tier' => 'Strong',
            'signal_breakdown' => json_encode(['closed_won' => 6]),
            'team_max_snapshot' => json_encode(['closed_won' => 10]),
            'calculated_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_cascade_on_user_delete(): void
    {
        $this->seed();
        $userId = DB::table('users')->insertGetId([
            'name' => 'Doomed User',
            'email' => 'doomed@test.local',
            'password' => bcrypt('password'),
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('user_performance_scores')->insert([
            'user_id' => $userId,
            'period_start' => '2026-05-01',
            'period_end' => '2026-05-31',
            'score' => 70, 'tier' => 'Strong',
            'signal_breakdown' => json_encode([]),
            'team_max_snapshot' => json_encode([]),
            'calculated_at' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        DB::table('users')->where('id', $userId)->delete();

        $this->assertSame(
            0,
            DB::table('user_performance_scores')->where('user_id', $userId)->count()
        );
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=UserPerformanceScoresTableMigrationTest`
Expected: All 3 fail with "Base table or view not found: user_performance_scores".

- [ ] **Step 3: Write the migration**

```php
<?php
// database/migrations/2026_05_02_120100_create_user_performance_scores_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('user_performance_scores', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->date('period_start');
            $table->date('period_end');
            $table->unsignedTinyInteger('score');
            $table->string('tier', 20);
            $table->json('signal_breakdown');
            $table->json('team_max_snapshot');
            $table->timestamp('calculated_at');
            $table->timestamps();

            $table->unique(['user_id', 'period_start']);
            $table->index(['period_start', 'score']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_performance_scores');
    }
};
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter=UserPerformanceScoresTableMigrationTest`
Expected: 3 passing.

- [ ] **Step 5: Commit**

```bash
git add database/migrations/2026_05_02_120100_create_user_performance_scores_table.php tests/Feature/Performance/UserPerformanceScoresTableMigrationTest.php
git commit -m "feat(performance): create user_performance_scores table"
```

---

## Task 4 — `UserPerformanceScore` model + factory

**Files:**
- Create: `app/Models/UserPerformanceScore.php`
- Create: `database/factories/UserPerformanceScoreFactory.php`
- Test: `tests/Feature/Performance/UserPerformanceScoreModelTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php
// tests/Feature/Performance/UserPerformanceScoreModelTest.php

namespace Tests\Feature\Performance;

use App\Models\User;
use App\Models\UserPerformanceScore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserPerformanceScoreModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_factory_creates_valid_record(): void
    {
        $score = UserPerformanceScore::factory()->create();

        $this->assertNotNull($score->id);
        $this->assertNotNull($score->user_id);
        $this->assertGreaterThanOrEqual(0, $score->score);
        $this->assertLessThanOrEqual(100, $score->score);
        $this->assertContains($score->tier, ['Star','Strong','Solid','Growth','Coaching']);
    }

    public function test_signal_breakdown_and_team_max_snapshot_are_arrays(): void
    {
        $score = UserPerformanceScore::factory()->create([
            'signal_breakdown' => ['closed_won' => 5, 'deal_won_amount' => 100000],
            'team_max_snapshot' => ['closed_won' => 10, 'deal_won_amount' => 200000],
        ]);

        $score->refresh();

        $this->assertIsArray($score->signal_breakdown);
        $this->assertSame(5, $score->signal_breakdown['closed_won']);
        $this->assertIsArray($score->team_max_snapshot);
        $this->assertSame(10, $score->team_max_snapshot['closed_won']);
    }

    public function test_period_start_and_period_end_cast_to_date(): void
    {
        $score = UserPerformanceScore::factory()->create([
            'period_start' => '2026-05-01',
            'period_end' => '2026-05-31',
        ]);

        $this->assertSame('2026-05-01', $score->period_start->format('Y-m-d'));
        $this->assertSame('2026-05-31', $score->period_end->format('Y-m-d'));
    }

    public function test_belongs_to_user_relation(): void
    {
        $user = User::factory()->create();
        $score = UserPerformanceScore::factory()->create(['user_id' => $user->id]);

        $this->assertInstanceOf(User::class, $score->user);
        $this->assertSame($user->id, $score->user->id);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=UserPerformanceScoreModelTest`
Expected: 4 failures, all "Class App\Models\UserPerformanceScore not found".

- [ ] **Step 3: Write the model**

```php
<?php
// app/Models/UserPerformanceScore.php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserPerformanceScore extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'period_start',
        'period_end',
        'score',
        'tier',
        'signal_breakdown',
        'team_max_snapshot',
        'calculated_at',
    ];

    protected $casts = [
        'period_start'     => 'date',
        'period_end'       => 'date',
        'score'            => 'integer',
        'signal_breakdown' => 'array',
        'team_max_snapshot'=> 'array',
        'calculated_at'    => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
```

- [ ] **Step 4: Write the factory**

```php
<?php
// database/factories/UserPerformanceScoreFactory.php

namespace Database\Factories;

use App\Models\User;
use App\Models\UserPerformanceScore;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<UserPerformanceScore>
 */
class UserPerformanceScoreFactory extends Factory
{
    protected $model = UserPerformanceScore::class;

    public function definition(): array
    {
        $start = now()->startOfMonth()->toDateString();
        $end   = now()->endOfMonth()->toDateString();
        $score = $this->faker->numberBetween(0, 100);

        return [
            'user_id'           => User::factory(),
            'period_start'      => $start,
            'period_end'        => $end,
            'score'             => $score,
            'tier'              => $this->tierFor($score),
            'signal_breakdown'  => [
                'closed_won' => 0, 'deal_won_amount' => 0, 'rank_prob_avg' => 0,
                'advance_received' => 0, 'cases_captured' => 0, 'meetings_held' => 0,
                'open_leads' => 0, 'balance_amount' => 0, 'stale_open' => 0,
                'conversion_rate' => 0, 'meeting_win_rate' => 0,
                'sub_scores' => [],
            ],
            'team_max_snapshot' => [
                'closed_won' => 0, 'deal_won_amount' => 0,
                'advance_received' => 0,
            ],
            'calculated_at'     => now(),
        ];
    }

    private function tierFor(int $score): string
    {
        return match (true) {
            $score >= 85 => 'Star',
            $score >= 70 => 'Strong',
            $score >= 55 => 'Solid',
            $score >= 40 => 'Growth',
            default      => 'Coaching',
        };
    }
}
```

- [ ] **Step 5: Run test to verify it passes**

Run: `php artisan test --filter=UserPerformanceScoreModelTest`
Expected: 4 passing.

- [ ] **Step 6: Commit**

```bash
git add app/Models/UserPerformanceScore.php database/factories/UserPerformanceScoreFactory.php tests/Feature/Performance/UserPerformanceScoreModelTest.php
git commit -m "feat(performance): add UserPerformanceScore model + factory"
```

---

## Task 5 — `StudentRankProbabilityObserver`

**Files:**
- Create: `app/Observers/StudentRankProbabilityObserver.php`
- Modify: `app/Providers/AppServiceProvider.php` (one new line in `boot()`)
- Test: `tests/Feature/Performance/StudentRankProbabilityObserverTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php
// tests/Feature/Performance/StudentRankProbabilityObserverTest.php

namespace Tests\Feature\Performance;

use App\Models\Student;
use App\Models\User;
use App\Services\Rank\StudentChoicePredictor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class StudentRankProbabilityObserverTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    public function test_creating_student_with_rank_calls_predictor_and_caches_probability(): void
    {
        $this->mockPredictor(returning: 73);

        $student = $this->makeStudent(['rank' => '12345', 'category' => 'Delhi', 'preference_r1' => 'NSUT/IT']);

        $this->assertSame(73, (int) $student->fresh()->rank_prob_first_choice);
    }

    public function test_creating_student_without_rank_leaves_cache_null(): void
    {
        $this->mockPredictor(returning: null);

        $student = $this->makeStudent(['rank' => null, 'category' => 'Delhi', 'preference_r1' => 'NSUT/IT']);

        $this->assertNull($student->fresh()->rank_prob_first_choice);
    }

    public function test_updating_rank_recomputes_probability(): void
    {
        $this->mockPredictor(returning: 50);
        $student = $this->makeStudent(['rank' => '20000', 'category' => 'Delhi', 'preference_r1' => 'NSUT/IT']);
        $this->assertSame(50, (int) $student->fresh()->rank_prob_first_choice);

        $this->mockPredictor(returning: 88);
        $student->update(['rank' => '5000']);

        $this->assertSame(88, (int) $student->fresh()->rank_prob_first_choice);
    }

    public function test_updating_unrelated_attribute_does_not_recompute(): void
    {
        $this->mockPredictor(returning: 50);
        $student = $this->makeStudent(['rank' => '20000', 'category' => 'Delhi', 'preference_r1' => 'NSUT/IT']);
        $this->assertSame(50, (int) $student->fresh()->rank_prob_first_choice);

        // Predictor must NOT be called again
        $spy = Mockery::mock(StudentChoicePredictor::class);
        $spy->shouldNotReceive('topChoices');
        $this->app->instance(StudentChoicePredictor::class, $spy);

        $student->update(['father_name' => 'Updated Name']);

        $this->assertSame(50, (int) $student->fresh()->rank_prob_first_choice);
    }

    public function test_updating_category_recomputes(): void
    {
        $this->mockPredictor(returning: 60);
        $student = $this->makeStudent(['rank' => '10000', 'category' => 'Delhi', 'preference_r1' => 'NSUT/IT']);
        $this->assertSame(60, (int) $student->fresh()->rank_prob_first_choice);

        $this->mockPredictor(returning: 40);
        $student->update(['category' => 'Outside']);

        $this->assertSame(40, (int) $student->fresh()->rank_prob_first_choice);
    }

    public function test_updating_preference_r1_recomputes(): void
    {
        $this->mockPredictor(returning: 60);
        $student = $this->makeStudent(['rank' => '10000', 'category' => 'Delhi', 'preference_r1' => 'NSUT/IT']);
        $this->assertSame(60, (int) $student->fresh()->rank_prob_first_choice);

        $this->mockPredictor(returning: 95);
        $student->update(['preference_r1' => 'IGDTUW/CSE']);

        $this->assertSame(95, (int) $student->fresh()->rank_prob_first_choice);
    }

    public function test_predictor_returning_empty_array_leaves_cache_null(): void
    {
        $mock = Mockery::mock(StudentChoicePredictor::class);
        $mock->shouldReceive('topChoices')->andReturn([]);
        $this->app->instance(StudentChoicePredictor::class, $mock);

        $student = $this->makeStudent(['rank' => '999999', 'category' => 'Delhi', 'preference_r1' => 'NSUT/IT']);

        $this->assertNull($student->fresh()->rank_prob_first_choice);
    }

    private function mockPredictor(?int $returning): void
    {
        $mock = Mockery::mock(StudentChoicePredictor::class);
        if ($returning === null) {
            $mock->shouldReceive('topChoices')->andReturn([]);
        } else {
            $mock->shouldReceive('topChoices')->andReturn([
                [
                    'rank' => 1,
                    'college' => 'NSUT',
                    'branch' => 'IT',
                    'probability_pct' => $returning,
                    'bucket' => 'safe',
                ],
            ]);
        }
        $this->app->instance(StudentChoicePredictor::class, $mock);
    }

    private function makeStudent(array $overrides): Student
    {
        $owner = User::factory()->create();
        return Student::factory()->create(array_merge([
            'owner_id' => $owner->id,
            'referrer_id' => $owner->id,
        ], $overrides));
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
```

NOTE on factory: this assumes `StudentFactory` exists and accepts `rank`, `category`, `preference_r1` overrides. If `StudentFactory::definition()` does not include these fields, add them as nullable defaults (rank=null, category='Delhi', preference_r1=null) — that is a one-line factory tweak, NOT a schema change. Verify by reading `database/factories/StudentFactory.php` before writing the test; if the factory needs the tweak, include it in this same task and commit together.

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=StudentRankProbabilityObserverTest`
Expected: All 7 fail (observer not registered → cache stays null on every save).

- [ ] **Step 3: Write the observer**

```php
<?php
// app/Observers/StudentRankProbabilityObserver.php

namespace App\Observers;

use App\Models\Student;
use App\Services\Rank\StudentChoicePredictor;

class StudentRankProbabilityObserver
{
    public function __construct(private readonly StudentChoicePredictor $predictor)
    {
    }

    public function creating(Student $student): void
    {
        $student->rank_prob_first_choice = $this->compute($student);
    }

    public function updating(Student $student): void
    {
        if (! $this->relevantAttributesChanged($student)) {
            return;
        }
        $student->rank_prob_first_choice = $this->compute($student);
    }

    private function relevantAttributesChanged(Student $student): bool
    {
        foreach (['rank', 'category', 'preference_r1'] as $attr) {
            if ($student->isDirty($attr)) {
                return true;
            }
        }
        return false;
    }

    private function compute(Student $student): ?int
    {
        if (empty($student->rank)) {
            return null;
        }

        $choices = $this->predictor->topChoices($student, 1);
        if ($choices === []) {
            return null;
        }

        return (int) $choices[0]['probability_pct'];
    }
}
```

- [ ] **Step 4: Register the observer**

Edit `app/Providers/AppServiceProvider.php` — add ONE line in the `boot()` method, immediately after the existing `Student::observe(...)` line:

```php
\App\Models\Student::observe(\App\Observers\StudentRankProbabilityObserver::class);
```

(Both observers will fire on Student events; Laravel supports multiple observers on the same model. Order is registration order.)

- [ ] **Step 5: Run test to verify it passes**

Run: `php artisan test --filter=StudentRankProbabilityObserverTest`
Expected: 7 passing.

- [ ] **Step 6: Commit**

```bash
git add app/Observers/StudentRankProbabilityObserver.php app/Providers/AppServiceProvider.php tests/Feature/Performance/StudentRankProbabilityObserverTest.php
# include database/factories/StudentFactory.php if you tweaked the factory in step 1
git commit -m "feat(performance): observer caches rank_prob_first_choice on student saves"
```

---

## Task 6 — `BackfillRankProbabilityCommand`

**Files:**
- Create: `app/Console/Commands/BackfillRankProbabilityCommand.php`
- Test: `tests/Feature/Performance/BackfillRankProbabilityCommandTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php
// tests/Feature/Performance/BackfillRankProbabilityCommandTest.php

namespace Tests\Feature\Performance;

use App\Models\Student;
use App\Models\User;
use App\Services\Rank\StudentChoicePredictor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Mockery;
use Tests\TestCase;

class BackfillRankProbabilityCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    public function test_command_populates_probability_for_students_with_rank(): void
    {
        // Create 3 students; observer mocked to return 50, but we'll wipe column to 0/null then run backfill
        $this->mockPredictor(returning: 50);
        $owner = User::factory()->create();
        Student::factory()->count(3)->create([
            'owner_id' => $owner->id,
            'referrer_id' => $owner->id,
            'rank' => '10000',
            'category' => 'Delhi',
            'preference_r1' => 'NSUT/IT',
        ]);

        // Wipe the cache to simulate "existing data without observer"
        Student::query()->update(['rank_prob_first_choice' => null]);

        // Re-mock predictor to return a different value so we can detect backfill ran
        $this->mockPredictor(returning: 77);

        $exitCode = Artisan::call('performance:backfill-rank-probabilities');

        $this->assertSame(0, $exitCode);
        $this->assertEquals(
            [77, 77, 77],
            Student::pluck('rank_prob_first_choice')->map(fn($v) => (int) $v)->all()
        );
    }

    public function test_command_skips_students_without_rank(): void
    {
        $owner = User::factory()->create();
        $withRank = Student::factory()->create([
            'owner_id' => $owner->id, 'referrer_id' => $owner->id,
            'rank' => '5000', 'category' => 'Delhi', 'preference_r1' => 'NSUT/IT',
        ]);
        $withoutRank = Student::factory()->create([
            'owner_id' => $owner->id, 'referrer_id' => $owner->id,
            'rank' => null, 'category' => 'Delhi', 'preference_r1' => 'NSUT/IT',
        ]);

        Student::query()->update(['rank_prob_first_choice' => null]);
        $this->mockPredictor(returning: 60);

        Artisan::call('performance:backfill-rank-probabilities');

        $this->assertSame(60, (int) $withRank->fresh()->rank_prob_first_choice);
        $this->assertNull($withoutRank->fresh()->rank_prob_first_choice);
    }

    public function test_command_is_idempotent(): void
    {
        $owner = User::factory()->create();
        Student::factory()->count(2)->create([
            'owner_id' => $owner->id, 'referrer_id' => $owner->id,
            'rank' => '5000', 'category' => 'Delhi', 'preference_r1' => 'NSUT/IT',
        ]);
        $this->mockPredictor(returning: 60);
        Student::query()->update(['rank_prob_first_choice' => null]);

        Artisan::call('performance:backfill-rank-probabilities');
        Artisan::call('performance:backfill-rank-probabilities');

        $this->assertEquals(
            [60, 60],
            Student::pluck('rank_prob_first_choice')->map(fn($v) => (int) $v)->all()
        );
    }

    private function mockPredictor(?int $returning): void
    {
        $mock = Mockery::mock(StudentChoicePredictor::class);
        if ($returning === null) {
            $mock->shouldReceive('topChoices')->andReturn([]);
        } else {
            $mock->shouldReceive('topChoices')->andReturn([
                ['rank'=>1,'college'=>'NSUT','branch'=>'IT','probability_pct'=>$returning,'bucket'=>'safe'],
            ]);
        }
        $this->app->instance(StudentChoicePredictor::class, $mock);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=BackfillRankProbabilityCommandTest`
Expected: 3 failures with "Command 'performance:backfill-rank-probabilities' is not defined."

- [ ] **Step 3: Write the command**

```php
<?php
// app/Console/Commands/BackfillRankProbabilityCommand.php

namespace App\Console\Commands;

use App\Models\Student;
use App\Services\Rank\StudentChoicePredictor;
use Illuminate\Console\Command;

class BackfillRankProbabilityCommand extends Command
{
    protected $signature = 'performance:backfill-rank-probabilities {--chunk=100}';

    protected $description = 'Recompute rank_prob_first_choice for all students. Idempotent — safe to re-run.';

    public function handle(StudentChoicePredictor $predictor): int
    {
        $chunk = (int) $this->option('chunk');
        $touched = 0;
        $cleared = 0;
        $skipped = 0;

        Student::query()->orderBy('id')->chunkById($chunk, function ($students) use ($predictor, &$touched, &$cleared, &$skipped) {
            foreach ($students as $student) {
                if (empty($student->rank)) {
                    if ($student->rank_prob_first_choice !== null) {
                        $student->rank_prob_first_choice = null;
                        $student->saveQuietly();   // skip observer; we are the source of truth here
                        $cleared++;
                    } else {
                        $skipped++;
                    }
                    continue;
                }

                $choices = $predictor->topChoices($student, 1);
                $value = $choices === [] ? null : (int) $choices[0]['probability_pct'];

                if ($student->rank_prob_first_choice !== $value) {
                    $student->rank_prob_first_choice = $value;
                    $student->saveQuietly();
                }
                $touched++;
            }
        });

        $this->info("Backfill complete — touched=$touched cleared=$cleared skipped=$skipped");
        return self::SUCCESS;
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter=BackfillRankProbabilityCommandTest`
Expected: 3 passing.

- [ ] **Step 5: Commit**

```bash
git add app/Console/Commands/BackfillRankProbabilityCommand.php tests/Feature/Performance/BackfillRankProbabilityCommandTest.php
git commit -m "feat(performance): add backfill command for rank_prob_first_choice"
```

---

## Task 7 — Final verification

**Files:** none — this task is a green-light gate before P2.

- [ ] **Step 1: Run the full test suite**

Run: `php artisan test`
Expected: All previously-passing tests still pass; 5 new test files (config, two migration tests, model test, observer test, backfill test) all pass.

- [ ] **Step 2: Verify migrate:fresh + seed runs cleanly**

Run: `php artisan migrate:fresh --seed`
Expected: All migrations run, all seeders complete, no errors.

- [ ] **Step 3: Verify rollback works for the two new migrations**

Run: `php artisan migrate:rollback --step=2 && php artisan migrate`
Expected: rollback removes both new migrations cleanly, re-migrate adds them back, no errors.

- [ ] **Step 4: Spot-check rank backfill on local data**

Optional but recommended: if you have a local copy of prod data, run:

```bash
php artisan performance:backfill-rank-probabilities
php artisan tinker --execute="echo \App\Models\Student::whereNotNull('rank_prob_first_choice')->count();"
```

Expected: a positive integer roughly matching the count of students with non-null `rank`.

- [ ] **Step 5: Mark P1 complete**

P1 has no further work. Hand-off to P2 (UserPerformanceScorer service + tests). Pre-deploy quality check (lint + visual + curl-verify) is NOT needed for P1 since there are no public-facing routes or views — that runs before P4 (Filament page) and P5 (prod backfill).

---

## Self-Review Checklist (run mentally before declaring done)

- [ ] Every step has actual code, not placeholders
- [ ] Every test step has expected output
- [ ] Every commit message follows the repo's `feat(scope): description` style
- [ ] Both migrations are reversible (verified in Tasks 2 + 3 + 7)
- [ ] Observer registered in AppServiceProvider boot() (Task 5 step 4)
- [ ] Factory matches model fillable + casts (Task 4)
- [ ] Backfill is idempotent and uses `saveQuietly` to bypass observer (we are source-of-truth)
- [ ] No production deploy in P1 — code-only foundation
- [ ] P2 unblocked: model + table + cached column + config all exist for the scorer to consume

---

**End of P1 plan.** Hand-off to next plan: P2 — UserPerformanceScorer service.
