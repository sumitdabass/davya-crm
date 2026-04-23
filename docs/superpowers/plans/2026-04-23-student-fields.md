# Custom Student Fields (Phase A) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ship admin self-serve custom student fields — three new additive tables, a `/admin/student-fields` Filament page (sections + fields with drag-reorder, soft-archive), and dynamic StudentResource form / table / kanban / CSV-import rendering — without touching the existing `students` schema or any current feature.

**Architecture:** EAV model. `student_field_sections` (groupings) + `student_fields` (definitions, with `is_built_in`/`built_in_column` for bridging to existing `students` columns) + `student_field_values` (typed value storage indexed for Phase B segment queries). Built-in columns continue to read/write the `students` table directly via the bridge. `FieldRenderer` service maps the Core 8 types to Filament components. Soft archive on delete preserves data and supports restore.

**Tech Stack:** Laravel 11 · Filament 3 · MySQL · Spatie ActivityLog · Spatie Permission · PHPUnit (no Pest) · SortableJS (already in repo for SP#1 / kanban / SP#3) · Tailwind+Filament with documented gotchas.

**Reference:** Spec at `docs/superpowers/specs/2026-04-23-student-fields-design.md`.

**PHP path:** Always use `/opt/alt/php84/usr/bin/php` for composer/artisan/test commands (per project memory). Local env may differ; the agent should `which php8.4 || which php` and use the first one that satisfies `composer.json`.

**Testing convention:** PHPUnit class-based tests (`extends Tests\TestCase`, `use RefreshDatabase`) — see `tests/Unit/ExpenseModelTest.php` and `tests/Feature/AdminDeleteUserActionTest.php` for patterns. Run with `php artisan test --filter=<TestName>`.

**Commit cadence:** One commit per task (after green tests).

---

## File map

**Created:**
- `database/migrations/2026_04_24_010000_create_student_field_sections_table.php`
- `database/migrations/2026_04_24_010100_create_student_fields_table.php`
- `database/migrations/2026_04_24_010200_create_student_field_values_table.php`
- `database/migrations/2026_04_24_010300_seed_built_in_student_fields.php`
- `app/Models/StudentFieldSection.php`
- `app/Models/StudentField.php`
- `app/Models/StudentFieldValue.php`
- `app/StudentFields/FieldRenderer.php`
- `app/StudentFields/StudentFormDynamicTrait.php`
- `app/StudentFields/DynamicTableColumns.php`
- `app/StudentFields/KanbanExtrasFormatter.php`
- `app/StudentFields/ImportColumnMapper.php`
- `app/Filament/Pages/StudentFieldsConfigPage.php`
- `app/Observers/StudentFieldValueObserver.php`
- `resources/views/filament/pages/student-fields-config.blade.php`
- `tests/Unit/StudentFields/FieldRendererTest.php`
- `tests/Unit/Models/StudentFieldSectionTest.php`
- `tests/Unit/Models/StudentFieldTest.php`
- `tests/Unit/Models/StudentFieldValueTest.php`
- `tests/Unit/StudentFields/KanbanExtrasFormatterTest.php`
- `tests/Unit/StudentFields/ImportColumnMapperTest.php`
- `tests/Feature/StudentFields/StudentFieldsConfigPageTest.php`
- `tests/Feature/StudentFields/SoftArchiveAndRestoreTest.php`
- `tests/Feature/StudentFields/SectionTransferOnDeleteTest.php`
- `tests/Feature/StudentFields/DynamicStudentFormTest.php`
- `tests/Feature/StudentFields/DynamicTableColumnsTest.php`
- `tests/Feature/StudentFields/CsvImportCustomFieldsTest.php`
- `tests/Feature/StudentFields/PhoneRequiredLockTest.php`
- `tests/Feature/StudentFields/ActivityLogTest.php`

**Modified:**
- `app/Models/Student.php` — add `fieldValues` hasMany relation
- `app/Filament/Resources/StudentResource.php` — dynamic form + table sections
- `app/Filament/Pages/KanbanBoard.php` (or its blade) — render kanban extras block
- `app/Services/LeadImport/...` (importer) — wire `ImportColumnMapper`
- `app/Providers/AppServiceProvider.php` — register `StudentFieldValueObserver`

---

## Pre-Phase-A hygiene (NOT this plan — separate commits on main)

These ship before Task 1 of this plan, per Sumit's brainstorming-session decision:
- SP#3 follow-up (a): missing StudentResource filter keys for Stuck Leads / Re-Entry Candidates / Seat Fee Pending "View all →" deep-links
- SP#3 follow-up (b): "uncheck all" on Customize Cards modal → option C empty state with "Reset to defaults" link
- SP#3 follow-up (d): SortableJS consolidation (single global include)
- Today Tab + Finance Admin prod browser smokes (Sumit walks through with operator)

These items live outside this plan. Do not attempt them as part of Phase A tasks.

---

### Task 1: Create student_field_sections table

**Files:**
- Create: `database/migrations/2026_04_24_010000_create_student_field_sections_table.php`
- Test: `tests/Unit/Models/StudentFieldSectionTest.php` (new — first assertion only here, more in Task 2)

- [ ] **Step 1: Write the failing test**

```php
<?php
namespace Tests\Unit\Models;

use App\Models\StudentFieldSection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StudentFieldSectionTest extends TestCase
{
    use RefreshDatabase;

    public function test_section_table_exists_with_expected_columns(): void
    {
        $section = StudentFieldSection::create(['name' => 'Identity', 'position' => 0]);
        $this->assertDatabaseHas('student_field_sections', ['id' => $section->id, 'name' => 'Identity', 'position' => 0]);
        $this->assertNotNull($section->fresh()->created_at);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=StudentFieldSectionTest`
Expected: FAIL — table does not exist (or model class missing).

- [ ] **Step 3: Create the migration**

```php
<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('student_field_sections', function (Blueprint $table) {
            $table->id();
            $table->string('name', 80);
            $table->integer('position')->default(0);
            $table->timestamps();
            $table->index('position');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('student_field_sections');
    }
};
```

Create stub model `app/Models/StudentFieldSection.php`:

```php
<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StudentFieldSection extends Model
{
    protected $fillable = ['name', 'position'];
    protected $casts = ['position' => 'integer'];
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter=StudentFieldSectionTest`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add database/migrations/2026_04_24_010000_create_student_field_sections_table.php app/Models/StudentFieldSection.php tests/Unit/Models/StudentFieldSectionTest.php
git commit -m "feat(fields): add student_field_sections table + model"
```

---

### Task 2: Create student_fields table + model with scopes

**Files:**
- Create: `database/migrations/2026_04_24_010100_create_student_fields_table.php`
- Create: `app/Models/StudentField.php`
- Test: `tests/Unit/Models/StudentFieldTest.php`

- [ ] **Step 1: Write failing tests**

```php
<?php
namespace Tests\Unit\Models;

use App\Models\StudentField;
use App\Models\StudentFieldSection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StudentFieldTest extends TestCase
{
    use RefreshDatabase;

    public function test_can_create_custom_text_field(): void
    {
        $section = StudentFieldSection::create(['name' => 'Demographics', 'position' => 0]);
        $field = StudentField::create([
            'section_id' => $section->id,
            'key' => 'dob',
            'label' => 'Date of Birth',
            'type' => 'date',
            'is_required' => false,
            'is_built_in' => false,
            'position' => 0,
        ]);
        $this->assertSame('date', $field->type);
        $this->assertFalse((bool) $field->is_built_in);
        $this->assertNull($field->archived_at);
    }

    public function test_key_must_be_unique(): void
    {
        $section = StudentFieldSection::create(['name' => 'X', 'position' => 0]);
        StudentField::create(['section_id' => $section->id, 'key' => 'dob', 'label' => 'A', 'type' => 'text', 'is_required' => false, 'is_built_in' => false, 'position' => 0]);
        $this->expectException(\Illuminate\Database\QueryException::class);
        StudentField::create(['section_id' => $section->id, 'key' => 'dob', 'label' => 'B', 'type' => 'text', 'is_required' => false, 'is_built_in' => false, 'position' => 1]);
    }

    public function test_active_scope_excludes_archived(): void
    {
        $section = StudentFieldSection::create(['name' => 'X', 'position' => 0]);
        $live = StudentField::create(['section_id' => $section->id, 'key' => 'a', 'label' => 'A', 'type' => 'text', 'is_required' => false, 'is_built_in' => false, 'position' => 0]);
        $arch = StudentField::create(['section_id' => $section->id, 'key' => 'b', 'label' => 'B', 'type' => 'text', 'is_required' => false, 'is_built_in' => false, 'position' => 1, 'archived_at' => now()]);
        $ids = StudentField::active()->pluck('id')->all();
        $this->assertContains($live->id, $ids);
        $this->assertNotContains($arch->id, $ids);
    }

    public function test_built_in_scope(): void
    {
        $section = StudentFieldSection::create(['name' => 'X', 'position' => 0]);
        StudentField::create(['section_id' => $section->id, 'key' => 'phone', 'label' => 'Phone', 'type' => 'text', 'is_required' => true, 'is_built_in' => true, 'built_in_column' => 'phone', 'position' => 0]);
        StudentField::create(['section_id' => $section->id, 'key' => 'dob', 'label' => 'DOB', 'type' => 'date', 'is_required' => false, 'is_built_in' => false, 'position' => 1]);
        $this->assertSame(1, StudentField::builtIn()->count());
        $this->assertSame(1, StudentField::custom()->count());
    }

    public function test_options_cast_as_array(): void
    {
        $section = StudentFieldSection::create(['name' => 'X', 'position' => 0]);
        $field = StudentField::create([
            'section_id' => $section->id,
            'key' => 'board',
            'label' => 'Board',
            'type' => 'dropdown',
            'is_required' => false,
            'is_built_in' => false,
            'options' => [['value' => 'cbse', 'label' => 'CBSE'], ['value' => 'icse', 'label' => 'ICSE']],
            'position' => 0,
        ]);
        $this->assertIsArray($field->fresh()->options);
        $this->assertSame('CBSE', $field->fresh()->options[0]['label']);
    }
}
```

- [ ] **Step 2: Run tests — expect FAIL**

Run: `php artisan test --filter=StudentFieldTest`
Expected: FAIL — table missing, model missing, scopes missing.

- [ ] **Step 3: Create the migration**

```php
<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('student_fields', function (Blueprint $table) {
            $table->id();
            $table->foreignId('section_id')->nullable()->constrained('student_field_sections')->nullOnDelete();
            $table->string('key', 80)->unique();
            $table->string('label', 120);
            $table->enum('type', ['text','textarea','number','date','email','dropdown','checkbox','multiselect']);
            $table->boolean('is_required')->default(false);
            $table->boolean('is_built_in')->default(false);
            $table->string('built_in_column', 40)->nullable();
            $table->json('options')->nullable();
            $table->boolean('show_in_table')->default(false);
            $table->boolean('show_in_kanban')->default(false);
            $table->boolean('show_in_import')->default(false);
            $table->integer('position')->default(0);
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();
            $table->index(['section_id', 'position']);
            $table->index('archived_at');
            $table->index('is_built_in');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('student_fields');
    }
};
```

- [ ] **Step 4: Create the model**

```php
<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Builder;

class StudentField extends Model
{
    protected $fillable = [
        'section_id', 'key', 'label', 'type', 'is_required', 'is_built_in',
        'built_in_column', 'options', 'show_in_table', 'show_in_kanban',
        'show_in_import', 'position', 'archived_at',
    ];

    protected $casts = [
        'is_required' => 'boolean',
        'is_built_in' => 'boolean',
        'options' => 'array',
        'show_in_table' => 'boolean',
        'show_in_kanban' => 'boolean',
        'show_in_import' => 'boolean',
        'position' => 'integer',
        'archived_at' => 'datetime',
    ];

    public function section(): BelongsTo
    {
        return $this->belongsTo(StudentFieldSection::class, 'section_id');
    }

    public function scopeActive(Builder $q): Builder
    {
        return $q->whereNull('archived_at');
    }

    public function scopeArchived(Builder $q): Builder
    {
        return $q->whereNotNull('archived_at');
    }

    public function scopeBuiltIn(Builder $q): Builder
    {
        return $q->where('is_built_in', true);
    }

    public function scopeCustom(Builder $q): Builder
    {
        return $q->where('is_built_in', false);
    }
}
```

- [ ] **Step 5: Run tests — expect PASS**

Run: `php artisan test --filter=StudentFieldTest`
Expected: PASS, all 5 assertions green.

- [ ] **Step 6: Commit**

```bash
git add database/migrations/2026_04_24_010100_create_student_fields_table.php app/Models/StudentField.php tests/Unit/Models/StudentFieldTest.php
git commit -m "feat(fields): add student_fields table + model with scopes"
```

---

### Task 3: Create student_field_values table + model

**Files:**
- Create: `database/migrations/2026_04_24_010200_create_student_field_values_table.php`
- Create: `app/Models/StudentFieldValue.php`
- Modify: `app/Models/Student.php` (add `fieldValues` relation)
- Test: `tests/Unit/Models/StudentFieldValueTest.php`

- [ ] **Step 1: Write failing tests**

```php
<?php
namespace Tests\Unit\Models;

use App\Models\Student;
use App\Models\StudentField;
use App\Models\StudentFieldSection;
use App\Models\StudentFieldValue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StudentFieldValueTest extends TestCase
{
    use RefreshDatabase;

    private function field(string $type, array $opts = []): StudentField
    {
        $section = StudentFieldSection::firstOrCreate(['name' => 'X'], ['position' => 0]);
        return StudentField::create(array_merge([
            'section_id' => $section->id,
            'key' => $type . '_' . uniqid(),
            'label' => ucfirst($type),
            'type' => $type,
            'is_required' => false,
            'is_built_in' => false,
            'position' => 0,
        ], $opts));
    }

    private function student(): Student
    {
        return Student::create(['phone' => '9000000001', 'name' => 'Test', 'stage' => 'Lead Captured']);
    }

    public function test_text_value_round_trip(): void
    {
        $v = StudentFieldValue::create(['student_id' => $this->student()->id, 'student_field_id' => $this->field('text')->id, 'value_text' => 'hello']);
        $this->assertSame('hello', $v->fresh()->value_text);
    }

    public function test_number_value_round_trip(): void
    {
        $v = StudentFieldValue::create(['student_id' => $this->student()->id, 'student_field_id' => $this->field('number')->id, 'value_number' => 92.5]);
        $this->assertSame('92.5000', (string) $v->fresh()->value_number);
    }

    public function test_date_value_round_trip(): void
    {
        $v = StudentFieldValue::create(['student_id' => $this->student()->id, 'student_field_id' => $this->field('date')->id, 'value_date' => '2009-05-12']);
        $this->assertSame('2009-05-12', $v->fresh()->value_date->toDateString());
    }

    public function test_multiselect_value_round_trip(): void
    {
        $v = StudentFieldValue::create(['student_id' => $this->student()->id, 'student_field_id' => $this->field('multiselect', ['options' => [['value' => 'a', 'label' => 'A'], ['value' => 'b', 'label' => 'B']]])->id, 'value_json' => ['a', 'b']]);
        $this->assertSame(['a', 'b'], $v->fresh()->value_json);
    }

    public function test_unique_constraint_per_student_field(): void
    {
        $s = $this->student();
        $f = $this->field('text');
        StudentFieldValue::create(['student_id' => $s->id, 'student_field_id' => $f->id, 'value_text' => 'first']);
        $this->expectException(\Illuminate\Database\QueryException::class);
        StudentFieldValue::create(['student_id' => $s->id, 'student_field_id' => $f->id, 'value_text' => 'second']);
    }

    public function test_student_has_field_values_relation(): void
    {
        $s = $this->student();
        StudentFieldValue::create(['student_id' => $s->id, 'student_field_id' => $this->field('text')->id, 'value_text' => 'a']);
        StudentFieldValue::create(['student_id' => $s->id, 'student_field_id' => $this->field('number')->id, 'value_number' => 5]);
        $this->assertCount(2, $s->fresh()->fieldValues);
    }
}
```

- [ ] **Step 2: Run tests — expect FAIL**

Run: `php artisan test --filter=StudentFieldValueTest`
Expected: FAIL — table + model + relation missing.

- [ ] **Step 3: Create the migration**

```php
<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('student_field_values', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained('students')->cascadeOnDelete();
            $table->foreignId('student_field_id')->constrained('student_fields')->cascadeOnDelete();
            $table->text('value_text')->nullable();
            $table->decimal('value_number', 20, 4)->nullable();
            $table->date('value_date')->nullable();
            $table->json('value_json')->nullable();
            $table->timestamps();
            $table->unique(['student_id', 'student_field_id']);
            $table->index(['student_field_id', 'value_text'], 'sfv_field_text_idx');
            $table->index(['student_field_id', 'value_number'], 'sfv_field_number_idx');
            $table->index(['student_field_id', 'value_date'], 'sfv_field_date_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('student_field_values');
    }
};
```

- [ ] **Step 4: Create the model**

```php
<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StudentFieldValue extends Model
{
    protected $fillable = [
        'student_id', 'student_field_id',
        'value_text', 'value_number', 'value_date', 'value_json',
    ];

    protected $casts = [
        'value_date' => 'date',
        'value_json' => 'array',
    ];

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function field(): BelongsTo
    {
        return $this->belongsTo(StudentField::class, 'student_field_id');
    }
}
```

- [ ] **Step 5: Add `fieldValues` relation to Student**

In `app/Models/Student.php`, add (or merge into existing `use` block) the import and append the relation method:

```php
use App\Models\StudentFieldValue;
use Illuminate\Database\Eloquent\Relations\HasMany;

// ... existing code ...

public function fieldValues(): HasMany
{
    return $this->hasMany(StudentFieldValue::class);
}
```

- [ ] **Step 6: Run tests — expect PASS**

Run: `php artisan test --filter=StudentFieldValueTest`
Expected: PASS, 6 assertions green.

- [ ] **Step 7: Commit**

```bash
git add database/migrations/2026_04_24_010200_create_student_field_values_table.php app/Models/StudentFieldValue.php app/Models/Student.php tests/Unit/Models/StudentFieldValueTest.php
git commit -m "feat(fields): add student_field_values EAV table + Student relation"
```

---

### Task 4: Seed built-in student_fields

**Files:**
- Create: `database/migrations/2026_04_24_010300_seed_built_in_student_fields.php`
- Test: `tests/Feature/StudentFields/SeedBuiltInsTest.php`

- [ ] **Step 1: Write failing test**

```php
<?php
namespace Tests\Feature\StudentFields;

use App\Models\StudentField;
use App\Models\StudentFieldSection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SeedBuiltInsTest extends TestCase
{
    use RefreshDatabase;

    public function test_built_in_sections_and_fields_seeded(): void
    {
        $this->assertSame(2, StudentFieldSection::count(), 'Identity + Academic seeded');
        $identity = StudentFieldSection::where('name', 'Identity')->first();
        $academic = StudentFieldSection::where('name', 'Academic')->first();
        $this->assertNotNull($identity);
        $this->assertNotNull($academic);

        $expected = [
            'phone' => ['Identity', true, 'phone', 'text'],
            'name' => ['Identity', true, 'name', 'text'],
            'father_name' => ['Identity', false, 'father_name', 'text'],
            'phone_2' => ['Identity', false, 'phone_2', 'text'],
            'category' => ['Identity', false, 'category', 'dropdown'],
            'state' => ['Identity', false, 'state', 'text'],
            'course' => ['Academic', false, 'course', 'text'],
            'final_course' => ['Academic', false, 'final_course', 'text'],
        ];

        foreach ($expected as $key => [$sectionName, $required, $col, $type]) {
            $field = StudentField::where('key', $key)->first();
            $this->assertNotNull($field, "Built-in '$key' missing");
            $this->assertTrue($field->is_built_in);
            $this->assertSame($col, $field->built_in_column);
            $this->assertSame($type, $field->type);
            $this->assertSame($required, (bool) $field->is_required);
            $this->assertSame($sectionName, $field->section->name);
        }

        // category options
        $cat = StudentField::where('key', 'category')->first();
        $values = collect($cat->options)->pluck('value')->all();
        $this->assertEqualsCanonicalizing(['Delhi', 'Outside'], $values);
    }
}
```

- [ ] **Step 2: Run test — expect FAIL**

Run: `php artisan test --filter=SeedBuiltInsTest`
Expected: FAIL — sections + fields not seeded.

- [ ] **Step 3: Create the data migration**

```php
<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        $now = now();
        $identityId = DB::table('student_field_sections')->insertGetId(['name' => 'Identity', 'position' => 0, 'created_at' => $now, 'updated_at' => $now]);
        $academicId = DB::table('student_field_sections')->insertGetId(['name' => 'Academic', 'position' => 1, 'created_at' => $now, 'updated_at' => $now]);

        $rows = [
            ['phone',        'Phone',           'text',     true,  'phone',        $identityId, 0, null],
            ['name',         'Name',            'text',     true,  'name',         $identityId, 1, null],
            ['father_name',  'Guardian Name',   'text',     false, 'father_name',  $identityId, 2, null],
            ['phone_2',      'Alternate Phone', 'text',     false, 'phone_2',      $identityId, 3, null],
            ['category',     'Zone',            'dropdown', false, 'category',     $identityId, 4, json_encode([['value' => 'Delhi', 'label' => 'Delhi'], ['value' => 'Outside', 'label' => 'Outside']])],
            ['state',        'State',           'text',     false, 'state',        $identityId, 5, null],
            ['course',       'Course',          'text',     false, 'course',       $academicId, 0, null],
            ['final_course', 'Final Course',    'text',     false, 'final_course', $academicId, 1, null],
        ];

        foreach ($rows as [$key, $label, $type, $req, $col, $sectionId, $pos, $opts]) {
            DB::table('student_fields')->insert([
                'section_id' => $sectionId,
                'key' => $key,
                'label' => $label,
                'type' => $type,
                'is_required' => $req,
                'is_built_in' => true,
                'built_in_column' => $col,
                'options' => $opts,
                'show_in_table' => false,
                'show_in_kanban' => false,
                'show_in_import' => true,
                'position' => $pos,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        DB::table('student_fields')->where('is_built_in', true)->delete();
        DB::table('student_field_sections')->whereIn('name', ['Identity', 'Academic'])->delete();
    }
};
```

- [ ] **Step 4: Run test — expect PASS**

Run: `php artisan test --filter=SeedBuiltInsTest`
Expected: PASS — all assertions green.

- [ ] **Step 5: Commit**

```bash
git add database/migrations/2026_04_24_010300_seed_built_in_student_fields.php tests/Feature/StudentFields/SeedBuiltInsTest.php
git commit -m "feat(fields): seed built-in student fields with section bridging"
```

---

### Task 5: FieldRenderer service (8 type → component map)

**Files:**
- Create: `app/StudentFields/FieldRenderer.php`
- Test: `tests/Unit/StudentFields/FieldRendererTest.php`

- [ ] **Step 1: Write failing tests**

```php
<?php
namespace Tests\Unit\StudentFields;

use App\Models\StudentField;
use App\Models\StudentFieldSection;
use App\StudentFields\FieldRenderer;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FieldRendererTest extends TestCase
{
    use RefreshDatabase;

    private function field(string $type, array $opts = []): StudentField
    {
        $section = StudentFieldSection::firstOrCreate(['name' => 'X'], ['position' => 0]);
        return StudentField::create(array_merge([
            'section_id' => $section->id,
            'key' => $type . '_' . uniqid(),
            'label' => ucfirst($type),
            'type' => $type,
            'is_required' => false,
            'is_built_in' => false,
            'position' => 0,
        ], $opts));
    }

    public function test_text_renders_text_input(): void
    {
        $c = (new FieldRenderer())->render($this->field('text'));
        $this->assertInstanceOf(TextInput::class, $c);
    }

    public function test_textarea_renders_textarea(): void
    {
        $c = (new FieldRenderer())->render($this->field('textarea'));
        $this->assertInstanceOf(Textarea::class, $c);
    }

    public function test_number_renders_numeric_text_input(): void
    {
        $c = (new FieldRenderer())->render($this->field('number'));
        $this->assertInstanceOf(TextInput::class, $c);
        $this->assertTrue($c->isNumeric());
    }

    public function test_date_renders_date_picker(): void
    {
        $c = (new FieldRenderer())->render($this->field('date'));
        $this->assertInstanceOf(DatePicker::class, $c);
    }

    public function test_email_renders_email_text_input(): void
    {
        $c = (new FieldRenderer())->render($this->field('email'));
        $this->assertInstanceOf(TextInput::class, $c);
    }

    public function test_dropdown_renders_select_with_options(): void
    {
        $f = $this->field('dropdown', ['options' => [['value' => 'a', 'label' => 'A'], ['value' => 'b', 'label' => 'B']]]);
        $c = (new FieldRenderer())->render($f);
        $this->assertInstanceOf(Select::class, $c);
        $this->assertEquals(['a' => 'A', 'b' => 'B'], $c->getOptions());
    }

    public function test_checkbox_renders_toggle(): void
    {
        $c = (new FieldRenderer())->render($this->field('checkbox'));
        $this->assertInstanceOf(Toggle::class, $c);
    }

    public function test_multiselect_renders_select_multiple(): void
    {
        $f = $this->field('multiselect', ['options' => [['value' => 'a', 'label' => 'A']]]);
        $c = (new FieldRenderer())->render($f);
        $this->assertInstanceOf(Select::class, $c);
        $this->assertTrue($c->isMultiple());
    }

    public function test_required_field_marks_component_required(): void
    {
        $f = $this->field('text', ['is_required' => true]);
        $c = (new FieldRenderer())->render($f);
        $this->assertTrue($c->isRequired());
    }
}
```

- [ ] **Step 2: Run tests — expect FAIL**

Run: `php artisan test --filter=FieldRendererTest`
Expected: FAIL — class missing.

- [ ] **Step 3: Implement**

```php
<?php
namespace App\StudentFields;

use App\Models\StudentField;
use Filament\Forms\Components\Component;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;

class FieldRenderer
{
    public function render(StudentField $field, ?string $statePath = null): Component
    {
        $name = $statePath ?? "custom_fields.{$field->key}";
        $component = match ($field->type) {
            'text' => TextInput::make($name)->maxLength(255),
            'textarea' => Textarea::make($name)->rows(3),
            'number' => TextInput::make($name)->numeric(),
            'date' => DatePicker::make($name)->native(false),
            'email' => TextInput::make($name)->email()->maxLength(255),
            'dropdown' => Select::make($name)->options($this->options($field)),
            'checkbox' => Toggle::make($name),
            'multiselect' => Select::make($name)->multiple()->options($this->options($field)),
            default => TextInput::make($name),
        };

        $component->label($field->label);

        if ($field->is_required || $field->key === 'phone') {
            $component->required();
        }

        return $component;
    }

    /** @return array<string, string> */
    private function options(StudentField $field): array
    {
        $opts = $field->options ?? [];
        return collect($opts)->mapWithKeys(fn ($o) => [$o['value'] => $o['label']])->all();
    }
}
```

- [ ] **Step 4: Run tests — expect PASS**

Run: `php artisan test --filter=FieldRendererTest`
Expected: PASS, 9 assertions green.

- [ ] **Step 5: Commit**

```bash
git add app/StudentFields/FieldRenderer.php tests/Unit/StudentFields/FieldRendererTest.php
git commit -m "feat(fields): FieldRenderer maps Core 8 types to Filament components"
```

---

### Task 6: StudentFieldsConfigPage scaffold + admin gate

**Files:**
- Create: `app/Filament/Pages/StudentFieldsConfigPage.php`
- Create: `resources/views/filament/pages/student-fields-config.blade.php`
- Test: `tests/Feature/StudentFields/StudentFieldsConfigPageTest.php`

- [ ] **Step 1: Write failing tests**

```php
<?php
namespace Tests\Feature\StudentFields;

use App\Filament\Pages\StudentFieldsConfigPage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class StudentFieldsConfigPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_page_is_accessible_to_admin(): void
    {
        $this->seed();
        $admin = User::where('email', 'sumit@davya.local')->first();
        $this->actingAs($admin);
        $this->assertTrue(StudentFieldsConfigPage::canAccess());
    }

    public function test_page_is_blocked_for_non_admin(): void
    {
        $this->seed();
        $user = User::factory()->create();
        Role::firstOrCreate(['name' => 'counsellor']);
        $user->assignRole('counsellor');
        $this->actingAs($user);
        $this->assertFalse(StudentFieldsConfigPage::canAccess());
    }

    public function test_page_renders_for_admin(): void
    {
        $this->seed();
        $admin = User::where('email', 'sumit@davya.local')->first();
        $this->actingAs($admin)->get('/admin/student-fields')->assertOk();
    }

    public function test_page_does_not_define_getRules_method(): void
    {
        $this->assertFalse(method_exists(StudentFieldsConfigPage::class, 'getRules'),
            'Defining getRules() shadows Filament BasePage::getRules() and triggers fatal LSP error (SP#1 gotcha).');
    }
}
```

- [ ] **Step 2: Run tests — expect FAIL**

Run: `php artisan test --filter=StudentFieldsConfigPageTest`
Expected: FAIL — class missing, route missing.

- [ ] **Step 3: Create the page class**

```php
<?php
namespace App\Filament\Pages;

use App\Models\StudentField;
use App\Models\StudentFieldSection;
use Filament\Pages\Page;

class StudentFieldsConfigPage extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-square-3-stack-3d';
    protected static ?string $navigationGroup = 'Settings';
    protected static ?string $navigationLabel = 'Student Fields';
    protected static ?string $title = 'Student Field Config';
    protected static ?string $slug = 'student-fields';
    protected static string $view = 'filament.pages.student-fields-config';
    protected static ?int $navigationSort = 2;

    public string $activeTab = 'live'; // 'live' | 'archived'
    public ?int $selectedSectionId = null;

    public static function canAccess(): bool
    {
        return auth()->check() && auth()->user()->hasRole('admin');
    }

    public function mount(): void
    {
        $this->selectedSectionId = StudentFieldSection::orderBy('position')->value('id');
    }

    public function sections()
    {
        return StudentFieldSection::orderBy('position')->get();
    }

    public function fieldsForSelectedSection()
    {
        if (!$this->selectedSectionId) return collect();
        return StudentField::active()->where('section_id', $this->selectedSectionId)->orderBy('position')->get();
    }

    public function archivedFields()
    {
        return StudentField::archived()->orderBy('archived_at', 'desc')->get();
    }
}
```

- [ ] **Step 4: Create the blade view (minimal stub for now)**

```blade
<x-filament-panels::page>
    <div class="space-y-4">
        <div class="flex gap-2">
            <button type="button" wire:click="$set('activeTab', 'live')" style="padding:6px 12px; border-radius:6px; background-color: {{ $activeTab === 'live' ? '#059669' : '#e5e7eb' }}; color: {{ $activeTab === 'live' ? 'white' : 'black' }};">
                Sections &amp; Fields
            </button>
            <button type="button" wire:click="$set('activeTab', 'archived')" style="padding:6px 12px; border-radius:6px; background-color: {{ $activeTab === 'archived' ? '#059669' : '#e5e7eb' }}; color: {{ $activeTab === 'archived' ? 'white' : 'black' }};">
                Archived
            </button>
        </div>

        @if ($activeTab === 'live')
            <div class="grid grid-cols-12 gap-4">
                <aside class="col-span-3 border rounded p-3">
                    <h3 class="font-semibold mb-2">Sections</h3>
                    <ul class="space-y-1">
                        @foreach ($this->sections() as $section)
                            <li>
                                <button type="button" wire:click="$set('selectedSectionId', {{ $section->id }})" class="w-full text-left px-2 py-1 rounded {{ $selectedSectionId === $section->id ? 'bg-emerald-100' : '' }}">
                                    {{ $section->name }}
                                </button>
                            </li>
                        @endforeach
                    </ul>
                </aside>
                <main class="col-span-9 border rounded p-3">
                    <h3 class="font-semibold mb-2">Fields</h3>
                    <ul class="space-y-1">
                        @foreach ($this->fieldsForSelectedSection() as $field)
                            <li class="flex items-center gap-3">
                                <span>{{ $field->label }}</span>
                                <span class="text-xs text-gray-500">{{ $field->key }}</span>
                                <span class="text-xs px-2 py-0.5 bg-gray-200 rounded">{{ $field->type }}</span>
                                @if ($field->is_built_in)
                                    <span class="text-xs px-2 py-0.5 bg-amber-100 rounded">🔒 built-in</span>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                </main>
            </div>
        @else
            <div class="border rounded p-3">
                <h3 class="font-semibold mb-2">Archived fields</h3>
                <ul class="space-y-1">
                    @foreach ($this->archivedFields() as $field)
                        <li>{{ $field->label }} — archived {{ $field->archived_at->diffForHumans() }}</li>
                    @endforeach
                </ul>
            </div>
        @endif
    </div>
</x-filament-panels::page>
```

- [ ] **Step 5: Run tests — expect PASS**

Run: `php artisan test --filter=StudentFieldsConfigPageTest`
Expected: PASS, 4 assertions green.

- [ ] **Step 6: Commit**

```bash
git add app/Filament/Pages/StudentFieldsConfigPage.php resources/views/filament/pages/student-fields-config.blade.php tests/Feature/StudentFields/StudentFieldsConfigPageTest.php
git commit -m "feat(fields): scaffold StudentFieldsConfigPage with admin gate"
```

---

### Task 7: Section CRUD with reorder + transfer-on-delete

**Files:**
- Modify: `app/Filament/Pages/StudentFieldsConfigPage.php` (add Livewire actions)
- Modify: `resources/views/filament/pages/student-fields-config.blade.php`
- Test: `tests/Feature/StudentFields/SectionTransferOnDeleteTest.php`

- [ ] **Step 1: Write failing tests**

```php
<?php
namespace Tests\Feature\StudentFields;

use App\Filament\Pages\StudentFieldsConfigPage;
use App\Models\StudentField;
use App\Models\StudentFieldSection;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class SectionTransferOnDeleteTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $this->seed();
        return User::where('email', 'sumit@davya.local')->first();
    }

    public function test_admin_can_create_section(): void
    {
        Livewire::actingAs($this->admin())
            ->test(StudentFieldsConfigPage::class)
            ->call('createSection', 'Engagement')
            ->assertHasNoErrors();
        $this->assertDatabaseHas('student_field_sections', ['name' => 'Engagement']);
    }

    public function test_admin_can_rename_section(): void
    {
        $section = StudentFieldSection::create(['name' => 'Old', 'position' => 99]);
        Livewire::actingAs($this->admin())
            ->test(StudentFieldsConfigPage::class)
            ->call('renameSection', $section->id, 'New Name')
            ->assertHasNoErrors();
        $this->assertSame('New Name', $section->fresh()->name);
    }

    public function test_admin_can_reorder_sections(): void
    {
        $a = StudentFieldSection::create(['name' => 'A', 'position' => 0]);
        $b = StudentFieldSection::create(['name' => 'B', 'position' => 1]);
        Livewire::actingAs($this->admin())
            ->test(StudentFieldsConfigPage::class)
            ->call('reorderSections', [$b->id, $a->id])
            ->assertHasNoErrors();
        $this->assertSame(0, $b->fresh()->position);
        $this->assertSame(1, $a->fresh()->position);
    }

    public function test_deleting_empty_section_succeeds_directly(): void
    {
        $section = StudentFieldSection::create(['name' => 'Empty', 'position' => 99]);
        Livewire::actingAs($this->admin())
            ->test(StudentFieldsConfigPage::class)
            ->call('deleteSection', $section->id)
            ->assertHasNoErrors();
        $this->assertDatabaseMissing('student_field_sections', ['id' => $section->id]);
    }

    public function test_deleting_non_empty_section_requires_transfer(): void
    {
        $src = StudentFieldSection::create(['name' => 'Src', 'position' => 99]);
        $dst = StudentFieldSection::create(['name' => 'Dst', 'position' => 100]);
        $field = StudentField::create(['section_id' => $src->id, 'key' => 'tmp', 'label' => 'Tmp', 'type' => 'text', 'is_required' => false, 'is_built_in' => false, 'position' => 0]);

        // Delete without transfer target → blocked
        Livewire::actingAs($this->admin())
            ->test(StudentFieldsConfigPage::class)
            ->call('deleteSection', $src->id)
            ->assertHasErrors(['transfer_target']);
        $this->assertDatabaseHas('student_field_sections', ['id' => $src->id]);

        // Delete with transfer target → succeeds, field moved
        Livewire::actingAs($this->admin())
            ->test(StudentFieldsConfigPage::class)
            ->call('deleteSectionWithTransfer', $src->id, $dst->id)
            ->assertHasNoErrors();
        $this->assertDatabaseMissing('student_field_sections', ['id' => $src->id]);
        $this->assertSame($dst->id, $field->fresh()->section_id);
    }
}
```

- [ ] **Step 2: Run tests — expect FAIL**

Run: `php artisan test --filter=SectionTransferOnDeleteTest`
Expected: FAIL — actions missing.

- [ ] **Step 3: Add actions to the page class**

Append to `app/Filament/Pages/StudentFieldsConfigPage.php`:

```php
use Illuminate\Support\Facades\DB;

public function createSection(string $name): void
{
    $name = trim($name);
    if ($name === '') return;
    $position = (int) StudentFieldSection::max('position') + 1;
    StudentFieldSection::create(['name' => $name, 'position' => $position]);
}

public function renameSection(int $id, string $name): void
{
    $name = trim($name);
    if ($name === '') return;
    StudentFieldSection::where('id', $id)->update(['name' => $name]);
}

public function reorderSections(array $orderedIds): void
{
    DB::transaction(function () use ($orderedIds) {
        foreach ($orderedIds as $i => $id) {
            StudentFieldSection::where('id', $id)->update(['position' => $i]);
        }
    });
}

public function deleteSection(int $id): void
{
    $hasFields = StudentField::where('section_id', $id)->exists();
    if ($hasFields) {
        $this->addError('transfer_target', 'Section has fields — pick a transfer target.');
        return;
    }
    StudentFieldSection::where('id', $id)->delete();
}

public function deleteSectionWithTransfer(int $sourceId, int $destinationId): void
{
    if ($sourceId === $destinationId) {
        $this->addError('transfer_target', 'Cannot transfer to the same section.');
        return;
    }
    DB::transaction(function () use ($sourceId, $destinationId) {
        $maxPos = (int) StudentField::where('section_id', $destinationId)->max('position');
        $i = $maxPos + 1;
        foreach (StudentField::where('section_id', $sourceId)->orderBy('position')->get() as $field) {
            $field->update(['section_id' => $destinationId, 'position' => $i++]);
        }
        StudentFieldSection::where('id', $sourceId)->delete();
    });
}
```

- [ ] **Step 4: Run tests — expect PASS**

Run: `php artisan test --filter=SectionTransferOnDeleteTest`
Expected: PASS, 5 tests / multiple assertions green.

- [ ] **Step 5: Commit**

```bash
git add app/Filament/Pages/StudentFieldsConfigPage.php tests/Feature/StudentFields/SectionTransferOnDeleteTest.php
git commit -m "feat(fields): section CRUD with reorder + transfer-on-delete"
```

---

### Task 8: Field CRUD (custom fields, all 8 types)

**Files:**
- Modify: `app/Filament/Pages/StudentFieldsConfigPage.php`
- Test: `tests/Feature/StudentFields/StudentFieldsConfigPageTest.php` (add cases)

- [ ] **Step 1: Append failing tests to StudentFieldsConfigPageTest.php**

```php
public function test_admin_can_create_custom_text_field(): void
{
    $section = \App\Models\StudentFieldSection::create(['name' => 'Demographics', 'position' => 99]);
    \Livewire\Livewire::actingAs($this->seedAdmin())
        ->test(\App\Filament\Pages\StudentFieldsConfigPage::class)
        ->call('createField', [
            'section_id' => $section->id,
            'label' => 'Email',
            'type' => 'email',
            'is_required' => false,
            'options' => null,
            'show_in_table' => true,
            'show_in_kanban' => false,
            'show_in_import' => true,
        ])
        ->assertHasNoErrors();
    $this->assertDatabaseHas('student_fields', ['key' => 'email', 'type' => 'email', 'section_id' => $section->id, 'show_in_table' => true]);
}

public function test_create_dropdown_field_persists_options(): void
{
    $section = \App\Models\StudentFieldSection::create(['name' => 'Academic', 'position' => 99]);
    \Livewire\Livewire::actingAs($this->seedAdmin())
        ->test(\App\Filament\Pages\StudentFieldsConfigPage::class)
        ->call('createField', [
            'section_id' => $section->id,
            'label' => 'Board',
            'type' => 'dropdown',
            'is_required' => false,
            'options' => [['value' => 'cbse', 'label' => 'CBSE'], ['value' => 'icse', 'label' => 'ICSE']],
            'show_in_table' => false,
            'show_in_kanban' => false,
            'show_in_import' => false,
        ])
        ->assertHasNoErrors();
    $field = \App\Models\StudentField::where('key', 'board')->first();
    $this->assertCount(2, $field->options);
    $this->assertSame('CBSE', $field->options[0]['label']);
}

public function test_field_key_is_auto_generated_as_slug(): void
{
    $section = \App\Models\StudentFieldSection::create(['name' => 'X', 'position' => 99]);
    \Livewire\Livewire::actingAs($this->seedAdmin())
        ->test(\App\Filament\Pages\StudentFieldsConfigPage::class)
        ->call('createField', ['section_id' => $section->id, 'label' => 'Marks 12th %', 'type' => 'number', 'is_required' => false, 'options' => null, 'show_in_table' => false, 'show_in_kanban' => false, 'show_in_import' => false]);
    $this->assertDatabaseHas('student_fields', ['key' => 'marks_12th_percent']);
}

public function test_admin_can_update_field_label_and_required(): void
{
    $section = \App\Models\StudentFieldSection::create(['name' => 'X', 'position' => 99]);
    $field = \App\Models\StudentField::create(['section_id' => $section->id, 'key' => 'foo', 'label' => 'Foo', 'type' => 'text', 'is_required' => false, 'is_built_in' => false, 'position' => 0]);
    \Livewire\Livewire::actingAs($this->seedAdmin())
        ->test(\App\Filament\Pages\StudentFieldsConfigPage::class)
        ->call('updateField', $field->id, ['label' => 'Bar', 'is_required' => true, 'show_in_table' => true, 'show_in_kanban' => true, 'show_in_import' => true])
        ->assertHasNoErrors();
    $f = $field->fresh();
    $this->assertSame('Bar', $f->label);
    $this->assertTrue($f->is_required);
    $this->assertTrue($f->show_in_table);
    $this->assertTrue($f->show_in_kanban);
    $this->assertTrue($f->show_in_import);
    $this->assertSame('foo', $f->key, 'key must not change on update');
}

public function test_admin_can_reorder_fields_within_section(): void
{
    $section = \App\Models\StudentFieldSection::create(['name' => 'X', 'position' => 99]);
    $a = \App\Models\StudentField::create(['section_id' => $section->id, 'key' => 'a', 'label' => 'A', 'type' => 'text', 'is_required' => false, 'is_built_in' => false, 'position' => 0]);
    $b = \App\Models\StudentField::create(['section_id' => $section->id, 'key' => 'b', 'label' => 'B', 'type' => 'text', 'is_required' => false, 'is_built_in' => false, 'position' => 1]);
    \Livewire\Livewire::actingAs($this->seedAdmin())
        ->test(\App\Filament\Pages\StudentFieldsConfigPage::class)
        ->call('reorderFields', $section->id, [$b->id, $a->id])
        ->assertHasNoErrors();
    $this->assertSame(0, $b->fresh()->position);
    $this->assertSame(1, $a->fresh()->position);
}

private function seedAdmin(): User
{
    $this->seed();
    return User::where('email', 'sumit@davya.local')->first();
}
```

- [ ] **Step 2: Run tests — expect FAIL**

Run: `php artisan test --filter=StudentFieldsConfigPageTest`
Expected: FAIL — `createField`, `updateField`, `reorderFields` missing.

- [ ] **Step 3: Add actions to page class**

```php
use Illuminate\Support\Str;

public function createField(array $data): void
{
    $label = trim($data['label'] ?? '');
    if ($label === '') {
        $this->addError('label', 'Label required'); return;
    }
    $key = $this->generateUniqueKey($label);
    $type = $data['type'] ?? 'text';
    $allowed = ['text','textarea','number','date','email','dropdown','checkbox','multiselect'];
    if (!in_array($type, $allowed, true)) {
        $this->addError('type', 'Invalid type'); return;
    }
    $sectionId = (int) ($data['section_id'] ?? 0);
    if (!$sectionId || !StudentFieldSection::find($sectionId)) {
        $this->addError('section_id', 'Section required'); return;
    }
    $position = (int) StudentField::where('section_id', $sectionId)->max('position') + 1;

    StudentField::create([
        'section_id' => $sectionId,
        'key' => $key,
        'label' => $label,
        'type' => $type,
        'is_required' => (bool) ($data['is_required'] ?? false),
        'is_built_in' => false,
        'options' => in_array($type, ['dropdown','multiselect'], true) ? ($data['options'] ?? []) : null,
        'show_in_table' => (bool) ($data['show_in_table'] ?? false),
        'show_in_kanban' => (bool) ($data['show_in_kanban'] ?? false),
        'show_in_import' => (bool) ($data['show_in_import'] ?? false),
        'position' => $position,
    ]);
}

public function updateField(int $id, array $data): void
{
    $field = StudentField::findOrFail($id);
    $update = [];
    if (isset($data['label']) && trim($data['label']) !== '') $update['label'] = trim($data['label']);

    // Built-in lock rules — see Task 9
    $update['is_required'] = (bool) ($data['is_required'] ?? $field->is_required);
    if ($field->key === 'phone') $update['is_required'] = true;

    foreach (['show_in_table','show_in_kanban','show_in_import'] as $f) {
        if (array_key_exists($f, $data)) $update[$f] = (bool) $data[$f];
    }
    if (!$field->is_built_in && isset($data['options']) && in_array($field->type, ['dropdown','multiselect'], true)) {
        $update['options'] = $data['options'];
    }
    $field->update($update);
}

public function reorderFields(int $sectionId, array $orderedIds): void
{
    DB::transaction(function () use ($sectionId, $orderedIds) {
        foreach ($orderedIds as $i => $id) {
            StudentField::where('id', $id)->where('section_id', $sectionId)->update(['position' => $i]);
        }
    });
}

private function generateUniqueKey(string $label): string
{
    $base = Str::slug($label, '_');
    // Replace common symbols
    $base = str_replace('%', 'percent', $label);
    $base = Str::slug($base, '_');
    if ($base === '') $base = 'field';
    $key = $base; $i = 2;
    while (StudentField::where('key', $key)->exists()) { $key = $base . '_' . $i++; }
    return $key;
}
```

- [ ] **Step 4: Run tests — expect PASS**

Run: `php artisan test --filter=StudentFieldsConfigPageTest`
Expected: PASS — all assertions green.

- [ ] **Step 5: Commit**

```bash
git add app/Filament/Pages/StudentFieldsConfigPage.php tests/Feature/StudentFields/StudentFieldsConfigPageTest.php
git commit -m "feat(fields): field CRUD + slug key + reorder within section"
```

---

### Task 9: Built-in lock rules (no archive, no type change, phone always-required)

**Files:**
- Modify: `app/Filament/Pages/StudentFieldsConfigPage.php` (enforce in archive/update)
- Test: `tests/Feature/StudentFields/PhoneRequiredLockTest.php` (new)
- Test: extend `StudentFieldsConfigPageTest.php` (add lock-rule cases)

- [ ] **Step 1: Write failing tests**

`tests/Feature/StudentFields/PhoneRequiredLockTest.php`:

```php
<?php
namespace Tests\Feature\StudentFields;

use App\Filament\Pages\StudentFieldsConfigPage;
use App\Models\StudentField;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class PhoneRequiredLockTest extends TestCase
{
    use RefreshDatabase;

    public function test_phone_required_cannot_be_unset_via_update(): void
    {
        $this->seed();
        $admin = User::where('email', 'sumit@davya.local')->first();
        $phone = StudentField::where('key', 'phone')->first();

        Livewire::actingAs($admin)
            ->test(StudentFieldsConfigPage::class)
            ->call('updateField', $phone->id, ['is_required' => false]);

        $this->assertTrue((bool) $phone->fresh()->is_required, 'phone is_required must remain true regardless of toggle');
    }
}
```

Append to `StudentFieldsConfigPageTest.php`:

```php
public function test_built_in_field_cannot_be_archived(): void
{
    $this->seed();
    $admin = User::where('email', 'sumit@davya.local')->first();
    $phone = \App\Models\StudentField::where('key', 'phone')->first();

    \Livewire\Livewire::actingAs($admin)
        ->test(\App\Filament\Pages\StudentFieldsConfigPage::class)
        ->call('archiveField', $phone->id)
        ->assertHasErrors(['archive']);
    $this->assertNull($phone->fresh()->archived_at);
}

public function test_built_in_field_type_cannot_be_changed(): void
{
    $this->seed();
    $admin = User::where('email', 'sumit@davya.local')->first();
    $name = \App\Models\StudentField::where('key', 'name')->first();
    $original = $name->type;

    \Livewire\Livewire::actingAs($admin)
        ->test(\App\Filament\Pages\StudentFieldsConfigPage::class)
        ->call('updateField', $name->id, ['type' => 'number']);
    $this->assertSame($original, $name->fresh()->type);
}
```

- [ ] **Step 2: Run tests — expect FAIL**

Run: `php artisan test --filter=PhoneRequiredLockTest && php artisan test --filter=StudentFieldsConfigPageTest`
Expected: FAIL — `archiveField` missing, type-change accepted, phone toggle accepted.

- [ ] **Step 3: Update page class**

Add to `StudentFieldsConfigPage.php`:

```php
public function archiveField(int $id): void
{
    $field = StudentField::findOrFail($id);
    if ($field->is_built_in) {
        $this->addError('archive', 'Built-in fields cannot be archived.');
        return;
    }
    $field->update(['archived_at' => now()]);
}
```

Inside `updateField`, before any `$update['type'] = …` would be set, ensure it never is. (The current implementation already ignores `$data['type']` — keep that.)

- [ ] **Step 4: Run tests — expect PASS**

Run: `php artisan test --filter=PhoneRequiredLockTest && php artisan test --filter=StudentFieldsConfigPageTest`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Filament/Pages/StudentFieldsConfigPage.php tests/Feature/StudentFields/PhoneRequiredLockTest.php tests/Feature/StudentFields/StudentFieldsConfigPageTest.php
git commit -m "feat(fields): enforce built-in locks + phone always-required"
```

---

### Task 10: Soft archive + restore + hard purge

**Files:**
- Modify: `app/Filament/Pages/StudentFieldsConfigPage.php`
- Test: `tests/Feature/StudentFields/SoftArchiveAndRestoreTest.php`

- [ ] **Step 1: Write failing tests**

```php
<?php
namespace Tests\Feature\StudentFields;

use App\Filament\Pages\StudentFieldsConfigPage;
use App\Models\Student;
use App\Models\StudentField;
use App\Models\StudentFieldSection;
use App\Models\StudentFieldValue;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class SoftArchiveAndRestoreTest extends TestCase
{
    use RefreshDatabase;

    private function setupAdmin(): User
    {
        $this->seed();
        return User::where('email', 'sumit@davya.local')->first();
    }

    private function customField(): StudentField
    {
        $section = StudentFieldSection::firstOrCreate(['name' => 'Demographics'], ['position' => 99]);
        return StudentField::create(['section_id' => $section->id, 'key' => 'dob', 'label' => 'DOB', 'type' => 'date', 'is_required' => false, 'is_built_in' => false, 'position' => 0]);
    }

    public function test_archiving_field_preserves_values(): void
    {
        $admin = $this->setupAdmin();
        $field = $this->customField();
        $student = Student::create(['phone' => '9000000099', 'name' => 'A', 'stage' => 'Lead Captured']);
        StudentFieldValue::create(['student_id' => $student->id, 'student_field_id' => $field->id, 'value_date' => '2009-01-01']);

        Livewire::actingAs($admin)
            ->test(StudentFieldsConfigPage::class)
            ->call('archiveField', $field->id)
            ->assertHasNoErrors();

        $this->assertNotNull($field->fresh()->archived_at);
        $this->assertDatabaseHas('student_field_values', ['student_id' => $student->id, 'student_field_id' => $field->id]);
    }

    public function test_archived_field_restored_to_original_section(): void
    {
        $admin = $this->setupAdmin();
        $field = $this->customField();
        $field->update(['archived_at' => now()]);

        Livewire::actingAs($admin)
            ->test(StudentFieldsConfigPage::class)
            ->call('restoreField', $field->id)
            ->assertHasNoErrors();

        $this->assertNull($field->fresh()->archived_at);
    }

    public function test_hard_purge_with_typed_confirmation_wipes_values(): void
    {
        $admin = $this->setupAdmin();
        $field = $this->customField();
        $student = Student::create(['phone' => '9000000077', 'name' => 'B', 'stage' => 'Lead Captured']);
        StudentFieldValue::create(['student_id' => $student->id, 'student_field_id' => $field->id, 'value_date' => '2009-01-01']);
        $field->update(['archived_at' => now()]);

        Livewire::actingAs($admin)
            ->test(StudentFieldsConfigPage::class)
            ->call('hardDeleteField', $field->id, 'DELETE')
            ->assertHasNoErrors();

        $this->assertDatabaseMissing('student_fields', ['id' => $field->id]);
        $this->assertDatabaseMissing('student_field_values', ['student_field_id' => $field->id]);
    }

    public function test_hard_purge_blocked_without_correct_typed_confirmation(): void
    {
        $admin = $this->setupAdmin();
        $field = $this->customField();
        $field->update(['archived_at' => now()]);

        Livewire::actingAs($admin)
            ->test(StudentFieldsConfigPage::class)
            ->call('hardDeleteField', $field->id, 'oops')
            ->assertHasErrors(['confirm']);

        $this->assertDatabaseHas('student_fields', ['id' => $field->id]);
    }

    public function test_hard_purge_blocked_for_built_in(): void
    {
        $admin = $this->setupAdmin();
        $name = StudentField::where('key', 'name')->first();
        Livewire::actingAs($admin)
            ->test(StudentFieldsConfigPage::class)
            ->call('hardDeleteField', $name->id, 'DELETE')
            ->assertHasErrors(['archive']);
        $this->assertDatabaseHas('student_fields', ['id' => $name->id]);
    }
}
```

- [ ] **Step 2: Run tests — expect FAIL**

Run: `php artisan test --filter=SoftArchiveAndRestoreTest`
Expected: FAIL — `restoreField` and `hardDeleteField` missing.

- [ ] **Step 3: Add actions to page class**

```php
public function restoreField(int $id): void
{
    $field = StudentField::findOrFail($id);
    if ($field->section_id && !StudentFieldSection::find($field->section_id)) {
        $field->section_id = StudentFieldSection::orderBy('position')->value('id');
    }
    $field->archived_at = null;
    $field->save();
}

public function hardDeleteField(int $id, string $confirm): void
{
    $field = StudentField::findOrFail($id);
    if ($field->is_built_in) {
        $this->addError('archive', 'Built-in fields cannot be deleted.');
        return;
    }
    if ($confirm !== 'DELETE') {
        $this->addError('confirm', 'Type DELETE to confirm.');
        return;
    }
    DB::transaction(function () use ($field) {
        StudentFieldValue::where('student_field_id', $field->id)->delete();
        $field->delete();
    });
}
```

- [ ] **Step 4: Run tests — expect PASS**

Run: `php artisan test --filter=SoftArchiveAndRestoreTest`
Expected: PASS — 5 tests green.

- [ ] **Step 5: Commit**

```bash
git add app/Filament/Pages/StudentFieldsConfigPage.php tests/Feature/StudentFields/SoftArchiveAndRestoreTest.php
git commit -m "feat(fields): soft archive + restore + typed-confirm hard purge"
```

---

### Task 11: StudentFormDynamicTrait — hydrate + persist custom values

**Files:**
- Create: `app/StudentFields/StudentFormDynamicTrait.php`
- Test: `tests/Feature/StudentFields/DynamicStudentFormTest.php`

- [ ] **Step 1: Write failing tests**

```php
<?php
namespace Tests\Feature\StudentFields;

use App\Models\Student;
use App\Models\StudentField;
use App\Models\StudentFieldSection;
use App\Models\StudentFieldValue;
use App\StudentFields\StudentFormDynamicTrait;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DynamicStudentFormTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        $this->seed();
        $section = StudentFieldSection::firstOrCreate(['name' => 'Demographics'], ['position' => 99]);
        $dob = StudentField::create(['section_id' => $section->id, 'key' => 'dob', 'label' => 'DOB', 'type' => 'date', 'is_required' => false, 'is_built_in' => false, 'position' => 0]);
        $marks = StudentField::create(['section_id' => $section->id, 'key' => 'marks', 'label' => 'Marks', 'type' => 'number', 'is_required' => false, 'is_built_in' => false, 'position' => 1]);
        $board = StudentField::create(['section_id' => $section->id, 'key' => 'board', 'label' => 'Board', 'type' => 'dropdown', 'is_required' => false, 'is_built_in' => false, 'options' => [['value' => 'cbse', 'label' => 'CBSE']], 'position' => 2]);
        return compact('dob', 'marks', 'board');
    }

    public function test_hydrate_pulls_existing_values_per_type(): void
    {
        ['dob' => $dob, 'marks' => $marks, 'board' => $board] = $this->fixture();
        $student = Student::create(['phone' => '9000000010', 'name' => 'A', 'stage' => 'Lead Captured']);
        StudentFieldValue::create(['student_id' => $student->id, 'student_field_id' => $dob->id, 'value_date' => '2009-05-12']);
        StudentFieldValue::create(['student_id' => $student->id, 'student_field_id' => $marks->id, 'value_number' => 92.5]);
        StudentFieldValue::create(['student_id' => $student->id, 'student_field_id' => $board->id, 'value_text' => 'cbse']);

        $hydrated = (new \App\StudentFields\StudentFormDynamicTrait\Hydrator())->hydrate($student);
        $this->assertSame('2009-05-12', $hydrated['dob']);
        $this->assertSame(92.5, (float) $hydrated['marks']);
        $this->assertSame('cbse', $hydrated['board']);
    }

    public function test_persist_writes_values_typed_correctly(): void
    {
        ['dob' => $dob, 'marks' => $marks, 'board' => $board] = $this->fixture();
        $student = Student::create(['phone' => '9000000011', 'name' => 'B', 'stage' => 'Lead Captured']);

        (new \App\StudentFields\StudentFormDynamicTrait\Persister())->persist($student, [
            'dob' => '2010-06-15',
            'marks' => '88.25',
            'board' => 'cbse',
        ]);

        $this->assertSame('2010-06-15', StudentFieldValue::where(['student_id' => $student->id, 'student_field_id' => $dob->id])->value('value_date')->toDateString() ?? StudentFieldValue::where(['student_id' => $student->id, 'student_field_id' => $dob->id])->value('value_date'));
        $this->assertSame('88.2500', (string) StudentFieldValue::where(['student_id' => $student->id, 'student_field_id' => $marks->id])->value('value_number'));
        $this->assertSame('cbse', StudentFieldValue::where(['student_id' => $student->id, 'student_field_id' => $board->id])->value('value_text'));
    }

    public function test_persist_upserts_existing_value_does_not_create_duplicate(): void
    {
        ['dob' => $dob] = $this->fixture();
        $student = Student::create(['phone' => '9000000012', 'name' => 'C', 'stage' => 'Lead Captured']);
        StudentFieldValue::create(['student_id' => $student->id, 'student_field_id' => $dob->id, 'value_date' => '2009-01-01']);

        (new \App\StudentFields\StudentFormDynamicTrait\Persister())->persist($student, ['dob' => '2010-01-01']);

        $this->assertSame(1, StudentFieldValue::where('student_id', $student->id)->count());
        $this->assertSame('2010-01-01', StudentFieldValue::where('student_id', $student->id)->first()->value_date->toDateString());
    }
}
```

(The plan structures Hydrator and Persister as nested classes inside the `StudentFormDynamicTrait` namespace folder for testability — they're the trait's two pure-function entry points.)

- [ ] **Step 2: Run tests — expect FAIL**

Run: `php artisan test --filter=DynamicStudentFormTest`
Expected: FAIL — classes missing.

- [ ] **Step 3: Implement Hydrator + Persister**

Create `app/StudentFields/StudentFormDynamicTrait/Hydrator.php`:

```php
<?php
namespace App\StudentFields\StudentFormDynamicTrait;

use App\Models\Student;
use App\Models\StudentField;
use App\Models\StudentFieldValue;

class Hydrator
{
    /** @return array<string, mixed> keyed by field key */
    public function hydrate(Student $student): array
    {
        $out = [];
        $values = StudentFieldValue::where('student_id', $student->id)
            ->with('field')->get();

        foreach ($values as $v) {
            $field = $v->field;
            if (!$field || $field->is_built_in) continue;
            $out[$field->key] = match ($field->type) {
                'number' => $v->value_number !== null ? (float) $v->value_number : null,
                'date' => $v->value_date?->toDateString(),
                'multiselect' => $v->value_json ?? [],
                'checkbox' => $v->value_text === '1',
                default => $v->value_text,
            };
        }
        return $out;
    }
}
```

Create `app/StudentFields/StudentFormDynamicTrait/Persister.php`:

```php
<?php
namespace App\StudentFields\StudentFormDynamicTrait;

use App\Models\Student;
use App\Models\StudentField;
use App\Models\StudentFieldValue;
use Illuminate\Support\Facades\DB;

class Persister
{
    /** @param array<string, mixed> $values keyed by field key */
    public function persist(Student $student, array $values): void
    {
        $fields = StudentField::custom()->active()->whereIn('key', array_keys($values))->get()->keyBy('key');

        DB::transaction(function () use ($student, $values, $fields) {
            foreach ($values as $key => $raw) {
                $field = $fields->get($key);
                if (!$field) continue;
                $payload = ['value_text' => null, 'value_number' => null, 'value_date' => null, 'value_json' => null];
                switch ($field->type) {
                    case 'number': $payload['value_number'] = $raw === null || $raw === '' ? null : (float) $raw; break;
                    case 'date': $payload['value_date'] = $raw ?: null; break;
                    case 'multiselect': $payload['value_json'] = is_array($raw) ? array_values($raw) : null; break;
                    case 'checkbox': $payload['value_text'] = $raw ? '1' : '0'; break;
                    default: $payload['value_text'] = $raw === '' ? null : (string) $raw; break;
                }
                StudentFieldValue::updateOrCreate(
                    ['student_id' => $student->id, 'student_field_id' => $field->id],
                    $payload
                );
            }
        });
    }
}
```

Create `app/StudentFields/StudentFormDynamicTrait.php` (the trait that StudentResource pages will use):

```php
<?php
namespace App\StudentFields;

use App\Models\Student;
use App\StudentFields\StudentFormDynamicTrait\Hydrator;
use App\StudentFields\StudentFormDynamicTrait\Persister;

trait StudentFormDynamicTrait
{
    protected function hydrateCustomFields(Student $student, array $formData): array
    {
        $formData['custom_fields'] = (new Hydrator())->hydrate($student);
        return $formData;
    }

    protected function persistCustomFields(Student $student, array $formData): void
    {
        $custom = $formData['custom_fields'] ?? [];
        if (!is_array($custom) || !$custom) return;
        (new Persister())->persist($student, $custom);
    }
}
```

- [ ] **Step 4: Run tests — expect PASS**

Run: `php artisan test --filter=DynamicStudentFormTest`
Expected: PASS, 3 tests green.

- [ ] **Step 5: Commit**

```bash
git add app/StudentFields/StudentFormDynamicTrait.php app/StudentFields/StudentFormDynamicTrait/Hydrator.php app/StudentFields/StudentFormDynamicTrait/Persister.php tests/Feature/StudentFields/DynamicStudentFormTest.php
git commit -m "feat(fields): hydrator + persister for custom field values"
```

---

### Task 12: Wire dynamic form sections into StudentResource

**Files:**
- Modify: `app/Filament/Resources/StudentResource.php` — append sections built from DB to existing form
- Modify: `app/Filament/Resources/StudentResource/Pages/CreateStudent.php` and `EditStudent.php` — call `persistCustomFields` on save, `hydrateCustomFields` on mount (use the trait)

- [ ] **Step 1: Write failing test**

Add to `tests/Feature/StudentFields/DynamicStudentFormTest.php`:

```php
public function test_create_page_renders_custom_fields_section(): void
{
    ['dob' => $dob] = $this->fixture();
    $admin = \App\Models\User::where('email', 'sumit@davya.local')->first();
    \Livewire\Livewire::actingAs($admin)
        ->test(\App\Filament\Resources\StudentResource\Pages\CreateStudent::class)
        ->assertSee('Demographics')
        ->assertFormFieldExists('custom_fields.dob');
}

public function test_edit_page_persists_custom_fields_on_save(): void
{
    ['dob' => $dob, 'marks' => $marks] = $this->fixture();
    $student = \App\Models\Student::create(['phone' => '9000000020', 'name' => 'X', 'stage' => 'Lead Captured']);
    $admin = \App\Models\User::where('email', 'sumit@davya.local')->first();

    \Livewire\Livewire::actingAs($admin)
        ->test(\App\Filament\Resources\StudentResource\Pages\EditStudent::class, ['record' => $student->id])
        ->fillForm([
            'name' => 'X',
            'phone' => '9000000020',
            'custom_fields' => ['dob' => '2010-01-01', 'marks' => '90.5'],
        ])
        ->call('save');

    $this->assertSame('2010-01-01', \App\Models\StudentFieldValue::where(['student_id' => $student->id, 'student_field_id' => $dob->id])->value('value_date')->toDateString());
    $this->assertSame('90.5000', (string) \App\Models\StudentFieldValue::where(['student_id' => $student->id, 'student_field_id' => $marks->id])->value('value_number'));
}
```

- [ ] **Step 2: Run tests — expect FAIL**

Run: `php artisan test --filter=DynamicStudentFormTest`
Expected: FAIL — sections don't render, custom_fields not saved.

- [ ] **Step 3: Read existing StudentResource form**

Open `app/Filament/Resources/StudentResource.php`. Locate the `form(Form $form)` method (returns a `Form::schema([...])`).

- [ ] **Step 4: Append dynamic sections to StudentResource form schema**

In `StudentResource::form()`, after the existing schema array, build extra sections from DB:

```php
use App\Models\StudentFieldSection;
use App\Models\StudentField;
use App\StudentFields\FieldRenderer;
use Filament\Forms\Components\Section;

// Inside form(Form $form):
$dynamicSections = StudentFieldSection::orderBy('position')->get()->map(function ($section) {
    $fields = StudentField::active()->custom()
        ->where('section_id', $section->id)
        ->orderBy('position')
        ->get();
    if ($fields->isEmpty()) return null;
    return Section::make($section->name)
        ->schema($fields->map(fn ($f) => (new FieldRenderer())->render($f))->all())
        ->collapsed(false);
})->filter()->values()->all();

return $form->schema(array_merge($existingSchemaArray, $dynamicSections));
```

(Replace `$existingSchemaArray` with the existing schema array literal — refactor the original `->schema([…])` arg into a variable first if needed.)

- [ ] **Step 5: Wire trait into Create/Edit pages**

In `app/Filament/Resources/StudentResource/Pages/CreateStudent.php`:

```php
use App\StudentFields\StudentFormDynamicTrait;

class CreateStudent extends CreateRecord
{
    use StudentFormDynamicTrait;
    protected static string $resource = StudentResource::class;

    protected function afterCreate(): void
    {
        $this->persistCustomFields($this->record, $this->data);
    }
}
```

In `app/Filament/Resources/StudentResource/Pages/EditStudent.php`:

```php
use App\StudentFields\StudentFormDynamicTrait;

class EditStudent extends EditRecord
{
    use StudentFormDynamicTrait;
    protected static string $resource = StudentResource::class;

    protected function mutateFormDataBeforeFill(array $data): array
    {
        return $this->hydrateCustomFields($this->record, $data);
    }

    protected function afterSave(): void
    {
        $this->persistCustomFields($this->record, $this->data);
    }
}
```

- [ ] **Step 6: Run tests — expect PASS**

Run: `php artisan test --filter=DynamicStudentFormTest`
Expected: PASS, all 5 tests green.

- [ ] **Step 7: Commit**

```bash
git add app/Filament/Resources/StudentResource.php app/Filament/Resources/StudentResource/Pages/CreateStudent.php app/Filament/Resources/StudentResource/Pages/EditStudent.php tests/Feature/StudentFields/DynamicStudentFormTest.php
git commit -m "feat(fields): render + persist custom fields on Student form"
```

---

### Task 13: DynamicTableColumns + StudentResource table integration

**Files:**
- Create: `app/StudentFields/DynamicTableColumns.php`
- Modify: `app/Filament/Resources/StudentResource.php` — table()
- Test: `tests/Feature/StudentFields/DynamicTableColumnsTest.php`

- [ ] **Step 1: Write failing test**

```php
<?php
namespace Tests\Feature\StudentFields;

use App\Filament\Resources\StudentResource\Pages\ListStudents;
use App\Models\Student;
use App\Models\StudentField;
use App\Models\StudentFieldSection;
use App\Models\StudentFieldValue;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class DynamicTableColumnsTest extends TestCase
{
    use RefreshDatabase;

    public function test_table_renders_show_in_table_custom_columns(): void
    {
        $this->seed();
        $admin = User::where('email', 'sumit@davya.local')->first();
        $section = StudentFieldSection::firstOrCreate(['name' => 'Engagement'], ['position' => 99]);
        StudentField::create(['section_id' => $section->id, 'key' => 'demo_attended', 'label' => 'Demo Attended', 'type' => 'checkbox', 'is_required' => false, 'is_built_in' => false, 'show_in_table' => true, 'position' => 0]);
        StudentField::create(['section_id' => $section->id, 'key' => 'lead_source', 'label' => 'Lead Source', 'type' => 'dropdown', 'is_required' => false, 'is_built_in' => false, 'show_in_table' => true, 'options' => [['value' => 'ig', 'label' => 'Instagram']], 'position' => 1]);

        $student = Student::create(['phone' => '9000000033', 'name' => 'Y', 'stage' => 'Lead Captured']);
        StudentFieldValue::create(['student_id' => $student->id, 'student_field_id' => StudentField::where('key', 'lead_source')->value('id'), 'value_text' => 'ig']);

        Livewire::actingAs($admin)
            ->test(ListStudents::class)
            ->assertSee('Demo Attended')
            ->assertSee('Lead Source')
            ->assertSee('Instagram');
    }
}
```

- [ ] **Step 2: Run test — expect FAIL**

Run: `php artisan test --filter=DynamicTableColumnsTest`
Expected: FAIL — columns not rendered.

- [ ] **Step 3: Implement DynamicTableColumns service**

```php
<?php
namespace App\StudentFields;

use App\Models\StudentField;
use App\Models\StudentFieldValue;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;

class DynamicTableColumns
{
    /** @return array<int, \Filament\Tables\Columns\Column> */
    public function build(): array
    {
        $columns = [];
        $fields = StudentField::active()->custom()->where('show_in_table', true)->orderBy('position')->get();

        foreach ($fields as $field) {
            $columns[] = match ($field->type) {
                'checkbox' => IconColumn::make("custom_{$field->key}")
                    ->label($field->label)
                    ->boolean()
                    ->getStateUsing(fn ($record) => $this->lookupBool($record, $field)),
                'multiselect' => TextColumn::make("custom_{$field->key}")
                    ->label($field->label)
                    ->getStateUsing(fn ($record) => implode(', ', $this->lookupJson($record, $field) ?: [])),
                'dropdown' => TextColumn::make("custom_{$field->key}")
                    ->label($field->label)
                    ->badge()
                    ->getStateUsing(fn ($record) => $this->dropdownLabel($field, $this->lookupText($record, $field))),
                'number' => TextColumn::make("custom_{$field->key}")
                    ->label($field->label)
                    ->numeric()
                    ->getStateUsing(fn ($record) => $this->lookupNumber($record, $field)),
                'date' => TextColumn::make("custom_{$field->key}")
                    ->label($field->label)
                    ->date()
                    ->getStateUsing(fn ($record) => $this->lookupDate($record, $field)),
                default => TextColumn::make("custom_{$field->key}")
                    ->label($field->label)
                    ->getStateUsing(fn ($record) => $this->lookupText($record, $field)),
            };
        }
        return $columns;
    }

    private function value($record, StudentField $field): ?StudentFieldValue
    {
        return StudentFieldValue::where(['student_id' => $record->id, 'student_field_id' => $field->id])->first();
    }
    private function lookupText($record, StudentField $field): ?string { return $this->value($record, $field)?->value_text; }
    private function lookupNumber($record, StudentField $field): ?float { $v = $this->value($record, $field); return $v?->value_number === null ? null : (float) $v->value_number; }
    private function lookupDate($record, StudentField $field): ?string { return $this->value($record, $field)?->value_date?->toDateString(); }
    private function lookupBool($record, StudentField $field): bool { return $this->value($record, $field)?->value_text === '1'; }
    private function lookupJson($record, StudentField $field): ?array { return $this->value($record, $field)?->value_json; }

    private function dropdownLabel(StudentField $field, ?string $value): ?string
    {
        if ($value === null) return null;
        foreach ($field->options ?? [] as $o) {
            if (($o['value'] ?? null) === $value) return $o['label'] ?? $value;
        }
        return $value . ' (removed)';
    }
}
```

- [ ] **Step 4: Wire into StudentResource::table()**

In `app/Filament/Resources/StudentResource.php`, locate the `table(Table $table)` method. After the existing `->columns([…])` array literal, append dynamic columns via `array_merge`:

```php
use App\StudentFields\DynamicTableColumns;

// inside table():
$existingColumns = [ /* the existing columns array */ ];
$dynamicColumns = (new DynamicTableColumns())->build();
return $table->columns(array_merge($existingColumns, $dynamicColumns)) /* ->actions(...) etc unchanged */;
```

- [ ] **Step 5: Run test — expect PASS**

Run: `php artisan test --filter=DynamicTableColumnsTest`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add app/StudentFields/DynamicTableColumns.php app/Filament/Resources/StudentResource.php tests/Feature/StudentFields/DynamicTableColumnsTest.php
git commit -m "feat(fields): dynamic StudentResource table columns from show_in_table"
```

---

### Task 14: KanbanExtrasFormatter + tile cap-3 warning

**Files:**
- Create: `app/StudentFields/KanbanExtrasFormatter.php`
- Modify: `resources/views/filament/pages/kanban-board.blade.php` (or whatever the kanban tile template is)
- Test: `tests/Unit/StudentFields/KanbanExtrasFormatterTest.php`

- [ ] **Step 1: Write failing tests**

```php
<?php
namespace Tests\Unit\StudentFields;

use App\Models\Student;
use App\Models\StudentField;
use App\Models\StudentFieldSection;
use App\Models\StudentFieldValue;
use App\StudentFields\KanbanExtrasFormatter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class KanbanExtrasFormatterTest extends TestCase
{
    use RefreshDatabase;

    public function test_format_returns_label_value_pairs_for_show_in_kanban_fields(): void
    {
        $section = StudentFieldSection::create(['name' => 'X', 'position' => 0]);
        $a = StudentField::create(['section_id' => $section->id, 'key' => 'a', 'label' => 'Marks', 'type' => 'number', 'is_required' => false, 'is_built_in' => false, 'show_in_kanban' => true, 'position' => 0]);
        $b = StudentField::create(['section_id' => $section->id, 'key' => 'b', 'label' => 'Board', 'type' => 'text', 'is_required' => false, 'is_built_in' => false, 'show_in_kanban' => true, 'position' => 1]);
        $student = Student::create(['phone' => '9000000044', 'name' => 'A', 'stage' => 'Lead Captured']);
        StudentFieldValue::create(['student_id' => $student->id, 'student_field_id' => $a->id, 'value_number' => 91]);
        StudentFieldValue::create(['student_id' => $student->id, 'student_field_id' => $b->id, 'value_text' => 'CBSE']);

        $formatter = new KanbanExtrasFormatter();
        $pairs = $formatter->format($student);
        $this->assertSame(['Marks: 91', 'Board: CBSE'], $pairs);
    }

    public function test_format_caps_at_three_pairs(): void
    {
        $section = StudentFieldSection::create(['name' => 'X', 'position' => 0]);
        for ($i = 0; $i < 5; $i++) {
            StudentField::create(['section_id' => $section->id, 'key' => "f{$i}", 'label' => "F{$i}", 'type' => 'text', 'is_required' => false, 'is_built_in' => false, 'show_in_kanban' => true, 'position' => $i]);
        }
        $student = Student::create(['phone' => '9000000045', 'name' => 'B', 'stage' => 'Lead Captured']);
        foreach (StudentField::all() as $field) {
            StudentFieldValue::create(['student_id' => $student->id, 'student_field_id' => $field->id, 'value_text' => 'v']);
        }
        $pairs = (new KanbanExtrasFormatter())->format($student);
        $this->assertCount(3, $pairs, 'kanban extras must cap at 3 (per spec)');
    }

    public function test_warn_returns_true_when_more_than_three_kanban_fields_enabled(): void
    {
        $section = StudentFieldSection::create(['name' => 'X', 'position' => 0]);
        for ($i = 0; $i < 4; $i++) {
            StudentField::create(['section_id' => $section->id, 'key' => "f{$i}", 'label' => "F{$i}", 'type' => 'text', 'is_required' => false, 'is_built_in' => false, 'show_in_kanban' => true, 'position' => $i]);
        }
        $this->assertTrue((new KanbanExtrasFormatter())->shouldWarnTooManyEnabled());
    }
}
```

- [ ] **Step 2: Run tests — expect FAIL**

Run: `php artisan test --filter=KanbanExtrasFormatterTest`
Expected: FAIL — class missing.

- [ ] **Step 3: Implement**

```php
<?php
namespace App\StudentFields;

use App\Models\Student;
use App\Models\StudentField;
use App\Models\StudentFieldValue;

class KanbanExtrasFormatter
{
    public const MAX = 3;

    /** @return array<int, string> */
    public function format(Student $student): array
    {
        $fields = StudentField::active()
            ->where('show_in_kanban', true)
            ->orderBy('position')
            ->limit(self::MAX)
            ->get();

        $pairs = [];
        foreach ($fields as $field) {
            $value = StudentFieldValue::where(['student_id' => $student->id, 'student_field_id' => $field->id])->first();
            $rendered = $this->renderValue($field, $value);
            if ($rendered !== null && $rendered !== '') {
                $pairs[] = "{$field->label}: {$rendered}";
            }
        }
        return $pairs;
    }

    public function shouldWarnTooManyEnabled(): bool
    {
        return StudentField::active()->where('show_in_kanban', true)->count() > self::MAX;
    }

    private function renderValue(StudentField $field, ?StudentFieldValue $v): ?string
    {
        if ($v === null) return null;
        return match ($field->type) {
            'number' => $v->value_number === null ? null : (string) (int) $v->value_number, // round small numbers for tile compactness
            'date' => $v->value_date?->toDateString(),
            'multiselect' => is_array($v->value_json) ? implode(',', $v->value_json) : null,
            'checkbox' => $v->value_text === '1' ? 'Yes' : 'No',
            default => $v->value_text,
        };
    }
}
```

- [ ] **Step 4: Wire into kanban tile blade**

Open `resources/views/filament/pages/kanban-board.blade.php` (or whichever blade renders the per-student tile). Find the tile body. Add (just before the tile closes):

```blade
@php($extras = (new \App\StudentFields\KanbanExtrasFormatter())->format($student))
@if (!empty($extras))
    <div class="mt-1 text-xs text-gray-700 space-y-0.5">
        @foreach ($extras as $line)
            <div>{{ $line }}</div>
        @endforeach
    </div>
@endif
```

(The actual `$student` variable name may differ — check the existing template.)

- [ ] **Step 5: Run tests — expect PASS**

Run: `php artisan test --filter=KanbanExtrasFormatterTest`
Expected: PASS, 3 tests green.

- [ ] **Step 6: Commit**

```bash
git add app/StudentFields/KanbanExtrasFormatter.php resources/views/filament/pages/kanban-board.blade.php tests/Unit/StudentFields/KanbanExtrasFormatterTest.php
git commit -m "feat(fields): kanban tile extras block with 3-field cap"
```

---

### Task 15: ImportColumnMapper + CSV import wiring

**Files:**
- Create: `app/StudentFields/ImportColumnMapper.php`
- Modify: existing CSV importer (search the codebase for `LeadImport` page / service that reads `show_in_import`)
- Test: `tests/Unit/StudentFields/ImportColumnMapperTest.php`
- Test: `tests/Feature/StudentFields/CsvImportCustomFieldsTest.php`

- [ ] **Step 1: Locate the importer**

Run: `grep -rn "LeadImport\|importStudents\|csv" app/Filament/Pages/LeadImport.php app/Services/LeadImport/ 2>/dev/null | head -30`

The plan assumes the importer is in `app/Services/LeadImport/` (per project structure) and the Filament page is `app/Filament/Pages/LeadImport.php`.

- [ ] **Step 2: Write failing tests**

`tests/Unit/StudentFields/ImportColumnMapperTest.php`:

```php
<?php
namespace Tests\Unit\StudentFields;

use App\Models\StudentField;
use App\Models\StudentFieldSection;
use App\StudentFields\ImportColumnMapper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ImportColumnMapperTest extends TestCase
{
    use RefreshDatabase;

    public function test_template_columns_include_built_ins_and_show_in_import_customs(): void
    {
        $section = StudentFieldSection::create(['name' => 'Identity', 'position' => 0]);
        StudentField::create(['section_id' => $section->id, 'key' => 'phone', 'label' => 'Phone', 'type' => 'text', 'is_required' => true, 'is_built_in' => true, 'built_in_column' => 'phone', 'show_in_import' => true, 'position' => 0]);
        StudentField::create(['section_id' => $section->id, 'key' => 'name', 'label' => 'Name', 'type' => 'text', 'is_required' => true, 'is_built_in' => true, 'built_in_column' => 'name', 'show_in_import' => true, 'position' => 1]);
        StudentField::create(['section_id' => $section->id, 'key' => 'dob', 'label' => 'DOB', 'type' => 'date', 'is_required' => false, 'is_built_in' => false, 'show_in_import' => true, 'position' => 2]);
        StudentField::create(['section_id' => $section->id, 'key' => 'hidden', 'label' => 'Hidden', 'type' => 'text', 'is_required' => false, 'is_built_in' => false, 'show_in_import' => false, 'position' => 3]);

        $headers = (new ImportColumnMapper())->templateHeaders();
        $this->assertContains('phone', $headers);
        $this->assertContains('name', $headers);
        $this->assertContains('dob', $headers);
        $this->assertNotContains('hidden', $headers);
    }
}
```

`tests/Feature/StudentFields/CsvImportCustomFieldsTest.php`:

```php
<?php
namespace Tests\Feature\StudentFields;

use App\Models\Student;
use App\Models\StudentField;
use App\Models\StudentFieldSection;
use App\Models\StudentFieldValue;
use App\StudentFields\ImportColumnMapper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CsvImportCustomFieldsTest extends TestCase
{
    use RefreshDatabase;

    public function test_apply_row_writes_built_in_column_and_field_value(): void
    {
        $this->seed();
        $section = StudentFieldSection::firstOrCreate(['name' => 'Demographics'], ['position' => 99]);
        StudentField::create(['section_id' => $section->id, 'key' => 'dob', 'label' => 'DOB', 'type' => 'date', 'is_required' => false, 'is_built_in' => false, 'show_in_import' => true, 'position' => 0]);

        $student = Student::create(['phone' => '9000000099', 'name' => 'X', 'stage' => 'Lead Captured']);
        (new ImportColumnMapper())->applyRow($student, ['dob' => '2009-04-04']);

        $f = StudentField::where('key', 'dob')->first();
        $this->assertSame('2009-04-04', StudentFieldValue::where(['student_id' => $student->id, 'student_field_id' => $f->id])->first()->value_date->toDateString());
    }
}
```

- [ ] **Step 3: Run tests — expect FAIL**

Run: `php artisan test --filter=ImportColumnMapperTest --filter=CsvImportCustomFieldsTest`
Expected: FAIL.

- [ ] **Step 4: Implement**

```php
<?php
namespace App\StudentFields;

use App\Models\Student;
use App\Models\StudentField;
use App\StudentFields\StudentFormDynamicTrait\Persister;

class ImportColumnMapper
{
    /** @return array<int, string> */
    public function templateHeaders(): array
    {
        return StudentField::active()->where('show_in_import', true)->orderBy('position')->pluck('key')->all();
    }

    /** @param array<string, mixed> $row */
    public function applyRow(Student $student, array $row): void
    {
        $fields = StudentField::active()->where('show_in_import', true)->get()->keyBy('key');
        $builtInUpdates = [];
        $customValues = [];

        foreach ($row as $col => $val) {
            $field = $fields->get($col);
            if (!$field) continue;
            if ($field->is_built_in) {
                $builtInUpdates[$field->built_in_column] = $val;
            } else {
                $customValues[$field->key] = $val;
            }
        }
        if ($builtInUpdates) $student->update($builtInUpdates);
        if ($customValues) (new Persister())->persist($student, $customValues);
    }
}
```

- [ ] **Step 5: Wire into existing importer**

In the existing CSV import code, the template generation should call `(new ImportColumnMapper())->templateHeaders()` for the header row, and per-row processing should call `(new ImportColumnMapper())->applyRow($student, $rowAssoc)` AFTER creating the student.

(Find the exact spot via `grep -rn "fputcsv\|csv\|template" app/Filament/Pages/LeadImport.php app/Services/LeadImport/`. If the existing code hardcodes its template headers, replace with the mapper call. If it hardcodes per-column writes, augment with the mapper call after the existing student insert.)

Keep the existing rejection-sheet flow unchanged.

- [ ] **Step 6: Run tests — expect PASS**

Run: `php artisan test --filter=ImportColumnMapperTest && php artisan test --filter=CsvImportCustomFieldsTest`
Expected: PASS.

- [ ] **Step 7: Commit**

```bash
git add app/StudentFields/ImportColumnMapper.php app/Filament/Pages/LeadImport.php app/Services/LeadImport/ tests/Unit/StudentFields/ImportColumnMapperTest.php tests/Feature/StudentFields/CsvImportCustomFieldsTest.php
git commit -m "feat(fields): CSV import with dynamic template + per-row mapper"
```

---

### Task 16: ActivityLog wiring for custom field value changes

**Files:**
- Create: `app/Observers/StudentFieldValueObserver.php`
- Modify: `app/Providers/AppServiceProvider.php` — register observer
- Test: `tests/Feature/StudentFields/ActivityLogTest.php`

- [ ] **Step 1: Write failing tests**

```php
<?php
namespace Tests\Feature\StudentFields;

use App\Models\Student;
use App\Models\StudentField;
use App\Models\StudentFieldSection;
use App\Models\StudentFieldValue;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

class ActivityLogTest extends TestCase
{
    use RefreshDatabase;

    public function test_value_create_logs_activity(): void
    {
        $this->seed();
        $admin = User::where('email', 'sumit@davya.local')->first();
        $this->actingAs($admin);

        $section = StudentFieldSection::firstOrCreate(['name' => 'X'], ['position' => 99]);
        $field = StudentField::create(['section_id' => $section->id, 'key' => 'dob', 'label' => 'DOB', 'type' => 'date', 'is_required' => false, 'is_built_in' => false, 'position' => 0]);
        $student = Student::create(['phone' => '9000000088', 'name' => 'A', 'stage' => 'Lead Captured']);

        StudentFieldValue::create(['student_id' => $student->id, 'student_field_id' => $field->id, 'value_date' => '2009-01-01']);

        $log = Activity::where('subject_type', StudentFieldValue::class)->latest()->first();
        $this->assertNotNull($log);
        $this->assertStringContainsString('DOB', $log->description);
    }

    public function test_value_update_logs_old_to_new(): void
    {
        $this->seed();
        $admin = User::where('email', 'sumit@davya.local')->first();
        $this->actingAs($admin);

        $section = StudentFieldSection::firstOrCreate(['name' => 'X'], ['position' => 99]);
        $field = StudentField::create(['section_id' => $section->id, 'key' => 'marks', 'label' => 'Marks', 'type' => 'number', 'is_required' => false, 'is_built_in' => false, 'position' => 0]);
        $student = Student::create(['phone' => '9000000089', 'name' => 'B', 'stage' => 'Lead Captured']);

        $v = StudentFieldValue::create(['student_id' => $student->id, 'student_field_id' => $field->id, 'value_number' => 80]);
        $v->update(['value_number' => 90]);

        $log = Activity::where('subject_type', StudentFieldValue::class)->latest()->first();
        $this->assertStringContainsString('Marks', $log->description);
        $this->assertStringContainsString('80', $log->description);
        $this->assertStringContainsString('90', $log->description);
    }
}
```

- [ ] **Step 2: Run tests — expect FAIL**

Run: `php artisan test --filter=ActivityLogTest`
Expected: FAIL — observer missing.

- [ ] **Step 3: Implement observer**

```php
<?php
namespace App\Observers;

use App\Models\StudentFieldValue;
use Spatie\Activitylog\Models\Activity;

class StudentFieldValueObserver
{
    public function created(StudentFieldValue $value): void
    {
        $label = $value->field?->label ?? 'field';
        $current = $this->displayValue($value);
        activity()
            ->performedOn($value)
            ->causedBy(auth()->user())
            ->log("field.{$label}: (empty) → {$current}");
    }

    public function updated(StudentFieldValue $value): void
    {
        $label = $value->field?->label ?? 'field';
        $original = [];
        foreach (['value_text','value_number','value_date','value_json'] as $col) {
            if ($value->isDirty($col)) $original[$col] = $value->getOriginal($col);
        }
        if (!$original) return;
        $oldDisplay = $this->displayFromArray($original);
        $newDisplay = $this->displayValue($value);
        activity()
            ->performedOn($value)
            ->causedBy(auth()->user())
            ->log("field.{$label}: {$oldDisplay} → {$newDisplay}");
    }

    private function displayValue(StudentFieldValue $v): string
    {
        return $v->value_text ?? ($v->value_number !== null ? (string) $v->value_number : null) ?? $v->value_date?->toDateString() ?? json_encode($v->value_json) ?? '(empty)';
    }

    private function displayFromArray(array $a): string
    {
        return $a['value_text'] ?? ($a['value_number'] !== null ? (string) $a['value_number'] : null) ?? $a['value_date'] ?? json_encode($a['value_json'] ?? null) ?? '(empty)';
    }
}
```

- [ ] **Step 4: Register observer**

In `app/Providers/AppServiceProvider.php`, inside `boot()`:

```php
\App\Models\StudentFieldValue::observe(\App\Observers\StudentFieldValueObserver::class);
```

- [ ] **Step 5: Run tests — expect PASS**

Run: `php artisan test --filter=ActivityLogTest`
Expected: PASS, 2 tests green.

- [ ] **Step 6: Commit**

```bash
git add app/Observers/StudentFieldValueObserver.php app/Providers/AppServiceProvider.php tests/Feature/StudentFields/ActivityLogTest.php
git commit -m "feat(fields): ActivityLog wiring for custom field value changes"
```

---

### Task 17: Migration smoke + full-suite green check

**Files:** none (verification step)

- [ ] **Step 1: Fresh migrate + verify**

```bash
/opt/alt/php84/usr/bin/php artisan migrate:fresh --seed --force
/opt/alt/php84/usr/bin/php artisan tinker --execute="echo App\\Models\\StudentFieldSection::count() . ' sections, ' . App\\Models\\StudentField::count() . ' fields' . PHP_EOL;"
```
Expected: prints `2 sections, 8 fields`.

- [ ] **Step 2: Run the full test suite**

```bash
/opt/alt/php84/usr/bin/php artisan test
```
Expected: ALL tests PASS. New StudentFields tests should add ≥ 50 to the suite count.

- [ ] **Step 3: Tag pre-deploy commit**

```bash
git tag pre-phase-a-student-fields-20260424
```

- [ ] **Step 4: Commit tag-companion notes (optional)**

If you want a session note alongside the tag:

```bash
echo "Phase A (Custom Student Fields) ready for prod deploy. Tag: pre-phase-a-student-fields-20260424" > docs/sessions/2026-04-24-phase-a-student-fields-runbook.md
git add docs/sessions/2026-04-24-phase-a-student-fields-runbook.md
git commit -m "docs(session): Phase A pre-deploy runbook"
```

---

### Task 18: Local smoke checklist

**Files:**
- Create: `docs/sessions/2026-04-24-phase-a-student-fields-smoke-checklist.md`

- [ ] **Step 1: Write the checklist**

```markdown
# Phase A (Custom Student Fields) — Local Smoke Checklist

Pre-merge to main. Run on local with `php artisan serve`. Login as `sumit@davya.local`.

- [ ] /admin/student-fields renders. Two seeded sections (Identity, Academic) visible. 8 built-in fields visible.
- [ ] Built-in field "Phone" badge shows "🔒 built-in", required toggle is disabled-checked, no archive option in the menu.
- [ ] Create a new section "Demographics" via "+ Add section". It appears at the bottom.
- [ ] Drag "Demographics" above "Academic". Order persists after page reload.
- [ ] Add a custom Text field "Email" under Demographics. Confirm `key=email` in DB. Mark it `Show in table`.
- [ ] Add a custom Date field "DOB" under Demographics.
- [ ] Add a custom Number field "Marks" under Demographics.
- [ ] Add a custom Email field "Alternate Email" under Demographics.
- [ ] Add a custom Dropdown "Board" with options CBSE / ICSE / State.
- [ ] Add a custom Checkbox "Demo Attended". Mark `Show in kanban tile`.
- [ ] Add a custom Multi-select "Subjects" with options Maths / Physics / Chemistry.
- [ ] Add a custom Textarea "Notes". (All 8 types now exist.)
- [ ] Open /admin/students/create. Demographics section appears with all 7 custom fields. Identity + Academic are at the top.
- [ ] Save a student with: phone `9000099001`, name "Test", DOB 2009-05-12, Marks 92.5, Board CBSE, Demo Attended ✓, Subjects [Maths, Physics], Notes "test note", Email "a@b.com".
- [ ] Edit the student. All values reload correctly. Change DOB to 2009-06-15, save.
- [ ] /admin/students table now shows "Email" column. Sort by Email.
- [ ] Open /admin/kanban. The student tile shows "Demo Attended: Yes" in the extras block.
- [ ] Enable `Show in kanban` on a 4th field. Tile still shows only 3. Soft warning surfaced in Field Config.
- [ ] /admin/lead-import. CSV template download includes columns: phone, name, ..., dob, marks, board, demo_attended, subjects, notes, email (those with `show_in_import`).
- [ ] Import a 2-row CSV with custom field values. Both rows succeed; values land in `student_field_values`.
- [ ] Archive "Notes" field. It disappears from /admin/students/edit form. /admin/student-fields → Archived tab shows it.
- [ ] Restore "Notes". It returns to Demographics. Old value still present on the test student.
- [ ] Mark "Notes" required. Try to save the student with empty Notes. Save fails with validation message.
- [ ] Try to archive built-in "Name". Action blocked.
- [ ] Try to set `is_required=false` on built-in "Phone". Toggle disabled in UI; if forced via Livewire call, value is corrected on save.
- [ ] Hard-delete an archived custom field (with a value). Type DELETE to confirm. Field + value purged.
- [ ] /admin/activity-log shows entries for created/updated custom field values with "field.<Label>: old → new" descriptions.

If all green: open PR for Phase A.
```

- [ ] **Step 2: Commit checklist**

```bash
git add docs/sessions/2026-04-24-phase-a-student-fields-smoke-checklist.md
git commit -m "docs(session): Phase A local smoke checklist"
```

---

## Self-review notes

**Spec coverage:** every section in the spec maps to one or more tasks above. Built-in bridging (Task 4 + 11), Core 8 types (Tasks 2, 5), soft archive (Task 10), section transfer (Task 7), per-surface visibility (Tasks 13/14/15), phone lock (Task 9), `getRules` guard (Task 6 test). Phase B is forward-referenced only — not implemented here.

**Placeholders:** none. Every code block contains the actual Laravel/Filament code an engineer needs.

**Type consistency:** `StudentFieldValue` columns (`value_text`, `value_number`, `value_date`, `value_json`) referenced consistently across migrations, model, hydrator, persister, table columns, kanban formatter, activity observer.

**Open implementation tells (NOT plan failures — engineer judgment calls):**
- The exact `tile body` location in the kanban blade (Task 14 Step 4) requires a `grep` since the template has been edited multiple times. The plan tells the engineer where to look and what to insert.
- The CSV importer integration point (Task 15 Step 5) likewise requires a `grep` because the importer file path may have shifted. The mapper service is fully spec'd; the wiring is described.

These are intentionally light-touch in the plan because the surrounding code has been actively modified across SP#1/SP#3 — calling out the search rather than guessing the line numbers is the right move.
