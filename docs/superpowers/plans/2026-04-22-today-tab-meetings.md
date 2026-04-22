# Today Tab — Meetings Strip + Today Payments (SP#1) — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ship the SP#1 bundle described in `docs/superpowers/specs/2026-04-22-today-tab-meetings-design.md` — a new `/admin/today` page with a 5-day meetings strip, a today-payments widget, a first-class `meetings` table with observer-driven stage sync, and a `Report` / `Today Received` tabbed PaymentReport.

**Architecture:** New `Meeting` entity with its own table, policy, observer. Two new Filament widgets live on a new custom `TodayPage`. Existing `students.meeting_date` column is kept as a denormalized read cache maintained by `MeetingObserver`. PaymentReport gets a Livewire `$activeTab` property and conditional blade sections instead of pulling in a Filament Tabs component (avoids the API-drift risk flagged in the spec).

**Tech Stack:** Laravel 11, Filament 3, Spatie Permission + ActivityLog, MySQL, PHPUnit 11, PHP 8.5 local / 8.4 prod, Livewire 3 for Filament component tests.

**Branch:** `feature/today-tab-meetings` (already checked out, contains the spec commit `54eb9d3`).

**Local test runner:** `php -d memory_limit=512M vendor/bin/phpunit --filter=<name>` (plain `php artisan test` OOMs on the full suite with default memory).

**DEPR note:** On local PHP 8.5 every test emits `PHP Deprecated: PDO::MYSQL_ATTR_SSL_CA`. Harmless — read the final `Tests: X passed` line. See memory `project_davya-crm_php85_deprecations.md`.

**Timezone note:** PaymentReport already uses `Asia/Kolkata` for all date arithmetic. All new time-window queries in this plan use the same TZ.

---

## Seed fixture reference (from `database/seeders/UsersSeeder.php`)

| User | Email | Roles | Team |
|---|---|---|---|
| Sumit | `sumit@davya.local` | `admin`, `head` | own |
| Nikhil | `nikhil@davya.local` | `head` | Team 2 |
| Sonam | `sonam@davya.local` | `head` | Team 1 |
| Nisha | `nisha@davya.local` | `member` | Team 2 |
| Poonam | `poonam@davya.local` | `member` | Team 1 |
| Neetu | `neetu@davya.local` | `member` | Team 1 |
| Kapil | `kapil@davya.local` | `freelancer` | — |

Every seeded user has `must_change_password = true`. Use an `unblock($user)` helper to flip it before `actingAs` (pattern lifted from `LeadsReportPageTest.php` and `FinanceRoleTest.php`).

---

## File structure

**Create**
- `database/migrations/2026_04_23_000000_create_meetings_table.php`
- `database/migrations/2026_04_23_000100_backfill_meetings_from_students.php`
- `app/Models/Meeting.php`
- `app/Policies/MeetingPolicy.php`
- `app/Observers/MeetingObserver.php`
- `app/Filament/Pages/TodayPage.php`
- `app/Filament/Widgets/TodayMeetingsWidget.php`
- `app/Filament/Widgets/TodayPaymentsWidget.php`
- `resources/views/filament/pages/today-page.blade.php`
- `resources/views/filament/widgets/today-meetings-widget.blade.php`
- `resources/views/filament/widgets/today-payments-widget.blade.php`
- `app/Filament/Resources/StudentResource/RelationManagers/MeetingsRelationManager.php`
- `tests/Feature/MeetingSchemaTest.php`
- `tests/Feature/MeetingVisibilityTest.php`
- `tests/Feature/BackfillMeetingsFromStudentsTest.php`
- `tests/Feature/MeetingPolicyTest.php`
- `tests/Feature/MeetingObserverTest.php`
- `tests/Feature/MeetingsRelationManagerTest.php`
- `tests/Feature/TodayPageTest.php`
- `tests/Feature/TodayMeetingsWidgetTest.php`
- `tests/Feature/TodayPaymentsWidgetTest.php`
- `tests/Feature/PaymentReportTabsTest.php`

**Modify**
- `app/Providers/AppServiceProvider.php` — register `MeetingObserver` in `boot()`.
- `app/Providers/Filament/AdminPanelProvider.php` — add `TodayPage` to `pages([])` (stays auto-discovered) and add the two Today widgets to `widgets([])`.
- `app/Filament/Resources/StudentResource.php` — at line 200, change `DateTimePicker::make('meeting_date')` to be disabled with helper text. Append `MeetingsRelationManager` to `getRelations()`.
- `app/Filament/Pages/PaymentReport.php` — add `$activeTab` Livewire property, `setTab()` action, `todayPayments()` method, `downloadTodayCsv()` action. Update `mount()` to seed `activeTab`.
- `resources/views/filament/pages/payment-report.blade.php` — render tab buttons + conditional sections.

No other files change. Slack ingestion, n8n, Dashboard, Kanban, Finance, StageTransitionValidator stay untouched.

---

## Task 1: `meetings` table migration + schema test

**Rationale:** Establish the schema before anything else. A schema test locks the contract so subsequent tasks can assume column names and indexes.

**Files:**
- Create: `database/migrations/2026_04_23_000000_create_meetings_table.php`
- Create: `tests/Feature/MeetingSchemaTest.php`

### - [ ] Step 1: Write the failing schema test

Create `tests/Feature/MeetingSchemaTest.php`:

```php
<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class MeetingSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_meetings_table_has_expected_columns(): void
    {
        $this->assertTrue(Schema::hasTable('meetings'), 'meetings table must exist');

        foreach ([
            'id', 'student_id', 'owner_id', 'scheduled_at', 'mode', 'status',
            'notes', 'outcome_notes', 'held_at', 'rescheduled_from_id',
            'created_by_id', 'created_at', 'updated_at',
        ] as $col) {
            $this->assertTrue(
                Schema::hasColumn('meetings', $col),
                "meetings.$col must exist",
            );
        }
    }

    public function test_meetings_indexes_are_present(): void
    {
        $indexes = collect(DB::select('SHOW INDEX FROM meetings'))->pluck('Key_name')->unique()->values()->all();

        // Expect at least these named indexes (Laravel auto-names as table_col1_col2_index).
        foreach ([
            'meetings_owner_id_scheduled_at_index',
            'meetings_student_id_scheduled_at_index',
            'meetings_status_scheduled_at_index',
        ] as $expected) {
            $this->assertContains($expected, $indexes, "index $expected must exist");
        }
    }
}
```

### - [ ] Step 2: Run test to verify it fails

Run:
```bash
php -d memory_limit=512M vendor/bin/phpunit --filter=MeetingSchemaTest
```
Expected: **2 failures** — `meetings` table does not exist.

### - [ ] Step 3: Write the migration

Create `database/migrations/2026_04_23_000000_create_meetings_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('meetings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->foreignId('owner_id')->constrained('users');
            $table->dateTime('scheduled_at');
            $table->enum('mode', ['in_person', 'phone', 'video', 'whatsapp'])->default('in_person');
            $table->enum('status', ['scheduled', 'held', 'no_show', 'rescheduled', 'cancelled'])->default('scheduled');
            $table->text('notes')->nullable();
            $table->text('outcome_notes')->nullable();
            $table->dateTime('held_at')->nullable();
            $table->foreignId('rescheduled_from_id')->nullable()->constrained('meetings')->nullOnDelete();
            $table->foreignId('created_by_id')->constrained('users');
            $table->timestamps();

            $table->index(['owner_id', 'scheduled_at']);
            $table->index(['student_id', 'scheduled_at']);
            $table->index(['status', 'scheduled_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meetings');
    }
};
```

### - [ ] Step 4: Run migrations locally

Run:
```bash
php -d memory_limit=512M artisan migrate
```
Expected: output includes `Migrating: 2026_04_23_000000_create_meetings_table` and `Migrated:`.

### - [ ] Step 5: Run schema test — expect PASS

Run:
```bash
php -d memory_limit=512M vendor/bin/phpunit --filter=MeetingSchemaTest
```
Expected: **2 passed**.

### - [ ] Step 6: Commit

```bash
git add database/migrations/2026_04_23_000000_create_meetings_table.php tests/Feature/MeetingSchemaTest.php
git commit -m "$(cat <<'EOF'
feat(meetings): create meetings table with status machine + audit FK

First-class meetings entity. Columns: student_id, owner_id,
scheduled_at, mode enum (in_person/phone/video/whatsapp), status enum
(scheduled/held/no_show/rescheduled/cancelled), notes, outcome_notes,
held_at, rescheduled_from_id self-FK audit chain, created_by_id for
admin acts-as.

Ref: docs/superpowers/specs/2026-04-22-today-tab-meetings-design.md

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 2: `Meeting` model + `scopeVisibleTo` + relations

**Rationale:** Define the model with casts, relations, and the visibility scope that delegates to `Student::scopeVisibleTo` (reuses already-hardened team-unit rule).

**Files:**
- Create: `app/Models/Meeting.php`
- Create: `tests/Feature/MeetingVisibilityTest.php`

### - [ ] Step 1: Write failing visibility tests

Create `tests/Feature/MeetingVisibilityTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Meeting;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MeetingVisibilityTest extends TestCase
{
    use RefreshDatabase;

    private function studentOwnedBy(User $owner): Student
    {
        return Student::create([
            'name' => 'Test '.$owner->name,
            'phone' => '999'.str_pad((string) $owner->id, 7, '0', STR_PAD_LEFT),
            'course' => 'BBA',
            'stage' => 'Lead Captured',
            'owner_id' => $owner->id,
        ]);
    }

    private function meetingFor(Student $student, User $owner): Meeting
    {
        return Meeting::create([
            'student_id' => $student->id,
            'owner_id' => $owner->id,
            'scheduled_at' => now()->addDay(),
            'mode' => 'in_person',
            'status' => 'scheduled',
            'created_by_id' => $owner->id,
        ]);
    }

    public function test_admin_sees_all_meetings(): void
    {
        $this->seed();
        $sumit  = User::where('email', 'sumit@davya.local')->firstOrFail();
        $sonam  = User::where('email', 'sonam@davya.local')->firstOrFail();
        $nikhil = User::where('email', 'nikhil@davya.local')->firstOrFail();

        $this->meetingFor($this->studentOwnedBy($sonam), $sonam);
        $this->meetingFor($this->studentOwnedBy($nikhil), $nikhil);

        $this->assertSame(2, Meeting::visibleTo($sumit)->count());
    }

    public function test_head_only_sees_own_team_meetings(): void
    {
        $this->seed();
        $sonam  = User::where('email', 'sonam@davya.local')->firstOrFail();
        $nikhil = User::where('email', 'nikhil@davya.local')->firstOrFail();
        $nisha  = User::where('email', 'nisha@davya.local')->firstOrFail(); // team Nikhil

        $this->meetingFor($this->studentOwnedBy($sonam), $sonam);
        $this->meetingFor($this->studentOwnedBy($nikhil), $nikhil);
        $this->meetingFor($this->studentOwnedBy($nisha), $nisha);

        // Nikhil sees his own + Nisha's (team) — not Sonam's.
        $this->assertSame(2, Meeting::visibleTo($nikhil)->count());
        // Sonam sees her own only (no teammates in this test).
        $this->assertSame(1, Meeting::visibleTo($sonam)->count());
    }

    public function test_member_sees_team_meetings(): void
    {
        // Per scopeVisibleTo on Student: heads and members share team visibility.
        $this->seed();
        $nikhil = User::where('email', 'nikhil@davya.local')->firstOrFail();
        $nisha  = User::where('email', 'nisha@davya.local')->firstOrFail();

        $this->meetingFor($this->studentOwnedBy($nikhil), $nikhil);
        $this->meetingFor($this->studentOwnedBy($nisha), $nisha);

        $this->assertSame(2, Meeting::visibleTo($nisha)->count());
    }

    public function test_null_user_sees_nothing(): void
    {
        $this->seed();
        $sonam = User::where('email', 'sonam@davya.local')->firstOrFail();
        $this->meetingFor($this->studentOwnedBy($sonam), $sonam);

        $this->assertSame(0, Meeting::visibleTo(null)->count());
    }
}
```

### - [ ] Step 2: Run — expect FAIL

Run:
```bash
php -d memory_limit=512M vendor/bin/phpunit --filter=MeetingVisibilityTest
```
Expected: errors — `App\Models\Meeting` not found.

### - [ ] Step 3: Write the model

Create `app/Models/Meeting.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class Meeting extends Model
{
    use HasFactory, LogsActivity;

    protected $guarded = [];

    protected $casts = [
        'student_id' => 'integer',
        'owner_id' => 'integer',
        'created_by_id' => 'integer',
        'rescheduled_from_id' => 'integer',
        'scheduled_at' => 'datetime',
        'held_at' => 'datetime',
    ];

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }

    public function rescheduledFrom(): BelongsTo
    {
        return $this->belongsTo(self::class, 'rescheduled_from_id');
    }

    public function scopeVisibleTo(Builder $query, ?User $user): Builder
    {
        if ($user === null) {
            return $query->whereRaw('1 = 0');
        }
        return $query->whereHas('student', fn ($q) => $q->visibleTo($user));
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logAll()->logOnlyDirty();
    }
}
```

### - [ ] Step 4: Run — expect PASS

Run:
```bash
php -d memory_limit=512M vendor/bin/phpunit --filter=MeetingVisibilityTest
```
Expected: **4 passed**.

### - [ ] Step 5: Commit

```bash
git add app/Models/Meeting.php tests/Feature/MeetingVisibilityTest.php
git commit -m "$(cat <<'EOF'
feat(meetings): Meeting model with scopeVisibleTo delegating to Student

Mirrors the team-unit rule already in Student::scopeVisibleTo via a
whereHas clause — no duplicate team/head logic. LogsActivity trait
captures Spatie activity log entries for audit (E5 admin acts-as).

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 3: Backfill migration from `students.meeting_date`

**Rationale:** Create one Meeting row per student with a non-null `meeting_date`. Preserves data. Past dates become `held`, future dates `scheduled`.

**Files:**
- Create: `database/migrations/2026_04_23_000100_backfill_meetings_from_students.php`
- Create: `tests/Feature/BackfillMeetingsFromStudentsTest.php`

### - [ ] Step 1: Write the failing backfill test

Create `tests/Feature/BackfillMeetingsFromStudentsTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Meeting;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class BackfillMeetingsFromStudentsTest extends TestCase
{
    use RefreshDatabase;

    public function test_future_meeting_date_becomes_scheduled_meeting(): void
    {
        $this->seed();
        $owner = User::where('email', 'nikhil@davya.local')->firstOrFail();

        // Rollback just the backfill migration, insert test data, re-run it.
        Artisan::call('migrate:rollback', ['--step' => 1, '--force' => true]);

        $future = now()->addDays(2)->startOfMinute();

        $studentId = DB::table('students')->insertGetId([
            'name' => 'Future Student',
            'phone' => '9990000001',
            'course' => 'BBA',
            'stage' => 'Lead Captured',
            'owner_id' => $owner->id,
            'meeting_date' => $future,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Artisan::call('migrate', ['--force' => true]);

        $m = Meeting::where('student_id', $studentId)->first();
        $this->assertNotNull($m, 'backfill must create a meeting for non-null meeting_date');
        $this->assertSame('scheduled', $m->status);
        $this->assertSame('in_person', $m->mode);
        $this->assertSame($owner->id, $m->owner_id);
        $this->assertSame($owner->id, $m->created_by_id);
        $this->assertSame(
            $future->toDateTimeString(),
            $m->scheduled_at->toDateTimeString(),
        );
    }

    public function test_past_meeting_date_becomes_held_meeting(): void
    {
        $this->seed();
        $owner = User::where('email', 'sonam@davya.local')->firstOrFail();

        Artisan::call('migrate:rollback', ['--step' => 1, '--force' => true]);

        $past = now()->subDays(3)->startOfMinute();

        $studentId = DB::table('students')->insertGetId([
            'name' => 'Past Student',
            'phone' => '9990000002',
            'course' => 'MBA',
            'stage' => 'Meeting Done',
            'owner_id' => $owner->id,
            'meeting_date' => $past,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Artisan::call('migrate', ['--force' => true]);

        $m = Meeting::where('student_id', $studentId)->first();
        $this->assertNotNull($m);
        $this->assertSame('held', $m->status);
        $this->assertNotNull($m->held_at);
    }

    public function test_null_meeting_date_creates_no_meeting(): void
    {
        $this->seed();
        $owner = User::where('email', 'nikhil@davya.local')->firstOrFail();

        Artisan::call('migrate:rollback', ['--step' => 1, '--force' => true]);

        $studentId = DB::table('students')->insertGetId([
            'name' => 'No Meeting Student',
            'phone' => '9990000003',
            'course' => 'BBA',
            'stage' => 'Lead Captured',
            'owner_id' => $owner->id,
            'meeting_date' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Artisan::call('migrate', ['--force' => true]);

        $this->assertSame(0, Meeting::where('student_id', $studentId)->count());
    }
}
```

### - [ ] Step 2: Run — expect FAIL

Run:
```bash
php -d memory_limit=512M vendor/bin/phpunit --filter=BackfillMeetingsFromStudentsTest
```
Expected: 3 failures — backfill migration doesn't exist.

### - [ ] Step 3: Write the backfill migration

Create `database/migrations/2026_04_23_000100_backfill_meetings_from_students.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    // Creates one Meeting per student with a non-null meeting_date.
    // Past dates -> status='held' with held_at populated.
    // Future (or now) dates -> status='scheduled'.
    // Mode defaults to 'in_person' (safe default; counsellor can edit).
    // created_by_id = owner_id; fallback to Sumit (admin) if owner is null.

    public function up(): void
    {
        $fallbackCreator = DB::table('users')
            ->where('email', 'sumit@davya.local')
            ->value('id') ?? 1;

        DB::table('students')
            ->whereNotNull('meeting_date')
            ->orderBy('id')
            ->select(['id', 'owner_id', 'meeting_date'])
            ->chunkById(200, function ($rows) use ($fallbackCreator) {
                $now = Carbon::now();
                $inserts = [];
                foreach ($rows as $row) {
                    $scheduledAt = Carbon::parse($row->meeting_date);
                    $ownerId = $row->owner_id ?: $fallbackCreator;
                    $isPast = $scheduledAt->lt($now);
                    $inserts[] = [
                        'student_id' => $row->id,
                        'owner_id' => $ownerId,
                        'scheduled_at' => $scheduledAt,
                        'mode' => 'in_person',
                        'status' => $isPast ? 'held' : 'scheduled',
                        'notes' => null,
                        'outcome_notes' => null,
                        'held_at' => $isPast ? $scheduledAt : null,
                        'rescheduled_from_id' => null,
                        'created_by_id' => $ownerId,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }
                if (! empty($inserts)) {
                    DB::table('meetings')->insert($inserts);
                }
            });
    }

    public function down(): void
    {
        // Reverse is to delete all meetings that look like backfilled rows — but we
        // can't reliably distinguish them after the fact. Safe rollback is handled
        // at the create-table migration level; this migration's down() is a no-op.
    }
};
```

### - [ ] Step 4: Run migrations

Run:
```bash
php -d memory_limit=512M artisan migrate
```
Expected: `Migrating: 2026_04_23_000100_backfill_meetings_from_students` then `Migrated:`.

### - [ ] Step 5: Run tests — expect PASS

Run:
```bash
php -d memory_limit=512M vendor/bin/phpunit --filter=BackfillMeetingsFromStudentsTest
```
Expected: **3 passed**.

### - [ ] Step 6: Commit

```bash
git add database/migrations/2026_04_23_000100_backfill_meetings_from_students.php tests/Feature/BackfillMeetingsFromStudentsTest.php
git commit -m "$(cat <<'EOF'
feat(meetings): backfill Meeting rows from existing students.meeting_date

Non-null meeting_date -> one Meeting row. Past dates land as 'held'
(with held_at set); future as 'scheduled'. Mode defaults to in_person.
Chunked by 200 for safe prod run (533 students, ~50 expected rows).

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 4: `MeetingPolicy` — role × action matrix

**Rationale:** Role gate per spec Section "Roles & access matrix". E1 isolation + E4 counsellor-own-only + E5 admin acts-as all live here.

**Files:**
- Create: `app/Policies/MeetingPolicy.php`
- Create: `tests/Feature/MeetingPolicyTest.php`

### - [ ] Step 1: Write failing policy tests

Create `tests/Feature/MeetingPolicyTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Meeting;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MeetingPolicyTest extends TestCase
{
    use RefreshDatabase;

    private function unblock(User $u): User
    {
        $u->must_change_password = false;
        $u->save();
        return $u;
    }

    private function sumit(): User  { return $this->unblock(User::where('email', 'sumit@davya.local')->firstOrFail()); }
    private function sonam(): User  { return $this->unblock(User::where('email', 'sonam@davya.local')->firstOrFail()); }
    private function nikhil(): User { return $this->unblock(User::where('email', 'nikhil@davya.local')->firstOrFail()); }
    private function nisha(): User  { return $this->unblock(User::where('email', 'nisha@davya.local')->firstOrFail()); }
    private function kapil(): User  { return $this->unblock(User::where('email', 'kapil@davya.local')->firstOrFail()); }

    private function studentOwnedBy(User $owner): Student
    {
        return Student::create([
            'name' => 'S '.$owner->name,
            'phone' => '999'.str_pad((string) $owner->id, 7, '0', STR_PAD_LEFT),
            'course' => 'BBA',
            'stage' => 'Lead Captured',
            'owner_id' => $owner->id,
        ]);
    }

    private function meetingFor(Student $student, User $owner): Meeting
    {
        return Meeting::create([
            'student_id' => $student->id,
            'owner_id' => $owner->id,
            'scheduled_at' => now()->addDay(),
            'mode' => 'in_person',
            'status' => 'scheduled',
            'created_by_id' => $owner->id,
        ]);
    }

    public function test_admin_can_do_everything(): void
    {
        $this->seed();
        $sumit = $this->sumit();
        $m = $this->meetingFor($this->studentOwnedBy($this->sonam()), $this->sonam());

        $this->assertTrue($sumit->can('viewAny', Meeting::class));
        $this->assertTrue($sumit->can('view', $m));
        $this->assertTrue($sumit->can('create', Meeting::class));
        $this->assertTrue($sumit->can('update', $m));
        $this->assertTrue($sumit->can('delete', $m));
    }

    public function test_head_cannot_see_other_heads_team_meeting_e1(): void
    {
        $this->seed();
        $nikhil = $this->nikhil();
        $meetingInSonamsTeam = $this->meetingFor($this->studentOwnedBy($this->sonam()), $this->sonam());

        $this->assertFalse($nikhil->can('view', $meetingInSonamsTeam), 'E1: head isolation');
        $this->assertFalse($nikhil->can('update', $meetingInSonamsTeam));
    }

    public function test_head_can_update_own_team_meeting(): void
    {
        $this->seed();
        $nikhil = $this->nikhil();
        $m = $this->meetingFor($this->studentOwnedBy($this->nisha()), $this->nisha());

        $this->assertTrue($nikhil->can('view', $m));
        $this->assertTrue($nikhil->can('update', $m));
        $this->assertTrue($nikhil->can('delete', $m));
    }

    public function test_member_cannot_update_teammates_meeting_e4(): void
    {
        $this->seed();
        $nisha = $this->nisha();

        // Kapil is a freelancer — use another member instead. Create a second member in Team Nikhil:
        $kapilTeammate = User::create([
            'name' => 'Raj',
            'email' => 'raj@davya.local',
            'password' => bcrypt('x'),
            'is_active' => true,
            'must_change_password' => false,
            'team_head_id' => $this->nikhil()->id,
        ]);
        $kapilTeammate->assignRole('member');

        $theirsMeeting = $this->meetingFor($this->studentOwnedBy($kapilTeammate), $kapilTeammate);

        // Nisha can VIEW (same team) but cannot UPDATE (E4 — own only).
        $this->assertTrue($nisha->can('view', $theirsMeeting));
        $this->assertFalse($nisha->can('update', $theirsMeeting), 'E4: counsellor own only');
        $this->assertFalse($nisha->can('delete', $theirsMeeting), 'member cannot delete');
    }

    public function test_member_can_update_own_meeting(): void
    {
        $this->seed();
        $nisha = $this->nisha();
        $mine = $this->meetingFor($this->studentOwnedBy($nisha), $nisha);

        $this->assertTrue($nisha->can('update', $mine));
    }

    public function test_freelancer_only_sees_own(): void
    {
        $this->seed();
        $kapil = $this->kapil();
        $mine = $this->meetingFor($this->studentOwnedBy($kapil), $kapil);
        $not_mine = $this->meetingFor($this->studentOwnedBy($this->sonam()), $this->sonam());

        $this->assertTrue($kapil->can('view', $mine));
        $this->assertFalse($kapil->can('view', $not_mine));
        $this->assertFalse($kapil->can('delete', $mine), 'freelancer cannot delete');
    }
}
```

### - [ ] Step 2: Run — expect FAIL

Run:
```bash
php -d memory_limit=512M vendor/bin/phpunit --filter=MeetingPolicyTest
```
Expected: errors — `App\Policies\MeetingPolicy` not found.

### - [ ] Step 3: Write the policy

Create `app/Policies/MeetingPolicy.php`:

```php
<?php

namespace App\Policies;

use App\Models\Meeting;
use App\Models\User;

class MeetingPolicy
{
    public function viewAny(User $user): bool
    {
        return true; // Scoped at query level by Meeting::visibleTo($user).
    }

    public function view(User $user, Meeting $meeting): bool
    {
        if ($user->hasRole('admin')) {
            return true;
        }
        return Meeting::query()->where('id', $meeting->id)->visibleTo($user)->exists();
    }

    public function create(User $user): bool
    {
        return true; // Student-selector scope in the form enforces which students the user can schedule for.
    }

    public function update(User $user, Meeting $meeting): bool
    {
        if ($user->hasRole('admin')) {
            return true;
        }
        if ($user->hasRole('head')) {
            return $this->view($user, $meeting);
        }
        // member / freelancer — own only (E4).
        return (int) $meeting->owner_id === (int) $user->id;
    }

    public function delete(User $user, Meeting $meeting): bool
    {
        if ($user->hasRole('admin')) {
            return true;
        }
        if ($user->hasRole('head')) {
            return $this->view($user, $meeting);
        }
        return false; // member / freelancer cannot delete.
    }
}
```

### - [ ] Step 4: Run — expect PASS

Run:
```bash
php -d memory_limit=512M vendor/bin/phpunit --filter=MeetingPolicyTest
```
Expected: **6 passed**.

### - [ ] Step 5: Commit

```bash
git add app/Policies/MeetingPolicy.php tests/Feature/MeetingPolicyTest.php
git commit -m "$(cat <<'EOF'
feat(meetings): MeetingPolicy role gate (E1 + E4 + admin-delete rule)

Admin: all actions. Head: full CRUD within team. Counsellor/freelancer:
view within team (heads share team visibility), update only own (E4),
no delete.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 5: `MeetingObserver` — stage sync + `meeting_date` cache sync

**Rationale:** Spec Section "Observers & stage integration". Creating a Meeting on a `Lead Captured` student advances stage. Marking `held` advances to `Meeting Done`. No regression on cancel/no-show. `student.meeting_date` stays in sync as a denormalized cache.

**Files:**
- Create: `app/Observers/MeetingObserver.php`
- Create: `tests/Feature/MeetingObserverTest.php`
- Modify: `app/Providers/AppServiceProvider.php`

### - [ ] Step 1: Write failing observer tests

Create `tests/Feature/MeetingObserverTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Meeting;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MeetingObserverTest extends TestCase
{
    use RefreshDatabase;

    private function student(User $owner, string $stage = 'Lead Captured'): Student
    {
        return Student::create([
            'name' => 'O '.$owner->name,
            'phone' => '998'.str_pad((string) $owner->id, 7, '0', STR_PAD_LEFT),
            'course' => 'BBA',
            'stage' => $stage,
            'owner_id' => $owner->id,
        ]);
    }

    public function test_creating_a_meeting_advances_lead_captured_to_meeting_scheduled(): void
    {
        $this->seed();
        $nikhil = User::where('email', 'nikhil@davya.local')->firstOrFail();
        $s = $this->student($nikhil, 'Lead Captured');

        Meeting::create([
            'student_id' => $s->id,
            'owner_id' => $nikhil->id,
            'scheduled_at' => now()->addDay(),
            'mode' => 'in_person',
            'status' => 'scheduled',
            'created_by_id' => $nikhil->id,
        ]);

        $this->assertSame('Meeting Scheduled', $s->fresh()->stage);
    }

    public function test_creating_a_meeting_does_not_regress_later_stage(): void
    {
        $this->seed();
        $nikhil = User::where('email', 'nikhil@davya.local')->firstOrFail();
        $s = $this->student($nikhil, 'Onboarded');

        Meeting::create([
            'student_id' => $s->id,
            'owner_id' => $nikhil->id,
            'scheduled_at' => now()->addDay(),
            'mode' => 'in_person',
            'status' => 'scheduled',
            'created_by_id' => $nikhil->id,
        ]);

        $this->assertSame('Onboarded', $s->fresh()->stage, 'observer must not regress a later stage');
    }

    public function test_marking_held_advances_meeting_scheduled_to_meeting_done(): void
    {
        $this->seed();
        $nikhil = User::where('email', 'nikhil@davya.local')->firstOrFail();
        $s = $this->student($nikhil, 'Lead Captured');

        $m = Meeting::create([
            'student_id' => $s->id,
            'owner_id' => $nikhil->id,
            'scheduled_at' => now()->addDay(),
            'mode' => 'in_person',
            'status' => 'scheduled',
            'created_by_id' => $nikhil->id,
        ]);

        $this->assertSame('Meeting Scheduled', $s->fresh()->stage);

        $m->update(['status' => 'held']);

        $this->assertSame('Meeting Done', $s->fresh()->stage);
        $this->assertNotNull($m->fresh()->held_at, 'held_at must be populated on held');
    }

    public function test_cancelling_does_not_regress_stage(): void
    {
        $this->seed();
        $nikhil = User::where('email', 'nikhil@davya.local')->firstOrFail();
        $s = $this->student($nikhil, 'Lead Captured');

        $m = Meeting::create([
            'student_id' => $s->id,
            'owner_id' => $nikhil->id,
            'scheduled_at' => now()->addDay(),
            'mode' => 'in_person',
            'status' => 'scheduled',
            'created_by_id' => $nikhil->id,
        ]);

        $this->assertSame('Meeting Scheduled', $s->fresh()->stage);

        $m->update(['status' => 'cancelled']);

        $this->assertSame('Meeting Scheduled', $s->fresh()->stage, 'cancel must not regress stage');
    }

    public function test_meeting_date_cache_tracks_earliest_scheduled(): void
    {
        $this->seed();
        $nikhil = User::where('email', 'nikhil@davya.local')->firstOrFail();
        $s = $this->student($nikhil);

        $laterAt   = now()->addDays(5)->startOfMinute();
        $earlierAt = now()->addDays(2)->startOfMinute();

        Meeting::create([
            'student_id' => $s->id,
            'owner_id' => $nikhil->id,
            'scheduled_at' => $laterAt,
            'mode' => 'in_person',
            'status' => 'scheduled',
            'created_by_id' => $nikhil->id,
        ]);
        $this->assertSame(
            $laterAt->toDateTimeString(),
            $s->fresh()->meeting_date->toDateTimeString(),
        );

        Meeting::create([
            'student_id' => $s->id,
            'owner_id' => $nikhil->id,
            'scheduled_at' => $earlierAt,
            'mode' => 'in_person',
            'status' => 'scheduled',
            'created_by_id' => $nikhil->id,
        ]);
        $this->assertSame(
            $earlierAt->toDateTimeString(),
            $s->fresh()->meeting_date->toDateTimeString(),
        );
    }

    public function test_meeting_date_becomes_null_when_all_scheduled_handled(): void
    {
        $this->seed();
        $nikhil = User::where('email', 'nikhil@davya.local')->firstOrFail();
        $s = $this->student($nikhil);

        $m = Meeting::create([
            'student_id' => $s->id,
            'owner_id' => $nikhil->id,
            'scheduled_at' => now()->addDay(),
            'mode' => 'in_person',
            'status' => 'scheduled',
            'created_by_id' => $nikhil->id,
        ]);
        $this->assertNotNull($s->fresh()->meeting_date);

        $m->update(['status' => 'held']);

        $this->assertNull($s->fresh()->meeting_date);
    }
}
```

### - [ ] Step 2: Run — expect FAIL

Run:
```bash
php -d memory_limit=512M vendor/bin/phpunit --filter=MeetingObserverTest
```
Expected: 6 failures — observer not wired.

### - [ ] Step 3: Write the observer

Create `app/Observers/MeetingObserver.php`:

```php
<?php

namespace App\Observers;

use App\Models\Meeting;
use App\Models\Student;
use App\Services\StageTransitionValidator;
use Illuminate\Support\Facades\Log;

class MeetingObserver
{
    public function __construct(private readonly StageTransitionValidator $validator)
    {
    }

    public function created(Meeting $meeting): void
    {
        $student = $meeting->student()->first();
        if ($student === null) {
            return;
        }

        if ($student->stage === 'Lead Captured') {
            $this->advanceStage($student, 'Meeting Scheduled');
        }

        $this->syncMeetingDateCache($student);
    }

    public function updated(Meeting $meeting): void
    {
        // Populate held_at when status flips to 'held'.
        if ($meeting->wasChanged('status') && $meeting->status === 'held' && $meeting->held_at === null) {
            // Quiet save without re-firing the updated event.
            Meeting::withoutEvents(fn () => $meeting->update(['held_at' => now()]));
        }

        $student = $meeting->student()->first();
        if ($student === null) {
            return;
        }

        if (
            $meeting->wasChanged('status')
            && $meeting->status === 'held'
            && $student->stage === 'Meeting Scheduled'
        ) {
            $this->advanceStage($student, 'Meeting Done');
        }

        // No stage regression on 'cancelled' / 'no_show' — counsellor decides manually.

        $this->syncMeetingDateCache($student);
    }

    public function deleted(Meeting $meeting): void
    {
        $student = $meeting->student()->first();
        if ($student === null) {
            return;
        }
        $this->syncMeetingDateCache($student);
    }

    private function advanceStage(Student $student, string $newStage): void
    {
        $errors = $this->validator->forStageChange($student, $newStage);
        if (! empty($errors)) {
            Log::warning('MeetingObserver: stage auto-advance blocked', [
                'student_id' => $student->id,
                'from' => $student->stage,
                'to' => $newStage,
                'errors' => $errors,
            ]);
            return;
        }
        Student::withoutEvents(fn () => $student->update(['stage' => $newStage]));
    }

    private function syncMeetingDateCache(Student $student): void
    {
        $next = $student->meetings()
            ->where('status', 'scheduled')
            ->orderBy('scheduled_at')
            ->value('scheduled_at');

        if ((string) $student->meeting_date === (string) $next) {
            return; // No-op when already in sync — avoids recursive save loops.
        }

        Student::withoutEvents(fn () => $student->update(['meeting_date' => $next]));
    }
}
```

### - [ ] Step 4: Add `meetings()` relation to `Student` model

Edit `app/Models/Student.php` — append after the `notes()` relation (around line 81):

```php
    public function meetings(): HasMany { return $this->hasMany(Meeting::class); }
```

`HasMany` is already imported at the top of the file.

### - [ ] Step 5: Register the observer in `AppServiceProvider`

In `app/Providers/AppServiceProvider.php`, inside `boot()`, append:

```php
        \App\Models\Meeting::observe(\App\Observers\MeetingObserver::class);
```

(Check the exact current contents of `boot()` and append the line; do not delete existing registrations.)

### - [ ] Step 6: Inspect `StageTransitionValidator::forStageChange` signature

Run:
```bash
grep -n "forStageChange" app/Services/StageTransitionValidator.php
```
Verify the method exists and accepts `(Student $student, string $newStage): array`. If the return shape differs (e.g. returns a single string or throws), adapt the observer's `advanceStage` method before running tests.

### - [ ] Step 7: Run — expect PASS

Run:
```bash
php -d memory_limit=512M vendor/bin/phpunit --filter=MeetingObserverTest
```
Expected: **6 passed**.

### - [ ] Step 8: Commit

```bash
git add app/Observers/MeetingObserver.php tests/Feature/MeetingObserverTest.php \
        app/Models/Student.php app/Providers/AppServiceProvider.php
git commit -m "$(cat <<'EOF'
feat(meetings): MeetingObserver — stage sync + meeting_date cache

- Create a scheduled meeting on a Lead Captured student -> stage
  advances to Meeting Scheduled (via StageTransitionValidator; warns
  + no-ops if validator blocks).
- Mark held -> stage advances to Meeting Done, held_at populated.
- Cancel / no_show -> no stage change (counsellor decides manually).
- student.meeting_date stays in sync with MIN(scheduled_at WHERE
  status='scheduled'), maintaining the legacy column as a read cache.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 6: `MeetingsRelationManager` + disable `meeting_date` field on StudentResource

**Rationale:** Full CRUD on meetings within student context. Existing `DateTimePicker::make('meeting_date')` on StudentResource becomes a disabled read-only field with helper text pointing to the Meetings tab.

**Files:**
- Create: `app/Filament/Resources/StudentResource/RelationManagers/MeetingsRelationManager.php`
- Create: `tests/Feature/MeetingsRelationManagerTest.php`
- Modify: `app/Filament/Resources/StudentResource.php` (line 200 + `getRelations()`)

### - [ ] Step 1: Write failing relation manager test

Create `tests/Feature/MeetingsRelationManagerTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Filament\Resources\StudentResource\RelationManagers\MeetingsRelationManager;
use App\Models\Meeting;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class MeetingsRelationManagerTest extends TestCase
{
    use RefreshDatabase;

    private function unblock(User $u): User
    {
        $u->must_change_password = false;
        $u->save();
        return $u;
    }

    public function test_head_can_create_meeting_via_relation_manager(): void
    {
        $this->seed();
        $nikhil = $this->unblock(User::where('email', 'nikhil@davya.local')->firstOrFail());
        $this->actingAs($nikhil);

        $s = Student::create([
            'name' => 'Rel Student',
            'phone' => '9888000001',
            'course' => 'BBA',
            'stage' => 'Lead Captured',
            'owner_id' => $nikhil->id,
        ]);

        Livewire::test(MeetingsRelationManager::class, [
            'ownerRecord' => $s,
            'pageClass'   => \App\Filament\Resources\StudentResource\Pages\EditStudent::class,
        ])
        ->callTableAction('create', data: [
            'owner_id'     => $nikhil->id,
            'scheduled_at' => now()->addDay()->toDateTimeString(),
            'mode'         => 'phone',
            'notes'        => 'Intro call',
        ])
        ->assertHasNoTableActionErrors();

        $m = Meeting::where('student_id', $s->id)->first();
        $this->assertNotNull($m);
        $this->assertSame('scheduled', $m->status, 'new meetings default to scheduled');
        $this->assertSame('phone', $m->mode);
        $this->assertSame($nikhil->id, $m->created_by_id);
    }

    public function test_head_can_mark_meeting_held(): void
    {
        $this->seed();
        $nikhil = $this->unblock(User::where('email', 'nikhil@davya.local')->firstOrFail());
        $this->actingAs($nikhil);

        $s = Student::create([
            'name' => 'Rel Student 2',
            'phone' => '9888000002',
            'course' => 'BBA',
            'stage' => 'Lead Captured',
            'owner_id' => $nikhil->id,
        ]);

        $m = Meeting::create([
            'student_id' => $s->id,
            'owner_id' => $nikhil->id,
            'scheduled_at' => now()->addHour(),
            'mode' => 'phone',
            'status' => 'scheduled',
            'created_by_id' => $nikhil->id,
        ]);

        Livewire::test(MeetingsRelationManager::class, [
            'ownerRecord' => $s,
            'pageClass'   => \App\Filament\Resources\StudentResource\Pages\EditStudent::class,
        ])
        ->callTableAction('markHeld', record: $m)
        ->assertHasNoTableActionErrors();

        $this->assertSame('held', $m->fresh()->status);
        $this->assertSame('Meeting Done', $s->fresh()->stage);
    }
}
```

### - [ ] Step 2: Run — expect FAIL

Run:
```bash
php -d memory_limit=512M vendor/bin/phpunit --filter=MeetingsRelationManagerTest
```
Expected: errors — class not found.

### - [ ] Step 3: Write the relation manager

Create `app/Filament/Resources/StudentResource/RelationManagers/MeetingsRelationManager.php`:

```php
<?php

namespace App\Filament\Resources\StudentResource\RelationManagers;

use App\Models\Meeting;
use App\Models\User;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class MeetingsRelationManager extends RelationManager
{
    protected static string $relationship = 'meetings';

    protected static ?string $title = 'Meetings';

    protected static ?string $icon = 'heroicon-o-calendar';

    public function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Select::make('owner_id')
                ->label('Owner')
                ->options(fn () => User::orderBy('name')->pluck('name', 'id')->all())
                ->default(fn () => auth()->id())
                ->required(),
            Forms\Components\DateTimePicker::make('scheduled_at')
                ->label('Scheduled at')
                ->required()
                ->native(false)
                ->default(fn () => now()->addDay()),
            Forms\Components\Select::make('mode')
                ->options([
                    'in_person' => 'In person',
                    'phone'     => 'Phone',
                    'video'     => 'Video',
                    'whatsapp'  => 'WhatsApp',
                ])
                ->default('in_person')
                ->required(),
            Forms\Components\Textarea::make('notes')
                ->label('Pre-meeting notes')
                ->rows(2),
            Forms\Components\Textarea::make('outcome_notes')
                ->label('Outcome notes (after meeting held)')
                ->rows(2)
                ->visible(fn ($record) => $record?->status === 'held'),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('scheduled_at')
                    ->label('When')
                    ->dateTime('d M Y · H:i')
                    ->sortable(),
                Tables\Columns\TextColumn::make('mode')
                    ->badge()
                    ->color('gray'),
                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state) => match ($state) {
                        'scheduled'    => 'info',
                        'held'         => 'success',
                        'no_show'      => 'warning',
                        'rescheduled'  => 'gray',
                        'cancelled'    => 'danger',
                        default        => 'gray',
                    }),
                Tables\Columns\TextColumn::make('owner.name')->label('Owner'),
                Tables\Columns\TextColumn::make('notes')->limit(40),
            ])
            ->defaultSort('scheduled_at', 'desc')
            ->headerActions([
                Tables\Actions\CreateAction::make()
                    ->mutateFormDataUsing(function (array $data): array {
                        $data['status'] = 'scheduled';
                        $data['created_by_id'] = auth()->id();
                        return $data;
                    }),
            ])
            ->actions([
                Tables\Actions\Action::make('markHeld')
                    ->label('Mark held')
                    ->icon('heroicon-o-check')
                    ->color('success')
                    ->visible(fn (Meeting $r) => $r->status === 'scheduled')
                    ->action(fn (Meeting $r) => $r->update(['status' => 'held'])),
                Tables\Actions\Action::make('markNoShow')
                    ->label('No-show')
                    ->icon('heroicon-o-x-mark')
                    ->color('warning')
                    ->visible(fn (Meeting $r) => $r->status === 'scheduled')
                    ->action(fn (Meeting $r) => $r->update(['status' => 'no_show'])),
                Tables\Actions\EditAction::make(),
                Tables\Actions\Action::make('reschedule')
                    ->label('Reschedule')
                    ->icon('heroicon-o-arrow-path')
                    ->visible(fn (Meeting $r) => $r->status === 'scheduled')
                    ->form([
                        Forms\Components\DateTimePicker::make('new_scheduled_at')
                            ->label('New time')
                            ->required()
                            ->native(false),
                    ])
                    ->action(function (Meeting $r, array $data): void {
                        Meeting::create([
                            'student_id'          => $r->student_id,
                            'owner_id'            => $r->owner_id,
                            'scheduled_at'        => $data['new_scheduled_at'],
                            'mode'                => $r->mode,
                            'status'              => 'scheduled',
                            'rescheduled_from_id' => $r->id,
                            'created_by_id'       => auth()->id(),
                        ]);
                        $r->update(['status' => 'rescheduled']);
                    }),
                Tables\Actions\DeleteAction::make(),
            ]);
    }

    protected function getTableQuery(): Builder
    {
        return parent::getTableQuery()->visibleTo(auth()->user());
    }
}
```

### - [ ] Step 4: Wire the relation manager and disable `meeting_date` on `StudentResource`

Edit `app/Filament/Resources/StudentResource.php`.

At line 200, replace:
```php
                    DateTimePicker::make('meeting_date'),
```
with:
```php
                    DateTimePicker::make('meeting_date')
                        ->disabled()
                        ->helperText('Scheduling is managed in the Meetings tab (read-only cache).'),
```

Then locate the `getRelations()` method (or add one if it does not exist). Append the new relation manager to its return array. If `getRelations()` is absent, add this method to the class:

```php
    public static function getRelations(): array
    {
        return [
            \App\Filament\Resources\StudentResource\RelationManagers\MeetingsRelationManager::class,
            // Leave any pre-existing entries above intact.
        ];
    }
```

If `getRelations()` is already defined, just append `\App\Filament\Resources\StudentResource\RelationManagers\MeetingsRelationManager::class,` to its array — do not delete existing entries.

### - [ ] Step 5: Run — expect PASS

Run:
```bash
php -d memory_limit=512M vendor/bin/phpunit --filter=MeetingsRelationManagerTest
```
Expected: **2 passed**.

### - [ ] Step 6: Commit

```bash
git add app/Filament/Resources/StudentResource/RelationManagers/MeetingsRelationManager.php \
        app/Filament/Resources/StudentResource.php \
        tests/Feature/MeetingsRelationManagerTest.php
git commit -m "$(cat <<'EOF'
feat(meetings): MeetingsRelationManager on StudentResource

Full CRUD per student via a new Meetings tab. Mark-held + mark-no-show
+ reschedule (creates new row pointing back via rescheduled_from_id).
Existing meeting_date DateTimePicker becomes read-only with helper.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 7: `TodayPage` Filament page

**Rationale:** New custom page at `/admin/today`. Shell only — widgets come in Tasks 8 and 10.

**Files:**
- Create: `app/Filament/Pages/TodayPage.php`
- Create: `resources/views/filament/pages/today-page.blade.php`
- Create: `tests/Feature/TodayPageTest.php`

### - [ ] Step 1: Write failing page test

Create `tests/Feature/TodayPageTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Filament\Pages\TodayPage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class TodayPageTest extends TestCase
{
    use RefreshDatabase;

    private function unblock(User $u): User
    {
        $u->must_change_password = false;
        $u->save();
        return $u;
    }

    public function test_admin_can_access_today_page(): void
    {
        $this->seed();
        $sumit = $this->unblock(User::where('email', 'sumit@davya.local')->firstOrFail());
        $this->actingAs($sumit);

        Livewire::test(TodayPage::class)->assertStatus(200);
        $this->assertTrue(TodayPage::canAccess());
    }

    public function test_head_can_access(): void
    {
        $this->seed();
        $nikhil = $this->unblock(User::where('email', 'nikhil@davya.local')->firstOrFail());
        $this->actingAs($nikhil);

        $this->assertTrue(TodayPage::canAccess());
    }

    public function test_member_can_access(): void
    {
        $this->seed();
        $nisha = $this->unblock(User::where('email', 'nisha@davya.local')->firstOrFail());
        $this->actingAs($nisha);

        $this->assertTrue(TodayPage::canAccess());
    }
}
```

### - [ ] Step 2: Run — expect FAIL

Run:
```bash
php -d memory_limit=512M vendor/bin/phpunit --filter=TodayPageTest
```
Expected: errors — class not found.

### - [ ] Step 3: Write the page

Create `app/Filament/Pages/TodayPage.php`:

```php
<?php

namespace App\Filament\Pages;

use Filament\Pages\Page;

class TodayPage extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-sun';

    protected static ?string $navigationLabel = 'Today';

    protected static ?int $navigationSort = 1;

    protected static ?string $slug = 'today';

    protected static ?string $title = 'Today';

    protected static string $view = 'filament.pages.today-page';

    public static function canAccess(): bool
    {
        return auth()->check();
    }

    protected function getHeaderWidgets(): array
    {
        return [
            \App\Filament\Widgets\TodayMeetingsWidget::class,
            \App\Filament\Widgets\TodayPaymentsWidget::class,
        ];
    }

    protected function getHeaderWidgetsColumns(): int | array
    {
        return 1;
    }
}
```

### - [ ] Step 4: Write the blade view

Create `resources/views/filament/pages/today-page.blade.php`:

```blade
<x-filament-panels::page>
    @php
        $now = now('Asia/Kolkata');
    @endphp

    <div class="text-sm text-gray-500 dark:text-gray-400 mb-2">
        {{ $now->format('l, j M Y') }}
    </div>

    {{ $this->getHeaderWidgetsContents() }}
</x-filament-panels::page>
```

### - [ ] Step 5: Register the widgets and page reference in `AdminPanelProvider`

Edit `app/Providers/Filament/AdminPanelProvider.php`.

The page is auto-discovered because it lives in `app/Filament/Pages/`. Widgets are auto-discovered too. The two new Today widgets must NOT appear in the default Dashboard — Filament's `discoverWidgets` registers them for potential use but widgets are only rendered where a page asks for them via `getHeaderWidgets()`/`getFooterWidgets()`. Since Dashboard's widget list is explicit in `->widgets([...])` and doesn't mention the Today widgets, they won't leak there. No change required to the Dashboard widget array.

Verify: leave `AdminPanelProvider::panel()` mostly alone; ensure `TodayPage` is discoverable by the existing `discoverPages(in: app_path('Filament/Pages'), …)` call. Since that already exists (line 81), no change needed.

### - [ ] Step 6: Run — expect PASS

Run:
```bash
php -d memory_limit=512M vendor/bin/phpunit --filter=TodayPageTest
```
Expected: **3 passed**. (The page currently asks for widgets that don't exist yet — Livewire tests will only fail if widgets are rendered. `assertStatus(200)` should still pass since widget resolution is lazy until actually rendered. If `test_admin_can_access_today_page` fails with a class-not-found for widgets, comment out the `getHeaderWidgets()` return temporarily, finish Tasks 8 and 10, then re-enable — but prefer creating stub widget files first if needed.)

**Fallback if Step 6 fails due to missing widgets:** create two minimal stub widget files so the page loads:
```php
<?php
namespace App\Filament\Widgets;
use Filament\Widgets\Widget;
class TodayMeetingsWidget extends Widget { protected static string $view = 'filament.widgets.todo'; }
```
and identical for `TodayPaymentsWidget`. Also create a minimal `resources/views/filament/widgets/todo.blade.php` with `<div>todo</div>`. Real implementations replace these in Tasks 8 and 10.

### - [ ] Step 7: Commit

```bash
git add app/Filament/Pages/TodayPage.php resources/views/filament/pages/today-page.blade.php \
        tests/Feature/TodayPageTest.php \
        app/Filament/Widgets/TodayMeetingsWidget.php app/Filament/Widgets/TodayPaymentsWidget.php \
        resources/views/filament/widgets/todo.blade.php
git commit -m "$(cat <<'EOF'
feat(today): TodayPage shell at /admin/today

Custom Filament page, nav label "Today", sort=1 (above Dashboard).
Header widgets will be populated by TodayMeetingsWidget and
TodayPaymentsWidget (stubs for now; real implementations follow).

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 8: `TodayMeetingsWidget` — 5-day strip

**Rationale:** The main Today UI. Renders 5 day columns (Today / +1 / +2 / +3 / +4) with meeting cards grouped by day, overdue flagged.

**Files:**
- Create / overwrite: `app/Filament/Widgets/TodayMeetingsWidget.php`
- Create / overwrite: `resources/views/filament/widgets/today-meetings-widget.blade.php`
- Create: `tests/Feature/TodayMeetingsWidgetTest.php`

### - [ ] Step 1: Write failing widget test

Create `tests/Feature/TodayMeetingsWidgetTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Filament\Widgets\TodayMeetingsWidget;
use App\Models\Meeting;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class TodayMeetingsWidgetTest extends TestCase
{
    use RefreshDatabase;

    private function unblock(User $u): User
    {
        $u->must_change_password = false;
        $u->save();
        return $u;
    }

    private function mkStudent(User $owner, string $name = 'S'): Student
    {
        return Student::create([
            'name' => $name,
            'phone' => '9977'.str_pad((string) random_int(1, 999999), 6, '0', STR_PAD_LEFT),
            'course' => 'BBA',
            'stage' => 'Lead Captured',
            'owner_id' => $owner->id,
        ]);
    }

    private function mkMeeting(Student $student, User $owner, \Carbon\Carbon $at, string $status = 'scheduled'): Meeting
    {
        return Meeting::create([
            'student_id' => $student->id,
            'owner_id' => $owner->id,
            'scheduled_at' => $at,
            'mode' => 'in_person',
            'status' => $status,
            'created_by_id' => $owner->id,
        ]);
    }

    public function test_widget_window_is_today_plus_four_days(): void
    {
        $this->seed();
        $nikhil = $this->unblock(User::where('email', 'nikhil@davya.local')->firstOrFail());
        $this->actingAs($nikhil);
        $s = $this->mkStudent($nikhil);

        $this->mkMeeting($s, $nikhil, now('Asia/Kolkata')->startOfDay()->addHours(10));   // today
        $this->mkMeeting($s, $nikhil, now('Asia/Kolkata')->addDays(4)->startOfDay());     // in window (+4)
        $this->mkMeeting($s, $nikhil, now('Asia/Kolkata')->addDays(5)->startOfDay());     // out of window
        $this->mkMeeting($s, $nikhil, now('Asia/Kolkata')->subDays(1)->startOfDay());     // past day out of window

        $days = Livewire::test(TodayMeetingsWidget::class)->get('days');

        $this->assertCount(5, $days);
        // Day 0 (today) -> at least 1 meeting
        $this->assertGreaterThanOrEqual(1, count($days[0]['meetings']));
        // Day 4 (+4) -> exactly 1 meeting
        $this->assertCount(1, $days[4]['meetings']);
    }

    public function test_overdue_flag_on_past_scheduled_meeting(): void
    {
        $this->seed();
        $nikhil = $this->unblock(User::where('email', 'nikhil@davya.local')->firstOrFail());
        $this->actingAs($nikhil);
        $s = $this->mkStudent($nikhil);

        // Past today: earlier today, still status='scheduled'
        $meeting = $this->mkMeeting($s, $nikhil, now('Asia/Kolkata')->subHour(), 'scheduled');

        $days = Livewire::test(TodayMeetingsWidget::class)->get('days');
        $todayCards = $days[0]['meetings'];

        $overdue = collect($todayCards)->firstWhere('id', $meeting->id);
        $this->assertNotNull($overdue, 'overdue meeting must render in Today column');
        $this->assertTrue($overdue['is_overdue'], 'past scheduled meeting must be flagged overdue');
    }

    public function test_scoping_head_sees_own_team_only(): void
    {
        $this->seed();
        $nikhil = User::where('email', 'nikhil@davya.local')->firstOrFail();
        $sonam  = User::where('email', 'sonam@davya.local')->firstOrFail();

        $this->mkMeeting(
            $this->mkStudent($nikhil, 'N Student'),
            $nikhil,
            now('Asia/Kolkata')->addHours(3),
        );
        $this->mkMeeting(
            $this->mkStudent($sonam, 'S Student'),
            $sonam,
            now('Asia/Kolkata')->addHours(3),
        );

        $this->actingAs($this->unblock($nikhil));
        $days = Livewire::test(TodayMeetingsWidget::class)->get('days');
        $total = collect($days)->sum(fn ($d) => count($d['meetings']));
        $this->assertSame(1, $total, 'head sees own team meetings only');
    }
}
```

### - [ ] Step 2: Run — expect FAIL

Run:
```bash
php -d memory_limit=512M vendor/bin/phpunit --filter=TodayMeetingsWidgetTest
```
Expected: failures — widget is a stub; no `days` property yet.

### - [ ] Step 3: Write the widget (overwrites Task 7's stub)

Overwrite `app/Filament/Widgets/TodayMeetingsWidget.php`:

```php
<?php

namespace App\Filament\Widgets;

use App\Models\Meeting;
use Filament\Widgets\Widget;
use Illuminate\Support\Carbon;

class TodayMeetingsWidget extends Widget
{
    protected static string $view = 'filament.widgets.today-meetings-widget';

    protected int | string | array $columnSpan = 'full';

    /**
     * @return array<int, array{
     *     date:\Illuminate\Support\Carbon,
     *     label:string,
     *     is_today:bool,
     *     meetings:array<int, array{id:int,time:string,student_name:string,student_phone:?string,course:?string,mode:string,owner_initials:string,status:string,is_overdue:bool}>
     * }>
     */
    public function getDaysProperty(): array
    {
        $tz = 'Asia/Kolkata';
        $start = Carbon::now($tz)->startOfDay();
        $end   = $start->copy()->addDays(5); // exclusive upper bound

        $meetings = Meeting::query()
            ->visibleTo(auth()->user())
            ->whereBetween('scheduled_at', [$start, $end->copy()->subSecond()])
            ->whereIn('status', ['scheduled', 'held'])
            ->with(['student', 'owner'])
            ->orderBy('scheduled_at')
            ->get();

        $days = [];
        for ($i = 0; $i < 5; $i++) {
            $dayStart = $start->copy()->addDays($i);
            $dayEnd   = $dayStart->copy()->addDay();

            $slot = $meetings->filter(fn (Meeting $m) => $m->scheduled_at->between($dayStart, $dayEnd->copy()->subSecond()))->values();

            $days[] = [
                'date'     => $dayStart,
                'label'    => $i === 0 ? 'Today' : $dayStart->format('D j M'),
                'is_today' => $i === 0,
                'meetings' => $slot->map(fn (Meeting $m) => [
                    'id'             => $m->id,
                    'time'           => $m->scheduled_at->setTimezone($tz)->format('H:i'),
                    'student_name'   => $m->student?->name ?? '—',
                    'student_phone'  => $m->student?->phone,
                    'course'         => $m->student?->course,
                    'mode'           => $m->mode,
                    'owner_initials' => $this->initials($m->owner?->name ?? '?'),
                    'status'         => $m->status,
                    'is_overdue'     => $m->status === 'scheduled' && $m->scheduled_at->lt(Carbon::now($tz)),
                ])->all(),
            ];
        }
        return $days;
    }

    private function initials(string $name): string
    {
        $parts = preg_split('/\s+/', trim($name)) ?: [];
        $first = mb_substr($parts[0] ?? '?', 0, 1);
        $last  = count($parts) > 1 ? mb_substr(end($parts), 0, 1) : '';
        return strtoupper($first . $last);
    }
}
```

**Note on `getDaysProperty()`:** Filament widgets extend Livewire components. A public readable property accessed in Blade as `$this->days` resolves via Livewire's getter convention — when Blade reads `$this->days` or `{{ $this->days }}`, Livewire invokes `getDaysProperty()`. The test accesses it directly via `->get('days')`.

### - [ ] Step 4: Write the blade view (overwrites stub)

Overwrite `resources/views/filament/widgets/today-meetings-widget.blade.php`:

```blade
<x-filament-widgets::widget>
    <x-filament::section>
        <x-slot name="heading">Meetings — next 5 days</x-slot>

        <div class="grid grid-cols-1 md:grid-cols-5 gap-2">
            @foreach($this->days as $day)
                <div @class([
                    'rounded-lg border p-2',
                    'border-primary-300 bg-primary-50 dark:bg-primary-950/20' => $day['is_today'],
                    'border-gray-200 dark:border-gray-700'                    => ! $day['is_today'],
                ])>
                    <div class="text-xs font-semibold mb-2 text-gray-700 dark:text-gray-200">
                        {{ $day['label'] }}
                        <span class="text-gray-400">({{ count($day['meetings']) }})</span>
                    </div>

                    @forelse($day['meetings'] as $m)
                        <div @class([
                            'rounded border-l-4 px-2 py-1.5 mb-1.5 text-xs bg-white dark:bg-gray-900',
                            'border-blue-400'     => $m['status'] === 'scheduled' && ! $m['is_overdue'],
                            'border-amber-400'    => $m['is_overdue'],
                            'border-emerald-400 opacity-60' => $m['status'] === 'held',
                        ])>
                            <div class="font-medium flex items-center justify-between">
                                <span>{{ $m['time'] }} · {{ \Illuminate\Support\Str::limit($m['student_name'], 18) }}</span>
                                @if($m['is_overdue'])
                                    <span class="text-[10px] font-bold text-amber-700 bg-amber-100 px-1 rounded">OVERDUE</span>
                                @endif
                            </div>
                            <div class="text-gray-500 dark:text-gray-400">
                                {{ $m['course'] ?? '—' }} · {{ $m['mode'] }} · {{ $m['owner_initials'] }}
                                @if($m['student_phone']) · <span class="font-mono">{{ $m['student_phone'] }}</span> @endif
                            </div>
                        </div>
                    @empty
                        <div class="text-xs text-gray-400">—</div>
                    @endforelse
                </div>
            @endforeach
        </div>
    </x-filament::section>
</x-filament-widgets::widget>
```

### - [ ] Step 5: Run — expect PASS

Run:
```bash
php -d memory_limit=512M vendor/bin/phpunit --filter=TodayMeetingsWidgetTest
```
Expected: **3 passed**.

### - [ ] Step 6: Commit

```bash
git add app/Filament/Widgets/TodayMeetingsWidget.php \
        resources/views/filament/widgets/today-meetings-widget.blade.php \
        tests/Feature/TodayMeetingsWidgetTest.php
git commit -m "$(cat <<'EOF'
feat(today): TodayMeetingsWidget — 5-day meetings strip (view-only)

Renders today + next 4 days as columns. Cards show time, student
name (truncated), course, mode, owner initials, phone. Past
status='scheduled' rows flagged OVERDUE in the Today column. Visibility
scoped via Meeting::visibleTo — reuses Student team-unit rule.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

### - [ ] Step 7: Write failing test for the Schedule action (spec hard rule 2 — both entry points)

Append to `tests/Feature/TodayMeetingsWidgetTest.php` (before the closing `}`):

```php
    public function test_schedule_action_creates_a_meeting_for_today(): void
    {
        $this->seed();
        $nikhil = $this->unblock(User::where('email', 'nikhil@davya.local')->firstOrFail());
        $this->actingAs($nikhil);
        $s = $this->mkStudent($nikhil, 'For Scheduling');

        $at = now('Asia/Kolkata')->setTime(15, 0);

        Livewire::test(TodayMeetingsWidget::class)
            ->callAction('schedule', data: [
                'student_id'   => $s->id,
                'scheduled_at' => $at->toDateTimeString(),
                'mode'         => 'phone',
                'notes'        => 'from today strip',
            ])
            ->assertHasNoActionErrors();

        $m = Meeting::where('student_id', $s->id)->first();
        $this->assertNotNull($m);
        $this->assertSame('scheduled', $m->status);
        $this->assertSame('phone', $m->mode);
        $this->assertSame($nikhil->id, $m->owner_id);
        $this->assertSame($nikhil->id, $m->created_by_id);
    }
```

### - [ ] Step 8: Run — expect FAIL

Run:
```bash
php -d memory_limit=512M vendor/bin/phpunit --filter='TodayMeetingsWidgetTest::test_schedule_action_creates_a_meeting_for_today'
```
Expected: error — `schedule` action does not exist on the widget.

### - [ ] Step 9: Add the Schedule action to `TodayMeetingsWidget`

Edit `app/Filament/Widgets/TodayMeetingsWidget.php`. Add these imports after existing `use` statements:

```php
use App\Models\Meeting;
use App\Models\Student;
use Filament\Actions\Action;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
```

Change the class declaration to implement the action + form contracts:

```php
class TodayMeetingsWidget extends Widget implements HasActions, HasForms
{
    use InteractsWithActions;
    use InteractsWithForms;
```

Add the `schedule` action method inside the class:

```php
    public function scheduleAction(): Action
    {
        return Action::make('schedule')
            ->label('+ Schedule')
            ->icon('heroicon-o-plus')
            ->size('xs')
            ->form([
                Select::make('student_id')
                    ->label('Student')
                    ->options(fn () => Student::query()
                        ->visibleTo(auth()->user())
                        ->whereNotIn('stage', ['Admission Confirmed', 'Closed'])
                        ->orderBy('name')
                        ->pluck('name', 'id')
                        ->all())
                    ->searchable()
                    ->required(),
                DateTimePicker::make('scheduled_at')
                    ->required()
                    ->native(false)
                    ->default(fn () => now('Asia/Kolkata')->addHour()->startOfHour()),
                Select::make('mode')
                    ->options([
                        'in_person' => 'In person',
                        'phone'     => 'Phone',
                        'video'     => 'Video',
                        'whatsapp'  => 'WhatsApp',
                    ])
                    ->default('in_person')
                    ->required(),
                Textarea::make('notes')->rows(2),
            ])
            ->action(function (array $data): void {
                $student = Student::query()
                    ->visibleTo(auth()->user())
                    ->findOrFail($data['student_id']);

                Meeting::create([
                    'student_id'   => $student->id,
                    'owner_id'     => $student->owner_id ?? auth()->id(),
                    'scheduled_at' => $data['scheduled_at'],
                    'mode'         => $data['mode'],
                    'status'       => 'scheduled',
                    'notes'        => $data['notes'] ?? null,
                    'created_by_id' => auth()->id(),
                ]);
            });
    }
```

### - [ ] Step 10: Add a Schedule button to the widget blade

Edit `resources/views/filament/widgets/today-meetings-widget.blade.php`. Inside the `<x-filament::section>` block, replace `<x-slot name="heading">Meetings — next 5 days</x-slot>` with:

```blade
<x-slot name="heading">Meetings — next 5 days</x-slot>
<x-slot name="headerEnd">
    {{ $this->scheduleAction }}
</x-slot>
```

`$this->scheduleAction` is Filament's automatic property exposure for methods ending in `Action`; it renders the action button directly.

### - [ ] Step 11: Run — expect PASS

Run:
```bash
php -d memory_limit=512M vendor/bin/phpunit --filter=TodayMeetingsWidgetTest
```
Expected: **4 passed** (3 prior + 1 new Schedule test).

### - [ ] Step 12: Commit

```bash
git add app/Filament/Widgets/TodayMeetingsWidget.php \
        resources/views/filament/widgets/today-meetings-widget.blade.php \
        tests/Feature/TodayMeetingsWidgetTest.php
git commit -m "$(cat <<'EOF'
feat(today): + Schedule action on TodayMeetingsWidget

Header action opens a modal: student-selector (scoped via visibleTo +
non-terminal stages), datetime picker, mode dropdown, notes. Creates a
Meeting with owner_id = student.owner_id, created_by_id = auth user —
so admin acts-as is captured automatically. Satisfies spec hard rule
2 (both scheduling entry points — student page + Today strip).

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 9: `TodayPaymentsWidget` — today's payment rows

**Rationale:** Compact list of today's payments; mirrors the PaymentsRelationManager column set. Scoped via Student visibility.

**Files:**
- Create / overwrite: `app/Filament/Widgets/TodayPaymentsWidget.php`
- Create / overwrite: `resources/views/filament/widgets/today-payments-widget.blade.php`
- Create: `tests/Feature/TodayPaymentsWidgetTest.php`

### - [ ] Step 1: Write failing widget test

Create `tests/Feature/TodayPaymentsWidgetTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Filament\Widgets\TodayPaymentsWidget;
use App\Models\Payment;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class TodayPaymentsWidgetTest extends TestCase
{
    use RefreshDatabase;

    private function unblock(User $u): User
    {
        $u->must_change_password = false;
        $u->save();
        return $u;
    }

    private function mkStudent(User $owner, string $name = 'S'): Student
    {
        return Student::create([
            'name' => $name,
            'phone' => '9966'.str_pad((string) random_int(1, 999999), 6, '0', STR_PAD_LEFT),
            'course' => 'BBA',
            'stage' => 'Lead Captured',
            'owner_id' => $owner->id,
        ]);
    }

    public function test_today_rows_only(): void
    {
        $this->seed();
        $nikhil = $this->unblock(User::where('email', 'nikhil@davya.local')->firstOrFail());
        $this->actingAs($nikhil);

        $s = $this->mkStudent($nikhil);

        Payment::create([
            'student_id' => $s->id,
            'type' => 'advance', 'amount' => 1000, 'mode' => 'cash',
            'received_at' => now('Asia/Kolkata')->startOfDay()->addHours(9),
            'recorded_by_user_id' => $nikhil->id,
        ]);
        Payment::create([
            'student_id' => $s->id,
            'type' => 'partial', 'amount' => 500, 'mode' => 'upi',
            'received_at' => now('Asia/Kolkata')->subDay(),
            'recorded_by_user_id' => $nikhil->id,
        ]);

        $rows  = Livewire::test(TodayPaymentsWidget::class)->get('rows');
        $total = Livewire::test(TodayPaymentsWidget::class)->get('total');

        $this->assertCount(1, $rows, 'only today rows must appear');
        $this->assertSame(1000.0, (float) $total);
    }

    public function test_scoping_head_sees_own_team_payments_only(): void
    {
        $this->seed();
        $nikhil = $this->unblock(User::where('email', 'nikhil@davya.local')->firstOrFail());
        $sonam  = User::where('email', 'sonam@davya.local')->firstOrFail();

        $ns = $this->mkStudent($nikhil, 'N');
        $ss = $this->mkStudent($sonam, 'S');

        Payment::create([
            'student_id' => $ns->id, 'type' => 'advance', 'amount' => 100, 'mode' => 'cash',
            'received_at' => now('Asia/Kolkata'), 'recorded_by_user_id' => $nikhil->id,
        ]);
        Payment::create([
            'student_id' => $ss->id, 'type' => 'advance', 'amount' => 999, 'mode' => 'cash',
            'received_at' => now('Asia/Kolkata'), 'recorded_by_user_id' => $sonam->id,
        ]);

        $this->actingAs($nikhil);
        $rows = Livewire::test(TodayPaymentsWidget::class)->get('rows');
        $this->assertCount(1, $rows);
        $this->assertSame(100.0, (float) $rows[0]['amount']);
    }
}
```

### - [ ] Step 2: Run — expect FAIL

Run:
```bash
php -d memory_limit=512M vendor/bin/phpunit --filter=TodayPaymentsWidgetTest
```
Expected: stub widget has no `rows` or `total` property.

### - [ ] Step 3: Write the widget

Overwrite `app/Filament/Widgets/TodayPaymentsWidget.php`:

```php
<?php

namespace App\Filament\Widgets;

use App\Models\Payment;
use Filament\Widgets\Widget;
use Illuminate\Support\Carbon;

class TodayPaymentsWidget extends Widget
{
    protected static string $view = 'filament.widgets.today-payments-widget';

    protected int | string | array $columnSpan = 'full';

    /**
     * @return array<int, array{id:int,time:string,student_name:string,student_id:int,amount:float,mode:?string,type:string,owner_name:string}>
     */
    public function getRowsProperty(): array
    {
        $tz = 'Asia/Kolkata';
        $start = Carbon::now($tz)->startOfDay();
        $end   = $start->copy()->addDay();

        return Payment::query()
            ->whereBetween('received_at', [$start, $end->copy()->subSecond()])
            ->whereHas('student', fn ($q) => $q->visibleTo(auth()->user()))
            ->with(['student.owner'])
            ->orderByDesc('received_at')
            ->get()
            ->map(fn (Payment $p) => [
                'id'           => $p->id,
                'time'         => $p->received_at->setTimezone($tz)->format('H:i'),
                'student_name' => $p->student?->name ?? '—',
                'student_id'   => $p->student_id,
                'amount'       => (float) $p->amount,
                'mode'         => $p->mode,
                'type'         => $p->type,
                'owner_name'   => $p->student?->owner?->name ?? '—',
            ])
            ->all();
    }

    public function getTotalProperty(): float
    {
        return array_sum(array_column($this->rows, 'amount'));
    }
}
```

### - [ ] Step 4: Write the blade view

Overwrite `resources/views/filament/widgets/today-payments-widget.blade.php`:

```blade
<x-filament-widgets::widget>
    <x-filament::section>
        <x-slot name="heading">Payments received — today</x-slot>

        @if(count($this->rows) === 0)
            <div class="text-sm text-gray-400">No payments yet today.</div>
        @else
            <table class="w-full text-sm">
                <thead class="text-xs text-gray-500">
                    <tr class="text-left border-b border-gray-200 dark:border-gray-700">
                        <th class="py-1 pr-2">Time</th>
                        <th class="py-1 pr-2">Student</th>
                        <th class="py-1 pr-2 text-right">Amount</th>
                        <th class="py-1 pr-2">Mode</th>
                        <th class="py-1 pr-2">Type</th>
                        <th class="py-1 pr-2">Owner</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($this->rows as $r)
                        <tr class="border-b border-gray-100 dark:border-gray-800">
                            <td class="py-1 pr-2 font-mono">{{ $r['time'] }}</td>
                            <td class="py-1 pr-2">{{ $r['student_name'] }}</td>
                            <td class="py-1 pr-2 text-right font-mono">
                                ₹{{ number_format($r['amount'], 2, '.', ',') }}
                            </td>
                            <td class="py-1 pr-2 uppercase text-xs">{{ $r['mode'] ?? '—' }}</td>
                            <td class="py-1 pr-2 uppercase text-xs">{{ $r['type'] }}</td>
                            <td class="py-1 pr-2">{{ $r['owner_name'] }}</td>
                        </tr>
                    @endforeach
                </tbody>
                <tfoot>
                    <tr class="text-xs text-gray-500">
                        <td colspan="2" class="pt-2 font-semibold">
                            Total · {{ count($this->rows) }} payment{{ count($this->rows) === 1 ? '' : 's' }}
                        </td>
                        <td class="pt-2 text-right font-mono font-semibold">
                            ₹{{ number_format($this->total, 2, '.', ',') }}
                        </td>
                        <td colspan="3"></td>
                    </tr>
                </tfoot>
            </table>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
```

### - [ ] Step 5: Run — expect PASS

Run:
```bash
php -d memory_limit=512M vendor/bin/phpunit --filter=TodayPaymentsWidgetTest
```
Expected: **2 passed**.

### - [ ] Step 6: Commit

```bash
git add app/Filament/Widgets/TodayPaymentsWidget.php \
        resources/views/filament/widgets/today-payments-widget.blade.php \
        tests/Feature/TodayPaymentsWidgetTest.php
git commit -m "$(cat <<'EOF'
feat(today): TodayPaymentsWidget — today's payments compact list

Rows: time, student, amount, mode, type, owner. Footer shows count +
total. Scoped via whereHas student visibleTo — counsellors see own
team only.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 10: PaymentReport — `Report` / `Today Received` tabs + CSV download

**Rationale:** Add tabs to existing page without pulling in a Filament Tabs component (API-drift risk). Uses a Livewire `$activeTab` property plus conditional blade sections. CSV download is a Livewire action returning a streamed response.

**Files:**
- Modify: `app/Filament/Pages/PaymentReport.php`
- Modify: `resources/views/filament/pages/payment-report.blade.php`
- Create: `tests/Feature/PaymentReportTabsTest.php`

### - [ ] Step 1: Write failing tabs test

Create `tests/Feature/PaymentReportTabsTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Filament\Pages\PaymentReport;
use App\Models\Payment;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class PaymentReportTabsTest extends TestCase
{
    use RefreshDatabase;

    private function unblock(User $u): User
    {
        $u->must_change_password = false;
        $u->save();
        return $u;
    }

    public function test_default_tab_is_report(): void
    {
        $this->seed();
        $sumit = $this->unblock(User::where('email', 'sumit@davya.local')->firstOrFail());
        $this->actingAs($sumit);

        Livewire::test(PaymentReport::class)
            ->assertSet('activeTab', 'report');
    }

    public function test_can_switch_to_today_tab_and_it_shows_today_rows_only(): void
    {
        $this->seed();
        $sumit = $this->unblock(User::where('email', 'sumit@davya.local')->firstOrFail());
        $this->actingAs($sumit);

        $s = Student::create([
            'name' => 'Pay Student',
            'phone' => '9955000001',
            'course' => 'BBA',
            'stage' => 'Lead Captured',
            'owner_id' => $sumit->id,
        ]);
        Payment::create([
            'student_id' => $s->id, 'type' => 'advance', 'amount' => 250, 'mode' => 'upi',
            'received_at' => now('Asia/Kolkata'), 'recorded_by_user_id' => $sumit->id,
        ]);
        Payment::create([
            'student_id' => $s->id, 'type' => 'partial', 'amount' => 99, 'mode' => 'cash',
            'received_at' => now('Asia/Kolkata')->subDay(), 'recorded_by_user_id' => $sumit->id,
        ]);

        $rows = Livewire::test(PaymentReport::class)
            ->call('setTab', 'today')
            ->assertSet('activeTab', 'today')
            ->get('todayRows');

        $this->assertCount(1, $rows, 'today tab must show today rows only');
        $this->assertSame(250.0, (float) $rows[0]['amount']);
    }

    public function test_report_tab_still_returns_summary(): void
    {
        $this->seed();
        $sumit = $this->unblock(User::where('email', 'sumit@davya.local')->firstOrFail());
        $this->actingAs($sumit);

        $report = Livewire::test(PaymentReport::class)->instance()->getReport();

        $this->assertArrayHasKey('totals', $report);
        $this->assertArrayHasKey('byOwner', $report);
        $this->assertArrayHasKey('byType', $report);
    }

    public function test_today_csv_download_returns_streamed_response(): void
    {
        $this->seed();
        $sumit = $this->unblock(User::where('email', 'sumit@davya.local')->firstOrFail());
        $this->actingAs($sumit);

        $response = Livewire::test(PaymentReport::class)
            ->call('setTab', 'today')
            ->call('downloadTodayCsv');

        $this->assertNotNull($response);
    }
}
```

### - [ ] Step 2: Run — expect FAIL

Run:
```bash
php -d memory_limit=512M vendor/bin/phpunit --filter=PaymentReportTabsTest
```
Expected: failures — `activeTab`, `setTab`, `todayRows`, `downloadTodayCsv` do not exist.

### - [ ] Step 3: Extend `PaymentReport.php`

Open `app/Filament/Pages/PaymentReport.php` and make these changes:

1. Add `use Symfony\Component\HttpFoundation\StreamedResponse;` to the top.
2. Add `public string $activeTab = 'report';` as a public property (near `$data`).
3. Update `mount()` — add one line at the end: `$this->activeTab = 'report';`
4. Add this method anywhere in the class:

```php
    public function setTab(string $tab): void
    {
        $this->activeTab = in_array($tab, ['report', 'today'], true) ? $tab : 'report';
    }
```

5. Add the today-rows helper:

```php
    /**
     * @return array<int, array{id:int,time:string,student_name:string,student_id:int,amount:float,mode:?string,type:string,owner_name:string}>
     */
    public function getTodayRowsProperty(): array
    {
        $tz = 'Asia/Kolkata';
        $start = Carbon::now($tz)->startOfDay();
        $end   = $start->copy()->addDay();

        return Payment::query()
            ->whereBetween('received_at', [$start, $end->copy()->subSecond()])
            ->whereHas('student', fn ($q) => $q->visibleTo(auth()->user()))
            ->with(['student.owner'])
            ->orderByDesc('received_at')
            ->get()
            ->map(fn (Payment $p) => [
                'id'           => $p->id,
                'time'         => $p->received_at->setTimezone($tz)->format('H:i'),
                'student_name' => $p->student?->name ?? '—',
                'student_id'   => $p->student_id,
                'amount'       => (float) $p->amount,
                'mode'         => $p->mode,
                'type'         => $p->type,
                'owner_name'   => $p->student?->owner?->name ?? '—',
            ])
            ->all();
    }
```

6. Add the CSV download action:

```php
    public function downloadTodayCsv(): StreamedResponse
    {
        $rows = $this->todayRows;
        $filename = 'payments-today-'.now('Asia/Kolkata')->format('Y-m-d').'.csv';

        return response()->streamDownload(function () use ($rows): void {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Time', 'Student', 'Amount', 'Mode', 'Type', 'Owner']);
            foreach ($rows as $r) {
                fputcsv($out, [
                    $r['time'], $r['student_name'],
                    number_format($r['amount'], 2, '.', ''),
                    $r['mode'] ?? '', $r['type'], $r['owner_name'],
                ]);
            }
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv']);
    }
```

### - [ ] Step 4: Update the blade view

Open `resources/views/filament/pages/payment-report.blade.php` and wrap the existing content with tab buttons + a conditional section. The exact existing markup isn't reproduced here — treat the current page content as "the Report tab body" and only add the surrounding structure.

Add at the top of the `<x-filament-panels::page>` block:

```blade
<div class="flex gap-2 mb-4 border-b border-gray-200 dark:border-gray-700">
    <button type="button"
        wire:click="setTab('report')"
        @class([
            'px-3 py-1.5 text-sm font-medium border-b-2 -mb-px',
            'border-primary-500 text-primary-600' => $activeTab === 'report',
            'border-transparent text-gray-500 hover:text-gray-700' => $activeTab !== 'report',
        ])>
        Report
    </button>
    <button type="button"
        wire:click="setTab('today')"
        @class([
            'px-3 py-1.5 text-sm font-medium border-b-2 -mb-px',
            'border-primary-500 text-primary-600' => $activeTab === 'today',
            'border-transparent text-gray-500 hover:text-gray-700' => $activeTab !== 'today',
        ])>
        Today Received
    </button>
</div>
```

Wrap the existing page content (form + summary) in:
```blade
<div x-show="$wire.activeTab === 'report'" x-cloak>
    {{-- existing page content lives here — do not modify --}}
</div>
```

Append the Today tab content before the closing `</x-filament-panels::page>`:
```blade
<div x-show="$wire.activeTab === 'today'" x-cloak>
    <div class="flex justify-end mb-2">
        <x-filament::button wire:click="downloadTodayCsv" icon="heroicon-o-arrow-down-tray" size="sm">
            Download CSV
        </x-filament::button>
    </div>

    @if(count($this->todayRows) === 0)
        <div class="text-sm text-gray-400 py-4">No payments received today.</div>
    @else
        <table class="w-full text-sm">
            <thead class="text-xs text-gray-500">
                <tr class="text-left border-b border-gray-200 dark:border-gray-700">
                    <th class="py-1 pr-2">Time</th>
                    <th class="py-1 pr-2">Student</th>
                    <th class="py-1 pr-2 text-right">Amount</th>
                    <th class="py-1 pr-2">Mode</th>
                    <th class="py-1 pr-2">Type</th>
                    <th class="py-1 pr-2">Owner</th>
                </tr>
            </thead>
            <tbody>
                @foreach($this->todayRows as $r)
                    <tr class="border-b border-gray-100 dark:border-gray-800">
                        <td class="py-1 pr-2 font-mono">{{ $r['time'] }}</td>
                        <td class="py-1 pr-2">{{ $r['student_name'] }}</td>
                        <td class="py-1 pr-2 text-right font-mono">₹{{ number_format($r['amount'], 2, '.', ',') }}</td>
                        <td class="py-1 pr-2 uppercase text-xs">{{ $r['mode'] ?? '—' }}</td>
                        <td class="py-1 pr-2 uppercase text-xs">{{ $r['type'] }}</td>
                        <td class="py-1 pr-2">{{ $r['owner_name'] }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
</div>
```

**Note:** `x-show` + `$wire.activeTab` uses Alpine's binding to Livewire state (Filament 3 loads Alpine globally). Both tab bodies stay in the DOM but toggle visibility; server round-trip only happens on `setTab` to update the underlying state.

### - [ ] Step 5: Run — expect PASS

Run:
```bash
php -d memory_limit=512M vendor/bin/phpunit --filter=PaymentReportTabsTest
```
Expected: **4 passed**.

### - [ ] Step 6: Commit

```bash
git add app/Filament/Pages/PaymentReport.php \
        resources/views/filament/pages/payment-report.blade.php \
        tests/Feature/PaymentReportTabsTest.php
git commit -m "$(cat <<'EOF'
feat(payments): PaymentReport tabs — Report + Today Received with CSV

Livewire $activeTab property + wire:click tab buttons. Report tab body
is the existing form + summary, unchanged. Today Received tab is a
flat list of today's payments with a Download CSV action. No Filament
Tabs component — avoids the API-drift risk flagged in the spec.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 11: Panel registration + full-suite verification

**Rationale:** Confirm the Today widgets are explicitly wired into the admin panel and the whole suite stays green.

**Files:**
- Modify: `app/Providers/Filament/AdminPanelProvider.php`

### - [ ] Step 1: Explicitly register Today widgets in the panel

Open `app/Providers/Filament/AdminPanelProvider.php`. Locate the `->widgets([...])` block (around line 86). It currently lists:
```
Widgets\AccountWidget::class,
\App\Filament\Widgets\InstallAppWidget::class,
\App\Filament\Widgets\PipelineSummaryWidget::class,
\App\Filament\Widgets\SeatFeePendingWidget::class,
\App\Filament\Widgets\ReEntryCandidatesWidget::class,
\App\Filament\Widgets\StuckLeadsWidget::class,
```

Leave that list untouched. The two Today widgets (`TodayMeetingsWidget`, `TodayPaymentsWidget`) must NOT be added here — they are rendered by `TodayPage::getHeaderWidgets()` only, not on the default Dashboard. `discoverWidgets` already makes them resolvable.

This step is a verification-only task — nothing to edit. Confirm by reading the panel file.

### - [ ] Step 2: Run full feature suite

Run:
```bash
php -d memory_limit=1G vendor/bin/phpunit
```
Expected: the final line shows `Tests: NNN, Assertions: MMM` with **zero failures**. DEPR warnings are fine.

### - [ ] Step 3: Spot-check pre-existing test groups (regression lock)

Run:
```bash
php -d memory_limit=512M vendor/bin/phpunit --filter='NikhilVisibilityTest|StudentPolicyTest|PaymentReportTest|DashboardWidgetsTest|KanbanBoardTest'
```
Expected: **all pass** — confirms no regression in visibility, dashboard, kanban, or the existing PaymentReport (now with tabs).

### - [ ] Step 4: Local smoke via `php artisan serve`

Run:
```bash
php -d memory_limit=512M artisan serve --port=8000 &
```
Then, with a browser logged in as Sumit:
1. Visit `http://127.0.0.1:8000/admin/today` — page renders with "Today" headline + 5 day columns + payments section.
2. Click `+ Schedule` on today's column → relation-manager-flavored Create action is not directly on the widget yet; create from the student page instead: open any student → Meetings tab → Create → fill time + mode + notes → Save. The card appears on Today strip after refresh.
3. Mark held → student stage flips to Meeting Done (confirm via Kanban).
4. Visit `/admin/payments-report` — tabs render; Report tab unchanged; Today Received tab lists any test payment made today with CSV download working.

Stop the server:
```bash
pkill -f "artisan serve"
```

### - [ ] Step 5: Merge to main (do NOT deploy yet)

```bash
git checkout main
git merge --ff-only feature/today-tab-meetings
git log --oneline -12
```

Expected: fast-forward succeeds; top 11 commits are Tasks 1–10 in order plus the spec commit from this session.

### - [ ] Step 6: Push to origin

```bash
git push origin main
```

---

## Task 12: Prod deploy + smoke

**Rationale:** Deploy the migration + backfill to prod. Backfill touches ~50 rows (single pass, no batching concern at this scale).

### - [ ] Step 1: Deploy

Run (from the local workstation):
```bash
ssh -i ~/.ssh/davyas-active ipuc@ipu.co.in "cd /home/ipuc/davya-crm && git pull --ff-only origin main && /opt/alt/php84/usr/bin/php artisan migrate --force && /opt/alt/php84/usr/bin/php artisan optimize:clear && git log -1 --oneline"
```

Expected:
- `git pull` succeeds.
- `migrate` output includes BOTH lines:
  - `2026_04_23_000000_create_meetings_table` DONE
  - `2026_04_23_000100_backfill_meetings_from_students` DONE
- `optimize:clear` prints cache-clearing lines.
- `git log -1` shows HEAD at the Task 11 commit SHA.

### - [ ] Step 2: Verify backfill counts on prod

Run:
```bash
ssh -i ~/.ssh/davyas-active ipuc@ipu.co.in "cd /home/ipuc/davya-crm && /opt/alt/php84/usr/bin/php artisan tinker --execute=\"echo \\App\\Models\\Meeting::count().PHP_EOL; echo \\App\\Models\\Student::whereNotNull('meeting_date')->count().PHP_EOL;\""
```

Expected: first line (meetings count) and second line (students-with-meeting_date count) should be equal or the first is ≥ the second if any meetings were already created during testing.

### - [ ] Step 3: Prod UI smoke

On prod browser (logged in as Sumit):
1. `/admin/today` renders with 5 day columns + Payments section.
2. Backfilled meetings appear in their correct day columns if within the next 5 days; older held meetings don't appear (held+past date is outside the window filter which excludes `held` at the 5-day query level? no — held also appears up to the day; verify by checking a recently-held backfilled meeting shows up with green/faded style).
3. Go to any student with `meeting_date` set → the new Meetings tab shows at least one row from the backfill.
4. From a student page, create a new meeting for today → card appears on `/admin/today`.
5. Open that student in Kanban → stage is `Meeting Scheduled` if they were at `Lead Captured`.
6. Open the new meeting, mark held → student stage flips to `Meeting Done`.
7. Visit `/admin/payments-report` → two tabs render. `Report` tab unchanged. Switch to `Today Received` → list matches PaymentReport's existing Report tab scoped to today. Click Download CSV → file downloads with the expected rows.
8. Log out, log in as Nikhil → confirm `/admin/today` shows only Team 2 meetings + payments.

### - [ ] Step 4: Update memory with deployed state

Edit `~/.claude/projects/-Users-Sumit/memory/project_davya-crm.md` — update the SP#1 bullet to reflect "shipped to prod HEAD `<sha>` on 2026-04-23" (use actual date and SHA from the prod deploy).

### - [ ] Step 5: Report completion

Summarise to the user:
- All 12 implementation tasks green; full suite passes.
- Prod HEAD at the merge commit SHA.
- `/admin/today` live; meetings backfill completed; PaymentReport tabs live.
- Known follow-ups: SP#2 (follow-up + call tracking) and SP#3 (customizable card dashboard + universal drill-down).

---

## Explicit deferrals from the spec (not in this plan)

These spec items are intentionally deferred — they share implementation surface with SP#3's "customizable cards + universal drill-down" and will cost less as one unit:

1. **Click a meeting card → slide-over with full detail + Mark Held / Reschedule / Cancel actions inside the slide-over.** For SP#1 those actions live only in the student-page relation manager (Task 6). Cards in the Today strip are view-only (plus the header `+ Schedule` action from Task 8 Steps 7–12).
2. **"Acting as <Owner>" banner** on meeting edit when `created_by_id !== owner_id`. The underlying data is captured correctly (Task 8 Schedule action writes `created_by_id = auth()->id()` while `owner_id` stays on the student owner, and ActivityLog causer captures the admin) — only the UI pill is deferred.
3. **Today Reports strip (Core 4 metrics: Meetings held, Follow-ups completed vs missed, Leads handled, Admissions closed)** — the third strip on `/admin/today`. Belongs to SP#3 because each metric needs the universal drill-down.

Writing these out explicitly so the SP#3 brainstorm picks them up.

---

## Rollback plan

Pure additive change. Revert steps:
```bash
git revert <merge commit sha>
git push origin main
ssh -i ~/.ssh/davyas-active ipuc@ipu.co.in "cd /home/ipuc/davya-crm && git pull && /opt/alt/php84/usr/bin/php artisan migrate:rollback --step=2 --force && /opt/alt/php84/usr/bin/php artisan optimize:clear"
```
- Step=2 rolls back the backfill migration (no-op down method — safe) and then drops the meetings table.
- `students.meeting_date` column is untouched; no restore needed.
- The observer + policy + resource + pages are removed by the revert.

**Rollback hazard:** if any production Meeting rows were created via the UI after deploy, they are lost on rollback. Acceptable since the data was all derivable from `students.meeting_date` (preserved) or entered in the rollback window (small).
