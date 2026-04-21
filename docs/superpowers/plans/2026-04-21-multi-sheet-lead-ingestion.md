# Multi-Sheet Lead Ingestion — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let three team-owned Google Sheets (Sonam, Nikhil, Sumit-website) flow into the CRM via `POST /api/leads` with phone as the dedup key and the sheet's owner as the CRM owner.

**Architecture:** Extract business logic from `LeadController` into a new `LeadIntakeService`. Extend the API contract (make `course` required, `name` optional, add `owner_name`/`rank`/`state`/`email`/`college`/`remarks`/`source` fields). Add a migration for the three new columns. Ship a one-off `leads:backfill-sumit-sheet` artisan command. n8n configuration (three workflows from one template) is handled outside this plan at rollout time; a JSON template is saved in `docs/` for reference.

**Tech Stack:** PHP 8.4+, Laravel 11, PHPUnit (SQLite `:memory:` with `RefreshDatabase`), Filament 3, MySQL prod (`ipuc_ipuc_davyapp`).

**Spec:** `docs/superpowers/specs/2026-04-21-multi-sheet-lead-ingestion-design.md`

**Repo root commands:** Always use `/opt/alt/php84/usr/bin/php` on the server. Locally use `php` from whichever setup has 8.4+. All paths below are relative to `/Users/Sumit/davya-crm`.

---

## File Structure

**New files:**
- `database/migrations/2026_04_21_150000_add_multi_sheet_fields_to_students.php` — adds `rank`, `state`, `email`; relaxes `name` to nullable.
- `app/Services/LeadIntakeService.php` — domain service holding the lead-intake business logic (normalization, owner resolution, dedup enforcement, persistence).
- `app/Console/Commands/BackfillSumitSheet.php` — one-off CSV backfill with `--dry-run`.
- `tests/Unit/LeadIntakeServiceTest.php` — unit coverage of the new service.
- `docs/n8n-multi-sheet-lead-workflow-template.json` — reusable n8n workflow template for the three sheets.

**Modified files:**
- `app/Http/Requests/StoreLeadRequest.php` — `course` required; `name` optional; `owner_name`, `rank`, `state`, `email`, `college`, `remarks`, `source` added.
- `app/Http/Controllers/LeadController.php` — thin controller delegating to `LeadIntakeService`.
- `tests/Feature/LeadCaptureTest.php` — updated validation/owner-override/field-storage assertions.
- `docs/LEAD_CAPTURE_API.md` — documents the updated contract.
- `docs/DECISIONS.md` — append a 2026-04-21 entry recording the design decision.

---

## Task 1: Schema Migration — new columns + nullable name

**Files:**
- Create: `database/migrations/2026_04_21_150000_add_multi_sheet_fields_to_students.php`
- Test: `tests/Feature/StudentsMultiSheetColumnsTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/StudentsMultiSheetColumnsTest.php`:

```php
<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class StudentsMultiSheetColumnsTest extends TestCase
{
    use RefreshDatabase;

    public function test_students_table_has_rank_state_and_email_columns(): void
    {
        $this->assertTrue(Schema::hasColumn('students', 'rank'));
        $this->assertTrue(Schema::hasColumn('students', 'state'));
        $this->assertTrue(Schema::hasColumn('students', 'email'));
    }

    public function test_students_name_column_is_nullable(): void
    {
        $type = Schema::getConnection()
            ->getDoctrineColumn('students', 'name');
        // Fallback for Laravel 11 without doctrine: check by insert.
        \App\Models\Student::factory()->create(['name' => null]);
        $this->assertTrue(true);
    }
}
```

If `factory()` is not wired for Student, replace the second test body with a raw insert:

```php
public function test_students_name_column_is_nullable(): void
{
    $admin = \App\Models\User::factory()->create();
    \DB::table('students')->insert([
        'phone' => '9000000001',
        'name' => null,
        'owner_id' => $admin->id,
        'stage' => 'Lead Captured',
        'lead_source' => 'Test',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $this->assertDatabaseHas('students', ['phone' => '9000000001', 'name' => null]);
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=StudentsMultiSheetColumnsTest`
Expected: FAIL — columns don't exist; name still NOT NULL.

- [ ] **Step 3: Write the migration**

Create `database/migrations/2026_04_21_150000_add_multi_sheet_fields_to_students.php`:

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
            $table->string('rank', 40)->nullable()->after('twelfth_marks');
            $table->string('state', 40)->nullable()->after('category');
            $table->string('email', 120)->nullable()->after('phone_2');
        });

        Schema::table('students', function (Blueprint $table) {
            $table->string('name', 120)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('students', function (Blueprint $table) {
            $table->string('name', 120)->nullable(false)->change();
        });

        Schema::table('students', function (Blueprint $table) {
            $table->dropColumn(['rank', 'state', 'email']);
        });
    }
};
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter=StudentsMultiSheetColumnsTest`
Expected: PASS.

- [ ] **Step 5: Run full test suite to confirm no regression**

Run: `php artisan test`
Expected: All pre-existing tests still green (the existing `LeadCaptureTest` still works because `name` stays present in its payload).

- [ ] **Step 6: Commit**

```bash
git add database/migrations/2026_04_21_150000_add_multi_sheet_fields_to_students.php tests/Feature/StudentsMultiSheetColumnsTest.php
git commit -m "feat(students): add rank/state/email columns; relax name to nullable"
```

---

## Task 2: LeadIntakeService — phone normalization + owner resolution (TDD)

Start small: only the bits of the service we can test without going through HTTP. Business rules to encode here so they can be unit-tested:
- Phone normalization (strip non-digits, strip `91` country code if 12 digits).
- Owner resolution order: `owner_name` payload → existing referrer→owner mapping → admin fallback.
- `lead_source` default (`Sheet:<owner>` when `source` blank and `owner_name` present; else `Walk-in / Self`).
- Dedup check (return `['duplicate' => true, 'existing_id' => N]`).
- Field mapping (`college` → `preference_r1`, `remarks` → `extra_notes`, `source` → `lead_source`).

**Files:**
- Create: `app/Services/LeadIntakeService.php`
- Create: `tests/Unit/LeadIntakeServiceTest.php`

- [ ] **Step 1: Write the first batch of failing unit tests**

Create `tests/Unit/LeadIntakeServiceTest.php`:

```php
<?php

namespace Tests\Unit;

use App\Models\Student;
use App\Models\User;
use App\Services\LeadIntakeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LeadIntakeServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    private function service(): LeadIntakeService
    {
        return app(LeadIntakeService::class);
    }

    public function test_ingests_minimal_payload_with_phone_and_course(): void
    {
        $result = $this->service()->ingest([
            'phone' => '9000000001',
            'course' => 'BCA',
        ]);

        $this->assertArrayHasKey('student', $result);
        $student = $result['student'];
        $this->assertSame('9000000001', $student->phone);
        $this->assertSame('BCA', $student->course);
        $this->assertNull($student->name);
        $this->assertSame('Lead Captured', $student->stage);
    }

    public function test_owner_name_overrides_referrer_derived_owner(): void
    {
        $nisha = User::where('email', 'nisha@davya.local')->first();
        $sonam = User::where('email', 'sonam@davya.local')->first();

        $result = $this->service()->ingest([
            'phone' => '9000000002',
            'course' => 'BBA',
            'referrer_name' => 'Nisha',
            'owner_name' => 'Sonam',
        ]);

        $student = $result['student'];
        $this->assertSame($sonam->id, $student->owner_id);
        $this->assertSame($nisha->id, $student->referrer_id);
    }

    public function test_owner_name_lookup_is_case_insensitive(): void
    {
        $sonam = User::where('email', 'sonam@davya.local')->first();

        $result = $this->service()->ingest([
            'phone' => '9000000003',
            'course' => 'BBA',
            'owner_name' => 'sOnAm',
        ]);

        $this->assertSame($sonam->id, $result['student']->owner_id);
    }

    public function test_unknown_owner_name_falls_through_to_referrer_mapping(): void
    {
        $nisha  = User::where('email', 'nisha@davya.local')->first();
        $nikhil = User::where('email', 'nikhil@davya.local')->first();

        $result = $this->service()->ingest([
            'phone' => '9000000004',
            'course' => 'BCA',
            'referrer_name' => 'Nisha',
            'owner_name' => 'NobodyKnown',
        ]);

        $student = $result['student'];
        $this->assertSame($nisha->id, $student->referrer_id);
        $this->assertSame($nikhil->id, $student->owner_id);
    }

    public function test_no_owner_and_no_referrer_defaults_to_admin(): void
    {
        $sumit = User::where('email', 'sumit@davya.local')->first();

        $result = $this->service()->ingest([
            'phone' => '9000000005',
            'course' => 'BCA',
        ]);

        $this->assertSame($sumit->id, $result['student']->owner_id);
    }

    public function test_phone_is_normalized_to_ten_digits(): void
    {
        $result = $this->service()->ingest([
            'phone' => '+91 90000 00006',
            'course' => 'BCA',
        ]);
        $this->assertSame('9000000006', $result['student']->phone);
    }

    public function test_duplicate_phone_returns_duplicate_result_without_inserting(): void
    {
        $this->service()->ingest(['phone' => '9000000007', 'course' => 'BCA']);
        $result = $this->service()->ingest(['phone' => '9000000007', 'course' => 'BBA']);

        $this->assertTrue($result['duplicate']);
        $this->assertIsInt($result['existing_id']);
        $this->assertSame(1, Student::where('phone', '9000000007')->count());
    }

    public function test_remarks_maps_to_extra_notes_and_college_maps_to_preference_r1(): void
    {
        $result = $this->service()->ingest([
            'phone' => '9000000008',
            'course' => 'BCA',
            'remarks' => 'called twice, interested',
            'college' => 'MAIT',
        ]);

        $student = $result['student'];
        $this->assertSame('called twice, interested', $student->extra_notes);
        $this->assertSame('MAIT', $student->preference_r1);
    }

    public function test_source_defaults_to_sheet_owner_when_owner_name_present_and_source_blank(): void
    {
        $result = $this->service()->ingest([
            'phone' => '9000000009',
            'course' => 'BCA',
            'owner_name' => 'Sonam',
        ]);
        $this->assertSame('Sheet:Sonam', $result['student']->lead_source);
    }

    public function test_explicit_source_overrides_default(): void
    {
        $result = $this->service()->ingest([
            'phone' => '9000000010',
            'course' => 'BCA',
            'owner_name' => 'Sonam',
            'source' => 'Instagram DM',
        ]);
        $this->assertSame('Instagram DM', $result['student']->lead_source);
    }

    public function test_stores_new_fields_rank_state_email(): void
    {
        $result = $this->service()->ingest([
            'phone' => '9000000011',
            'course' => 'BCA',
            'rank' => '55000',
            'state' => 'Delhi',
            'email' => 'x@example.com',
        ]);
        $student = $result['student'];
        $this->assertSame('55000', $student->rank);
        $this->assertSame('Delhi', $student->state);
        $this->assertSame('x@example.com', $student->email);
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --filter=LeadIntakeServiceTest`
Expected: FAIL — `App\Services\LeadIntakeService` does not exist.

- [ ] **Step 3: Create the service**

Create `app/Services/LeadIntakeService.php`:

```php
<?php

namespace App\Services;

use App\Models\Student;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class LeadIntakeService
{
    public const WALK_IN_LABEL = 'Walk-in / Self';

    /**
     * Ingest a normalized lead payload.
     *
     * Returns either:
     *   ['duplicate' => true, 'existing_id' => int]
     *   ['student' => Student]
     */
    public function ingest(array $data): array
    {
        $phone = $this->normalizePhone($data['phone'] ?? null);

        $existing = Student::where('phone', $phone)->first();
        if ($existing !== null) {
            return ['duplicate' => true, 'existing_id' => $existing->id];
        }

        $ownerName    = $this->trimOrNull($data['owner_name']    ?? null);
        $referrerName = $this->trimOrNull($data['referrer_name'] ?? null);

        [$ownerId, $referrerId] = $this->resolveOwnership($ownerName, $referrerName);

        $leadSource = $this->trimOrNull($data['source'] ?? null)
            ?? ($ownerName !== null ? 'Sheet:' . $ownerName : ($referrerName ?? self::WALK_IN_LABEL));

        $student = DB::transaction(fn () => Student::create([
            'phone'         => $phone,
            'name'          => $data['name']          ?? null,
            'father_name'   => $data['father_name']   ?? null,
            'phone_2'       => $this->normalizePhone($data['phone_2'] ?? null),
            'email'         => $data['email']         ?? null,
            'exam_appeared' => $data['exam_appeared'] ?? null,
            'twelfth_marks' => $data['twelfth_marks'] ?? null,
            'rank'          => $data['rank']          ?? null,
            'category'      => $data['category']      ?? null,
            'state'         => $data['state']         ?? null,
            'course'        => $data['course']        ?? null,
            'preference_r1' => $data['college']       ?? null,
            'extra_notes'   => $data['remarks']       ?? null,
            'description'   => $data['description']   ?? null,
            'owner_id'      => $ownerId,
            'referrer_id'   => $referrerId,
            'lead_source'   => $leadSource,
            'stage'         => 'Lead Captured',
        ]));

        return ['student' => $student];
    }

    private function resolveOwnership(?string $ownerName, ?string $referrerName): array
    {
        $owner = $this->findUserByName($ownerName);
        if ($owner !== null) {
            $referrer = $this->findUserByName($referrerName);
            return [$owner->id, $referrer?->id];
        }

        if ($referrerName === null || $referrerName === self::WALK_IN_LABEL) {
            return [$this->adminId(), null];
        }

        $referrer = $this->findUserByName($referrerName);
        if ($referrer === null) {
            return [$this->adminId(), null];
        }

        $ownerId = $referrer->team_head_id ?? $referrer->id;
        return [$ownerId, $referrer->id];
    }

    private function findUserByName(?string $name): ?User
    {
        if ($name === null || $name === '') {
            return null;
        }
        return User::whereRaw('LOWER(name) = ?', [strtolower($name)])->first();
    }

    private function adminId(): int
    {
        return User::role('admin')->firstOrFail()->id;
    }

    public function normalizePhone(?string $v): ?string
    {
        if ($v === null || $v === '') {
            return null;
        }
        $digits = preg_replace('/\D+/', '', $v);
        if (strlen($digits) === 12 && str_starts_with($digits, '91')) {
            $digits = substr($digits, 2);
        }
        return $digits;
    }

    private function trimOrNull(?string $v): ?string
    {
        if ($v === null) {
            return null;
        }
        $v = trim($v);
        return $v === '' ? null : $v;
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `php artisan test --filter=LeadIntakeServiceTest`
Expected: all 11 tests PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Services/LeadIntakeService.php tests/Unit/LeadIntakeServiceTest.php
git commit -m "feat(leads): extract LeadIntakeService with owner_name override and new fields"
```

---

## Task 3: Refactor LeadController to delegate to LeadIntakeService (no API change yet)

Goal: `LeadCaptureTest` stays entirely green while the controller becomes a thin wrapper. We only delegate; we do **not** widen validation yet — that is Task 4.

**Files:**
- Modify: `app/Http/Controllers/LeadController.php`

- [ ] **Step 1: Run existing feature test and confirm baseline green**

Run: `php artisan test --filter=LeadCaptureTest`
Expected: PASS (21 tests).

- [ ] **Step 2: Rewrite the controller to call the service**

Replace `app/Http/Controllers/LeadController.php` with:

```php
<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreLeadRequest;
use App\Services\LeadIntakeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

class LeadController extends Controller
{
    public function __construct(private LeadIntakeService $intake) {}

    public function store(StoreLeadRequest $request): JsonResponse
    {
        $data   = $request->validated();
        $result = $this->intake->ingest($data);

        if ($result['duplicate'] ?? false) {
            return response()->json([
                'error'       => 'duplicate_phone',
                'existing_id' => $result['existing_id'],
            ], 409);
        }

        $student = $result['student'];

        Log::info('lead.captured', [
            'student_id'    => $student->id,
            'owner_id'      => $student->owner_id,
            'referrer_name' => $data['referrer_name'] ?? null,
            'owner_name'    => $data['owner_name']    ?? null,
        ]);

        return response()->json([
            'id'       => $student->id,
            'stage'    => $student->stage,
            'owner'    => $student->owner?->name,
            'referrer' => $student->referrer?->name,
        ], 201);
    }
}
```

- [ ] **Step 3: Re-run the feature test**

Run: `php artisan test --filter=LeadCaptureTest`
Expected: PASS (21 tests).

- [ ] **Step 4: Commit**

```bash
git add app/Http/Controllers/LeadController.php
git commit -m "refactor(leads): delegate LeadController to LeadIntakeService"
```

---

## Task 4: Widen StoreLeadRequest — course required, name optional, new fields

**Files:**
- Modify: `app/Http/Requests/StoreLeadRequest.php`
- Modify: `tests/Feature/LeadCaptureTest.php`

- [ ] **Step 1: Update the feature test to express the new contract**

Open `tests/Feature/LeadCaptureTest.php`. Make these surgical edits:

1. In `postLead()` default payload (around line 26), add `'course' => 'BCA'` is already present — keep it. Leave `'name' => 'Ankit Sharma'` in the default so existing happy-path cases still pass.

2. Replace `test_missing_name_returns_422` (lines 163-168) with:

```php
public function test_missing_name_is_accepted_now_that_course_is_the_required_human_field(): void
{
    $resp = $this->postLead(['name' => null]);
    $resp->assertCreated();
    $student = \App\Models\Student::find($resp->json('id'));
    $this->assertNull($student->name);
    $this->assertSame('BCA', $student->course);
}
```

3. Add the following new tests at the end of the class (before the closing `}`):

```php
// --- new required/optional rules ---

public function test_missing_course_returns_422(): void
{
    $resp = $this->postLead(['course' => null]);
    $resp->assertStatus(422);
    $resp->assertJsonValidationErrors('course');
}

public function test_owner_name_overrides_referrer_mapping(): void
{
    $sonam = \App\Models\User::where('email', 'sonam@davya.local')->first();
    $nisha = \App\Models\User::where('email', 'nisha@davya.local')->first();

    $resp = $this->postLead([
        'phone' => '9888000001',
        'referrer_name' => 'Nisha',
        'owner_name' => 'Sonam',
    ]);

    $resp->assertCreated();
    $student = \App\Models\Student::find($resp->json('id'));
    $this->assertSame($sonam->id, $student->owner_id);
    $this->assertSame($nisha->id, $student->referrer_id);
}

public function test_accepts_and_stores_new_optional_fields(): void
{
    $resp = $this->postLead([
        'phone' => '9888000002',
        'rank' => '55000',
        'state' => 'Uttar Pradesh',
        'email' => 'lead@example.com',
        'college' => 'MAIT',
        'remarks' => 'asked about scholarship',
        'source' => 'Sheet:Sumit',
    ]);

    $resp->assertCreated();
    $student = \App\Models\Student::find($resp->json('id'));
    $this->assertSame('55000', $student->rank);
    $this->assertSame('Uttar Pradesh', $student->state);
    $this->assertSame('lead@example.com', $student->email);
    $this->assertSame('MAIT', $student->preference_r1);
    $this->assertSame('asked about scholarship', $student->extra_notes);
    $this->assertSame('Sheet:Sumit', $student->lead_source);
}

public function test_source_defaults_to_sheet_owner_when_blank(): void
{
    $resp = $this->postLead([
        'phone' => '9888000003',
        'owner_name' => 'Sonam',
        'source' => null,
    ]);
    $resp->assertCreated();
    $student = \App\Models\Student::find($resp->json('id'));
    $this->assertSame('Sheet:Sonam', $student->lead_source);
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --filter=LeadCaptureTest`
Expected: FAIL — `course` is currently `nullable`; `name` is still `required`; `owner_name`/`rank`/`state`/`email`/`college`/`remarks`/`source` aren't in `validated()` so `ingest()` never sees them.

- [ ] **Step 3: Update the form request**

Replace `app/Http/Requests/StoreLeadRequest.php` with:

```php
<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class StoreLeadRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'phone'   => $this->digitsOnly($this->input('phone')),
            'phone_2' => $this->digitsOnly($this->input('phone_2')),
        ]);
    }

    public function rules(): array
    {
        return [
            'phone'          => ['required', 'string', 'regex:/^\d{10}$/'],
            'course'         => ['required', 'string', 'max:80'],
            'name'           => ['nullable', 'string', 'max:120'],
            'father_name'    => ['nullable', 'string', 'max:120'],
            'phone_2'        => ['nullable', 'string', 'regex:/^\d{10}$/'],
            'email'          => ['nullable', 'string', 'max:120'],
            'exam_appeared'  => ['nullable', 'string', 'in:IPU CET,CUET,JEE,Other'],
            'twelfth_marks'  => ['nullable', 'string', 'max:20'],
            'rank'           => ['nullable', 'string', 'max:40'],
            'category'       => ['nullable', 'string', 'in:Delhi,Outside'],
            'state'          => ['nullable', 'string', 'max:40'],
            'college'        => ['nullable', 'string', 'max:120'],
            'referrer_name'  => ['nullable', 'string', 'max:60'],
            'owner_name'     => ['nullable', 'string', 'max:60'],
            'remarks'        => ['nullable', 'string', 'max:2000'],
            'source'         => ['nullable', 'string', 'max:60'],
            'description'    => ['nullable', 'string', 'max:2000'],
        ];
    }

    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(response()->json([
            'message' => 'The given data was invalid.',
            'errors'  => $validator->errors(),
        ], 422));
    }

    private function digitsOnly(?string $v): ?string
    {
        if ($v === null || $v === '') {
            return null;
        }
        $digits = preg_replace('/\D+/', '', $v);
        if (strlen($digits) === 12 && str_starts_with($digits, '91')) {
            $digits = substr($digits, 2);
        }
        return $digits;
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `php artisan test --filter=LeadCaptureTest`
Expected: all `LeadCaptureTest` cases PASS (updated + 4 new ones).

- [ ] **Step 5: Run the full suite**

Run: `php artisan test`
Expected: green.

- [ ] **Step 6: Commit**

```bash
git add app/Http/Requests/StoreLeadRequest.php tests/Feature/LeadCaptureTest.php
git commit -m "feat(leads): course required, name optional, add owner_name/rank/state/email/college/remarks/source"
```

---

## Task 5: `leads:backfill-sumit-sheet` artisan command

**Files:**
- Create: `app/Console/Commands/BackfillSumitSheet.php`
- Create: `tests/Feature/BackfillSumitSheetTest.php`

**CSV contract:** First line is a header; we read by column name. Required columns: `Phone`, `Course`. Optional: `Name`, `Father Name`, `12th marks`, `Rank`, `Category`, `State`, `College`, `Email`, `Reference`, `Remarks`, `Source`. Unknown columns are ignored. Phones normalize through `LeadIntakeService::normalizePhone`. First occurrence of a duplicated phone wins.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/BackfillSumitSheetTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Student;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class BackfillSumitSheetTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    private function writeCsv(string $body): string
    {
        $path = tempnam(sys_get_temp_dir(), 'leads_') . '.csv';
        file_put_contents($path, $body);
        return $path;
    }

    public function test_imports_unique_rows_with_sumit_as_owner(): void
    {
        $sumit = \App\Models\User::where('email', 'sumit@davya.local')->first();

        $path = $this->writeCsv(
            "Phone,Course,Name\n" .
            "9000001001,BCA,Alice\n" .
            "9000001002,BBA,Bob\n"
        );

        $exit = Artisan::call('leads:backfill-sumit-sheet', ['file' => $path]);
        $this->assertSame(0, $exit);

        $this->assertDatabaseHas('students', ['phone' => '9000001001', 'name' => 'Alice', 'owner_id' => $sumit->id]);
        $this->assertDatabaseHas('students', ['phone' => '9000001002', 'name' => 'Bob', 'owner_id' => $sumit->id]);
    }

    public function test_in_memory_dedup_keeps_first_of_bounce_duplicates(): void
    {
        $path = $this->writeCsv(
            "Phone,Course,Name\n" .
            "9000002001,BCA,First\n" .
            "9000002001,BCA,Second\n" .
            "9000002001,BCA,Third\n"
        );

        Artisan::call('leads:backfill-sumit-sheet', ['file' => $path]);

        $this->assertSame(1, Student::where('phone', '9000002001')->count());
        $this->assertSame('First', Student::where('phone', '9000002001')->value('name'));
    }

    public function test_rejects_rows_missing_phone_or_course(): void
    {
        $path = $this->writeCsv(
            "Phone,Course,Name\n" .
            ",BCA,NoPhone\n" .
            "9000003001,,NoCourse\n" .
            "9000003002,BCA,Good\n"
        );

        $exit = Artisan::call('leads:backfill-sumit-sheet', ['file' => $path]);
        $this->assertSame(0, $exit);
        $this->assertSame(1, Student::count());
        $this->assertDatabaseHas('students', ['phone' => '9000003002']);
    }

    public function test_is_idempotent_when_rerun(): void
    {
        $path = $this->writeCsv("Phone,Course,Name\n9000004001,BCA,Alice\n");

        Artisan::call('leads:backfill-sumit-sheet', ['file' => $path]);
        Artisan::call('leads:backfill-sumit-sheet', ['file' => $path]);

        $this->assertSame(1, Student::where('phone', '9000004001')->count());
    }

    public function test_dry_run_inserts_nothing(): void
    {
        $path = $this->writeCsv("Phone,Course,Name\n9000005001,BCA,Alice\n");

        $exit = Artisan::call('leads:backfill-sumit-sheet', ['file' => $path, '--dry-run' => true]);
        $this->assertSame(0, $exit);
        $this->assertSame(0, Student::count());
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=BackfillSumitSheetTest`
Expected: FAIL — command does not exist.

- [ ] **Step 3: Create the artisan command**

Create `app/Console/Commands/BackfillSumitSheet.php`:

```php
<?php

namespace App\Console\Commands;

use App\Services\LeadIntakeService;
use Illuminate\Console\Command;

class BackfillSumitSheet extends Command
{
    protected $signature = 'leads:backfill-sumit-sheet
                            {file : Path to the CSV export}
                            {--dry-run : Parse and normalize but do not insert}';

    protected $description = 'Import Sumit website-form sheet export into students (owner=Sumit).';

    public function handle(LeadIntakeService $intake): int
    {
        $path = (string) $this->argument('file');
        if (! is_file($path) || ! is_readable($path)) {
            $this->error("File not readable: {$path}");
            return self::FAILURE;
        }

        $handle = fopen($path, 'r');
        if ($handle === false) {
            $this->error("Could not open: {$path}");
            return self::FAILURE;
        }

        $header = fgetcsv($handle);
        if ($header === false || $header === null) {
            $this->error('CSV is empty');
            fclose($handle);
            return self::FAILURE;
        }
        $map = array_flip(array_map('trim', $header));

        $seen     = [];
        $imported = 0;
        $skipped  = 0;
        $rejected = 0;
        $dryRun   = (bool) $this->option('dry-run');

        while (($row = fgetcsv($handle)) !== false) {
            $get = fn (string $col) => isset($map[$col]) && isset($row[$map[$col]]) ? trim((string) $row[$map[$col]]) : '';

            $phoneRaw = $get('Phone');
            $course   = $get('Course');
            $phone    = $intake->normalizePhone($phoneRaw);

            if ($phone === null || $phone === '' || $course === '') {
                $rejected++;
                continue;
            }

            if (isset($seen[$phone])) {
                $skipped++;
                continue;
            }
            $seen[$phone] = true;

            if ($dryRun) {
                $imported++;
                continue;
            }

            $payload = [
                'phone'         => $phone,
                'course'        => $course,
                'name'          => $get('Name')         ?: null,
                'father_name'   => $get('Father Name')  ?: null,
                'twelfth_marks' => $get('12th marks')   ?: null,
                'rank'          => $get('Rank')         ?: null,
                'category'      => $this->normalizeCategory($get('Category')),
                'state'         => $get('State')        ?: null,
                'college'       => $get('College')      ?: null,
                'email'         => $get('Email')        ?: null,
                'referrer_name' => $get('Reference')    ?: null,
                'remarks'       => $get('Remarks')      ?: null,
                'source'        => $get('Source')       ?: 'Sheet:Sumit',
                'owner_name'    => 'Sumit',
            ];

            $result = $intake->ingest($payload);
            if ($result['duplicate'] ?? false) {
                $skipped++;
            } else {
                $imported++;
            }
        }

        fclose($handle);

        $this->info("Imported: {$imported} | Skipped (duplicate): {$skipped} | Rejected (missing phone/course): {$rejected}");
        return self::SUCCESS;
    }

    private function normalizeCategory(string $raw): ?string
    {
        $v = strtolower(trim($raw));
        return match (true) {
            $v === '' => null,
            in_array($v, ['d', 'delhi'], true) => 'Delhi',
            in_array($v, ['od', 'outside', 'outsider'], true) => 'Outside',
            default => null,
        };
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `php artisan test --filter=BackfillSumitSheetTest`
Expected: all 5 tests PASS.

- [ ] **Step 5: Run full suite**

Run: `php artisan test`
Expected: green.

- [ ] **Step 6: Commit**

```bash
git add app/Console/Commands/BackfillSumitSheet.php tests/Feature/BackfillSumitSheetTest.php
git commit -m "feat(leads): add leads:backfill-sumit-sheet artisan command"
```

---

## Task 6: n8n workflow template JSON

Not code, but versioned alongside the existing `docs/n8n-lead-capture-workflow.json`. Three sheets will each clone this template with their own Sheet ID and hardcoded `owner_name`.

**Files:**
- Create: `docs/n8n-multi-sheet-lead-workflow-template.json`

- [ ] **Step 1: Write the template**

Create `docs/n8n-multi-sheet-lead-workflow-template.json` with the following content. Replace `<<SHEET_ID>>` and `<<OWNER_NAME>>` per workflow in n8n before saving the three concrete workflows.

```json
{
  "name": "lead-<<OWNER_NAME_LOWER>>-sheet",
  "nodes": [
    {
      "parameters": {
        "pollTimes": { "item": [{ "mode": "everyMinute" }] },
        "documentId": "<<SHEET_ID>>",
        "sheetName": "Sheet1",
        "event": "rowAdded"
      },
      "name": "Google Sheets Trigger",
      "type": "n8n-nodes-base.googleSheetsTrigger",
      "typeVersion": 1,
      "position": [240, 300]
    },
    {
      "parameters": {
        "values": {
          "string": [
            { "name": "phone",         "value": "={{ $json['Phone'] || $json['Ph no'] || $json['Contact no'] || '' }}" },
            { "name": "course",        "value": "={{ $json['Course'] || '' }}" },
            { "name": "name",          "value": "={{ $json['Name'] || $json['Student Name'] || '' }}" },
            { "name": "father_name",   "value": "={{ $json['Father Name'] || '' }}" },
            { "name": "twelfth_marks", "value": "={{ $json['12th marks'] || '' }}" },
            { "name": "rank",          "value": "={{ $json['Rank'] || '' }}" },
            { "name": "category",      "value": "={{ $json['Category'] || $json['D/OD'] || '' }}" },
            { "name": "state",         "value": "={{ $json['State'] || '' }}" },
            { "name": "college",       "value": "={{ $json['College'] || '' }}" },
            { "name": "email",         "value": "={{ $json['Email'] || '' }}" },
            { "name": "referrer_name", "value": "={{ $json['Reference'] || '' }}" },
            { "name": "remarks",       "value": "={{ $json['Remarks'] || $json['enquiry'] || $json['Message'] || '' }}" },
            { "name": "source",        "value": "={{ $json['Source'] || '' }}" },
            { "name": "owner_name",    "value": "<<OWNER_NAME>>" }
          ]
        },
        "options": {}
      },
      "name": "Map columns",
      "type": "n8n-nodes-base.set",
      "typeVersion": 2,
      "position": [480, 300]
    },
    {
      "parameters": {
        "conditions": {
          "string": [
            { "value1": "={{ $json.phone }}",  "operation": "isEmpty" },
            { "value1": "={{ $json.course }}", "operation": "isEmpty" }
          ]
        },
        "combineOperation": "any"
      },
      "name": "Missing phone/course?",
      "type": "n8n-nodes-base.if",
      "typeVersion": 1,
      "position": [720, 300]
    },
    {
      "parameters": {
        "documentId": "<<SHEET_ID>>",
        "sheetName": "Rejected",
        "operation": "append",
        "columns": ["Original Row Number", "Row Data JSON", "Error", "Timestamp"]
      },
      "name": "Append to Rejected tab",
      "type": "n8n-nodes-base.googleSheets",
      "typeVersion": 4,
      "position": [960, 180]
    },
    {
      "parameters": {
        "url": "https://davyas.ipu.co.in/api/leads",
        "authentication": "predefinedCredentialType",
        "method": "POST",
        "sendHeaders": true,
        "headerParameters": {
          "parameters": [{ "name": "X-Lead-Token", "value": "={{ $credentials.leadToken }}" }]
        },
        "sendBody": true,
        "contentType": "json",
        "bodyParametersJson": "={{ JSON.stringify($json) }}",
        "options": { "response": { "response": { "fullResponse": true, "neverError": true } } }
      },
      "name": "POST /api/leads",
      "type": "n8n-nodes-base.httpRequest",
      "typeVersion": 4,
      "position": [960, 420]
    },
    {
      "parameters": {
        "rules": {
          "rules": [
            { "value2": 201 },
            { "value2": 409 },
            { "value2": 422 }
          ]
        },
        "value1": "={{ $json.statusCode }}"
      },
      "name": "Switch on status",
      "type": "n8n-nodes-base.switch",
      "typeVersion": 1,
      "position": [1200, 420]
    }
  ],
  "connections": {
    "Google Sheets Trigger": { "main": [[{ "node": "Map columns", "type": "main", "index": 0 }]] },
    "Map columns":           { "main": [[{ "node": "Missing phone/course?", "type": "main", "index": 0 }]] },
    "Missing phone/course?": { "main": [
      [{ "node": "Append to Rejected tab", "type": "main", "index": 0 }],
      [{ "node": "POST /api/leads",        "type": "main", "index": 0 }]
    ]},
    "POST /api/leads":       { "main": [[{ "node": "Switch on status",       "type": "main", "index": 0 }]] }
  }
}
```

- [ ] **Step 2: Commit**

```bash
git add docs/n8n-multi-sheet-lead-workflow-template.json
git commit -m "docs(n8n): workflow template for multi-sheet lead ingestion"
```

---

## Task 7: Docs — update `LEAD_CAPTURE_API.md` and append to `DECISIONS.md`

**Files:**
- Modify: `docs/LEAD_CAPTURE_API.md`
- Modify: `docs/DECISIONS.md`

- [ ] **Step 1: Update `docs/LEAD_CAPTURE_API.md`**

Open and update the field table (and any worked example) to reflect the new contract:

- `phone` — required, 10 digits after normalization.
- `course` — **required**, max 80.
- `name` — **optional**, max 120.
- `owner_name` — optional, max 60. Case-insensitive user name lookup; overrides referrer-derived owner.
- `referrer_name` — optional.
- `father_name` — optional.
- `twelfth_marks` — optional.
- `rank` — optional, max 40, free text.
- `category` — optional, `Delhi` / `Outside`.
- `state` — optional, max 40, free text.
- `college` — optional, max 120. Persists to `students.preference_r1`.
- `email` — optional, max 120.
- `remarks` — optional, max 2000. Persists to `students.extra_notes`.
- `source` — optional, max 60. Persists to `students.lead_source`. Defaults server-side to `Sheet:<owner_name>` when `owner_name` present and `source` blank, otherwise to the referrer name or `Walk-in / Self`.
- `description` — optional, max 2000.

Responses unchanged: `201`, `401 {error: "unauthorized"}`, `409 {error: "duplicate_phone", existing_id: N}`, `422 {message, errors}`.

- [ ] **Step 2: Append to `docs/DECISIONS.md`**

Append this entry at the bottom:

```markdown
## 2026-04-21 — Multi-sheet lead ingestion

- Sonam / Nikhil / Sumit-website Google Sheets flow into `POST /api/leads` via three n8n workflows cloned from a shared template.
- API contract widened: `course` is required; `name` is optional; new optional fields `owner_name`, `rank`, `state`, `email`, `college`, `remarks`, `source`.
- Business logic moved out of `LeadController` into `App\Services\LeadIntakeService`.
- Owner resolution order: `owner_name` (direct user) → referrer → team_head → admin fallback.
- Nikhil's and Sonam's sheets are **not** backfilled; historical rows stay read-only in the old sheet tabs. Sumit's ~600-row sheet is backfilled once via `leads:backfill-sumit-sheet`.
- Phone remains the dedup key: `students.phone` UNIQUE + service-level check.
```

- [ ] **Step 3: Commit**

```bash
git add docs/LEAD_CAPTURE_API.md docs/DECISIONS.md
git commit -m "docs(leads): document multi-sheet ingestion contract and decision"
```

---

## Task 8: Final verification

- [ ] **Step 1: Run full suite**

Run: `php artisan test`
Expected: green. No skipped tests related to this feature.

- [ ] **Step 2: Dry-run the backfill against a small sample CSV**

Create a throwaway CSV with 3–5 rows (including one duplicate) and run:

```bash
php artisan leads:backfill-sumit-sheet /tmp/sample.csv --dry-run
```

Expected output:

```
Imported: N | Skipped (duplicate): M | Rejected (missing phone/course): K
```

No rows inserted (verify with `php artisan tinker -- 'echo \App\Models\Student::count();'` or similar).

- [ ] **Step 3: Confirm migration up/down round-trip on a scratch DB**

```bash
php artisan migrate --pretend
php artisan migrate
php artisan migrate:rollback --step=1
php artisan migrate
```

Expected: no errors; `students.rank`, `students.state`, `students.email` exist after the final `migrate`; `students.name` is nullable.

- [ ] **Step 4: Tag and summarize**

Do **not** auto-tag — leave for Sumit. Print a summary of the commits added on this branch:

```bash
git log --oneline origin/main..HEAD
```

---

## Out of Scope / Deferred

- Creating the three concrete n8n workflows (`lead-sonam`, `lead-nikhil`, `lead-sumit-website`) — done in n8n UI at rollout time using Task 6's template.
- Migrating Nikhil's and Sonam's sheets to the standard column layout — owner-driven, manual, at rollout.
- Running the Sumit backfill against production data — execution choice (local vs server) will be made at plan execution time; the command is env-agnostic.
- Admin UI changes to show `rank`, `state`, `email` in Filament resources — covered by a future UI task, not this ingestion plan.
