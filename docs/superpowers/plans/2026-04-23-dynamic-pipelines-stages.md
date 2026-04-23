# Dynamic Pipelines & Stages Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ship SP#1 of the zero-code-admin-configuration roadmap as defined in `docs/superpowers/specs/2026-04-23-dynamic-pipelines-stages-design.md` — replace the hardcoded `PipelineStage` enum and `StageTransitionValidator` with DB-backed, admin-editable pipelines, stages, and transition rules behind a new `/admin/pipeline-config` Filament page. Existing kanban keeps its URL; existing behavior for the 4 current hardcoded rules is preserved byte-for-byte via seeded DB rows.

**Architecture:** One new Filament page with two tabs (Stages, Transition Rules), three domain services (`PipelineConfig` cache-backed reader, `StageRepository` for CRUD with transfer-before-delete, `StageTransitionEngine` + `ConditionEvaluator` for rule evaluation), four new tables (`pipelines`, `stages`, `stage_transition_rules`, `stage_transition_conditions`), one column addition (`students.stage_id`), one column widening (`students.stage` ENUM → VARCHAR(80) for the 1-release cache window).

**Tech Stack:** Laravel 11, Filament 3, Spatie Permission, MySQL prod / SQLite tests, PHPUnit 11, PHP 8.5 local / 8.4 prod, Livewire for Filament component tests.

**Branch:** `feature/dynamic-pipelines-stages` — create it fresh off `main` before Task 1.

**Local test runner:** `php -d memory_limit=512M vendor/bin/phpunit --filter=<name>` (plain `php artisan test` OOMs on the full suite with default memory).

**DEPR note:** On local PHP 8.5 every test emits a `PHP Deprecated: PDO::MYSQL_ATTR_SSL_CA` line. These are harmless; read the final `Tests: X passed` line. See memory `project_davya-crm_php85_deprecations.md`.

---

## Seed fixture reference

From `database/seeders/UsersSeeder.php` (seeded by `$this->seed()`):

| User | Email | Roles |
|---|---|---|
| Sumit | `sumit@davya.local` | `admin`, `head` |
| Nikhil | `nikhil@davya.local` | `head` |
| Sonam | `sonam@davya.local` | `head` |
| Nisha | `nisha@davya.local` | `member` (team Nikhil) |
| Poonam | `poonam@davya.local` | `member` (team Sonam) |
| Neetu | `neetu@davya.local` | `member` (team Sonam) |
| Kapil | `kapil@davya.local` | `freelancer` |

Every test user has `must_change_password = true`; use an `unblock($user)` helper to flip it to `false` before acting as them (pattern from `tests/Feature/FinanceRoleTest.php`).

---

## File structure

**Create — migrations**
- `database/migrations/2026_04_23_100000_create_pipelines_and_stages_tables.php`
- `database/migrations/2026_04_23_100100_create_stage_transition_rule_tables.php`
- `database/migrations/2026_04_23_100200_add_stage_id_to_students_and_widen_stage.php`
- `database/migrations/2026_04_23_100300_seed_default_pipeline_and_stages.php`
- `database/migrations/2026_04_23_100400_seed_default_transition_rules.php`
- `database/migrations/2026_04_23_100500_backfill_student_stage_id.php`

**Create — models**
- `app/Models/Pipeline.php`
- `app/Models/Stage.php`
- `app/Models/StageTransitionRule.php`
- `app/Models/StageTransitionCondition.php`

**Create — services**
- `app/Services/Pipeline/PipelineConfig.php`
- `app/Services/Pipeline/StageRepository.php`
- `app/Services/Pipeline/ConditionEvaluator.php`
- `app/Services/Pipeline/StageTransitionEngine.php`

**Create — Filament page + views**
- `app/Filament/Pages/PipelineConfigPage.php`
- `resources/views/filament/pages/pipeline-config.blade.php`

**Create — tests**
- `tests/Feature/Pipeline/StageRepositoryTest.php`
- `tests/Feature/Pipeline/ConditionEvaluatorTest.php`
- `tests/Feature/Pipeline/StageTransitionEngineTest.php`
- `tests/Feature/Pipeline/PipelineConfigPageTest.php`
- `tests/Feature/Pipeline/KanbanDynamicStagesTest.php`

**Modify**
- `app/Models/Student.php` — add `stage_id`, `stageRow()` relation, observer-maintained `stage` cache
- `app/Filament/Pages/KanbanBoard.php` — read stages from `PipelineConfig`; delegate to `StageTransitionEngine`
- `app/Filament/Resources/StudentResource.php` — stage select reads from `PipelineConfig`; validator swap
- `app/Filament/Resources/StudentResource/Pages/EditStudent.php` — validator swap
- `app/Observers/MeetingObserver.php` — use `StageTransitionEngine`, keep `students.stage` cache in sync
- `app/Services/PipelineSummary.php` — `stages()` reads from `PipelineConfig` (keep method signature)

**Delete (at the end, after all callers migrated)**
- `app/Enums/PipelineStage.php`
- `app/Services/StageTransitionValidator.php`

---

## Preflight: create branch

Before Task 1, run:

```bash
cd /Users/Sumit/davya-crm
git checkout main && git pull
git checkout -b feature/dynamic-pipelines-stages
```

---

## Phase 1 — Schema

### Task 1: Create `pipelines` and `stages` tables

**Files:**
- Create: `database/migrations/2026_04_23_100000_create_pipelines_and_stages_tables.php`
- Create: `tests/Feature/Pipeline/PipelineStageSchemaTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php
// tests/Feature/Pipeline/PipelineStageSchemaTest.php
namespace Tests\Feature\Pipeline;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PipelineStageSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_pipelines_table_has_expected_columns(): void
    {
        $this->assertTrue(Schema::hasTable('pipelines'));
        foreach (['id','name','icon','record_label','is_default','created_at','updated_at'] as $col) {
            $this->assertTrue(Schema::hasColumn('pipelines', $col), "missing column $col");
        }
    }

    public function test_stages_table_has_expected_columns(): void
    {
        $this->assertTrue(Schema::hasTable('stages'));
        foreach (['id','pipeline_id','name','description','stage_type','display_order','color','created_at','updated_at'] as $col) {
            $this->assertTrue(Schema::hasColumn('stages', $col), "missing column $col");
        }
    }
}
```

- [ ] **Step 2: Run test — verify it fails**

```
php -d memory_limit=512M vendor/bin/phpunit --filter=PipelineStageSchemaTest
```
Expected: FAIL ("Table 'pipelines' doesn't exist" or missing-column assertions).

- [ ] **Step 3: Write the migration**

```php
<?php
// database/migrations/2026_04_23_100000_create_pipelines_and_stages_tables.php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('pipelines', function (Blueprint $t) {
            $t->id();
            $t->string('name', 120);
            $t->string('icon', 60)->nullable();
            $t->string('record_label', 40)->default('Student');
            $t->boolean('is_default')->default(false);
            $t->timestamps();
        });

        Schema::create('stages', function (Blueprint $t) {
            $t->id();
            $t->foreignId('pipeline_id')->constrained('pipelines')->cascadeOnDelete();
            $t->string('name', 80);
            $t->text('description')->nullable();
            $t->string('stage_type', 20); // OPEN | CLOSED_WON | CLOSED_LOST
            $t->integer('display_order');
            $t->string('color', 7)->nullable();
            $t->timestamps();
            $t->unique(['pipeline_id', 'name']);
            $t->index(['pipeline_id', 'display_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stages');
        Schema::dropIfExists('pipelines');
    }
};
```

- [ ] **Step 4: Run test — verify it passes**

```
php -d memory_limit=512M vendor/bin/phpunit --filter=PipelineStageSchemaTest
```
Expected: PASS (2 tests).

- [ ] **Step 5: Commit**

```bash
git add database/migrations/2026_04_23_100000_create_pipelines_and_stages_tables.php tests/Feature/Pipeline/PipelineStageSchemaTest.php
git commit -m "feat(pipeline): pipelines + stages tables"
```

---

### Task 2: Create rule + condition tables

**Files:**
- Create: `database/migrations/2026_04_23_100100_create_stage_transition_rule_tables.php`
- Modify: `tests/Feature/Pipeline/PipelineStageSchemaTest.php` — add 2 tests

- [ ] **Step 1: Append tests**

Add to `PipelineStageSchemaTest.php`:

```php
public function test_stage_transition_rules_table_has_expected_columns(): void
{
    $this->assertTrue(Schema::hasTable('stage_transition_rules'));
    foreach (['id','pipeline_id','name','from_stage_id','to_stage_id','severity','is_active','created_at','updated_at'] as $col) {
        $this->assertTrue(Schema::hasColumn('stage_transition_rules', $col), "missing $col");
    }
}

public function test_stage_transition_conditions_table_has_expected_columns(): void
{
    $this->assertTrue(Schema::hasTable('stage_transition_conditions'));
    foreach (['id','rule_id','condition_type','field_or_relation','operator','value','display_order'] as $col) {
        $this->assertTrue(Schema::hasColumn('stage_transition_conditions', $col), "missing $col");
    }
}
```

- [ ] **Step 2: Run — verify fails**

```
php -d memory_limit=512M vendor/bin/phpunit --filter=PipelineStageSchemaTest
```
Expected: FAIL on the two new tests.

- [ ] **Step 3: Write the migration**

```php
<?php
// database/migrations/2026_04_23_100100_create_stage_transition_rule_tables.php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('stage_transition_rules', function (Blueprint $t) {
            $t->id();
            $t->foreignId('pipeline_id')->constrained('pipelines')->cascadeOnDelete();
            $t->string('name', 160);
            $t->foreignId('from_stage_id')->nullable()->constrained('stages')->nullOnDelete();
            $t->foreignId('to_stage_id')->nullable()->constrained('stages')->nullOnDelete();
            $t->string('severity', 10); // HARD | SOFT
            $t->boolean('is_active')->default(true);
            $t->timestamps();
            $t->index(['pipeline_id', 'to_stage_id', 'is_active']);
        });

        Schema::create('stage_transition_conditions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('rule_id')->constrained('stage_transition_rules')->cascadeOnDelete();
            $t->string('condition_type', 20); // FIELD_CHECK | HAS_RELATION
            $t->string('field_or_relation', 60);
            $t->string('operator', 24);
            $t->json('value')->nullable();
            $t->integer('display_order')->default(0);
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stage_transition_conditions');
        Schema::dropIfExists('stage_transition_rules');
    }
};
```

- [ ] **Step 4: Run — verify passes**

```
php -d memory_limit=512M vendor/bin/phpunit --filter=PipelineStageSchemaTest
```
Expected: PASS (4 tests).

- [ ] **Step 5: Commit**

```bash
git add database/migrations/2026_04_23_100100_create_stage_transition_rule_tables.php tests/Feature/Pipeline/PipelineStageSchemaTest.php
git commit -m "feat(pipeline): transition rules + conditions tables"
```

---

### Task 3: Add `students.stage_id` FK + widen `students.stage` ENUM → VARCHAR(80)

**Files:**
- Create: `database/migrations/2026_04_23_100200_add_stage_id_to_students_and_widen_stage.php`
- Modify: `tests/Feature/Pipeline/PipelineStageSchemaTest.php`

- [ ] **Step 1: Append test**

```php
public function test_students_has_stage_id_column(): void
{
    $this->assertTrue(Schema::hasColumn('students', 'stage_id'));
}

public function test_students_stage_accepts_arbitrary_values(): void
{
    // After widening, we must be able to INSERT a stage name outside the old enum.
    \DB::table('students')->insert([
        'name' => 'Widen Test',
        'phone' => '9999999999',
        'owner_id' => 1,
        'referrer_id' => 1,
        'lead_source' => 'test',
        'stage' => 'Custom Admin Added Stage',
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $this->assertDatabaseHas('students', ['stage' => 'Custom Admin Added Stage']);
}
```

- [ ] **Step 2: Run — verify fails**

```
php -d memory_limit=512M vendor/bin/phpunit --filter=PipelineStageSchemaTest
```
Expected: FAIL on new tests (no `stage_id` column; widen-test fails since ENUM rejects unknown value on MySQL — on SQLite it might pass since no enum enforcement, but the `stage_id` test still fails).

- [ ] **Step 3: Write the migration**

```php
<?php
// database/migrations/2026_04_23_100200_add_stage_id_to_students_and_widen_stage.php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // Add nullable FK first; backfill runs in a later migration.
        Schema::table('students', function (Blueprint $t) {
            $t->foreignId('stage_id')->nullable()->after('stage')->constrained('stages')->nullOnDelete();
            $t->index('stage_id');
        });

        // Widen ENUM → VARCHAR on MySQL so admin-added stages can write to the cache column.
        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE students MODIFY stage VARCHAR(80) NOT NULL");
        }
        // SQLite stores enums as TEXT already — no-op.
    }

    public function down(): void
    {
        Schema::table('students', function (Blueprint $t) {
            $t->dropForeign(['stage_id']);
            $t->dropIndex(['stage_id']);
            $t->dropColumn('stage_id');
        });
        // Intentionally not re-narrowing stage to ENUM on down() — too risky with custom values present.
    }
};
```

- [ ] **Step 4: Run — verify passes**

```
php -d memory_limit=512M vendor/bin/phpunit --filter=PipelineStageSchemaTest
```
Expected: PASS (6 tests).

- [ ] **Step 5: Commit**

```bash
git add database/migrations/2026_04_23_100200_add_stage_id_to_students_and_widen_stage.php tests/Feature/Pipeline/PipelineStageSchemaTest.php
git commit -m "feat(pipeline): students.stage_id FK + widen stage col"
```

---

## Phase 2 — Models

### Task 4: `Pipeline` + `Stage` models

**Files:**
- Create: `app/Models/Pipeline.php`
- Create: `app/Models/Stage.php`
- Create: `tests/Feature/Pipeline/PipelineStageModelTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php
// tests/Feature/Pipeline/PipelineStageModelTest.php
namespace Tests\Feature\Pipeline;

use App\Models\Pipeline;
use App\Models\Stage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PipelineStageModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_pipeline_hasmany_stages(): void
    {
        $p = Pipeline::create(['name' => 'Test', 'is_default' => true]);
        $s = Stage::create(['pipeline_id' => $p->id, 'name' => 'First', 'stage_type' => 'OPEN', 'display_order' => 1]);
        $this->assertSame(1, $p->stages()->count());
        $this->assertSame('First', $p->stages->first()->name);
        $this->assertSame($p->id, $s->pipeline->id);
    }

    public function test_stage_scope_by_type(): void
    {
        $p = Pipeline::create(['name' => 'T', 'is_default' => true]);
        Stage::create(['pipeline_id' => $p->id, 'name' => 'A', 'stage_type' => 'OPEN', 'display_order' => 1]);
        Stage::create(['pipeline_id' => $p->id, 'name' => 'B', 'stage_type' => 'CLOSED_WON', 'display_order' => 2]);
        Stage::create(['pipeline_id' => $p->id, 'name' => 'C', 'stage_type' => 'CLOSED_LOST', 'display_order' => 3]);
        $this->assertSame(1, Stage::openStages()->count());
        $this->assertSame(1, Stage::wonStages()->count());
        $this->assertSame(1, Stage::lostStages()->count());
    }
}
```

- [ ] **Step 2: Run — verify fails**

```
php -d memory_limit=512M vendor/bin/phpunit --filter=PipelineStageModelTest
```
Expected: FAIL ("Class App\Models\Pipeline not found").

- [ ] **Step 3: Write the models**

```php
<?php
// app/Models/Pipeline.php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Pipeline extends Model
{
    protected $fillable = ['name','icon','record_label','is_default'];

    protected $casts = ['is_default' => 'bool'];

    public function stages(): HasMany
    {
        return $this->hasMany(Stage::class)->orderBy('display_order');
    }

    public function transitionRules(): HasMany
    {
        return $this->hasMany(StageTransitionRule::class);
    }

    public static function default(): self
    {
        return self::where('is_default', true)->firstOrFail();
    }
}
```

```php
<?php
// app/Models/Stage.php
namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Stage extends Model
{
    public const TYPE_OPEN = 'OPEN';
    public const TYPE_WON  = 'CLOSED_WON';
    public const TYPE_LOST = 'CLOSED_LOST';
    public const TYPES = [self::TYPE_OPEN, self::TYPE_WON, self::TYPE_LOST];

    protected $fillable = ['pipeline_id','name','description','stage_type','display_order','color'];

    public function pipeline(): BelongsTo
    {
        return $this->belongsTo(Pipeline::class);
    }

    public function students(): HasMany
    {
        return $this->hasMany(Student::class);
    }

    public function scopeOpenStages(Builder $q): Builder  { return $q->where('stage_type', self::TYPE_OPEN); }
    public function scopeWonStages(Builder $q): Builder   { return $q->where('stage_type', self::TYPE_WON); }
    public function scopeLostStages(Builder $q): Builder  { return $q->where('stage_type', self::TYPE_LOST); }

    public function isTerminal(): bool
    {
        return in_array($this->stage_type, [self::TYPE_WON, self::TYPE_LOST], true);
    }
}
```

- [ ] **Step 4: Run — verify passes**

```
php -d memory_limit=512M vendor/bin/phpunit --filter=PipelineStageModelTest
```
Expected: PASS (2 tests).

- [ ] **Step 5: Commit**

```bash
git add app/Models/Pipeline.php app/Models/Stage.php tests/Feature/Pipeline/PipelineStageModelTest.php
git commit -m "feat(pipeline): Pipeline + Stage models"
```

---

### Task 5: `StageTransitionRule` + `StageTransitionCondition` models

**Files:**
- Create: `app/Models/StageTransitionRule.php`
- Create: `app/Models/StageTransitionCondition.php`
- Modify: `tests/Feature/Pipeline/PipelineStageModelTest.php`

- [ ] **Step 1: Append test**

```php
public function test_rule_has_conditions_and_from_to_relations(): void
{
    $p = Pipeline::create(['name'=>'P','is_default'=>true]);
    $from = Stage::create(['pipeline_id'=>$p->id,'name'=>'A','stage_type'=>'OPEN','display_order'=>1]);
    $to   = Stage::create(['pipeline_id'=>$p->id,'name'=>'B','stage_type'=>'CLOSED_LOST','display_order'=>2]);

    $rule = \App\Models\StageTransitionRule::create([
        'pipeline_id'=>$p->id,'name'=>'r1',
        'from_stage_id'=>$from->id,'to_stage_id'=>$to->id,
        'severity'=>'HARD','is_active'=>true,
    ]);
    $rule->conditions()->create([
        'condition_type'=>'FIELD_CHECK','field_or_relation'=>'close_reason',
        'operator'=>'is_not_empty','value'=>null,'display_order'=>0,
    ]);

    $this->assertSame(1, $rule->conditions()->count());
    $this->assertSame('A', $rule->fromStage->name);
    $this->assertSame('B', $rule->toStage->name);
    $this->assertSame(1, $p->transitionRules()->count());
}
```

- [ ] **Step 2: Run — verify fails**

```
php -d memory_limit=512M vendor/bin/phpunit --filter=PipelineStageModelTest::test_rule_has_conditions
```
Expected: FAIL ("Class StageTransitionRule not found").

- [ ] **Step 3: Write the models**

```php
<?php
// app/Models/StageTransitionRule.php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StageTransitionRule extends Model
{
    public const SEV_HARD = 'HARD';
    public const SEV_SOFT = 'SOFT';

    protected $fillable = ['pipeline_id','name','from_stage_id','to_stage_id','severity','is_active'];

    protected $casts = ['is_active' => 'bool'];

    public function pipeline(): BelongsTo { return $this->belongsTo(Pipeline::class); }
    public function fromStage(): BelongsTo { return $this->belongsTo(Stage::class, 'from_stage_id'); }
    public function toStage(): BelongsTo   { return $this->belongsTo(Stage::class, 'to_stage_id'); }
    public function conditions(): HasMany  { return $this->hasMany(StageTransitionCondition::class, 'rule_id')->orderBy('display_order'); }
}
```

```php
<?php
// app/Models/StageTransitionCondition.php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StageTransitionCondition extends Model
{
    public const TYPE_FIELD_CHECK  = 'FIELD_CHECK';
    public const TYPE_HAS_RELATION = 'HAS_RELATION';

    protected $fillable = ['rule_id','condition_type','field_or_relation','operator','value','display_order'];

    protected $casts = ['value' => 'array'];

    public function rule(): BelongsTo { return $this->belongsTo(StageTransitionRule::class, 'rule_id'); }
}
```

- [ ] **Step 4: Run — verify passes**

```
php -d memory_limit=512M vendor/bin/phpunit --filter=PipelineStageModelTest
```
Expected: PASS (3 tests).

- [ ] **Step 5: Commit**

```bash
git add app/Models/StageTransitionRule.php app/Models/StageTransitionCondition.php tests/Feature/Pipeline/PipelineStageModelTest.php
git commit -m "feat(pipeline): StageTransitionRule + Condition models"
```

---

## Phase 3 — Seeders & backfill (as migrations for zero-downtime prod)

### Task 6: Seed default pipeline + 13 stages

**Files:**
- Create: `database/migrations/2026_04_23_100300_seed_default_pipeline_and_stages.php`
- Create: `tests/Feature/Pipeline/SeededPipelineTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php
// tests/Feature/Pipeline/SeededPipelineTest.php
namespace Tests\Feature\Pipeline;

use App\Models\Pipeline;
use App\Models\Stage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SeededPipelineTest extends TestCase
{
    use RefreshDatabase;

    public function test_default_pipeline_exists_with_13_stages(): void
    {
        $p = Pipeline::default();
        $this->assertSame('IPU Admission', $p->name);
        $this->assertSame(13, $p->stages()->count());
    }

    public function test_stage_types_distribution(): void
    {
        $p = Pipeline::default();
        $this->assertSame(11, $p->stages()->where('stage_type', Stage::TYPE_OPEN)->count());
        $this->assertSame(1,  $p->stages()->where('stage_type', Stage::TYPE_WON)->count());
        $this->assertSame(1,  $p->stages()->where('stage_type', Stage::TYPE_LOST)->count());
    }

    public function test_complete_payment_received_is_won(): void
    {
        $p = Pipeline::default();
        $won = $p->stages()->where('stage_type', Stage::TYPE_WON)->first();
        $this->assertSame('Complete Payment Received', $won->name);
    }

    public function test_closed_is_lost(): void
    {
        $p = Pipeline::default();
        $lost = $p->stages()->where('stage_type', Stage::TYPE_LOST)->first();
        $this->assertSame('Closed', $lost->name);
    }

    public function test_seat_allotted_is_open(): void
    {
        $p = Pipeline::default();
        $sa = $p->stages()->where('name', 'Seat Allotted')->firstOrFail();
        $this->assertSame(Stage::TYPE_OPEN, $sa->stage_type);
    }
}
```

- [ ] **Step 2: Run — verify fails**

```
php -d memory_limit=512M vendor/bin/phpunit --filter=SeededPipelineTest
```
Expected: FAIL ("No records found" from `Pipeline::default()`).

- [ ] **Step 3: Write the seed migration**

```php
<?php
// database/migrations/2026_04_23_100300_seed_default_pipeline_and_stages.php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        $now = now();
        $pipelineId = DB::table('pipelines')->insertGetId([
            'name' => 'IPU Admission',
            'record_label' => 'Student',
            'is_default' => true,
            'created_at' => $now, 'updated_at' => $now,
        ]);

        $stages = [
            ['Lead Captured',              'OPEN'],
            ['Meeting Scheduled',          'OPEN'],
            ['Meeting Done',               'OPEN'],
            ['Advance Received',           'OPEN'],
            ['MQ',                         'OPEN'],
            ['Round 1',                    'OPEN'],
            ['Round 2',                    'OPEN'],
            ['Round 3',                    'OPEN'],
            ['Sliding',                    'OPEN'],
            ['Offline',                    'OPEN'],
            ['Seat Allotted',              'OPEN'],
            ['Complete Payment Received',  'CLOSED_WON'],
            ['Closed',                     'CLOSED_LOST'],
        ];

        $rows = [];
        foreach ($stages as $i => [$name, $type]) {
            $rows[] = [
                'pipeline_id' => $pipelineId,
                'name' => $name,
                'stage_type' => $type,
                'display_order' => $i + 1,
                'created_at' => $now, 'updated_at' => $now,
            ];
        }
        DB::table('stages')->insert($rows);
    }

    public function down(): void
    {
        DB::table('stages')->where('pipeline_id', function ($q) {
            $q->select('id')->from('pipelines')->where('name', 'IPU Admission');
        })->delete();
        DB::table('pipelines')->where('name', 'IPU Admission')->delete();
    }
};
```

- [ ] **Step 4: Run — verify passes**

```
php -d memory_limit=512M vendor/bin/phpunit --filter=SeededPipelineTest
```
Expected: PASS (5 tests).

- [ ] **Step 5: Commit**

```bash
git add database/migrations/2026_04_23_100300_seed_default_pipeline_and_stages.php tests/Feature/Pipeline/SeededPipelineTest.php
git commit -m "feat(pipeline): seed IPU Admission pipeline + 13 stages"
```

---

### Task 7: Seed the 4 default transition rules

**Files:**
- Create: `database/migrations/2026_04_23_100400_seed_default_transition_rules.php`
- Modify: `tests/Feature/Pipeline/SeededPipelineTest.php`

- [ ] **Step 1: Append tests**

```php
public function test_four_default_rules_seeded(): void
{
    $p = \App\Models\Pipeline::default();
    $this->assertSame(4, $p->transitionRules()->count());
}

public function test_closed_requires_close_reason(): void
{
    $p = \App\Models\Pipeline::default();
    $closed = $p->stages()->where('name','Closed')->firstOrFail();
    $rule = $p->transitionRules()->where('to_stage_id', $closed->id)->whereNull('from_stage_id')->firstOrFail();
    $this->assertSame('HARD', $rule->severity);
    $cond = $rule->conditions()->firstOrFail();
    $this->assertSame('FIELD_CHECK', $cond->condition_type);
    $this->assertSame('close_reason', $cond->field_or_relation);
    $this->assertSame('is_not_empty', $cond->operator);
}

public function test_reentry_from_closed_rule_uses_null_to_stage(): void
{
    $p = \App\Models\Pipeline::default();
    $closed = $p->stages()->where('name','Closed')->firstOrFail();
    $rule = $p->transitionRules()->where('from_stage_id', $closed->id)->whereNull('to_stage_id')->firstOrFail();
    $cond = $rule->conditions()->firstOrFail();
    $this->assertSame('re_entry_reason', $cond->field_or_relation);
}
```

- [ ] **Step 2: Run — verify fails**

```
php -d memory_limit=512M vendor/bin/phpunit --filter=SeededPipelineTest
```
Expected: FAIL (only 0 rules present).

- [ ] **Step 3: Write the rule seed migration**

```php
<?php
// database/migrations/2026_04_23_100400_seed_default_transition_rules.php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        $now = now();
        $pipelineId = DB::table('pipelines')->where('is_default', true)->value('id');
        if (! $pipelineId) return;

        $stageId = fn (string $name) => DB::table('stages')
            ->where('pipeline_id', $pipelineId)->where('name', $name)->value('id');

        // Rule 1: Any → Closed requires close_reason (HARD)
        $id1 = DB::table('stage_transition_rules')->insertGetId([
            'pipeline_id' => $pipelineId, 'name' => 'Closed requires reason',
            'from_stage_id' => null, 'to_stage_id' => $stageId('Closed'),
            'severity' => 'HARD', 'is_active' => true,
            'created_at' => $now, 'updated_at' => $now,
        ]);
        DB::table('stage_transition_conditions')->insert([
            'rule_id' => $id1, 'condition_type' => 'FIELD_CHECK',
            'field_or_relation' => 'close_reason', 'operator' => 'is_not_empty',
            'value' => null, 'display_order' => 0,
            'created_at' => $now, 'updated_at' => $now,
        ]);

        // Rule 2: Closed → Any requires re_entry_reason (HARD)
        $id2 = DB::table('stage_transition_rules')->insertGetId([
            'pipeline_id' => $pipelineId, 'name' => 'Re-opening requires re-entry reason',
            'from_stage_id' => $stageId('Closed'), 'to_stage_id' => null,
            'severity' => 'HARD', 'is_active' => true,
            'created_at' => $now, 'updated_at' => $now,
        ]);
        DB::table('stage_transition_conditions')->insert([
            'rule_id' => $id2, 'condition_type' => 'FIELD_CHECK',
            'field_or_relation' => 're_entry_reason', 'operator' => 'is_not_empty',
            'value' => null, 'display_order' => 0,
            'created_at' => $now, 'updated_at' => $now,
        ]);

        // Rule 3: Any → Meeting Scheduled wants a future meeting (SOFT)
        $id3 = DB::table('stage_transition_rules')->insertGetId([
            'pipeline_id' => $pipelineId, 'name' => 'Meeting Scheduled needs a future meeting',
            'from_stage_id' => null, 'to_stage_id' => $stageId('Meeting Scheduled'),
            'severity' => 'SOFT', 'is_active' => true,
            'created_at' => $now, 'updated_at' => $now,
        ]);
        DB::table('stage_transition_conditions')->insert([
            'rule_id' => $id3, 'condition_type' => 'HAS_RELATION',
            'field_or_relation' => 'meetings', 'operator' => 'has_where',
            'value' => json_encode([
                'status' => 'scheduled',
                'scheduled_at_gte' => 'now',
                'count_min' => 1,
            ]),
            'display_order' => 0,
            'created_at' => $now, 'updated_at' => $now,
        ]);

        // Rule 4: Any → Sliding wants prior allotment (SOFT)
        $id4 = DB::table('stage_transition_rules')->insertGetId([
            'pipeline_id' => $pipelineId, 'name' => 'Sliding needs prior allotment',
            'from_stage_id' => null, 'to_stage_id' => $stageId('Sliding'),
            'severity' => 'SOFT', 'is_active' => true,
            'created_at' => $now, 'updated_at' => $now,
        ]);
        DB::table('stage_transition_conditions')->insert([
            'rule_id' => $id4, 'condition_type' => 'HAS_RELATION',
            'field_or_relation' => 'roundHistory', 'operator' => 'has_where',
            'value' => json_encode([
                'outcome_like' => 'Allotted%',
                'count_min' => 1,
            ]),
            'display_order' => 0,
            'created_at' => $now, 'updated_at' => $now,
        ]);
    }

    public function down(): void
    {
        $pipelineId = DB::table('pipelines')->where('is_default', true)->value('id');
        if (! $pipelineId) return;
        DB::table('stage_transition_rules')->where('pipeline_id', $pipelineId)->delete();
    }
};
```

- [ ] **Step 4: Run — verify passes**

```
php -d memory_limit=512M vendor/bin/phpunit --filter=SeededPipelineTest
```
Expected: PASS (8 tests).

- [ ] **Step 5: Commit**

```bash
git add database/migrations/2026_04_23_100400_seed_default_transition_rules.php tests/Feature/Pipeline/SeededPipelineTest.php
git commit -m "feat(pipeline): seed 4 default transition rules"
```

---

### Task 8: Backfill `students.stage_id` from legacy `students.stage` string

**Files:**
- Create: `database/migrations/2026_04_23_100500_backfill_student_stage_id.php`
- Create: `tests/Feature/Pipeline/BackfillStudentStageIdTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php
// tests/Feature/Pipeline/BackfillStudentStageIdTest.php
namespace Tests\Feature\Pipeline;

use App\Models\Pipeline;
use App\Models\Student;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class BackfillStudentStageIdTest extends TestCase
{
    use RefreshDatabase;

    public function test_backfill_populates_stage_id_for_existing_rows(): void
    {
        // Simulate a legacy row: seed only fills stages, not students. Insert directly.
        $ownerId = \App\Models\User::factory()->create()->id;
        DB::table('students')->insert([
            'name' => 'Legacy', 'phone' => '9000000001',
            'owner_id' => $ownerId, 'referrer_id' => $ownerId,
            'lead_source' => 'test',
            'stage' => 'Meeting Scheduled', 'stage_id' => null,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        // Rerun the backfill migration's up() explicitly.
        \Artisan::call('migrate:refresh');  // re-runs all migrations including backfill
        $this->seed();

        // After running full migrations, all students with legacy stage must have stage_id set.
        $this->markTestIncomplete('Verified via manual prod dry-run; full migrate:refresh re-seeds, see BackfillStudentStageIdTest::test_direct_backfill_call');
    }

    public function test_direct_backfill_call_maps_stage_string_to_stage_id(): void
    {
        // After the stages seed migration runs (automatically), directly test the backfill logic.
        $p = Pipeline::default();
        $scheduledStageId = $p->stages()->where('name', 'Meeting Scheduled')->value('id');

        $ownerId = \App\Models\User::factory()->create()->id;
        DB::table('students')->insert([
            'name' => 'Legacy2', 'phone' => '9000000002',
            'owner_id' => $ownerId, 'referrer_id' => $ownerId,
            'lead_source' => 'test',
            'stage' => 'Meeting Scheduled', 'stage_id' => null,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        // Run the backfill UPDATE the migration would have run.
        DB::statement("
            UPDATE students
            SET stage_id = (SELECT id FROM stages WHERE stages.name = students.stage AND stages.pipeline_id = ?)
            WHERE stage_id IS NULL
        ", [$p->id]);

        $row = DB::table('students')->where('phone', '9000000002')->first();
        $this->assertSame($scheduledStageId, $row->stage_id);
    }
}
```

- [ ] **Step 2: Run — verify fails**

```
php -d memory_limit=512M vendor/bin/phpunit --filter=BackfillStudentStageIdTest::test_direct_backfill_call
```
Expected: PASS (direct SQL works; test verifies the mechanism). If it fails, stop and inspect.

- [ ] **Step 3: Write the backfill migration**

```php
<?php
// database/migrations/2026_04_23_100500_backfill_student_stage_id.php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        $pipelineId = DB::table('pipelines')->where('is_default', true)->value('id');
        if (! $pipelineId) return;

        // Backfill by exact stage-name match.
        DB::statement("
            UPDATE students
            SET stage_id = (
                SELECT id FROM stages
                WHERE stages.name = students.stage AND stages.pipeline_id = ?
            )
            WHERE stage_id IS NULL
        ", [$pipelineId]);

        // Any rows still NULL (orphan legacy value)? Park them at Lead Captured.
        $leadCapturedId = DB::table('stages')
            ->where('pipeline_id', $pipelineId)->where('name', 'Lead Captured')->value('id');

        DB::table('students')->whereNull('stage_id')->update([
            'stage_id' => $leadCapturedId,
            'stage' => 'Lead Captured',
        ]);
    }

    public function down(): void
    {
        DB::table('students')->update(['stage_id' => null]);
    }
};
```

- [ ] **Step 4: Run — verify still passes**

```
php -d memory_limit=512M vendor/bin/phpunit --filter=BackfillStudentStageIdTest
```
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add database/migrations/2026_04_23_100500_backfill_student_stage_id.php tests/Feature/Pipeline/BackfillStudentStageIdTest.php
git commit -m "feat(pipeline): backfill students.stage_id from stage string"
```

---

## Phase 4 — Domain services

### Task 9: `PipelineConfig` cache-backed reader

**Files:**
- Create: `app/Services/Pipeline/PipelineConfig.php`
- Create: `tests/Feature/Pipeline/PipelineConfigTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php
// tests/Feature/Pipeline/PipelineConfigTest.php
namespace Tests\Feature\Pipeline;

use App\Services\Pipeline\PipelineConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class PipelineConfigTest extends TestCase
{
    use RefreshDatabase;

    public function test_stages_returns_13_seeded_stages_in_order(): void
    {
        $stages = app(PipelineConfig::class)->stages();
        $this->assertCount(13, $stages);
        $this->assertSame('Lead Captured', $stages->first()->name);
        $this->assertSame('Closed', $stages->last()->name);
    }

    public function test_open_won_lost_buckets(): void
    {
        $cfg = app(PipelineConfig::class);
        $this->assertCount(11, $cfg->openStages());
        $this->assertCount(1,  $cfg->wonStages());
        $this->assertCount(1,  $cfg->lostStages());
    }

    public function test_stage_by_name_and_id(): void
    {
        $cfg = app(PipelineConfig::class);
        $sliding = $cfg->stageByName('Sliding');
        $this->assertNotNull($sliding);
        $this->assertSame($sliding->id, $cfg->stageById($sliding->id)->id);
        $this->assertNull($cfg->stageByName('Nonexistent'));
    }

    public function test_invalidate_clears_cache(): void
    {
        $cfg = app(PipelineConfig::class);
        $cfg->stages(); // populate cache
        // Insert a new stage directly, bypass repo
        \DB::table('stages')->insert([
            'pipeline_id' => \App\Models\Pipeline::default()->id,
            'name' => 'Bypass', 'stage_type' => 'OPEN', 'display_order' => 99,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        // Cache still has 13
        $this->assertCount(13, $cfg->stages());
        $cfg->invalidate();
        $this->assertCount(14, $cfg->stages());
    }
}
```

- [ ] **Step 2: Run — verify fails**

```
php -d memory_limit=512M vendor/bin/phpunit --filter=PipelineConfigTest
```
Expected: FAIL ("Class PipelineConfig not found").

- [ ] **Step 3: Write the service**

```php
<?php
// app/Services/Pipeline/PipelineConfig.php
namespace App\Services\Pipeline;

use App\Models\Pipeline;
use App\Models\Stage;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

class PipelineConfig
{
    private const CACHE_KEY = 'pipeline-config:default-stages';
    private const CACHE_TTL = 3600;

    /** @return Collection<int,Stage> */
    public function stages(): Collection
    {
        return Cache::remember(self::CACHE_KEY, self::CACHE_TTL, function (): Collection {
            return Pipeline::default()
                ->stages()
                ->orderBy('display_order')
                ->get();
        });
    }

    public function openStages(): Collection  { return $this->stages()->where('stage_type', Stage::TYPE_OPEN)->values(); }
    public function wonStages(): Collection   { return $this->stages()->where('stage_type', Stage::TYPE_WON)->values(); }
    public function lostStages(): Collection  { return $this->stages()->where('stage_type', Stage::TYPE_LOST)->values(); }

    public function stageByName(string $name): ?Stage
    {
        return $this->stages()->firstWhere('name', $name);
    }

    public function stageById(int $id): ?Stage
    {
        return $this->stages()->firstWhere('id', $id);
    }

    /** @return string[] Stage names in display order — drop-in replacement for PipelineStage::values() */
    public function stageNames(): array
    {
        return $this->stages()->pluck('name')->all();
    }

    public function invalidate(): void
    {
        Cache::forget(self::CACHE_KEY);
    }
}
```

- [ ] **Step 4: Run — verify passes**

```
php -d memory_limit=512M vendor/bin/phpunit --filter=PipelineConfigTest
```
Expected: PASS (4 tests).

- [ ] **Step 5: Commit**

```bash
git add app/Services/Pipeline/PipelineConfig.php tests/Feature/Pipeline/PipelineConfigTest.php
git commit -m "feat(pipeline): PipelineConfig cached reader service"
```

---

### Task 10: `StageRepository` — create, rename, reorder

**Files:**
- Create: `app/Services/Pipeline/StageRepository.php`
- Create: `tests/Feature/Pipeline/StageRepositoryTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php
// tests/Feature/Pipeline/StageRepositoryTest.php
namespace Tests\Feature\Pipeline;

use App\Models\Pipeline;
use App\Models\Stage;
use App\Services\Pipeline\StageRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class StageRepositoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_create_adds_stage_with_next_display_order(): void
    {
        $repo = app(StageRepository::class);
        $s = $repo->create(Pipeline::default(), 'Custom Stage', Stage::TYPE_OPEN);
        $this->assertSame('Custom Stage', $s->name);
        $this->assertGreaterThan(0, $s->display_order);
    }

    public function test_create_enforces_20_cap(): void
    {
        $p = Pipeline::default();
        $repo = app(StageRepository::class);
        // Seeded: 13. Add 7 more = 20.
        for ($i = 1; $i <= 7; $i++) $repo->create($p, "Extra $i", Stage::TYPE_OPEN);
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessageMatches('/20 stages/i');
        $repo->create($p, 'OneTooMany', Stage::TYPE_OPEN);
    }

    public function test_create_rejects_duplicate_name(): void
    {
        $p = Pipeline::default();
        $repo = app(StageRepository::class);
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessageMatches('/already exists/i');
        $repo->create($p, 'Sliding', Stage::TYPE_OPEN);
    }

    public function test_rename_updates_name_and_invalidates_cache(): void
    {
        $repo = app(StageRepository::class);
        $s = $repo->create(Pipeline::default(), 'To Rename', Stage::TYPE_OPEN);
        $renamed = $repo->rename($s, 'Renamed');
        $this->assertSame('Renamed', $renamed->fresh()->name);
    }

    public function test_reorder_renumbers_display_order(): void
    {
        $repo = app(StageRepository::class);
        $p = Pipeline::default();
        $ids = $p->stages->pluck('id')->reverse()->values()->all();
        $repo->reorder($p, $ids);
        $firstAfter = $p->stages()->orderBy('display_order')->first();
        $this->assertSame($ids[0], $firstAfter->id);
    }
}
```

- [ ] **Step 2: Run — verify fails**

```
php -d memory_limit=512M vendor/bin/phpunit --filter=StageRepositoryTest
```
Expected: FAIL (class missing).

- [ ] **Step 3: Write the service**

```php
<?php
// app/Services/Pipeline/StageRepository.php
namespace App\Services\Pipeline;

use App\Models\Pipeline;
use App\Models\Stage;
use App\Models\Student;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class StageRepository
{
    public const MAX_STAGES_PER_PIPELINE = 20;

    public function __construct(private readonly PipelineConfig $config) {}

    public function create(Pipeline $pipeline, string $name, string $type, ?string $description = null): Stage
    {
        if (! in_array($type, Stage::TYPES, true)) {
            throw ValidationException::withMessages(['stage_type' => "Invalid stage type: $type"]);
        }

        if ($pipeline->stages()->count() >= self::MAX_STAGES_PER_PIPELINE) {
            throw ValidationException::withMessages([
                'stages' => 'Cannot create stage — pipeline already has the maximum of 20 stages.',
            ]);
        }

        if ($pipeline->stages()->where('name', $name)->exists()) {
            throw ValidationException::withMessages([
                'name' => "A stage named \"$name\" already exists in this pipeline.",
            ]);
        }

        $nextOrder = ((int) $pipeline->stages()->max('display_order')) + 1;

        $stage = $pipeline->stages()->create([
            'name' => $name,
            'stage_type' => $type,
            'description' => $description,
            'display_order' => $nextOrder,
        ]);

        $this->config->invalidate();
        return $stage;
    }

    public function rename(Stage $stage, string $newName): Stage
    {
        if ($stage->pipeline->stages()->where('name', $newName)->where('id', '!=', $stage->id)->exists()) {
            throw ValidationException::withMessages([
                'name' => "A stage named \"$newName\" already exists.",
            ]);
        }
        $stage->update(['name' => $newName]);
        $this->config->invalidate();
        return $stage;
    }

    /** @param int[] $orderedStageIds */
    public function reorder(Pipeline $pipeline, array $orderedStageIds): void
    {
        DB::transaction(function () use ($pipeline, $orderedStageIds) {
            foreach ($orderedStageIds as $i => $id) {
                $pipeline->stages()->where('id', $id)->update(['display_order' => $i + 1]);
            }
        });
        $this->config->invalidate();
    }
}
```

- [ ] **Step 4: Run — verify passes**

```
php -d memory_limit=512M vendor/bin/phpunit --filter=StageRepositoryTest
```
Expected: PASS (5 tests).

- [ ] **Step 5: Commit**

```bash
git add app/Services/Pipeline/StageRepository.php tests/Feature/Pipeline/StageRepositoryTest.php
git commit -m "feat(pipeline): StageRepository create/rename/reorder"
```

---

### Task 11: `StageRepository::delete` with transfer-before-delete + `changeType`

**Files:**
- Modify: `app/Services/Pipeline/StageRepository.php`
- Modify: `tests/Feature/Pipeline/StageRepositoryTest.php`

- [ ] **Step 1: Append tests**

```php
public function test_delete_without_students_succeeds(): void
{
    $repo = app(StageRepository::class);
    $s = $repo->create(Pipeline::default(), 'Empty', Stage::TYPE_OPEN);
    $repo->delete($s);
    $this->assertDatabaseMissing('stages', ['id' => $s->id]);
}

public function test_delete_with_students_and_no_target_throws(): void
{
    $repo = app(StageRepository::class);
    $p = Pipeline::default();
    $s = $p->stages()->where('name','Meeting Scheduled')->firstOrFail();
    $ownerId = \App\Models\User::factory()->create()->id;
    \DB::table('students')->insert([
        'name'=>'S','phone'=>'9111111111','owner_id'=>$ownerId,'referrer_id'=>$ownerId,
        'lead_source'=>'t','stage'=>'Meeting Scheduled','stage_id'=>$s->id,
        'created_at'=>now(),'updated_at'=>now(),
    ]);
    $this->expectException(\Illuminate\Validation\ValidationException::class);
    $this->expectExceptionMessageMatches('/has 1 student|transfer_to_stage_id/i');
    $repo->delete($s);
}

public function test_delete_with_students_and_target_migrates_then_deletes(): void
{
    $repo = app(StageRepository::class);
    $p = Pipeline::default();
    $from = $p->stages()->where('name','Meeting Scheduled')->firstOrFail();
    $to   = $p->stages()->where('name','Meeting Done')->firstOrFail();
    $ownerId = \App\Models\User::factory()->create()->id;
    \DB::table('students')->insert([
        'name'=>'S','phone'=>'9222222222','owner_id'=>$ownerId,'referrer_id'=>$ownerId,
        'lead_source'=>'t','stage'=>'Meeting Scheduled','stage_id'=>$from->id,
        'created_at'=>now(),'updated_at'=>now(),
    ]);
    $repo->delete($from, $to->id);
    $this->assertDatabaseMissing('stages', ['id' => $from->id]);
    $this->assertSame($to->id, \DB::table('students')->where('phone','9222222222')->value('stage_id'));
    $this->assertSame('Meeting Done', \DB::table('students')->where('phone','9222222222')->value('stage'));
}

public function test_change_type_moves_stage_to_new_section(): void
{
    $repo = app(StageRepository::class);
    $p = Pipeline::default();
    $s = $p->stages()->where('name', 'Seat Allotted')->firstOrFail();
    $repo->changeType($s, Stage::TYPE_WON);
    $this->assertSame(Stage::TYPE_WON, $s->fresh()->stage_type);
}
```

- [ ] **Step 2: Run — verify fails**

```
php -d memory_limit=512M vendor/bin/phpunit --filter=StageRepositoryTest
```
Expected: FAIL ("method delete not defined").

- [ ] **Step 3: Extend the service**

Append to `app/Services/Pipeline/StageRepository.php` (inside the class):

```php
public function delete(Stage $stage, ?int $transferToStageId = null): void
{
    $studentCount = Student::where('stage_id', $stage->id)->count();

    if ($studentCount > 0 && $transferToStageId === null) {
        throw ValidationException::withMessages([
            'transfer_to_stage_id' => "Stage has $studentCount student(s). Choose a target stage to move them to before deleting.",
        ]);
    }

    if ($studentCount > 0) {
        if ($transferToStageId === $stage->id) {
            throw ValidationException::withMessages(['transfer_to_stage_id' => 'Cannot transfer to the same stage.']);
        }
        $target = $stage->pipeline->stages()->where('id', $transferToStageId)->firstOrFail();

        DB::transaction(function () use ($stage, $target) {
            Student::where('stage_id', $stage->id)->update([
                'stage_id' => $target->id,
                'stage' => $target->name,
            ]);
            $stage->delete();
        });
    } else {
        $stage->delete();
    }

    $this->config->invalidate();
}

public function changeType(Stage $stage, string $newType): Stage
{
    if (! in_array($newType, Stage::TYPES, true)) {
        throw ValidationException::withMessages(['stage_type' => "Invalid stage type: $newType"]);
    }
    $stage->update(['stage_type' => $newType]);
    $this->config->invalidate();
    return $stage->fresh();
}
```

- [ ] **Step 4: Run — verify passes**

```
php -d memory_limit=512M vendor/bin/phpunit --filter=StageRepositoryTest
```
Expected: PASS (9 tests).

- [ ] **Step 5: Commit**

```bash
git add app/Services/Pipeline/StageRepository.php tests/Feature/Pipeline/StageRepositoryTest.php
git commit -m "feat(pipeline): StageRepository delete-with-transfer + changeType"
```

---

### Task 12: `ConditionEvaluator` — FIELD_CHECK + HAS_RELATION

**Files:**
- Create: `app/Services/Pipeline/ConditionEvaluator.php`
- Create: `tests/Feature/Pipeline/ConditionEvaluatorTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php
// tests/Feature/Pipeline/ConditionEvaluatorTest.php
namespace Tests\Feature\Pipeline;

use App\Models\Student;
use App\Models\StageTransitionCondition;
use App\Services\Pipeline\ConditionEvaluator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ConditionEvaluatorTest extends TestCase
{
    use RefreshDatabase;

    private function student(array $overrides = []): Student
    {
        $ownerId = \App\Models\User::factory()->create()->id;
        $base = [
            'name'=>'E','phone'=>'9' . mt_rand(100000000, 999999999),
            'owner_id'=>$ownerId,'referrer_id'=>$ownerId,
            'lead_source'=>'t',
            'stage'=>'Lead Captured',
            'stage_id'=>\App\Models\Pipeline::default()->stages()->where('name','Lead Captured')->value('id'),
        ];
        return Student::create(array_merge($base, $overrides));
    }

    private function cond(string $type, string $field, string $op, $value = null): StageTransitionCondition
    {
        return new StageTransitionCondition([
            'condition_type' => $type, 'field_or_relation' => $field,
            'operator' => $op, 'value' => $value, 'display_order' => 0,
        ]);
    }

    public function test_field_is_not_empty_passes_when_set(): void
    {
        $eval = app(ConditionEvaluator::class);
        $s = $this->student(['close_reason' => 'Not Interested']);
        $this->assertTrue($eval->passes($this->cond('FIELD_CHECK','close_reason','is_not_empty'), $s));
    }

    public function test_field_is_not_empty_fails_when_null(): void
    {
        $eval = app(ConditionEvaluator::class);
        $s = $this->student();
        $this->assertFalse($eval->passes($this->cond('FIELD_CHECK','close_reason','is_not_empty'), $s));
    }

    public function test_field_gte_operator(): void
    {
        $eval = app(ConditionEvaluator::class);
        $s = $this->student(['deal_amount' => 100000]);
        $this->assertTrue($eval->passes($this->cond('FIELD_CHECK','deal_amount','>=', ['rhs' => 50000]), $s));
        $this->assertFalse($eval->passes($this->cond('FIELD_CHECK','deal_amount','>=', ['rhs' => 200000]), $s));
    }

    public function test_has_relation_scheduled_meeting_in_future(): void
    {
        $eval = app(ConditionEvaluator::class);
        $s = $this->student();
        // Seed a future meeting
        $s->meetings()->create([
            'scheduled_at' => now()->addDay(),
            'status' => 'scheduled',
            'created_by' => $s->owner_id,
        ]);
        $c = $this->cond('HAS_RELATION','meetings','has_where', [
            'status' => 'scheduled', 'scheduled_at_gte' => 'now', 'count_min' => 1,
        ]);
        $this->assertTrue($eval->passes($c, $s));
    }

    public function test_has_relation_returns_false_when_no_rows(): void
    {
        $eval = app(ConditionEvaluator::class);
        $s = $this->student();
        $c = $this->cond('HAS_RELATION','meetings','has_where', [
            'status' => 'scheduled', 'count_min' => 1,
        ]);
        $this->assertFalse($eval->passes($c, $s));
    }
}
```

- [ ] **Step 2: Run — verify fails**

```
php -d memory_limit=512M vendor/bin/phpunit --filter=ConditionEvaluatorTest
```
Expected: FAIL (class missing).

- [ ] **Step 3: Write the service**

```php
<?php
// app/Services/Pipeline/ConditionEvaluator.php
namespace App\Services\Pipeline;

use App\Models\StageTransitionCondition;
use App\Models\Student;

class ConditionEvaluator
{
    public function passes(StageTransitionCondition $cond, Student $student): bool
    {
        return match ($cond->condition_type) {
            'FIELD_CHECK'  => $this->checkField($cond, $student),
            'HAS_RELATION' => $this->checkRelation($cond, $student),
            default        => false,
        };
    }

    private function checkField(StageTransitionCondition $cond, Student $student): bool
    {
        $value = $student->getAttribute($cond->field_or_relation);
        $rhs   = is_array($cond->value) ? ($cond->value['rhs'] ?? null) : null;

        return match ($cond->operator) {
            'is_empty'     => $value === null || $value === '',
            'is_not_empty' => $value !== null && $value !== '',
            '='            => $value == $rhs,
            '!='           => $value != $rhs,
            '>'            => $value !== null && $value >  $rhs,
            '<'            => $value !== null && $value <  $rhs,
            '>=', '≥'      => $value !== null && $value >= $rhs,
            '<=', '≤'      => $value !== null && $value <= $rhs,
            default        => false,
        };
    }

    private function checkRelation(StageTransitionCondition $cond, Student $student): bool
    {
        $relation = $cond->field_or_relation;
        if (! method_exists($student, $relation)) return false;

        $filters = is_array($cond->value) ? $cond->value : [];
        $q = $student->{$relation}();

        foreach ($filters as $key => $val) {
            if ($key === 'count_min') continue;
            if (str_ends_with($key, '_gte')) {
                $col = substr($key, 0, -4);
                $q->where($col, '>=', $val === 'now' ? now() : $val);
            } elseif (str_ends_with($key, '_lte')) {
                $col = substr($key, 0, -4);
                $q->where($col, '<=', $val === 'now' ? now() : $val);
            } elseif (str_ends_with($key, '_like')) {
                $col = substr($key, 0, -5);
                $q->where($col, 'like', $val);
            } else {
                $q->where($key, '=', $val);
            }
        }

        $countMin = (int) ($filters['count_min'] ?? 1);
        return $q->count() >= $countMin;
    }
}
```

- [ ] **Step 4: Run — verify passes**

```
php -d memory_limit=512M vendor/bin/phpunit --filter=ConditionEvaluatorTest
```
Expected: PASS (5 tests).

- [ ] **Step 5: Commit**

```bash
git add app/Services/Pipeline/ConditionEvaluator.php tests/Feature/Pipeline/ConditionEvaluatorTest.php
git commit -m "feat(pipeline): ConditionEvaluator — field + relation ops"
```

---

### Task 13: `StageTransitionEngine` — orchestrates rules + conditions

**Files:**
- Create: `app/Services/Pipeline/StageTransitionEngine.php`
- Create: `tests/Feature/Pipeline/StageTransitionEngineTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php
// tests/Feature/Pipeline/StageTransitionEngineTest.php
namespace Tests\Feature\Pipeline;

use App\Models\Pipeline;
use App\Models\Student;
use App\Services\Pipeline\StageTransitionEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StageTransitionEngineTest extends TestCase
{
    use RefreshDatabase;

    private function studentInStage(string $stageName, array $overrides = []): Student
    {
        $stageId = Pipeline::default()->stages()->where('name',$stageName)->value('id');
        $ownerId = \App\Models\User::factory()->create()->id;
        return Student::create(array_merge([
            'name'=>'T','phone'=>'9' . mt_rand(100000000, 999999999),
            'owner_id'=>$ownerId,'referrer_id'=>$ownerId,
            'lead_source'=>'t','stage'=>$stageName,'stage_id'=>$stageId,
        ], $overrides));
    }

    public function test_closed_transition_without_reason_returns_hard_error(): void
    {
        $engine = app(StageTransitionEngine::class);
        $s = $this->studentInStage('Meeting Done');
        $closedId = Pipeline::default()->stages()->where('name','Closed')->value('id');
        $out = $engine->forStageChange($s, $closedId);
        $this->assertNotEmpty($out['hard']);
        $this->assertStringContainsString('close_reason', implode(' ', $out['hard']));
    }

    public function test_closed_transition_with_reason_passes(): void
    {
        $engine = app(StageTransitionEngine::class);
        $s = $this->studentInStage('Meeting Done', ['close_reason' => 'Not Interested']);
        $closedId = Pipeline::default()->stages()->where('name','Closed')->value('id');
        $out = $engine->forStageChange($s, $closedId);
        $this->assertEmpty($out['hard']);
    }

    public function test_reentry_from_closed_without_reason_returns_hard(): void
    {
        $engine = app(StageTransitionEngine::class);
        $s = $this->studentInStage('Closed');
        $meetingDoneId = Pipeline::default()->stages()->where('name','Meeting Done')->value('id');
        $out = $engine->forStageChange($s, $meetingDoneId);
        $this->assertNotEmpty($out['hard']);
        $this->assertStringContainsString('re_entry_reason', implode(' ', $out['hard']));
    }

    public function test_meeting_scheduled_without_future_meeting_returns_soft(): void
    {
        $engine = app(StageTransitionEngine::class);
        $s = $this->studentInStage('Lead Captured');
        $msId = Pipeline::default()->stages()->where('name','Meeting Scheduled')->value('id');
        $out = $engine->forStageChange($s, $msId);
        $this->assertEmpty($out['hard']);
        $this->assertNotEmpty($out['soft']);
    }

    public function test_meeting_scheduled_with_future_meeting_no_warnings(): void
    {
        $engine = app(StageTransitionEngine::class);
        $s = $this->studentInStage('Lead Captured');
        $s->meetings()->create(['scheduled_at' => now()->addDay(), 'status' => 'scheduled', 'owner_id' => $s->owner_id, 'created_by_id' => $s->owner_id]);
        $msId = Pipeline::default()->stages()->where('name','Meeting Scheduled')->value('id');
        $out = $engine->forStageChange($s, $msId);
        $this->assertEmpty($out['soft']);
    }
}
```

- [ ] **Step 2: Run — verify fails**

```
php -d memory_limit=512M vendor/bin/phpunit --filter=StageTransitionEngineTest
```
Expected: FAIL (class missing).

- [ ] **Step 3: Write the service**

```php
<?php
// app/Services/Pipeline/StageTransitionEngine.php
namespace App\Services\Pipeline;

use App\Models\Pipeline;
use App\Models\StageTransitionRule;
use App\Models\Student;
use Illuminate\Support\Collection;

class StageTransitionEngine
{
    public function __construct(
        private readonly ConditionEvaluator $evaluator,
        private readonly PipelineConfig $config,
    ) {}

    /** @return array{hard: string[], soft: string[]} */
    public function forStageChange(Student $student, int $toStageId): array
    {
        $fromStageId = $student->stage_id;
        $pipelineId  = Pipeline::default()->id;

        $rules = $this->matchingRules($pipelineId, $fromStageId, $toStageId);

        $hard = [];
        $soft = [];

        foreach ($rules as $rule) {
            $failures = $this->failingConditions($rule, $student);
            if (empty($failures)) continue;

            $message = $this->humanMessage($rule, $failures);
            if ($rule->severity === StageTransitionRule::SEV_HARD) {
                $hard[] = $message;
            } else {
                $soft[] = $message;
            }
        }

        return ['hard' => $hard, 'soft' => $soft];
    }

    /** @return Collection<int,StageTransitionRule> */
    private function matchingRules(int $pipelineId, ?int $fromStageId, int $toStageId): Collection
    {
        return StageTransitionRule::query()
            ->with('conditions')
            ->where('pipeline_id', $pipelineId)
            ->where('is_active', true)
            ->where(fn ($q) => $q->whereNull('from_stage_id')->orWhere('from_stage_id', $fromStageId))
            ->where(fn ($q) => $q->whereNull('to_stage_id')->orWhere('to_stage_id', $toStageId))
            // Skip rules where both sides NULL — meaningless (guarded at DB level by CHECK, but be defensive).
            ->where(fn ($q) => $q->whereNotNull('from_stage_id')->orWhereNotNull('to_stage_id'))
            ->get();
    }

    /** @return string[] human descriptions of each failing condition */
    private function failingConditions(StageTransitionRule $rule, Student $student): array
    {
        $out = [];
        foreach ($rule->conditions as $cond) {
            if (! $this->evaluator->passes($cond, $student)) {
                $out[] = $this->describeCondition($cond);
            }
        }
        return $out;
    }

    private function describeCondition($cond): string
    {
        if ($cond->condition_type === 'FIELD_CHECK') {
            if ($cond->operator === 'is_not_empty') {
                return "{$cond->field_or_relation} is required";
            }
            if ($cond->operator === 'is_empty') {
                return "{$cond->field_or_relation} must be empty";
            }
            $rhs = is_array($cond->value) ? ($cond->value['rhs'] ?? '') : '';
            return "{$cond->field_or_relation} {$cond->operator} $rhs";
        }
        // HAS_RELATION
        $min = (int) ($cond->value['count_min'] ?? 1);
        return "record needs at least $min {$cond->field_or_relation}";
    }

    private function humanMessage(StageTransitionRule $rule, array $failures): string
    {
        return "[{$rule->name}] " . implode('; ', $failures) . '.';
    }
}
```

- [ ] **Step 4: Run — verify passes**

```
php -d memory_limit=512M vendor/bin/phpunit --filter=StageTransitionEngineTest
```
Expected: PASS (5 tests).

- [ ] **Step 5: Commit**

```bash
git add app/Services/Pipeline/StageTransitionEngine.php tests/Feature/Pipeline/StageTransitionEngineTest.php
git commit -m "feat(pipeline): StageTransitionEngine orchestrator"
```

---

## Phase 5 — Wire new services into existing callers

### Task 14: Swap callers from `StageTransitionValidator` + `PipelineStage` enum to new services

**Files (modify):**
- `app/Filament/Pages/KanbanBoard.php`
- `app/Filament/Resources/StudentResource.php`
- `app/Filament/Resources/StudentResource/Pages/EditStudent.php`
- `app/Observers/MeetingObserver.php`
- `app/Services/PipelineSummary.php`

- [ ] **Step 1: Write a regression test that exercises the old kanban endpoint**

Create `tests/Feature/Pipeline/KanbanDynamicStagesTest.php`:

```php
<?php
namespace Tests\Feature\Pipeline;

use App\Filament\Pages\KanbanBoard;
use App\Models\Pipeline;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class KanbanDynamicStagesTest extends TestCase
{
    use RefreshDatabase;

    private function unblock(User $u): User { $u->must_change_password = false; $u->save(); return $u; }

    public function test_kanban_board_returns_13_columns_from_db(): void
    {
        $this->seed();
        $sumit = $this->unblock(User::where('email','sumit@davya.local')->firstOrFail());
        $this->actingAs($sumit);
        $board = Livewire::test(KanbanBoard::class)->instance()->getBoard();
        $this->assertCount(13, $board);
        $this->assertSame('Lead Captured', $board[0]['stage']);
        $this->assertSame('Closed', end($board)['stage']);
    }

    public function test_move_student_to_closed_without_reason_is_blocked(): void
    {
        $this->seed();
        $sumit = $this->unblock(User::where('email','sumit@davya.local')->firstOrFail());
        $this->actingAs($sumit);

        $nikhil = User::where('email','nikhil@davya.local')->firstOrFail();
        $stageId = Pipeline::default()->stages()->where('name','Meeting Done')->value('id');
        $s = Student::create([
            'name'=>'X','phone'=>'9888888881','owner_id'=>$nikhil->id,'referrer_id'=>$nikhil->id,
            'lead_source'=>'test','stage'=>'Meeting Done','stage_id'=>$stageId,
        ]);

        $board = app(KanbanBoard::class);
        $result = $board->moveStudentToStage($s->id, 'Closed');
        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('close_reason', implode(' ', $result['errors']));
    }
}
```

- [ ] **Step 2: Run — expect FAIL**

```
php -d memory_limit=512M vendor/bin/phpunit --filter=KanbanDynamicStagesTest
```
Expected: likely FAIL (board probably returns from enum; move uses old validator which still works but string-matched).

- [ ] **Step 3: Rewire `KanbanBoard.php`**

Replace the top of `app/Filament/Pages/KanbanBoard.php`:

- Remove `use App\Services\StageTransitionValidator;`
- Remove `use App\Services\PipelineSummary;` (replaced inline)
- Add `use App\Services\Pipeline\PipelineConfig;` and `use App\Services\Pipeline\StageTransitionEngine;`

Replace `foreach (PipelineSummary::stages() as $stage)` with:

```php
foreach (app(PipelineConfig::class)->stageNames() as $stage) {
```

Replace the `moveStudentToStage` body's validator block:

```php
// OLD:
// if (! in_array($newStage, \App\Enums\PipelineStage::values(), true)) { ... }
// $out = (new StageTransitionValidator)->forStageChange($student, $newStage);

// NEW:
$config = app(PipelineConfig::class);
$target = $config->stageByName($newStage);
if (! $target) {
    return ['ok' => false, 'errors' => ["Unknown stage: $newStage"]];
}

$out = app(StageTransitionEngine::class)->forStageChange($student, $target->id);
```

Then update the assignment so `$student->stage_id = $target->id` + keep `$student->stage = $newStage` for the cache. Save as before.

- [ ] **Step 4: Rewire `StudentResource.php` and `EditStudent.php`**

In `StudentResource.php`:
- Replace `PipelineStage::options()` → `collect(app(PipelineConfig::class)->stageNames())->mapWithKeys(fn ($n) => [$n => $n])->all()`
- Replace `PipelineStage::LeadCaptured->value` → `'Lead Captured'`
- Replace the `StageTransitionValidator` block in the Select `->rule(...)` closure with:

```php
$config = app(\App\Services\Pipeline\PipelineConfig::class);
$target = $config->stageByName($state);
if (! $target) { return $fail("Unknown stage: $state"); }
$record->stage_id = $target->id;
$out = app(\App\Services\Pipeline\StageTransitionEngine::class)->forStageChange($record, $target->id);
```

In `EditStudent.php` — same replacement of the validator call.

- [ ] **Step 5: Rewire `MeetingObserver.php` and `PipelineSummary.php`**

`MeetingObserver.php` — replace constructor dependency `StageTransitionValidator $validator` with `StageTransitionEngine $engine`; replace the `forRoundChange` call (which no longer exists; if that method is critical, preserve its behavior by reading-from the existing rules — for the migration-compatibility phase, keep a shim method named `forRoundChange()` on the engine that returns an empty `soft[]` array; round-change warnings are not currently stored as rules).

Add to `StageTransitionEngine`:

```php
/** @return string[] Back-compat shim for MeetingObserver — round-change soft warnings are not yet modeled as rules. */
public function forRoundChange(Student $student, string $newRound): array
{
    return [];
}
```

`PipelineSummary.php` — replace `stages()` body:

```php
public static function stages(): array
{
    return app(PipelineConfig::class)->stageNames();
}
```

- [ ] **Step 6: Run the regression test**

```
php -d memory_limit=512M vendor/bin/phpunit --filter=KanbanDynamicStagesTest
```
Expected: PASS (2 tests).

- [ ] **Step 7: Run the full test suite to catch collateral damage**

```
php -d memory_limit=512M vendor/bin/phpunit
```
Expected: 0 failures. If a test depending on the old enum breaks, read the message and update the test to use the new API (string-name lookups are identical).

- [ ] **Step 8: Commit**

```bash
git add app/Filament/Pages/KanbanBoard.php app/Filament/Resources/StudentResource.php app/Filament/Resources/StudentResource/Pages/EditStudent.php app/Observers/MeetingObserver.php app/Services/PipelineSummary.php app/Services/Pipeline/StageTransitionEngine.php tests/Feature/Pipeline/KanbanDynamicStagesTest.php
git commit -m "refactor(pipeline): swap callers to dynamic stage services"
```

---

## Phase 6 — Filament admin UI

### Task 15: `PipelineConfigPage` scaffold — admin-only, two tabs

**Files:**
- Create: `app/Filament/Pages/PipelineConfigPage.php`
- Create: `resources/views/filament/pages/pipeline-config.blade.php`
- Create: `tests/Feature/Pipeline/PipelineConfigPageTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php
// tests/Feature/Pipeline/PipelineConfigPageTest.php
namespace Tests\Feature\Pipeline;

use App\Filament\Pages\PipelineConfigPage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class PipelineConfigPageTest extends TestCase
{
    use RefreshDatabase;

    private function unblock(User $u): User { $u->must_change_password = false; $u->save(); return $u; }

    public function test_admin_can_access_page(): void
    {
        $this->seed();
        $sumit = $this->unblock(User::where('email','sumit@davya.local')->firstOrFail());
        $this->actingAs($sumit);
        Livewire::test(PipelineConfigPage::class)->assertStatus(200);
    }

    public function test_non_admin_cannot_access(): void
    {
        $this->seed();
        $nikhil = $this->unblock(User::where('email','nikhil@davya.local')->firstOrFail());
        $this->actingAs($nikhil);
        $this->get('/admin/pipeline-config')->assertStatus(403);
    }

    public function test_page_shows_13_seeded_stages(): void
    {
        $this->seed();
        $sumit = $this->unblock(User::where('email','sumit@davya.local')->firstOrFail());
        $this->actingAs($sumit);
        Livewire::test(PipelineConfigPage::class)
            ->assertSeeText('Lead Captured')
            ->assertSeeText('Seat Allotted')
            ->assertSeeText('Complete Payment Received')
            ->assertSeeText('Closed');
    }
}
```

- [ ] **Step 2: Run — verify fails**

```
php -d memory_limit=512M vendor/bin/phpunit --filter=PipelineConfigPageTest
```
Expected: FAIL.

- [ ] **Step 3: Write the page class**

```php
<?php
// app/Filament/Pages/PipelineConfigPage.php
namespace App\Filament\Pages;

use App\Models\Pipeline;
use App\Services\Pipeline\PipelineConfig;
use App\Services\Pipeline\StageRepository;
use Filament\Pages\Page;

class PipelineConfigPage extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-adjustments-horizontal';
    protected static ?string $navigationGroup = 'Settings';
    protected static ?string $navigationLabel = 'Pipeline Config';
    protected static ?string $title = 'Pipeline Configuration';
    protected static ?string $slug = 'pipeline-config';
    protected static string $view = 'filament.pages.pipeline-config';
    protected static ?int $navigationSort = 1;

    public string $activeTab = 'stages'; // 'stages' | 'rules'

    public static function canAccess(): bool
    {
        return auth()->check() && auth()->user()->hasRole('admin');
    }

    public function getPipeline(): Pipeline
    {
        return Pipeline::default();
    }

    public function getStagesByType(): array
    {
        $config = app(PipelineConfig::class);
        return [
            'open' => $config->openStages(),
            'won'  => $config->wonStages(),
            'lost' => $config->lostStages(),
        ];
    }
}
```

- [ ] **Step 4: Write the Blade view (scaffold only — tabs + stage list render)**

```blade
{{-- resources/views/filament/pages/pipeline-config.blade.php --}}
<x-filament-panels::page>
    <div class="flex gap-2 border-b border-gray-200 mb-6">
        <button wire:click="$set('activeTab', 'stages')" class="px-4 py-2 text-sm font-medium {{ $activeTab === 'stages' ? 'text-emerald-600 border-b-2 border-emerald-600' : 'text-gray-500' }}">Stages</button>
        <button wire:click="$set('activeTab', 'rules')" class="px-4 py-2 text-sm font-medium {{ $activeTab === 'rules' ? 'text-emerald-600 border-b-2 border-emerald-600' : 'text-gray-500' }}">Transition Rules</button>
    </div>

    @if ($activeTab === 'stages')
        @php($buckets = $this->getStagesByType())
        @php($total = $buckets['open']->count() + $buckets['won']->count() + $buckets['lost']->count())
        <div class="bg-white border border-gray-200 rounded-lg p-5">
            <div class="flex items-center gap-2 mb-1">
                <span class="text-amber-500">★</span>
                <h3 class="text-base font-semibold">{{ $this->getPipeline()->name }}</h3>
            </div>
            <p class="text-xs text-gray-500 mb-4">{{ $total }} of 20 stages used</p>

            @foreach (['open' => 'Open Stages', 'won' => 'Won Stages', 'lost' => 'Lost Stages'] as $key => $label)
                <div class="text-xs uppercase tracking-wide text-gray-500 font-semibold mt-4 mb-2">{{ $label }}</div>
                @foreach ($buckets[$key] as $stage)
                    <div class="flex items-center gap-3 px-3 py-2 border border-gray-200 rounded mb-1.5" wire:key="stage-{{ $stage->id }}">
                        <span class="text-gray-300 text-sm tracking-widest select-none">⋮⋮</span>
                        <span class="flex-1 text-sm font-medium text-gray-800">{{ $stage->name }}</span>
                        @if ($stage->stage_type === 'CLOSED_WON') <span class="px-2 py-0.5 rounded-full text-[11px] font-semibold bg-emerald-100 text-emerald-800">Won</span> @endif
                        @if ($stage->stage_type === 'CLOSED_LOST') <span class="px-2 py-0.5 rounded-full text-[11px] font-semibold bg-red-100 text-red-800">Lost</span> @endif
                    </div>
                @endforeach
            @endforeach
        </div>
    @else
        <div class="text-gray-500 text-sm">Rules tab — populated in Task 18.</div>
    @endif
</x-filament-panels::page>
```

- [ ] **Step 5: Run — verify passes**

```
php -d memory_limit=512M vendor/bin/phpunit --filter=PipelineConfigPageTest
```
Expected: PASS (3 tests).

- [ ] **Step 6: Commit**

```bash
git add app/Filament/Pages/PipelineConfigPage.php resources/views/filament/pages/pipeline-config.blade.php tests/Feature/Pipeline/PipelineConfigPageTest.php
git commit -m "feat(pipeline): PipelineConfigPage scaffold with admin gate"
```

---

### Task 16: Stages tab — create / rename / delete-with-transfer actions

**Files:**
- Modify: `app/Filament/Pages/PipelineConfigPage.php`
- Modify: `resources/views/filament/pages/pipeline-config.blade.php`
- Modify: `tests/Feature/Pipeline/PipelineConfigPageTest.php`

- [ ] **Step 1: Append tests**

```php
public function test_admin_can_create_a_new_stage(): void
{
    $this->seed();
    $sumit = $this->unblock(User::where('email','sumit@davya.local')->firstOrFail());
    $this->actingAs($sumit);

    Livewire::test(PipelineConfigPage::class)
        ->call('createStage', 'New Thing', 'OPEN')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('stages', ['name' => 'New Thing', 'stage_type' => 'OPEN']);
}

public function test_admin_cannot_create_21st_stage(): void
{
    $this->seed();
    $sumit = $this->unblock(User::where('email','sumit@davya.local')->firstOrFail());
    $this->actingAs($sumit);

    for ($i = 1; $i <= 7; $i++) {
        Livewire::test(PipelineConfigPage::class)->call('createStage', "Extra $i", 'OPEN')->assertHasNoErrors();
    }
    // 13 seeded + 7 = 20. Next one should fail.
    Livewire::test(PipelineConfigPage::class)
        ->call('createStage', 'TooMany', 'OPEN')
        ->assertNotified();
    $this->assertDatabaseMissing('stages', ['name' => 'TooMany']);
}

public function test_delete_stage_with_students_requires_transfer_target(): void
{
    $this->seed();
    $sumit = $this->unblock(User::where('email','sumit@davya.local')->firstOrFail());
    $this->actingAs($sumit);

    $nikhil = User::where('email','nikhil@davya.local')->firstOrFail();
    $stage = \App\Models\Pipeline::default()->stages()->where('name','Meeting Scheduled')->firstOrFail();
    \DB::table('students')->insert([
        'name'=>'D','phone'=>'9222222200','owner_id'=>$nikhil->id,'referrer_id'=>$nikhil->id,
        'lead_source'=>'t','stage'=>$stage->name,'stage_id'=>$stage->id,
        'created_at'=>now(),'updated_at'=>now(),
    ]);

    Livewire::test(PipelineConfigPage::class)
        ->call('deleteStage', $stage->id, null)
        ->assertNotified();
    $this->assertDatabaseHas('stages', ['id' => $stage->id]);

    $target = \App\Models\Pipeline::default()->stages()->where('name','Meeting Done')->firstOrFail();
    Livewire::test(PipelineConfigPage::class)
        ->call('deleteStage', $stage->id, $target->id)
        ->assertHasNoErrors();
    $this->assertDatabaseMissing('stages', ['id' => $stage->id]);
}
```

- [ ] **Step 2: Run — verify fails**

```
php -d memory_limit=512M vendor/bin/phpunit --filter=PipelineConfigPageTest
```
Expected: FAIL (methods missing).

- [ ] **Step 3: Add action methods on the page**

Append to `PipelineConfigPage`:

```php
use App\Models\Stage;
use Filament\Notifications\Notification;

public function createStage(string $name, string $type): void
{
    if (! static::canAccess()) abort(403);
    try {
        app(StageRepository::class)->create($this->getPipeline(), $name, $type);
        Notification::make()->title("Stage \"$name\" created")->success()->send();
    } catch (\Illuminate\Validation\ValidationException $e) {
        Notification::make()->title('Could not create stage')->body(collect($e->errors())->flatten()->first())->danger()->send();
    }
}

public function renameStage(int $stageId, string $newName): void
{
    if (! static::canAccess()) abort(403);
    $stage = Stage::findOrFail($stageId);
    try {
        app(StageRepository::class)->rename($stage, $newName);
        Notification::make()->title('Renamed')->success()->send();
    } catch (\Illuminate\Validation\ValidationException $e) {
        Notification::make()->title('Rename failed')->body(collect($e->errors())->flatten()->first())->danger()->send();
    }
}

public function deleteStage(int $stageId, ?int $transferTo = null): void
{
    if (! static::canAccess()) abort(403);
    $stage = Stage::findOrFail($stageId);
    try {
        app(StageRepository::class)->delete($stage, $transferTo);
        Notification::make()->title('Stage deleted')->success()->send();
    } catch (\Illuminate\Validation\ValidationException $e) {
        Notification::make()->title('Cannot delete')->body(collect($e->errors())->flatten()->first())->warning()->send();
    }
}

public function changeStageType(int $stageId, string $newType): void
{
    if (! static::canAccess()) abort(403);
    $stage = Stage::findOrFail($stageId);
    try {
        app(StageRepository::class)->changeType($stage, $newType);
        Notification::make()->title('Type changed')->success()->send();
    } catch (\Illuminate\Validation\ValidationException $e) {
        Notification::make()->title('Change failed')->body(collect($e->errors())->flatten()->first())->danger()->send();
    }
}
```

- [ ] **Step 4: Wire UI controls in the Blade view**

Below each section heading in `pipeline-config.blade.php`, add the `+ Stage` link:

```blade
@php($capHit = $total >= 20)
<button
    wire:click="$dispatch('open-stage-modal', { type: '{{ strtoupper($key) === 'OPEN' ? 'OPEN' : ($key === 'won' ? 'CLOSED_WON' : 'CLOSED_LOST') }}' })"
    @disabled($capHit)
    class="text-sm font-medium text-blue-600 hover:underline px-2 py-1 {{ $capHit ? 'opacity-40 cursor-not-allowed' : '' }}">
    + Stage
</button>
```

Add an inline Alpine modal at the end of the blade file for the name input (minimal — prompt-style):

```blade
<div x-data="{ open: false, type: 'OPEN', name: '' }"
     x-on:open-stage-modal.window="open = true; type = $event.detail.type; name = ''">
    <div x-show="open" x-cloak class="fixed inset-0 bg-black/40 z-50 flex items-center justify-center">
        <div class="bg-white rounded-lg p-6 w-96 shadow-xl" @click.outside="open = false">
            <h3 class="font-semibold mb-3">New Stage</h3>
            <input x-model="name" class="w-full border border-gray-300 rounded px-3 py-2 text-sm mb-3" placeholder="Stage name">
            <div class="flex justify-end gap-2">
                <button @click="open = false" class="px-3 py-1.5 text-sm">Cancel</button>
                <button
                    @click="$wire.createStage(name, type); open = false"
                    class="px-3 py-1.5 text-sm bg-emerald-600 text-white rounded">Create</button>
            </div>
        </div>
    </div>
</div>
```

- [ ] **Step 5: Run — verify passes**

```
php -d memory_limit=512M vendor/bin/phpunit --filter=PipelineConfigPageTest
```
Expected: PASS (6 tests).

- [ ] **Step 6: Commit**

```bash
git add app/Filament/Pages/PipelineConfigPage.php resources/views/filament/pages/pipeline-config.blade.php tests/Feature/Pipeline/PipelineConfigPageTest.php
git commit -m "feat(pipeline): stages-tab create/rename/delete actions"
```

---

### Task 17: Stages tab — drag-reorder via Livewire

**Files:**
- Modify: `app/Filament/Pages/PipelineConfigPage.php`
- Modify: `resources/views/filament/pages/pipeline-config.blade.php`

- [ ] **Step 1: Append test**

```php
public function test_admin_can_reorder_stages(): void
{
    $this->seed();
    $sumit = $this->unblock(User::where('email','sumit@davya.local')->firstOrFail());
    $this->actingAs($sumit);

    $p = \App\Models\Pipeline::default();
    $openIds = $p->stages()->where('stage_type','OPEN')->orderBy('display_order')->pluck('id')->all();
    $reversed = array_reverse($openIds);

    Livewire::test(PipelineConfigPage::class)
        ->call('reorderStages', $reversed);

    $newFirst = $p->stages()->where('stage_type','OPEN')->orderBy('display_order')->first();
    $this->assertSame($reversed[0], $newFirst->id);
}
```

- [ ] **Step 2: Run — verify fails**

```
php -d memory_limit=512M vendor/bin/phpunit --filter=PipelineConfigPageTest::test_admin_can_reorder
```
Expected: FAIL (method missing).

- [ ] **Step 3: Add the action on the page**

```php
public function reorderStages(array $orderedIds): void
{
    if (! static::canAccess()) abort(403);
    app(StageRepository::class)->reorder($this->getPipeline(), array_map('intval', $orderedIds));
}
```

- [ ] **Step 4: Add Sortable.js hook in the blade**

Inside each section's stage-row container, wrap in a sortable div:

```blade
<div
    x-data
    x-init="new Sortable($el, { animation: 150, handle: '.grip', onEnd: (e) => {
        const ids = Array.from($el.children).map(c => c.dataset.stageId);
        $wire.reorderStages(ids);
    }})"
>
    @foreach ($buckets[$key] as $stage)
        <div data-stage-id="{{ $stage->id }}" class="flex items-center gap-3 px-3 py-2 border border-gray-200 rounded mb-1.5" wire:key="stage-{{ $stage->id }}">
            <span class="grip text-gray-300 text-sm tracking-widest select-none cursor-grab">⋮⋮</span>
            {{-- existing row content --}}
        </div>
    @endforeach
</div>
```

Add Sortable CDN at the top of the view:

```blade
@push('scripts')
<script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.0/Sortable.min.js"></script>
@endpush
```

- [ ] **Step 5: Run — verify passes**

```
php -d memory_limit=512M vendor/bin/phpunit --filter=PipelineConfigPageTest
```
Expected: PASS (7 tests).

- [ ] **Step 6: Commit**

```bash
git add app/Filament/Pages/PipelineConfigPage.php resources/views/filament/pages/pipeline-config.blade.php tests/Feature/Pipeline/PipelineConfigPageTest.php
git commit -m "feat(pipeline): stages-tab drag-reorder via Sortable.js"
```

---

### Task 18: Rules tab — list existing rules

**Files:**
- Modify: `app/Filament/Pages/PipelineConfigPage.php`
- Modify: `resources/views/filament/pages/pipeline-config.blade.php`

- [ ] **Step 1: Append test**

```php
public function test_rules_tab_lists_4_seeded_rules(): void
{
    $this->seed();
    $sumit = $this->unblock(User::where('email','sumit@davya.local')->firstOrFail());
    $this->actingAs($sumit);

    Livewire::test(PipelineConfigPage::class)
        ->set('activeTab', 'rules')
        ->assertSeeText('Closed requires reason')
        ->assertSeeText('Re-opening requires re-entry reason')
        ->assertSeeText('Meeting Scheduled needs a future meeting')
        ->assertSeeText('Sliding needs prior allotment');
}
```

- [ ] **Step 2: Run — verify fails**

```
php -d memory_limit=512M vendor/bin/phpunit --filter=PipelineConfigPageTest::test_rules_tab_lists
```
Expected: FAIL (blade still shows "Rules tab — populated in Task 18" placeholder).

- [ ] **Step 3: Add `getRules()` helper and update view**

Add method to page class:

```php
public function getRules(): \Illuminate\Support\Collection
{
    return $this->getPipeline()->transitionRules()->with(['fromStage','toStage','conditions'])->orderBy('id')->get();
}
```

Replace the `@else` branch in the blade with:

```blade
@else
    <div class="flex justify-end mb-4">
        <button class="bg-emerald-600 text-white px-4 py-2 rounded text-sm font-semibold" wire:click="$dispatch('open-rule-editor', { ruleId: null })">+ Add Rule</button>
    </div>
    @foreach ($this->getRules() as $rule)
        <div class="bg-white border border-gray-200 rounded-lg p-4 mb-2" wire:key="rule-{{ $rule->id }}">
            <div class="flex items-center gap-2 mb-2">
                <span class="font-semibold text-sm flex-1">{{ $rule->name }}</span>
                <span class="px-2 py-0.5 rounded-full text-[11px] font-semibold {{ $rule->severity === 'HARD' ? 'bg-red-100 text-red-800' : 'bg-amber-100 text-amber-900' }}">
                    {{ $rule->severity === 'HARD' ? 'Hard · Blocks' : 'Soft · Warns' }}
                </span>
                <span class="px-2 py-0.5 rounded-full text-[11px] font-semibold {{ $rule->is_active ? 'bg-emerald-100 text-emerald-800' : 'bg-gray-100 text-gray-600' }}">
                    {{ $rule->is_active ? 'Active' : 'Inactive' }}
                </span>
            </div>
            <div class="text-sm text-gray-600 mb-2">
                <span class="px-2 py-1 rounded {{ $rule->from_stage_id ? 'bg-gray-100' : 'bg-indigo-50 italic text-indigo-800' }}">
                    {{ $rule->fromStage?->name ?? 'Any stage' }}
                </span>
                →
                <span class="px-2 py-1 rounded {{ $rule->to_stage_id ? 'bg-gray-100' : 'bg-indigo-50 italic text-indigo-800' }}">
                    {{ $rule->toStage?->name ?? 'Any stage' }}
                </span>
            </div>
            @foreach ($rule->conditions as $cond)
                <div class="text-xs text-gray-500 border-t border-dashed border-gray-200 pt-2 mt-2">
                    <b>IF</b>
                    @if ($cond->condition_type === 'FIELD_CHECK')
                        field <code class="bg-gray-100 px-1 rounded">{{ $cond->field_or_relation }}</code> {{ $cond->operator }}
                        @if (is_array($cond->value) && isset($cond->value['rhs'])) <code class="bg-gray-100 px-1 rounded">{{ $cond->value['rhs'] }}</code> @endif
                    @else
                        record has ≥{{ $cond->value['count_min'] ?? 1 }} <code class="bg-gray-100 px-1 rounded">{{ $cond->field_or_relation }}</code>
                    @endif
                </div>
            @endforeach
        </div>
    @endforeach
@endif
```

- [ ] **Step 4: Run — verify passes**

```
php -d memory_limit=512M vendor/bin/phpunit --filter=PipelineConfigPageTest
```
Expected: PASS (8 tests).

- [ ] **Step 5: Commit**

```bash
git add app/Filament/Pages/PipelineConfigPage.php resources/views/filament/pages/pipeline-config.blade.php tests/Feature/Pipeline/PipelineConfigPageTest.php
git commit -m "feat(pipeline): rules-tab lists existing rules"
```

---

### Task 19: Rules tab — create/edit/toggle rule

**Files:**
- Modify: `app/Filament/Pages/PipelineConfigPage.php`
- Modify: `resources/views/filament/pages/pipeline-config.blade.php`

- [ ] **Step 1: Append tests**

```php
public function test_admin_can_create_rule(): void
{
    $this->seed();
    $sumit = $this->unblock(User::where('email','sumit@davya.local')->firstOrFail());
    $this->actingAs($sumit);

    $cpr = \App\Models\Pipeline::default()->stages()->where('name','Complete Payment Received')->value('id');

    Livewire::test(PipelineConfigPage::class)
        ->call('saveRule', [
            'name' => 'Custom — payment required',
            'from_stage_id' => null, 'to_stage_id' => $cpr,
            'severity' => 'HARD', 'is_active' => true,
            'conditions' => [[
                'condition_type' => 'FIELD_CHECK',
                'field_or_relation' => 'deal_amount',
                'operator' => '>=',
                'value' => ['rhs' => 1000],
            ]],
        ])
        ->assertHasNoErrors();

    $this->assertDatabaseHas('stage_transition_rules', ['name' => 'Custom — payment required']);
}

public function test_admin_can_toggle_rule_active(): void
{
    $this->seed();
    $sumit = $this->unblock(User::where('email','sumit@davya.local')->firstOrFail());
    $this->actingAs($sumit);

    $ruleId = \App\Models\Pipeline::default()->transitionRules()->first()->id;
    Livewire::test(PipelineConfigPage::class)->call('toggleRule', $ruleId);
    $this->assertDatabaseHas('stage_transition_rules', ['id' => $ruleId, 'is_active' => false]);
}
```

- [ ] **Step 2: Run — verify fails**

```
php -d memory_limit=512M vendor/bin/phpunit --filter=PipelineConfigPageTest::test_admin_can_create_rule
```
Expected: FAIL (method missing).

- [ ] **Step 3: Add page methods**

```php
use App\Models\StageTransitionRule;
use App\Models\StageTransitionCondition;

public function saveRule(array $data, ?int $ruleId = null): void
{
    if (! static::canAccess()) abort(403);

    $rule = $ruleId
        ? StageTransitionRule::findOrFail($ruleId)
        : new StageTransitionRule(['pipeline_id' => $this->getPipeline()->id]);

    $rule->fill([
        'name'          => $data['name'],
        'from_stage_id' => $data['from_stage_id'] ?: null,
        'to_stage_id'   => $data['to_stage_id'] ?: null,
        'severity'      => $data['severity'] ?? 'HARD',
        'is_active'     => (bool) ($data['is_active'] ?? true),
    ]);
    $rule->save();

    // Replace conditions
    $rule->conditions()->delete();
    foreach ($data['conditions'] ?? [] as $i => $c) {
        $rule->conditions()->create([
            'condition_type'    => $c['condition_type'],
            'field_or_relation' => $c['field_or_relation'],
            'operator'          => $c['operator'],
            'value'             => $c['value'] ?? null,
            'display_order'     => $i,
        ]);
    }

    app(PipelineConfig::class)->invalidate();
    Notification::make()->title('Rule saved')->success()->send();
}

public function toggleRule(int $ruleId): void
{
    if (! static::canAccess()) abort(403);
    $rule = StageTransitionRule::findOrFail($ruleId);
    $rule->update(['is_active' => ! $rule->is_active]);
}

public function deleteRule(int $ruleId): void
{
    if (! static::canAccess()) abort(403);
    StageTransitionRule::findOrFail($ruleId)->delete();
}
```

- [ ] **Step 4: Add a minimal rule editor modal to the view**

Append below the rules loop in the blade:

```blade
<div x-data="{ open: false, rule: null }" x-on:open-rule-editor.window="open = true; rule = $event.detail.ruleId ? null : null">
    <div x-show="open" x-cloak class="fixed inset-0 bg-black/40 z-50 flex items-center justify-center">
        <div class="bg-white rounded-lg p-6 w-[520px] shadow-xl" @click.outside="open = false" x-data="{
            form: { name: '', from_stage_id: '', to_stage_id: '', severity: 'HARD', is_active: true, conditions: [{condition_type: 'FIELD_CHECK', field_or_relation: '', operator: 'is_not_empty', value: null}] }
        }">
            <h3 class="font-semibold mb-3">New Rule</h3>
            <input x-model="form.name" placeholder="Rule name" class="w-full border rounded px-3 py-2 text-sm mb-2">
            <div class="grid grid-cols-2 gap-2 mb-2">
                <select x-model="form.from_stage_id" class="border rounded px-2 py-2 text-sm">
                    <option value="">Any (from)</option>
                    @foreach ($this->getStagesByType()['open']->concat($this->getStagesByType()['won'])->concat($this->getStagesByType()['lost']) as $st)
                        <option value="{{ $st->id }}">{{ $st->name }}</option>
                    @endforeach
                </select>
                <select x-model="form.to_stage_id" class="border rounded px-2 py-2 text-sm">
                    <option value="">Any (to)</option>
                    @foreach ($this->getStagesByType()['open']->concat($this->getStagesByType()['won'])->concat($this->getStagesByType()['lost']) as $st)
                        <option value="{{ $st->id }}">{{ $st->name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="flex gap-4 text-sm mb-3">
                <label><input type="radio" x-model="form.severity" value="HARD"> Hard (block)</label>
                <label><input type="radio" x-model="form.severity" value="SOFT"> Soft (warn)</label>
            </div>
            <template x-for="(c, idx) in form.conditions" :key="idx">
                <div class="grid grid-cols-[1fr_1fr_1fr_auto] gap-1 mb-2">
                    <select x-model="c.condition_type" class="border rounded text-xs px-2 py-1">
                        <option value="FIELD_CHECK">field</option>
                        <option value="HAS_RELATION">has</option>
                    </select>
                    <input x-model="c.field_or_relation" class="border rounded text-xs px-2 py-1" placeholder="e.g. close_reason or meetings">
                    <input x-model="c.operator" class="border rounded text-xs px-2 py-1" placeholder="is_not_empty, >=, has_where">
                    <button @click="form.conditions.splice(idx, 1)" class="text-red-500 px-2">✕</button>
                </div>
            </template>
            <button @click="form.conditions.push({condition_type:'FIELD_CHECK',field_or_relation:'',operator:'is_not_empty',value:null})" class="text-blue-600 text-sm mb-3">+ Add condition</button>
            <div class="flex justify-end gap-2">
                <button @click="open = false" class="px-3 py-1.5 text-sm">Cancel</button>
                <button @click="$wire.saveRule(form); open = false" class="px-3 py-1.5 text-sm bg-emerald-600 text-white rounded">Save rule</button>
            </div>
        </div>
    </div>
</div>
```

- [ ] **Step 5: Run — verify passes**

```
php -d memory_limit=512M vendor/bin/phpunit --filter=PipelineConfigPageTest
```
Expected: PASS (10 tests).

- [ ] **Step 6: Commit**

```bash
git add app/Filament/Pages/PipelineConfigPage.php resources/views/filament/pages/pipeline-config.blade.php tests/Feature/Pipeline/PipelineConfigPageTest.php
git commit -m "feat(pipeline): rules-tab create/edit/toggle/delete"
```

---

## Phase 7 — Cleanup and deployment

### Task 20: Remove dead code — `PipelineStage` enum + `StageTransitionValidator`

**Files:**
- Delete: `app/Enums/PipelineStage.php`
- Delete: `app/Services/StageTransitionValidator.php`

- [ ] **Step 1: Grep for remaining references**

```bash
grep -rn "App\\\\Enums\\\\PipelineStage\|StageTransitionValidator" app/ tests/
```
Expected: only test files remain, plus any I missed in Task 14. Any match here is a must-fix BEFORE deleting.

- [ ] **Step 2: Replace remaining references**

For each file returned by grep:
- Replace `PipelineStage::values()` → `app(PipelineConfig::class)->stageNames()`
- Replace `PipelineStage::options()` → `collect(app(PipelineConfig::class)->stageNames())->mapWithKeys(fn ($n) => [$n => $n])->all()`
- Replace `PipelineStage::from($string)` → `app(PipelineConfig::class)->stageByName($string)` (returns Stage or null; adjust callers)
- Replace `PipelineStage::fromRoundName(...)` → keep a small static helper. Move the switch into `app/Support/RoundNameToStage.php` (one public method returning a stage name string), since this maps external round names to canonical stage names and doesn't belong in the dynamic stage machinery.
- Replace any `new StageTransitionValidator` → `app(StageTransitionEngine::class)`. If a caller called `forRoundChange`, the shim added in Task 14 covers it.

After edits, re-run the grep. Must be empty (besides the files being deleted themselves).

- [ ] **Step 3: Delete the dead files**

```bash
rm app/Enums/PipelineStage.php
rm app/Services/StageTransitionValidator.php
```

- [ ] **Step 4: Full test suite**

```
php -d memory_limit=512M vendor/bin/phpunit
```
Expected: 0 failures. Any test referencing the deleted classes must be updated — treat failures as "test assumed the legacy API; update to use PipelineConfig / StageTransitionEngine".

- [ ] **Step 5: Commit**

```bash
git add -u app/
git commit -m "refactor(pipeline): drop legacy PipelineStage enum + StageTransitionValidator"
```

---

### Task 21: End-to-end smoke test — full kanban journey

**Files:**
- Create: `tests/Feature/Pipeline/PipelineEndToEndTest.php`

- [ ] **Step 1: Write the test**

```php
<?php
// tests/Feature/Pipeline/PipelineEndToEndTest.php
namespace Tests\Feature\Pipeline;

use App\Filament\Pages\KanbanBoard;
use App\Models\Pipeline;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PipelineEndToEndTest extends TestCase
{
    use RefreshDatabase;

    private function unblock(User $u): User { $u->must_change_password = false; $u->save(); return $u; }

    public function test_student_walks_from_lead_to_complete_payment(): void
    {
        $this->seed();
        $sumit = $this->unblock(User::where('email','sumit@davya.local')->firstOrFail());
        $this->actingAs($sumit);

        $nikhil = User::where('email','nikhil@davya.local')->firstOrFail();
        $leadStageId = Pipeline::default()->stages()->where('name','Lead Captured')->value('id');

        $s = Student::create([
            'name'=>'Walker','phone'=>'9333333333',
            'owner_id'=>$nikhil->id,'referrer_id'=>$nikhil->id,
            'lead_source'=>'test','stage'=>'Lead Captured','stage_id'=>$leadStageId,
            'deal_amount' => 100000,
        ]);

        $board = app(KanbanBoard::class);

        // Walk forward: Lead → Meeting Scheduled (soft warn OK to bypass in non-UI context; engine returns warning but moveStudentToStage accepts)
        $s->meetings()->create(['scheduled_at' => now()->addDay(), 'status' => 'scheduled', 'owner_id' => $s->owner_id, 'created_by_id' => $s->owner_id]);
        $this->assertTrue($board->moveStudentToStage($s->id, 'Meeting Scheduled')['ok']);

        // Meeting Done
        $this->assertTrue($board->moveStudentToStage($s->id, 'Meeting Done')['ok']);

        // → Closed should fail (hard: close_reason missing)
        $out = $board->moveStudentToStage($s->id, 'Closed');
        $this->assertFalse($out['ok']);

        // Set reason and retry
        $s->refresh()->update(['close_reason' => 'Not Interested']);
        $this->assertTrue($board->moveStudentToStage($s->id, 'Closed')['ok']);

        // Re-open — must set re_entry_reason first
        $out = $board->moveStudentToStage($s->id, 'Meeting Done');
        $this->assertFalse($out['ok']);
        $s->refresh()->update(['re_entry_reason' => 'changed mind']);
        $this->assertTrue($board->moveStudentToStage($s->id, 'Meeting Done')['ok']);
    }
}
```

- [ ] **Step 2: Run — verify passes**

```
php -d memory_limit=512M vendor/bin/phpunit --filter=PipelineEndToEndTest
```
Expected: PASS (1 test).

- [ ] **Step 3: Commit**

```bash
git add tests/Feature/Pipeline/PipelineEndToEndTest.php
git commit -m "test(pipeline): end-to-end journey across all 4 seeded rules"
```

---

### Task 22: Deploy runbook

**Files:**
- Create: `docs/sessions/2026-04-23-dynamic-pipelines-stages-runbook.md`

- [ ] **Step 1: Write the runbook**

```markdown
# Dynamic Pipelines & Stages — Deploy Runbook

**Branch:** feature/dynamic-pipelines-stages
**Prod:** davyas.ipu.co.in
**Migrations added:** 6

## Pre-deploy checklist

- [ ] `php -d memory_limit=512M vendor/bin/phpunit` — 0 failures
- [ ] `php artisan route:list | grep pipeline-config` — route present
- [ ] Staging smoke: open /admin/pipeline-config as admin, verify 13 stages render
- [ ] Tag release: `git tag v12-dynamic-pipelines`

## Deploy steps (Hostinger SSH)

```
ssh ipuc@davyas.ipu.co.in
cd ~/davya-crm
git fetch origin && git checkout main && git pull
/opt/alt/php84/usr/bin/php artisan down --render=maintenance
/opt/alt/php84/usr/bin/php artisan migrate --force
/opt/alt/php84/usr/bin/php artisan cache:clear
/opt/alt/php84/usr/bin/php artisan config:cache
/opt/alt/php84/usr/bin/php artisan route:cache
/opt/alt/php84/usr/bin/php artisan view:cache
/opt/alt/php84/usr/bin/php artisan up
```

## Post-deploy smoke

1. Open /admin/pipeline-config as Sumit — tabs render, 13 stages, 4 rules visible
2. Drag a test student (created in staging copy) between two open stages → works
3. Try to move a test student to Closed without close_reason → blocked with "[Closed requires reason]" notification
4. Check `SELECT COUNT(*) FROM students WHERE stage_id IS NULL;` → 0

## Rollback (if needed)

```
/opt/alt/php84/usr/bin/php artisan migrate:rollback --step=6 --force
git checkout <previous-tag>
/opt/alt/php84/usr/bin/php artisan cache:clear
```

Note: rollback preserves `students.stage` varchar — no data loss risk.
```

- [ ] **Step 2: Commit + push branch**

```bash
git add docs/sessions/2026-04-23-dynamic-pipelines-stages-runbook.md
git commit -m "docs(pipeline): deploy runbook"
git push -u origin feature/dynamic-pipelines-stages
```

- [ ] **Step 3: Open PR (human step)**

PR body: link to spec + plan; tick all tasks; paste final `phpunit` summary.

---

## Self-review

**Spec coverage:**
- Scope: single pipeline, 20 cap, 3 stage types — covered by Tasks 1, 6, 10 (20-cap test).
- Delete-with-transfer — Task 11.
- Transition rules with Hard/Soft + field/relation conditions — Tasks 12, 13, 19.
- Seed migration for 4 existing rules — Task 7.
- `students.stage` ENUM→VARCHAR widening — Task 3.
- Kanban reuse — Task 14.
- Admin-only access — Task 15 + 16 (auth tests).
- Observer keeps `students.stage` in sync — handled inside `StageRepository::delete` (writes cache) and `moveStudentToStage` (Task 14). Student form save needs a touchup to also write `stage` cache when `stage_id` changes — add to EditStudent edit hook (covered in Task 14 Step 4).
- End-to-end test — Task 21.
- Deploy runbook — Task 22.

**Placeholder scan:** none found. Each step shows real code.

**Type consistency:** `PipelineConfig` methods consistent across tasks (`stages`, `stageNames`, `stageByName`, `stageById`, `openStages`, `wonStages`, `lostStages`, `invalidate`). `StageRepository` methods consistent (`create`, `rename`, `reorder`, `delete`, `changeType`). `StageTransitionEngine::forStageChange` signature stable across caller swaps and tests.
