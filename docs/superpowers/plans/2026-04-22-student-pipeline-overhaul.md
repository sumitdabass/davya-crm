# Student Form + Pipeline Overhaul — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ship the overhaul described in `docs/superpowers/specs/2026-04-22-student-pipeline-overhaul-design.md` — 12-stage pipeline via a canonical enum, soft-warning stage gates, Source/Owner form reshape with `referrer_name` text + heads-only Owner, `ipu_password` → plain-text `ipu_login_code`, Final-allotment section dropped, humanized activity log via `ActivityDescriber` + observers, and a top-level Tabs form layout.

**Architecture:** Single-source-of-truth `PipelineStage` enum replaces two duplicated stage constants. `StageTransitionValidator` returns a `{hard, soft}` split; Kanban and the edit form both consume it — hard errors snap back, soft warnings save + toast yellow. A new `ActivityDescriber` writes human-readable rows via `activity()` directly from observers; Spatie's default dirty-attribute logging on `Student` is turned off. Form restructures into Filament Tabs (identity · source-and-stage · academic · deal · counselling · history · closure); `MeetingsRelationManager` stays as a relation manager below the form, and other relation managers are relocated inside History / Counselling / Deal tabs using Filament v3 inline relation-manager sections.

**Tech Stack:** Laravel 11, Filament 3, Spatie ActivityLog + Permission, MySQL, PHPUnit 11, PHP 8.5 local / 8.4 prod.

**Branch:** create `feature/student-pipeline-overhaul` off `main` (currently at `6dfe2ae`).

**Local test runner:** `php -d memory_limit=512M vendor/bin/phpunit --filter=<name>` (plain `php artisan test` OOMs on the full suite with default memory).

**DEPR note:** On local PHP 8.5 every test emits `PHP Deprecated: PDO::MYSQL_ATTR_SSL_CA`. Harmless — read the final `Tests: X passed` line. See memory `project_davya-crm_php85_deprecations.md`.

**MeetingObserver coupling:** `MeetingObserver::advanceStage()` calls `StageTransitionValidator::forStageChange()` and expects an array of errors. Task 2 changes that return type to `['hard' => [...], 'soft' => [...]]`. MeetingObserver must be updated in the same task — any missed caller will break meeting tests.

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

Every seeded user has `must_change_password = true`. Use `$user->update(['must_change_password' => false])` before `actingAs` (pattern lifted from `LeadsReportPageTest.php` and `FinanceRoleTest.php`).

---

## File structure

**Create**

- `app/Enums/PipelineStage.php`
- `app/Services/ActivityDescriber.php`
- `app/Observers/StudentObserver.php`
- `app/Observers/PaymentObserver.php`
- `app/Observers/RoundHistoryObserver.php`
- `app/Observers/StudentNoteObserver.php`
- `database/migrations/2026_04_24_000000_alter_students_stage_to_varchar.php`
- `database/migrations/2026_04_24_000100_add_referrer_name_to_students.php`
- `database/migrations/2026_04_24_000200_rename_ipu_password_to_ipu_login_code.php`
- `database/migrations/2026_04_24_000300_drop_final_allotment_columns_from_students.php`
- `database/migrations/2026_04_24_000400_remap_student_pipeline_stages.php`
- `tests/Unit/PipelineStageTest.php`
- `tests/Feature/StageTransitionValidatorTest.php`
- `tests/Feature/RemapStudentPipelineStagesTest.php`
- `tests/Feature/StudentSourceOwnerFormTest.php`
- `tests/Feature/IpuLoginCodeTest.php`
- `tests/Feature/DropFinalAllotmentTest.php`
- `tests/Feature/ActivityDescriberTest.php`
- `tests/Feature/StudentObserverTest.php`
- `tests/Feature/PaymentObserverTest.php`
- `tests/Feature/RoundHistoryObserverTest.php`
- `tests/Feature/StudentNoteObserverTest.php`
- `tests/Feature/ActivityLogIntegrationTest.php`
- `tests/Feature/StudentFormTabsTest.php`

**Modify**

- `app/Services/PipelineSummary.php` — replace inline `STAGES` array with `PipelineStage::values()`; remove the three `STAGE_*` string constants (callers switch to enum).
- `app/Services/StageTransitionValidator.php` — change `forStageChange` return type to `['hard' => [], 'soft' => []]`; add soft rules from spec §4.
- `app/Filament/Resources/StudentResource.php` — delete `const STAGES`; Source & Owner section rewrite; top-level Tabs restructure; IPU login code input; drop Final-allotment + Logistics + on-create first-payment sections; update `getGloballySearchableAttributes` to drop `final_college`; stage Select `afterStateUpdated` consumes hard/soft split.
- `app/Filament/Pages/KanbanBoard.php` — `moveStudentToStage` consumes hard/soft split; yellow notification on soft; save still succeeds.
- `app/Observers/MeetingObserver.php` — update `advanceStage` to call new validator shape; replace existing `Notification::make()` calls with `ActivityDescriber` describer methods.
- `app/Models/Student.php` — drop `'ipu_password' => 'encrypted'` cast; drop `'admission_date' => 'date'` cast; replace `getActivitylogOptions()` with `LogOptions::defaults()->logOnly([])` (suppress default auto-logging).
- `app/Filament/Resources/StudentResource/RelationManagers/ActivityRelationManager.php` — 3 columns (When/Who/What); drop event badge.
- `app/Providers/AppServiceProvider.php` — register `StudentObserver`, `PaymentObserver`, `RoundHistoryObserver`, `StudentNoteObserver` in `boot()`.
- `app/Services/LeadIntakeService.php` — call `ActivityDescriber::leadCaptured` after creating a student (single added line; no behavioral change otherwise).

**Delete**

- `app/Actions/RevealIpuPassword.php`

**Out of scope for this plan:** dropping `students.referrer_id`, dropping `students.current_round`, dropping `students.meeting_date` denormalized cache — those are hygiene follow-ups after a stable release.

---

## Task 1: `PipelineStage` enum + dedup

**Rationale:** Canonical source of truth for stage names + ordering. Two existing constants (`PipelineSummary::STAGES`, `StudentResource::STAGES`) collapse into this.

**Files:**
- Create: `app/Enums/PipelineStage.php`
- Create: `tests/Unit/PipelineStageTest.php`
- Modify: `app/Services/PipelineSummary.php`
- Modify: `app/Filament/Resources/StudentResource.php` (remove `const STAGES`, reference enum)
- Modify: `app/Filament/Pages/KanbanBoard.php` (references `PipelineSummary::STAGES` — keeps working; no change needed if PipelineSummary keeps the constant as a method)

### - [ ] Step 1: Write the enum test

Create `tests/Unit/PipelineStageTest.php`:

```php
<?php

namespace Tests\Unit;

use App\Enums\PipelineStage;
use Tests\TestCase;

class PipelineStageTest extends TestCase
{
    public function test_cases_in_canonical_order(): void
    {
        $this->assertSame([
            'Lead Captured', 'Meeting Scheduled', 'Meeting Done', 'Advance Received',
            'MQ', 'Round 1', 'Round 2', 'Round 3', 'Sliding', 'Offline',
            'Seat Allotted', 'Closed',
        ], PipelineStage::values());
    }

    public function test_values_helper_returns_string_values(): void
    {
        $values = PipelineStage::values();
        $this->assertCount(12, $values);
        $this->assertContainsOnly('string', $values);
    }

    public function test_options_returns_label_keyed_array_for_filament(): void
    {
        $opts = PipelineStage::options();
        $this->assertSame('Lead Captured', $opts['Lead Captured']);
        $this->assertSame('Round 1', $opts['Round 1']);
        $this->assertCount(12, $opts);
    }

    public static function roundNameMappingProvider(): array
    {
        return [
            ['Online_R1', PipelineStage::Round1],
            ['S2_R1', PipelineStage::Round1],
            ['Online_R2', PipelineStage::Round2],
            ['Online_R3', PipelineStage::Round3],
            ['S2_R3', PipelineStage::Round3],
            ['Online_Sliding', PipelineStage::Sliding],
            ['Online_Reporting', PipelineStage::Sliding],
            ['Offline_R1', PipelineStage::Offline],
            ['Offline_R2', PipelineStage::Offline],
        ];
    }

    /** @dataProvider roundNameMappingProvider */
    public function test_from_round_name_maps_correctly(string $roundName, PipelineStage $expected): void
    {
        $this->assertSame($expected, PipelineStage::fromRoundName($roundName));
    }

    public function test_from_round_name_returns_null_for_unknown(): void
    {
        $this->assertNull(PipelineStage::fromRoundName('Bogus'));
    }

    public function test_round_stages_helper(): void
    {
        $this->assertSame([
            PipelineStage::Round1, PipelineStage::Round2, PipelineStage::Round3,
            PipelineStage::Sliding, PipelineStage::Offline,
        ], PipelineStage::roundStages());
    }
}
```

### - [ ] Step 2: Run test to verify it fails

```bash
php -d memory_limit=512M vendor/bin/phpunit --filter=PipelineStageTest
```
Expected: errors — `App\Enums\PipelineStage` not found.

### - [ ] Step 3: Create the enum

Create `app/Enums/PipelineStage.php`:

```php
<?php

namespace App\Enums;

enum PipelineStage: string
{
    case LeadCaptured = 'Lead Captured';
    case MeetingScheduled = 'Meeting Scheduled';
    case MeetingDone = 'Meeting Done';
    case AdvanceReceived = 'Advance Received';
    case Mq = 'MQ';
    case Round1 = 'Round 1';
    case Round2 = 'Round 2';
    case Round3 = 'Round 3';
    case Sliding = 'Sliding';
    case Offline = 'Offline';
    case SeatAllotted = 'Seat Allotted';
    case Closed = 'Closed';

    /** @return string[] */
    public static function values(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }

    /** @return array<string,string> value => value (for Filament Select options). */
    public static function options(): array
    {
        return array_combine(self::values(), self::values());
    }

    /** @return self[] */
    public static function roundStages(): array
    {
        return [self::Round1, self::Round2, self::Round3, self::Sliding, self::Offline];
    }

    public static function fromRoundName(string $roundName): ?self
    {
        return match ($roundName) {
            'Online_R1', 'S2_R1' => self::Round1,
            'Online_R2' => self::Round2,
            'Online_R3', 'S2_R3' => self::Round3,
            'Online_Sliding', 'Online_Reporting' => self::Sliding,
            'Offline_R1', 'Offline_R2' => self::Offline,
            default => null,
        };
    }
}
```

### - [ ] Step 4: Run test — expect PASS

```bash
php -d memory_limit=512M vendor/bin/phpunit --filter=PipelineStageTest
```
Expected: **7 passed** (one per test method + dataprovider rows grouped as one method each).

### - [ ] Step 5: Point `PipelineSummary` at the enum

Edit `app/Services/PipelineSummary.php`:

```php
use App\Enums\PipelineStage;

// Replace the existing const block (lines ~10-25) with:

public const STAGES = [
    'Lead Captured','Meeting Scheduled','Meeting Done','Advance Received',
    'MQ','Round 1','Round 2','Round 3','Sliding','Offline',
    'Seat Allotted','Closed',
];

public const STAGE_LEAD_CAPTURED = 'Lead Captured';
public const STAGE_CLOSED = 'Closed';
```

Notes:
- Keep `PipelineSummary::STAGES` as a duplicated constant (it's a compile-time `const` and can't reference the enum). This is the single place the list is hard-coded in the runtime stage reporting; the enum is the source of truth everywhere else.
- `STAGE_ADMISSION_CONFIRMED` constant is **removed** — that stage no longer exists. Grep for any callers first (`grep -rn STAGE_ADMISSION_CONFIRMED app tests`) and replace with `PipelineStage::Closed->value` if any exist.

### - [ ] Step 6: Remove `StudentResource::STAGES`

Edit `app/Filament/Resources/StudentResource.php`:

Replace the `const STAGES = [...]` block (currently lines 66-77) with:

```php
// Canonical list lives in App\Enums\PipelineStage. Use PipelineStage::options().
```

And replace `self::STAGES` usages:
- Line 108 stage Select: `->options(PipelineStage::options())`
- Line 280 stage filter: `->options(PipelineStage::options())`

Add at the top of the file: `use App\Enums\PipelineStage;`

### - [ ] Step 7: Run full stage-related tests, verify existing suite green

```bash
php -d memory_limit=512M vendor/bin/phpunit --filter='StudentResource|KanbanBoard|PipelineSummary|PipelineStage'
```
Expected: all pass. If `STAGE_ADMISSION_CONFIRMED` grep found callers, fix them before running.

### - [ ] Step 8: Commit

```bash
git add app/Enums/PipelineStage.php tests/Unit/PipelineStageTest.php \
    app/Services/PipelineSummary.php app/Filament/Resources/StudentResource.php
git commit -m "feat(stages): PipelineStage enum + dedup StudentResource::STAGES"
```

---

## Task 2: `StageTransitionValidator` — hard/soft split + new rules

**Rationale:** Centralize gate logic with an explicit return shape so both Kanban and the form consume it uniformly.

**Files:**
- Modify: `app/Services/StageTransitionValidator.php`
- Modify: `app/Observers/MeetingObserver.php` (adapt to new return shape)
- Create: `tests/Feature/StageTransitionValidatorTest.php`

### - [ ] Step 1: Write the failing test

Create `tests/Feature/StageTransitionValidatorTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Enums\PipelineStage;
use App\Models\Meeting;
use App\Models\Payment;
use App\Models\RoundHistory;
use App\Models\Student;
use App\Models\User;
use App\Services\StageTransitionValidator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StageTransitionValidatorTest extends TestCase
{
    use RefreshDatabase;

    private StageTransitionValidator $v;
    private Student $s;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->v = new StageTransitionValidator;
        $owner = User::first();
        $this->s = Student::create([
            'phone' => '9999900001', 'name' => 'Test', 'owner_id' => $owner->id,
            'referrer_id' => null, 'referrer_name' => null, 'lead_source' => 'Website',
            'stage' => 'Lead Captured',
        ]);
    }

    public function test_closed_without_reason_is_hard_error(): void
    {
        $this->s->close_reason = null;
        $out = $this->v->forStageChange($this->s, 'Closed');
        $this->assertNotEmpty($out['hard']);
        $this->assertSame([], $out['soft']);
    }

    public function test_reopen_without_reason_is_hard_error(): void
    {
        $this->s->stage = 'Closed';
        $this->s->syncOriginalAttribute('stage');
        $this->s->stage = 'Lead Captured';
        $this->s->re_entry_reason = null;
        $out = $this->v->forStageChange($this->s, 'Lead Captured');
        $this->assertNotEmpty($out['hard']);
    }

    public function test_meeting_scheduled_without_meeting_row_is_soft_warning(): void
    {
        $out = $this->v->forStageChange($this->s, 'Meeting Scheduled');
        $this->assertSame([], $out['hard']);
        $this->assertNotEmpty($out['soft']);
        $this->assertStringContainsString('Meeting Scheduled incomplete', $out['soft'][0]);
    }

    public function test_meeting_scheduled_with_future_scheduled_meeting_passes(): void
    {
        Meeting::create([
            'student_id' => $this->s->id, 'owner_id' => $this->s->owner_id,
            'scheduled_at' => now()->addDay(), 'status' => 'scheduled',
            'created_by_id' => $this->s->owner_id,
        ]);
        $out = $this->v->forStageChange($this->s, 'Meeting Scheduled');
        $this->assertSame([], $out['soft']);
    }

    public function test_meeting_done_without_student_response_is_soft(): void
    {
        $this->s->student_response = null;
        $out = $this->v->forStageChange($this->s, 'Meeting Done');
        $this->assertNotEmpty($out['soft']);
    }

    public function test_advance_received_without_payment_is_soft(): void
    {
        $out = $this->v->forStageChange($this->s, 'Advance Received');
        $this->assertNotEmpty($out['soft']);
    }

    public function test_advance_received_with_payment_passes(): void
    {
        Payment::create([
            'student_id' => $this->s->id, 'amount' => 100,
            'paid_at' => now(), 'payer_name' => 'x',
        ]);
        $out = $this->v->forStageChange($this->s, 'Advance Received');
        $this->assertSame([], $out['soft']);
    }

    public function test_round1_without_matching_round_history_is_soft(): void
    {
        $out = $this->v->forStageChange($this->s, 'Round 1');
        $this->assertNotEmpty($out['soft']);
    }

    public function test_round1_with_online_r1_row_passes(): void
    {
        RoundHistory::create([
            'student_id' => $this->s->id, 'round_name' => 'Online_R1',
            'outcome' => 'Not Allotted',
        ]);
        $out = $this->v->forStageChange($this->s, 'Round 1');
        $this->assertSame([], $out['soft']);
    }

    public function test_seat_allotted_without_college_is_soft(): void
    {
        RoundHistory::create([
            'student_id' => $this->s->id, 'round_name' => 'Online_R1',
            'outcome' => 'Allotted — Fee Paid', 'allotted_college' => null,
        ]);
        $out = $this->v->forStageChange($this->s, 'Seat Allotted');
        $this->assertNotEmpty($out['soft']);
    }

    public function test_mq_has_no_gate(): void
    {
        $out = $this->v->forStageChange($this->s, 'MQ');
        $this->assertSame(['hard' => [], 'soft' => []], $out);
    }
}
```

### - [ ] Step 2: Run test to verify it fails

```bash
php -d memory_limit=512M vendor/bin/phpunit --filter=StageTransitionValidatorTest
```
Expected: failures — return type is still a flat array.

### - [ ] Step 3: Rewrite `StageTransitionValidator::forStageChange`

Replace `app/Services/StageTransitionValidator.php` entirely:

```php
<?php

namespace App\Services;

use App\Enums\PipelineStage;
use App\Models\Student;

class StageTransitionValidator
{
    /** @return string[] soft warnings */
    public function forRoundChange(Student $student, string $newRound): array
    {
        $warnings = [];

        $latest = $student->roundHistory()->latest()->first();
        if ($latest && str_starts_with($latest->outcome, 'Allotted — Fee Pending')) {
            $warnings[] = "Seat fee unpaid for {$latest->round_name}. Continue anyway?";
        }

        if ($newRound === 'Online_Sliding') {
            $hasPrior = $student->roundHistory()
                ->where('outcome', 'like', 'Allotted%')
                ->exists();
            if (! $hasPrior) {
                $warnings[] = 'Not eligible for Sliding (no prior allotment).';
            }
        }

        return $warnings;
    }

    /**
     * @return array{hard: string[], soft: string[]}
     */
    public function forStageChange(Student $student, string $newStage): array
    {
        $hard = [];
        $soft = [];

        // Hard: Closed requires close_reason.
        if ($newStage === 'Closed' && empty($student->close_reason)) {
            $hard[] = 'close_reason is required when moving to Closed.';
        }

        // Hard: re-opening requires re_entry_reason.
        if ($student->getOriginal('stage') === 'Closed'
            && $newStage !== 'Closed'
            && empty($student->re_entry_reason)
        ) {
            $hard[] = 're_entry_reason is required when re-opening a closed student.';
        }

        // Soft gates by target.
        switch ($newStage) {
            case 'Meeting Scheduled':
                $hasFuture = $student->meetings()
                    ->where('status', 'scheduled')
                    ->where('scheduled_at', '>=', now())
                    ->exists();
                if (! $hasFuture) {
                    $soft[] = 'Meeting Scheduled incomplete: schedule a meeting (date + title) in the Meetings tab.';
                }
                break;

            case 'Meeting Done':
                if (empty($student->student_response)) {
                    $soft[] = 'Meeting Done incomplete: set student_response (Ready / Not Interested / Needs Time).';
                }
                break;

            case 'Advance Received':
                if (! $student->payments()->exists()) {
                    $soft[] = 'Advance Received incomplete: record the advance payment on the Deal tab.';
                }
                break;

            case 'Round 1':
            case 'Round 2':
            case 'Round 3':
            case 'Sliding':
            case 'Offline':
                $targetStage = PipelineStage::from($newStage);
                $matchingRoundNames = array_keys(array_filter(
                    [
                        'Online_R1' => PipelineStage::Round1, 'S2_R1' => PipelineStage::Round1,
                        'Online_R2' => PipelineStage::Round2,
                        'Online_R3' => PipelineStage::Round3, 'S2_R3' => PipelineStage::Round3,
                        'Online_Sliding' => PipelineStage::Sliding, 'Online_Reporting' => PipelineStage::Sliding,
                        'Offline_R1' => PipelineStage::Offline, 'Offline_R2' => PipelineStage::Offline,
                    ],
                    fn (PipelineStage $s) => $s === $targetStage,
                ));
                if (! $student->roundHistory()->whereIn('round_name', $matchingRoundNames)->exists()) {
                    $soft[] = "$newStage incomplete: create a round_history row with round_name matching $newStage.";
                }
                break;

            case 'Seat Allotted':
                $latest = $student->roundHistory()->latest()->first();
                if (! $latest || empty($latest->allotted_college)) {
                    $soft[] = 'Seat Allotted incomplete: set allotted_college on the latest round row.';
                }
                break;
        }

        return ['hard' => $hard, 'soft' => $soft];
    }
}
```

### - [ ] Step 4: Adapt `MeetingObserver` to new shape

Edit `app/Observers/MeetingObserver.php`:

Replace the `advanceStage` method with:

```php
private function advanceStage(Student $student, string $newStage): void
{
    $out = $this->validator->forStageChange($student, $newStage);
    if (! empty($out['hard'])) {
        \Illuminate\Support\Facades\Log::warning('MeetingObserver: stage auto-advance blocked', [
            'student_id' => $student->id,
            'from' => $student->stage,
            'to' => $newStage,
            'errors' => $out['hard'],
        ]);
        return;
    }
    // Soft warnings are not logged from the observer — observer runs on infra events,
    // not user actions. Only user-facing entry points (Kanban drag, form save) show soft warnings.
    Student::withoutEvents(fn () => $student->update(['stage' => $newStage]));
}
```

### - [ ] Step 5: Run validator + meeting observer tests

```bash
php -d memory_limit=512M vendor/bin/phpunit --filter='StageTransitionValidatorTest|MeetingObserverTest'
```
Expected: all pass. If MeetingObserverTest references the old array shape, update assertions.

### - [ ] Step 6: Commit

```bash
git add app/Services/StageTransitionValidator.php app/Observers/MeetingObserver.php \
    tests/Feature/StageTransitionValidatorTest.php
git commit -m "feat(stages): StageTransitionValidator returns hard/soft split + new gate rules"
```

---

## Task 3: Kanban + form wiring — soft warnings

**Rationale:** Consume the new `{hard, soft}` shape in both user-facing entry points. Soft warnings save + yellow; hard errors snap back + red (existing behavior for Closed).

**Files:**
- Modify: `app/Filament/Pages/KanbanBoard.php`
- Modify: `app/Filament/Resources/StudentResource.php` (stage Select afterStateUpdated)
- Create: `tests/Feature/KanbanSoftWarningsTest.php`

### - [ ] Step 1: Write the failing test

Create `tests/Feature/KanbanSoftWarningsTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Filament\Pages\KanbanBoard;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class KanbanSoftWarningsTest extends TestCase
{
    use RefreshDatabase;

    public function test_drag_to_meeting_scheduled_without_meeting_saves_and_warns(): void
    {
        $this->seed();
        $sumit = User::where('email', 'sumit@davya.local')->first();
        $sumit->update(['must_change_password' => false]);

        $s = Student::create([
            'phone' => '9999900010', 'name' => 'Test', 'owner_id' => $sumit->id,
            'referrer_id' => null, 'referrer_name' => null, 'lead_source' => 'Website',
            'stage' => 'Lead Captured',
        ]);

        $this->actingAs($sumit);

        Livewire::test(KanbanBoard::class)
            ->call('moveStudentToStage', $s->id, 'Meeting Scheduled')
            ->assertNotified();

        $this->assertSame('Meeting Scheduled', $s->fresh()->stage, 'soft warning should not block save');
    }

    public function test_drag_to_closed_without_reason_blocks(): void
    {
        $this->seed();
        $sumit = User::where('email', 'sumit@davya.local')->first();
        $sumit->update(['must_change_password' => false]);

        $s = Student::create([
            'phone' => '9999900011', 'name' => 'Test', 'owner_id' => $sumit->id,
            'referrer_id' => null, 'referrer_name' => null, 'lead_source' => 'Website',
            'stage' => 'Lead Captured',
        ]);

        $this->actingAs($sumit);

        Livewire::test(KanbanBoard::class)
            ->call('moveStudentToStage', $s->id, 'Closed');

        $this->assertSame('Lead Captured', $s->fresh()->stage, 'hard block should prevent save');
    }
}
```

### - [ ] Step 2: Run test — verify it fails

```bash
php -d memory_limit=512M vendor/bin/phpunit --filter=KanbanSoftWarningsTest
```
Expected: failures — Kanban still uses the old flat-array contract.

### - [ ] Step 3: Update `KanbanBoard::moveStudentToStage`

Edit `app/Filament/Pages/KanbanBoard.php`, replace the method body:

```php
public function moveStudentToStage(int $studentId, string $newStage): array
{
    $user = auth()->user();
    $student = $this->visibleStudentQuery($user)->whereKey($studentId)->first();

    if (! $student) {
        return $this->kanbanResponse(false, 'Not allowed.');
    }
    if (! in_array($newStage, \App\Enums\PipelineStage::values(), true)) {
        return $this->kanbanResponse(false, 'Unknown stage.');
    }
    if ($student->stage === $newStage) {
        return $this->kanbanResponse(true, 'No change.');
    }

    $original = $student->stage;
    $student->stage = $newStage;

    $out = (new StageTransitionValidator)->forStageChange($student, $newStage);

    if (! empty($out['hard'])) {
        $student->stage = $original;
        Notification::make()
            ->title('Stage move blocked')
            ->body(implode("\n", $out['hard']))
            ->danger()
            ->send();
        return $this->kanbanResponse(false, implode(' ', $out['hard']));
    }

    $student->save();

    if (! empty($out['soft'])) {
        Notification::make()
            ->title("Moved to {$newStage} — some fields still missing")
            ->body(implode("\n", $out['soft']))
            ->warning()
            ->send();
    } else {
        Notification::make()
            ->title("Moved to {$newStage}")
            ->success()
            ->send();
    }

    return $this->kanbanResponse(true, 'ok');
}
```

### - [ ] Step 4: Update form stage Select handler

Edit `app/Filament/Resources/StudentResource.php` — the `afterStateUpdated` inside the stage Select (currently ~line 110-117):

```php
Select::make('stage')->options(PipelineStage::options())->required()->default('Lead Captured')
    ->live()
    ->afterStateUpdated(function ($state, $record, $set) {
        if (! $record) {
            return;
        }
        $out = (new StageTransitionValidator)->forStageChange($record, $state);

        foreach ($out['hard'] as $err) {
            Notification::make()->danger()->title('Stage change blocked')->body($err)->send();
            // Reset the field so the user sees the revert.
            $set('stage', $record->getOriginal('stage'));
            return;
        }
        foreach ($out['soft'] as $warn) {
            Notification::make()->warning()->title('Stage changed — incomplete')->body($warn)->send();
        }
    }),
```

### - [ ] Step 5: Run Kanban tests — expect PASS

```bash
php -d memory_limit=512M vendor/bin/phpunit --filter='KanbanSoftWarningsTest|KanbanBoardTest'
```
Expected: all pass.

### - [ ] Step 6: Commit

```bash
git add app/Filament/Pages/KanbanBoard.php app/Filament/Resources/StudentResource.php \
    tests/Feature/KanbanSoftWarningsTest.php
git commit -m "feat(stages): Kanban + form consume hard/soft validator split"
```

---

## Task 4: Alter `students.stage` to varchar + add `referrer_name`

**Rationale:** The existing `stage` column is a DB `ENUM` — adding new values (`MQ`, `Advance Received`, `Round 1..3`, `Sliding`, `Offline`) requires dropping the enum constraint first. Adding `referrer_name` now avoids a second deploy.

**Files:**
- Create: `database/migrations/2026_04_24_000000_alter_students_stage_to_varchar.php`
- Create: `database/migrations/2026_04_24_000100_add_referrer_name_to_students.php`

### - [ ] Step 1: Write the stage-alter migration

Create `database/migrations/2026_04_24_000000_alter_students_stage_to_varchar.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (config('database.default') === 'sqlite') {
            // SQLite already stores enum as TEXT — nothing to alter.
            return;
        }
        DB::statement('ALTER TABLE students MODIFY stage VARCHAR(60) NOT NULL DEFAULT "Lead Captured"');
    }

    public function down(): void
    {
        if (config('database.default') === 'sqlite') {
            return;
        }
        DB::statement(<<<'SQL'
            ALTER TABLE students MODIFY stage ENUM(
                'Lead Captured','Meeting Scheduled','Meeting Done','Onboarded',
                'University Registration','Counselling In Progress','Seat Allotted',
                'Full Payment Received','Admission Confirmed','Closed'
            ) NOT NULL DEFAULT 'Lead Captured'
        SQL);
    }
};
```

### - [ ] Step 2: Write the referrer_name migration

Create `database/migrations/2026_04_24_000100_add_referrer_name_to_students.php`:

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
            $table->string('referrer_name', 120)->nullable()->after('referrer_id');
        });
    }

    public function down(): void
    {
        Schema::table('students', function (Blueprint $table) {
            $table->dropColumn('referrer_name');
        });
    }
};
```

### - [ ] Step 3: Run migrations locally

```bash
php -d memory_limit=512M artisan migrate
```
Expected: two migrations run (`alter_students_stage_to_varchar`, `add_referrer_name_to_students`).

### - [ ] Step 4: Verify with a quick tinker check

```bash
php -d memory_limit=512M artisan tinker --execute='echo json_encode([
    "has_referrer_name" => \Schema::hasColumn("students","referrer_name"),
    "stage_type" => DB::selectOne("SELECT DATA_TYPE, COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_NAME=\"students\" AND COLUMN_NAME=\"stage\""),
]);'
```
Expected: `has_referrer_name: true`, `stage_type` shows `varchar(60)` (not enum).

### - [ ] Step 5: Commit

```bash
git add database/migrations/2026_04_24_000000_alter_students_stage_to_varchar.php \
    database/migrations/2026_04_24_000100_add_referrer_name_to_students.php
git commit -m "feat(migration): relax students.stage to varchar + add referrer_name column"
```

---

## Task 5: Source & Owner form — heads-only Owner + referrer_name text

**Rationale:** Reshape the Source & Owner section. Owner dropdown filters to heads/admins; Referrer becomes a text input; Lead Source keeps current behavior.

**Files:**
- Modify: `app/Filament/Resources/StudentResource.php` (Source & Owner section)
- Create: `tests/Feature/StudentSourceOwnerFormTest.php`

### - [ ] Step 1: Write the failing test

Create `tests/Feature/StudentSourceOwnerFormTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Filament\Resources\StudentResource\Pages\CreateStudent;
use App\Filament\Resources\StudentResource\Pages\EditStudent;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class StudentSourceOwnerFormTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_options_only_include_admins_and_heads(): void
    {
        $this->seed();
        $sumit = User::where('email', 'sumit@davya.local')->first();
        $sumit->update(['must_change_password' => false]);
        $this->actingAs($sumit);

        Livewire::test(CreateStudent::class)
            ->assertFormFieldExists('owner_id');

        // Build the Select's options the same way the resource does.
        $ownerIds = User::query()
            ->whereHas('roles', fn ($q) => $q->whereIn('name', ['admin', 'head']))
            ->pluck('id');

        $this->assertTrue($ownerIds->contains($sumit->id));
        $nikhil = User::where('email', 'nikhil@davya.local')->first();
        $sonam = User::where('email', 'sonam@davya.local')->first();
        $nisha = User::where('email', 'nisha@davya.local')->first();
        $this->assertTrue($ownerIds->contains($nikhil->id));
        $this->assertTrue($ownerIds->contains($sonam->id));
        $this->assertFalse($ownerIds->contains($nisha->id), 'members must not appear as owners');
    }

    public function test_referrer_name_saves_as_plain_text(): void
    {
        $this->seed();
        $sumit = User::where('email', 'sumit@davya.local')->first();
        $sumit->update(['must_change_password' => false]);
        $this->actingAs($sumit);

        Livewire::test(CreateStudent::class)
            ->fillForm([
                'phone' => '9999900100',
                'name' => 'Test',
                'owner_id' => $sumit->id,
                'referrer_name' => 'Rahul Sharma (2023)',
                'lead_source' => $sumit->name,
                'stage' => 'Lead Captured',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $s = Student::where('phone', '9999900100')->first();
        $this->assertSame('Rahul Sharma (2023)', $s->referrer_name);
        $this->assertNull($s->referrer_id, 'referrer_id should not be set from the form');
    }
}
```

### - [ ] Step 2: Run the test — expect FAIL

```bash
php -d memory_limit=512M vendor/bin/phpunit --filter=StudentSourceOwnerFormTest
```
Expected: failure — `referrer_name` form field does not exist, `referrer_id` is still the form field.

### - [ ] Step 3: Rewrite the Source & Owner section

Edit `app/Filament/Resources/StudentResource.php`. Replace the existing `Section::make('Source & Owner')` block:

```php
Section::make('Source & Owner')
    ->description('Who brought the lead and who handles it now.')
    ->icon('heroicon-o-user-group')
    ->schema([
        Select::make('owner_id')
            ->label('Owner')
            ->relationship(
                name: 'owner',
                titleAttribute: 'name',
                modifyQueryUsing: fn ($query) =>
                    $query->whereHas('roles', fn ($q) => $q->whereIn('name', ['admin', 'head'])),
            )
            ->required()
            ->searchable(),

        Select::make('lead_source')
            ->label('Lead Source')
            ->options(fn () => User::where('is_active', true)->orderBy('name')->pluck('name', 'name'))
            ->required()
            ->searchable(),

        TextInput::make('referrer_name')
            ->label('Referrer name')
            ->maxLength(120),
    ])->columns(3),
```

### - [ ] Step 4: Update `Student` model fillable / handle referrer_id backward-compat

Edit `app/Models/Student.php` — because `$guarded = []`, nothing to do for mass-assignment. But verify `Student::scopeVisibleTo` still works when `referrer_id` is null (it reads `orWhereIn('referrer_id', $teamIds)` which handles null gracefully). No code change needed; keep the scope as-is.

### - [ ] Step 5: Run test — expect PASS

```bash
php -d memory_limit=512M vendor/bin/phpunit --filter=StudentSourceOwnerFormTest
```
Expected: **2 passed**.

### - [ ] Step 6: Run the full student form suite to catch regressions

```bash
php -d memory_limit=512M vendor/bin/phpunit --filter='StudentResource|StudentPolicy|LeadIntake'
```
Expected: all green. If `LeadIntakeService` stores `referrer_id` and depends on it, no change — the intake pipeline is untouched.

### - [ ] Step 7: Commit

```bash
git add app/Filament/Resources/StudentResource.php tests/Feature/StudentSourceOwnerFormTest.php
git commit -m "feat(form): Owner restricted to heads; Referrer becomes freeform text"
```

---

## Task 6: Rename `ipu_password` → `ipu_login_code` (plain text)

**Rationale:** Drop the security ceremony. One migration renames the column and decrypts existing values; model, form, and reveal action all follow.

**Files:**
- Create: `database/migrations/2026_04_24_000200_rename_ipu_password_to_ipu_login_code.php`
- Modify: `app/Models/Student.php` (casts + global search)
- Modify: `app/Filament/Resources/StudentResource.php` (Counselling section)
- Delete: `app/Actions/RevealIpuPassword.php`
- Create: `tests/Feature/IpuLoginCodeTest.php`

### - [ ] Step 1: Write the failing test

Create `tests/Feature/IpuLoginCodeTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class IpuLoginCodeTest extends TestCase
{
    use RefreshDatabase;

    public function test_column_is_renamed(): void
    {
        $this->assertFalse(Schema::hasColumn('students', 'ipu_password'));
        $this->assertTrue(Schema::hasColumn('students', 'ipu_login_code'));
    }

    public function test_value_is_stored_plain_text(): void
    {
        $this->seed();
        $owner = User::first();
        $s = Student::create([
            'phone' => '9999900200', 'name' => 'Test',
            'owner_id' => $owner->id, 'referrer_id' => null, 'lead_source' => 'Website',
            'ipu_login_code' => 'plain-value-123',
        ]);

        $raw = \DB::table('students')->where('id', $s->id)->value('ipu_login_code');
        $this->assertSame('plain-value-123', $raw, 'must NOT be encrypted');
        $this->assertSame('plain-value-123', $s->fresh()->ipu_login_code);
    }

    public function test_reveal_action_class_is_deleted(): void
    {
        $this->assertFalse(class_exists(\App\Actions\RevealIpuPassword::class));
    }
}
```

### - [ ] Step 2: Run test — expect FAIL

```bash
php -d memory_limit=512M vendor/bin/phpunit --filter=IpuLoginCodeTest
```
Expected: failures — column and reveal action still present.

### - [ ] Step 3: Write the rename + decrypt migration

Create `database/migrations/2026_04_24_000200_rename_ipu_password_to_ipu_login_code.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Rename the column first so we can write plain values back to it.
        Schema::table('students', function (Blueprint $table) {
            $table->renameColumn('ipu_password', 'ipu_login_code');
        });

        // Decrypt every non-null value in place. Rows that fail to decrypt are
        // logged and left as-is (operator handles manually).
        DB::table('students')
            ->whereNotNull('ipu_login_code')
            ->orderBy('id')
            ->chunkById(200, function ($rows): void {
                foreach ($rows as $row) {
                    try {
                        $plain = decrypt($row->ipu_login_code);
                        DB::table('students')->where('id', $row->id)->update(['ipu_login_code' => $plain]);
                    } catch (\Throwable $e) {
                        Log::warning('ipu_login_code decrypt failed', [
                            'student_id' => $row->id,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }
            });
    }

    public function down(): void
    {
        Schema::table('students', function (Blueprint $table) {
            $table->renameColumn('ipu_login_code', 'ipu_password');
        });
    }
};
```

### - [ ] Step 4: Update the Student model

Edit `app/Models/Student.php`:

- Remove `'ipu_password' => 'encrypted'` from `$casts`.
- Update `getGloballySearchableAttributes` to replace `'ipu_user_id'` nothing needed (still valid), but make sure the searchable list from StudentResource still matches.

No new cast for `ipu_login_code` — plain string.

### - [ ] Step 5: Update StudentResource Counselling section

Edit `app/Filament/Resources/StudentResource.php`:

Replace the Counselling `TextInput::make('ipu_password')` block (currently with `->password()` and `->suffixAction(Action::make('reveal'))`) with:

```php
TextInput::make('ipu_login_code')
    ->label('IPU login code')
    ->maxLength(60)
    ->helperText('Shared with the student during counselling.'),
```

Remove these imports if unused after the change:
- `use App\Actions\RevealIpuPassword;`
- `use Filament\Forms\Components\Actions\Action;`

Also update `StudentResource::getGloballySearchableAttributes`:

```php
return ['name', 'phone', 'phone_2', 'email', 'father_name', 'ipu_user_id'];
```

(Dropped `final_college` — prepping for Task 7.)

### - [ ] Step 6: Delete the reveal action

```bash
rm app/Actions/RevealIpuPassword.php
```

### - [ ] Step 7: Run migrations + tests

```bash
php -d memory_limit=512M artisan migrate
php -d memory_limit=512M vendor/bin/phpunit --filter='IpuLoginCodeTest|StudentResource'
```
Expected: migrations run, tests pass.

### - [ ] Step 8: Commit

```bash
git add database/migrations/2026_04_24_000200_rename_ipu_password_to_ipu_login_code.php \
    app/Models/Student.php app/Filament/Resources/StudentResource.php \
    tests/Feature/IpuLoginCodeTest.php
git rm app/Actions/RevealIpuPassword.php
git commit -m "feat(ipu): rename ipu_password to ipu_login_code (plain text, no reveal action)"
```

---

## Task 7: Drop Final-allotment columns + form section

**Files:**
- Create: `database/migrations/2026_04_24_000300_drop_final_allotment_columns_from_students.php`
- Modify: `app/Filament/Resources/StudentResource.php` (remove Final allotment section + Logistics section + on-create first-payment section)
- Modify: `app/Models/Student.php` (remove admission_date cast)
- Create: `tests/Feature/DropFinalAllotmentTest.php`

### - [ ] Step 1: Write the failing test

Create `tests/Feature/DropFinalAllotmentTest.php`:

```php
<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class DropFinalAllotmentTest extends TestCase
{
    use RefreshDatabase;

    public function test_columns_are_dropped(): void
    {
        $this->assertFalse(Schema::hasColumn('students', 'final_college'));
        $this->assertFalse(Schema::hasColumn('students', 'final_course'));
        $this->assertFalse(Schema::hasColumn('students', 'admission_date'));
    }

    public function test_global_search_attributes_do_not_include_final_college(): void
    {
        $this->assertNotContains('final_college', \App\Filament\Resources\StudentResource::getGloballySearchableAttributes());
    }
}
```

### - [ ] Step 2: Run test — expect FAIL

```bash
php -d memory_limit=512M vendor/bin/phpunit --filter=DropFinalAllotmentTest
```
Expected: failure — columns still present.

### - [ ] Step 3: Pre-migration safety log (one-time shell)

Before running the migration, verify there are no students whose `final_college` is the only record. Run:

```bash
php -d memory_limit=512M artisan tinker --execute='
$at = \App\Models\Student::whereNotNull("final_college")->whereDoesntHave("roundHistory", fn($q) => $q->whereNotNull("allotted_college"))->get(["id","name","final_college"]);
echo $at->toJson(JSON_PRETTY_PRINT);
'
```
Any rows listed need a manual note added before dropping the column. In local dev the list is empty. Flag any rows to the user before continuing in prod.

### - [ ] Step 4: Write the migration

Create `database/migrations/2026_04_24_000300_drop_final_allotment_columns_from_students.php`:

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
            $table->dropColumn(['final_college', 'final_course', 'admission_date']);
        });
    }

    public function down(): void
    {
        Schema::table('students', function (Blueprint $table) {
            $table->string('final_college', 120)->nullable();
            $table->string('final_course', 120)->nullable();
            $table->date('admission_date')->nullable();
        });
    }
};
```

### - [ ] Step 5: Remove the form sections

Edit `app/Filament/Resources/StudentResource.php`. Delete these three `Section::make(...)` blocks:

1. `Section::make('Final allotment')` (around line 185)
2. `Section::make('Logistics')` (around line 195)
3. `Section::make('First payment (optional)')` (around line 236) — payments go on the Deal tab's relation manager

### - [ ] Step 6: Remove the admission_date cast

Edit `app/Models/Student.php`, remove `'admission_date' => 'date'` from `$casts`.

### - [ ] Step 7: Run migrations + tests

```bash
php -d memory_limit=512M artisan migrate
php -d memory_limit=512M vendor/bin/phpunit --filter='DropFinalAllotmentTest|StudentResource'
```
Expected: all pass.

### - [ ] Step 8: Commit

```bash
git add database/migrations/2026_04_24_000300_drop_final_allotment_columns_from_students.php \
    app/Filament/Resources/StudentResource.php app/Models/Student.php \
    tests/Feature/DropFinalAllotmentTest.php
git commit -m "feat(schema): drop final_allotment columns + form sections (logistics, final allotment, on-create payment)"
```

---

## Task 8: Data migration — remap student stages

**Rationale:** Map old stages (`Onboarded`, `University Registration`, `Full Payment Received`, `Admission Confirmed`) to the new list. Must run *after* the stage-to-varchar migration from Task 4.

**Files:**
- Create: `database/migrations/2026_04_24_000400_remap_student_pipeline_stages.php`
- Create: `tests/Feature/RemapStudentPipelineStagesTest.php`

### - [ ] Step 1: Write the failing test

Create `tests/Feature/RemapStudentPipelineStagesTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\RoundHistory;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class RemapStudentPipelineStagesTest extends TestCase
{
    use RefreshDatabase;

    public function test_old_stages_remap_to_new_stages(): void
    {
        $this->seed();
        $owner = User::first();

        // Seed one student per old stage. Use raw DB insert because some of
        // these values are no longer valid for the model's validator.
        $mkStudent = function (string $oldStage, array $extra = []) use ($owner): int {
            return DB::table('students')->insertGetId(array_merge([
                'phone' => '9999910' . random_int(1000, 9999),
                'name' => "Old {$oldStage}",
                'owner_id' => $owner->id,
                'lead_source' => 'Website',
                'stage' => $oldStage,
                'created_at' => now(),
                'updated_at' => now(),
            ], $extra));
        };

        $idOnboarded = $mkStudent('Onboarded');
        $idUniReg = $mkStudent('University Registration');
        $idCipWithRound = $mkStudent('Counselling In Progress');
        RoundHistory::create([
            'student_id' => $idCipWithRound, 'round_name' => 'Online_R2', 'outcome' => 'Not Allotted',
        ]);
        $idCipNoRound = $mkStudent('Counselling In Progress');
        $idFullPaid = $mkStudent('Full Payment Received');
        $idAdmConf = $mkStudent('Admission Confirmed');
        $idClosed = $mkStudent('Closed', ['close_reason' => 'Not Interested']);

        // Run the remap migration.
        $this->artisan('migrate')->assertExitCode(0);

        $stage = fn (int $id) => DB::table('students')->where('id', $id)->value('stage');
        $closeReason = fn (int $id) => DB::table('students')->where('id', $id)->value('close_reason');

        $this->assertSame('Advance Received', $stage($idOnboarded));
        $this->assertSame('Advance Received', $stage($idUniReg));
        $this->assertSame('Round 2', $stage($idCipWithRound));
        $this->assertSame('MQ', $stage($idCipNoRound));
        $this->assertSame('Seat Allotted', $stage($idFullPaid));
        $this->assertSame('Closed', $stage($idAdmConf));
        $this->assertSame('Completed', $closeReason($idAdmConf), 'Admission Confirmed must set close_reason');
        $this->assertSame('Closed', $stage($idClosed));
        $this->assertSame('Not Interested', $closeReason($idClosed), 'existing Closed reasons preserved');
    }
}
```

### - [ ] Step 2: Run test — expect FAIL

```bash
php -d memory_limit=512M vendor/bin/phpunit --filter=RemapStudentPipelineStagesTest
```
Expected: migration not found.

### - [ ] Step 3: Write the migration

Create `database/migrations/2026_04_24_000400_remap_student_pipeline_stages.php`:

```php
<?php

use App\Enums\PipelineStage;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

return new class extends Migration
{
    public function up(): void
    {
        $counts = ['Onboarded' => 0, 'University Registration' => 0,
                   'Counselling In Progress' => 0, 'Full Payment Received' => 0,
                   'Admission Confirmed' => 0];

        DB::table('students')->where('stage', 'Onboarded')
            ->update(['stage' => 'Advance Received']);
        $counts['Onboarded'] = DB::table('students')->where('stage', 'Advance Received')->count();

        // University Registration → derive from latest round_history, else Advance Received.
        $uniRegIds = DB::table('students')->where('stage', 'University Registration')->pluck('id');
        foreach ($uniRegIds as $id) {
            $stage = $this->deriveRoundStage($id) ?? 'Advance Received';
            DB::table('students')->where('id', $id)->update(['stage' => $stage]);
            $counts['University Registration']++;
        }

        // Counselling In Progress → derive from latest round_history, else MQ.
        $cipIds = DB::table('students')->where('stage', 'Counselling In Progress')->pluck('id');
        foreach ($cipIds as $id) {
            $stage = $this->deriveRoundStage($id) ?? 'MQ';
            DB::table('students')->where('id', $id)->update(['stage' => $stage]);
            $counts['Counselling In Progress']++;
        }

        // Full Payment Received → Seat Allotted.
        $counts['Full Payment Received'] = DB::table('students')
            ->where('stage', 'Full Payment Received')
            ->update(['stage' => 'Seat Allotted']);

        // Admission Confirmed → Closed + close_reason='Completed' (only if blank).
        $admIds = DB::table('students')->where('stage', 'Admission Confirmed')->pluck('id');
        foreach ($admIds as $id) {
            $current = DB::table('students')->where('id', $id)->value('close_reason');
            DB::table('students')->where('id', $id)->update([
                'stage' => 'Closed',
                'close_reason' => $current ?: 'Completed',
            ]);
            $counts['Admission Confirmed']++;
        }

        Log::info('Student stage remap complete', $counts);
    }

    public function down(): void
    {
        // Not reversible: original stage values are not preserved.
    }

    private function deriveRoundStage(int $studentId): ?string
    {
        $latest = DB::table('round_history')
            ->where('student_id', $studentId)
            ->orderByDesc('id')
            ->value('round_name');
        if ($latest === null) {
            return null;
        }
        return PipelineStage::fromRoundName($latest)?->value;
    }
};
```

### - [ ] Step 4: Run migration + test

```bash
php -d memory_limit=512M artisan migrate
php -d memory_limit=512M vendor/bin/phpunit --filter=RemapStudentPipelineStagesTest
```
Expected: migration runs; test passes.

### - [ ] Step 5: Commit

```bash
git add database/migrations/2026_04_24_000400_remap_student_pipeline_stages.php \
    tests/Feature/RemapStudentPipelineStagesTest.php
git commit -m "feat(migration): remap legacy student stages to new 12-stage pipeline"
```

---

## Task 9: `ActivityDescriber` service

**Rationale:** Central formatter for human-readable descriptions. Each method emits a Spatie Activity row via `activity()` directly.

**Files:**
- Create: `app/Services/ActivityDescriber.php`
- Create: `tests/Feature/ActivityDescriberTest.php`

### - [ ] Step 1: Write the failing test

Create `tests/Feature/ActivityDescriberTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Payment;
use App\Models\RoundHistory;
use App\Models\Student;
use App\Models\StudentNote;
use App\Models\User;
use App\Services\ActivityDescriber;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

class ActivityDescriberTest extends TestCase
{
    use RefreshDatabase;

    private function student(): Student
    {
        $this->seed();
        $owner = User::first();
        return Student::create([
            'phone' => '9999920000', 'name' => 'T', 'owner_id' => $owner->id,
            'lead_source' => 'Website', 'stage' => 'Lead Captured',
        ]);
    }

    public function test_stage_changed(): void
    {
        $s = $this->student();
        $actor = User::first();
        $this->actingAs($actor);

        (new ActivityDescriber)->stageChanged($s, 'Lead Captured', 'Meeting Scheduled');

        $a = Activity::latest('id')->first();
        $this->assertSame('Moved from Lead Captured → Meeting Scheduled', $a->description);
        $this->assertSame('stage_changed', $a->event);
        $this->assertSame($actor->id, $a->causer_id);
    }

    public function test_payment_added(): void
    {
        $s = $this->student();
        $p = Payment::create([
            'student_id' => $s->id, 'amount' => 10000, 'paid_at' => now(),
            'payer_name' => 'x', 'type' => 'advance',
        ]);
        (new ActivityDescriber)->paymentAdded($p);
        $a = Activity::latest('id')->first();
        $this->assertStringContainsString('Added payment ₹10,000', $a->description);
        $this->assertStringContainsString('advance', $a->description);
    }

    public function test_round_entered(): void
    {
        $s = $this->student();
        $r = RoundHistory::create([
            'student_id' => $s->id, 'round_name' => 'Online_R1', 'outcome' => 'Not Allotted',
        ]);
        (new ActivityDescriber)->roundEntered($r);
        $a = Activity::latest('id')->first();
        $this->assertStringContainsString('Round entered: Round 1', $a->description);
    }

    public function test_note_added(): void
    {
        $s = $this->student();
        $n = StudentNote::create([
            'student_id' => $s->id, 'user_id' => User::first()->id,
            'body' => 'Parent called and said they want the third round option now please',
        ]);
        (new ActivityDescriber)->noteAdded($n);
        $a = Activity::latest('id')->first();
        $this->assertStringContainsString('Added note:', $a->description);
        $this->assertLessThanOrEqual(90, strlen($a->description), 'body truncated to <=60 chars in desc');
    }

    public function test_owner_changed(): void
    {
        $s = $this->student();
        $from = User::where('email', 'sonam@davya.local')->first();
        $to = User::where('email', 'nikhil@davya.local')->first();
        (new ActivityDescriber)->ownerChanged($s, $from, $to);
        $a = Activity::latest('id')->first();
        $this->assertStringContainsString('Reassigned owner Sonam → Nikhil', $a->description);
    }
}
```

### - [ ] Step 2: Run test — expect FAIL

```bash
php -d memory_limit=512M vendor/bin/phpunit --filter=ActivityDescriberTest
```
Expected: `ActivityDescriber` not found.

### - [ ] Step 3: Write the service

Create `app/Services/ActivityDescriber.php`:

```php
<?php

namespace App\Services;

use App\Enums\PipelineStage;
use App\Models\Meeting;
use App\Models\Payment;
use App\Models\RoundHistory;
use App\Models\Student;
use App\Models\StudentNote;
use App\Models\User;

class ActivityDescriber
{
    public function stageChanged(Student $s, string $from, string $to): void
    {
        $this->log($s, 'stage_changed', "Moved from {$from} → {$to}");
    }

    public function ownerChanged(Student $s, ?User $from, User $to): void
    {
        $fromName = $from?->name ?? '—';
        $this->log($s, 'owner_changed', "Reassigned owner {$fromName} → {$to->name}");
    }

    public function ipuCodeChanged(Student $s, bool $wasSet): void
    {
        $desc = $wasSet ? 'Updated IPU login code' : 'Set IPU login code';
        $this->log($s, 'ipu_code_changed', $desc);
    }

    public function closed(Student $s, string $reason): void
    {
        $this->log($s, 'closed', "Closed (reason: {$reason})");
    }

    public function reopened(Student $s, string $reason): void
    {
        $this->log($s, 'reopened', "Re-opened (reason: {$reason})");
    }

    public function paymentAdded(Payment $p): void
    {
        $parts = ['Added payment ₹' . number_format((float) $p->amount, 0, '.', ',')];
        if (! empty($p->type)) {
            $parts[] = "({$p->type})";
        }
        if (! empty($p->proof_url)) {
            $parts[] = '· proof uploaded';
        }
        $this->log($p->student, 'payment_added', implode(' ', $parts));
    }

    public function paymentUpdated(Payment $p): void
    {
        $this->log($p->student, 'payment_updated', "Updated payment #{$p->id}");
    }

    public function paymentDeleted(Payment $p, Student $student): void
    {
        $this->log($student, 'payment_deleted', "Deleted payment #{$p->id} (₹" . number_format((float) $p->amount, 0, '.', ',') . ')');
    }

    public function meetingScheduled(Meeting $m): void
    {
        $when = $m->scheduled_at?->format('d M H:i');
        $title = $m->notes ? ' "' . \Str::limit($m->notes, 40, '…') . '"' : '';
        $this->log($m->student, 'meeting_scheduled', "Scheduled meeting{$title} for {$when}");
    }

    public function meetingRescheduled(Meeting $m, \DateTimeInterface $from): void
    {
        $fromStr = $from->format('d M H:i');
        $toStr = $m->scheduled_at?->format('d M H:i');
        $this->log($m->student, 'meeting_rescheduled', "Rescheduled meeting {$fromStr} → {$toStr}");
    }

    public function meetingCancelled(Meeting $m): void
    {
        $this->log($m->student, 'meeting_cancelled', 'Cancelled meeting');
    }

    public function roundEntered(RoundHistory $r): void
    {
        $stage = PipelineStage::fromRoundName($r->round_name)?->value ?? $r->round_name;
        $this->log($r->student, 'round_entered', "Round entered: {$stage} ({$r->round_name})");
    }

    public function roundOutcomeUpdated(RoundHistory $r): void
    {
        $this->log($r->student, 'round_outcome_updated', "Round {$r->round_name}: {$r->outcome}");
    }

    public function noteAdded(StudentNote $n): void
    {
        $excerpt = \Str::limit($n->body, 60, '…');
        $this->log($n->student, 'note_added', "Added note: \"{$excerpt}\"");
    }

    public function leadCaptured(Student $s, string $source): void
    {
        $this->log($s, 'lead_captured', "Lead captured from {$source}");
    }

    private function log(Student $subject, string $event, string $description): void
    {
        activity()
            ->performedOn($subject)
            ->causedBy(auth()->user())
            ->event($event)
            ->log($description);
    }
}
```

### - [ ] Step 4: Run the test — expect PASS

```bash
php -d memory_limit=512M vendor/bin/phpunit --filter=ActivityDescriberTest
```
Expected: **5 passed**.

### - [ ] Step 5: Commit

```bash
git add app/Services/ActivityDescriber.php tests/Feature/ActivityDescriberTest.php
git commit -m "feat(activity): ActivityDescriber service — human-readable log descriptions"
```

---

## Task 10: Disable default Spatie logging + `StudentObserver`

**Rationale:** Replace Spatie's raw-diff auto-logging on `Student` with targeted observer calls.

**Files:**
- Modify: `app/Models/Student.php`
- Create: `app/Observers/StudentObserver.php`
- Modify: `app/Providers/AppServiceProvider.php` (register observer)
- Create: `tests/Feature/StudentObserverTest.php`

### - [ ] Step 1: Write the failing test

Create `tests/Feature/StudentObserverTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

class StudentObserverTest extends TestCase
{
    use RefreshDatabase;

    private function studentFor(User $owner): Student
    {
        return Student::create([
            'phone' => '9999930' . random_int(100, 999), 'name' => 'T', 'owner_id' => $owner->id,
            'lead_source' => 'Website', 'stage' => 'Lead Captured',
        ]);
    }

    public function test_stage_change_logs_humanized_row(): void
    {
        $this->seed();
        $sumit = User::where('email', 'sumit@davya.local')->first();
        $this->actingAs($sumit);
        $s = $this->studentFor($sumit);

        Activity::query()->delete();
        $s->update(['stage' => 'MQ']);

        $a = Activity::where('subject_id', $s->id)->latest('id')->first();
        $this->assertSame('stage_changed', $a->event);
        $this->assertSame('Moved from Lead Captured → MQ', $a->description);
    }

    public function test_owner_change_logs_humanized_row(): void
    {
        $this->seed();
        $sumit = User::where('email', 'sumit@davya.local')->first();
        $sonam = User::where('email', 'sonam@davya.local')->first();
        $this->actingAs($sumit);
        $s = $this->studentFor($sumit);

        Activity::query()->delete();
        $s->update(['owner_id' => $sonam->id]);

        $a = Activity::where('subject_id', $s->id)->latest('id')->first();
        $this->assertSame('owner_changed', $a->event);
        $this->assertSame('Reassigned owner Sumit → Sonam', $a->description);
    }

    public function test_random_attribute_update_does_NOT_log(): void
    {
        $this->seed();
        $sumit = User::where('email', 'sumit@davya.local')->first();
        $this->actingAs($sumit);
        $s = $this->studentFor($sumit);

        Activity::query()->delete();
        $s->update(['twelfth_marks' => '95']);

        $this->assertSame(0, Activity::where('subject_id', $s->id)->count(),
            'non-meaningful attribute updates must not produce activity rows');
    }

    public function test_close_reason_set_logs_closed_event(): void
    {
        $this->seed();
        $sumit = User::where('email', 'sumit@davya.local')->first();
        $this->actingAs($sumit);
        $s = $this->studentFor($sumit);

        Activity::query()->delete();
        $s->update(['close_reason' => 'Not Interested', 'stage' => 'Closed']);

        $events = Activity::where('subject_id', $s->id)->pluck('event')->all();
        $this->assertContains('closed', $events);
    }
}
```

### - [ ] Step 2: Run test — expect FAIL

```bash
php -d memory_limit=512M vendor/bin/phpunit --filter=StudentObserverTest
```
Expected: default Spatie logs too much OR not in the humanized format.

### - [ ] Step 3: Disable Spatie default logging on Student

Edit `app/Models/Student.php` — replace `getActivitylogOptions`:

```php
public function getActivitylogOptions(): LogOptions
{
    // Humanized logging is done explicitly via ActivityDescriber; suppress Spatie auto-log.
    return LogOptions::defaults()->logOnly([])->dontLogIfAttributesChangedOnly(['*']);
}
```

### - [ ] Step 4: Create `StudentObserver`

Create `app/Observers/StudentObserver.php`:

```php
<?php

namespace App\Observers;

use App\Models\Student;
use App\Models\User;
use App\Services\ActivityDescriber;

class StudentObserver
{
    public function __construct(private readonly ActivityDescriber $describer)
    {
    }

    public function updated(Student $student): void
    {
        if ($student->wasChanged('stage')) {
            $from = $student->getOriginal('stage');
            $to = $student->stage;
            $this->describer->stageChanged($student, $from, $to);

            if ($to === 'Closed') {
                $this->describer->closed($student, $student->close_reason ?? '—');
            } elseif ($from === 'Closed') {
                $this->describer->reopened($student, $student->re_entry_reason ?? '—');
            }
        }

        if ($student->wasChanged('owner_id')) {
            $fromId = $student->getOriginal('owner_id');
            $from = $fromId ? User::find($fromId) : null;
            $to = User::find($student->owner_id);
            if ($to) {
                $this->describer->ownerChanged($student, $from, $to);
            }
        }

        if ($student->wasChanged('ipu_login_code')) {
            $wasSet = (bool) $student->getOriginal('ipu_login_code');
            $this->describer->ipuCodeChanged($student, $wasSet);
        }
    }
}
```

### - [ ] Step 5: Register the observer

Edit `app/Providers/AppServiceProvider.php`, inside `boot()`:

```php
use App\Models\Student;
use App\Observers\StudentObserver;

// existing boot() contents...
Student::observe(StudentObserver::class);
```

### - [ ] Step 6: Run the test — expect PASS

```bash
php -d memory_limit=512M vendor/bin/phpunit --filter=StudentObserverTest
```
Expected: **4 passed**.

### - [ ] Step 7: Commit

```bash
git add app/Models/Student.php app/Observers/StudentObserver.php \
    app/Providers/AppServiceProvider.php tests/Feature/StudentObserverTest.php
git commit -m "feat(activity): StudentObserver replaces default Spatie auto-logging on Student"
```

---

## Task 11: Payment, RoundHistory, StudentNote observers

**Files:**
- Create: `app/Observers/PaymentObserver.php`
- Create: `app/Observers/RoundHistoryObserver.php`
- Create: `app/Observers/StudentNoteObserver.php`
- Modify: `app/Providers/AppServiceProvider.php` (register all three)
- Create: `tests/Feature/PaymentObserverTest.php`
- Create: `tests/Feature/RoundHistoryObserverTest.php`
- Create: `tests/Feature/StudentNoteObserverTest.php`

### - [ ] Step 1: Write the failing tests

Create `tests/Feature/PaymentObserverTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Payment;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

class PaymentObserverTest extends TestCase
{
    use RefreshDatabase;

    public function test_payment_created_logs_humanized_row(): void
    {
        $this->seed();
        $sumit = User::where('email', 'sumit@davya.local')->first();
        $this->actingAs($sumit);
        $s = Student::create([
            'phone' => '9999940001', 'name' => 'T', 'owner_id' => $sumit->id,
            'lead_source' => 'Website', 'stage' => 'Lead Captured',
        ]);
        Activity::query()->delete();

        Payment::create([
            'student_id' => $s->id, 'amount' => 10000,
            'paid_at' => now(), 'payer_name' => 'x', 'type' => 'advance',
        ]);

        $a = Activity::where('subject_id', $s->id)->latest('id')->first();
        $this->assertSame('payment_added', $a->event);
        $this->assertStringContainsString('₹10,000', $a->description);
        $this->assertStringContainsString('advance', $a->description);
    }
}
```

Create `tests/Feature/RoundHistoryObserverTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\RoundHistory;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

class RoundHistoryObserverTest extends TestCase
{
    use RefreshDatabase;

    public function test_round_created_logs_entered(): void
    {
        $this->seed();
        $sumit = User::where('email', 'sumit@davya.local')->first();
        $this->actingAs($sumit);
        $s = Student::create([
            'phone' => '9999950001', 'name' => 'T', 'owner_id' => $sumit->id,
            'lead_source' => 'Website', 'stage' => 'Lead Captured',
        ]);
        Activity::query()->delete();

        RoundHistory::create([
            'student_id' => $s->id, 'round_name' => 'Online_R1', 'outcome' => 'Not Allotted',
        ]);

        $a = Activity::where('subject_id', $s->id)->latest('id')->first();
        $this->assertSame('round_entered', $a->event);
        $this->assertStringContainsString('Round 1', $a->description);
    }

    public function test_round_outcome_update_logs(): void
    {
        $this->seed();
        $sumit = User::where('email', 'sumit@davya.local')->first();
        $this->actingAs($sumit);
        $s = Student::create([
            'phone' => '9999950002', 'name' => 'T', 'owner_id' => $sumit->id,
            'lead_source' => 'Website', 'stage' => 'Lead Captured',
        ]);
        $r = RoundHistory::create([
            'student_id' => $s->id, 'round_name' => 'Online_R1', 'outcome' => 'Not Allotted',
        ]);
        Activity::query()->delete();

        $r->update(['outcome' => 'Allotted — Fee Pending']);

        $a = Activity::where('subject_id', $s->id)->latest('id')->first();
        $this->assertSame('round_outcome_updated', $a->event);
    }
}
```

Create `tests/Feature/StudentNoteObserverTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Student;
use App\Models\StudentNote;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

class StudentNoteObserverTest extends TestCase
{
    use RefreshDatabase;

    public function test_note_created_logs(): void
    {
        $this->seed();
        $sumit = User::where('email', 'sumit@davya.local')->first();
        $this->actingAs($sumit);
        $s = Student::create([
            'phone' => '9999960001', 'name' => 'T', 'owner_id' => $sumit->id,
            'lead_source' => 'Website', 'stage' => 'Lead Captured',
        ]);
        Activity::query()->delete();

        StudentNote::create([
            'student_id' => $s->id, 'user_id' => $sumit->id,
            'body' => 'Parent called, wants R3',
        ]);

        $a = Activity::where('subject_id', $s->id)->latest('id')->first();
        $this->assertSame('note_added', $a->event);
        $this->assertStringContainsString('Parent called', $a->description);
    }
}
```

### - [ ] Step 2: Run tests — expect FAIL

```bash
php -d memory_limit=512M vendor/bin/phpunit --filter='PaymentObserverTest|RoundHistoryObserverTest|StudentNoteObserverTest'
```
Expected: failures — observers not registered.

### - [ ] Step 3: Write the observers

Create `app/Observers/PaymentObserver.php`:

```php
<?php

namespace App\Observers;

use App\Models\Payment;
use App\Services\ActivityDescriber;

class PaymentObserver
{
    public function __construct(private readonly ActivityDescriber $describer)
    {
    }

    public function created(Payment $p): void
    {
        $this->describer->paymentAdded($p);
    }

    public function updated(Payment $p): void
    {
        $this->describer->paymentUpdated($p);
    }

    public function deleted(Payment $p): void
    {
        // Accessor may be null after delete; load student explicitly.
        $student = $p->student()->withTrashed()->first();
        if ($student) {
            $this->describer->paymentDeleted($p, $student);
        }
    }
}
```

Create `app/Observers/RoundHistoryObserver.php`:

```php
<?php

namespace App\Observers;

use App\Models\RoundHistory;
use App\Services\ActivityDescriber;

class RoundHistoryObserver
{
    public function __construct(private readonly ActivityDescriber $describer)
    {
    }

    public function created(RoundHistory $r): void
    {
        $this->describer->roundEntered($r);
    }

    public function updated(RoundHistory $r): void
    {
        if ($r->wasChanged('outcome')) {
            $this->describer->roundOutcomeUpdated($r);
        }
    }
}
```

Create `app/Observers/StudentNoteObserver.php`:

```php
<?php

namespace App\Observers;

use App\Models\StudentNote;
use App\Services\ActivityDescriber;

class StudentNoteObserver
{
    public function __construct(private readonly ActivityDescriber $describer)
    {
    }

    public function created(StudentNote $n): void
    {
        $this->describer->noteAdded($n);
    }
}
```

### - [ ] Step 4: Register them

Edit `app/Providers/AppServiceProvider.php` `boot()`:

```php
use App\Models\{Payment, RoundHistory, StudentNote};
use App\Observers\{PaymentObserver, RoundHistoryObserver, StudentNoteObserver};

Payment::observe(PaymentObserver::class);
RoundHistory::observe(RoundHistoryObserver::class);
StudentNote::observe(StudentNoteObserver::class);
```

### - [ ] Step 5: Run tests — expect PASS

```bash
php -d memory_limit=512M vendor/bin/phpunit --filter='PaymentObserverTest|RoundHistoryObserverTest|StudentNoteObserverTest'
```
Expected: all pass.

### - [ ] Step 6: Commit

```bash
git add app/Observers/PaymentObserver.php app/Observers/RoundHistoryObserver.php \
    app/Observers/StudentNoteObserver.php app/Providers/AppServiceProvider.php \
    tests/Feature/PaymentObserverTest.php tests/Feature/RoundHistoryObserverTest.php \
    tests/Feature/StudentNoteObserverTest.php
git commit -m "feat(activity): observers for Payment, RoundHistory, StudentNote → describer"
```

---

## Task 12: Extend `MeetingObserver` with describer calls + LeadIntake hook

**Files:**
- Modify: `app/Observers/MeetingObserver.php`
- Modify: `app/Services/LeadIntakeService.php`
- Create: `tests/Feature/ActivityLogIntegrationTest.php`

### - [ ] Step 1: Write the integration test

Create `tests/Feature/ActivityLogIntegrationTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Meeting;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

class ActivityLogIntegrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_meeting_schedule_logs_humanized_row(): void
    {
        $this->seed();
        $sumit = User::where('email', 'sumit@davya.local')->first();
        $this->actingAs($sumit);
        $s = Student::create([
            'phone' => '9999970001', 'name' => 'T', 'owner_id' => $sumit->id,
            'lead_source' => 'Website', 'stage' => 'Lead Captured',
        ]);
        Activity::query()->delete();

        Meeting::create([
            'student_id' => $s->id, 'owner_id' => $sumit->id,
            'scheduled_at' => now()->addDay(), 'status' => 'scheduled',
            'created_by_id' => $sumit->id, 'notes' => 'Counselling session',
        ]);

        $events = Activity::where('subject_id', $s->id)->pluck('event', 'description')->all();
        $this->assertContains('meeting_scheduled', $events);
        $scheduleRow = Activity::where('event', 'meeting_scheduled')->first();
        $this->assertStringContainsString('Scheduled meeting', $scheduleRow->description);
    }

    public function test_full_journey_produces_expected_chronology(): void
    {
        $this->seed();
        $sumit = User::where('email', 'sumit@davya.local')->first();
        $this->actingAs($sumit);
        $s = Student::create([
            'phone' => '9999970002', 'name' => 'T', 'owner_id' => $sumit->id,
            'lead_source' => 'Website', 'stage' => 'Lead Captured',
        ]);

        Meeting::create([
            'student_id' => $s->id, 'owner_id' => $sumit->id,
            'scheduled_at' => now()->addDay(), 'status' => 'scheduled',
            'created_by_id' => $sumit->id, 'notes' => 'Counselling',
        ]);
        $s->update(['stage' => 'Meeting Done', 'student_response' => 'Ready']);

        $events = Activity::where('subject_id', $s->id)->orderBy('id')->pluck('event')->all();
        // Expect meeting_scheduled, stage_changed (to Meeting Scheduled via observer), stage_changed (to Meeting Done).
        $this->assertContains('meeting_scheduled', $events);
        $this->assertContains('stage_changed', $events);
    }
}
```

### - [ ] Step 2: Run test — expect FAIL

```bash
php -d memory_limit=512M vendor/bin/phpunit --filter=ActivityLogIntegrationTest
```
Expected: meeting_scheduled event missing (observer only logs stage changes).

### - [ ] Step 3: Extend MeetingObserver

Edit `app/Observers/MeetingObserver.php`. Inject `ActivityDescriber` alongside the validator:

```php
public function __construct(
    private readonly StageTransitionValidator $validator,
    private readonly \App\Services\ActivityDescriber $describer,
) {
}

public function created(Meeting $meeting): void
{
    $student = $meeting->student()->first();
    if ($student === null) {
        return;
    }

    $this->describer->meetingScheduled($meeting);

    if ($student->stage === 'Lead Captured') {
        $this->advanceStage($student, 'Meeting Scheduled');
    }

    $this->syncMeetingDateCache($student);
}

public function updated(Meeting $meeting): void
{
    if ($meeting->wasChanged('status') && $meeting->status === 'held' && $meeting->held_at === null) {
        Meeting::withoutEvents(fn () => $meeting->update(['held_at' => now()]));
    }

    if ($meeting->wasChanged('scheduled_at')) {
        $from = $meeting->getOriginal('scheduled_at');
        if ($from instanceof \DateTimeInterface) {
            $this->describer->meetingRescheduled($meeting, $from);
        }
    }

    if ($meeting->wasChanged('status') && $meeting->status === 'cancelled') {
        $this->describer->meetingCancelled($meeting);
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

    $this->syncMeetingDateCache($student);
}
```

Keep the existing `deleted` and private helpers as-is.

### - [ ] Step 4: Hook `LeadIntakeService`

Edit `app/Services/LeadIntakeService.php` — after a student is created in the intake flow, call:

```php
app(\App\Services\ActivityDescriber::class)->leadCaptured($student, $source);
```

Where `$source` is the existing variable representing `lead_source` (e.g., `'Sheet:Sonam'` or `'Website'`). The exact location depends on current code; search for the create call and append the describer call.

### - [ ] Step 5: Run integration test — expect PASS

```bash
php -d memory_limit=512M vendor/bin/phpunit --filter='ActivityLogIntegrationTest|MeetingObserverTest'
```
Expected: all pass.

### - [ ] Step 6: Commit

```bash
git add app/Observers/MeetingObserver.php app/Services/LeadIntakeService.php \
    tests/Feature/ActivityLogIntegrationTest.php
git commit -m "feat(activity): MeetingObserver + LeadIntakeService emit describer rows"
```

---

## Task 13: `ActivityRelationManager` UI — 3 columns

**Files:**
- Modify: `app/Filament/Resources/StudentResource/RelationManagers/ActivityRelationManager.php`

### - [ ] Step 1: Update the columns

Edit the file, replace the `columns([...])` block with:

```php
->columns([
    TextColumn::make('created_at')->label('When')->dateTime('d M Y, H:i')->sortable(),
    TextColumn::make('causer.name')->label('Who')->badge()->color('gray'),
    TextColumn::make('description')->label('What')->wrap(),
])
```

Remove the `TextColumn::make('event')` row.

### - [ ] Step 2: Smoke test via existing tests

```bash
php -d memory_limit=512M vendor/bin/phpunit --filter=ActivityRelation
```
Expected: any existing test that queried 4 columns should be updated or removed; otherwise green.

### - [ ] Step 3: Commit

```bash
git add app/Filament/Resources/StudentResource/RelationManagers/ActivityRelationManager.php
git commit -m "feat(activity): simplify relation manager to When/Who/What (3 columns)"
```

---

## Task 14: Form restructure — top-level Tabs

**Rationale:** Wrap the scrolling sections in Filament `Tabs`. Sections don't move across files; they get grouped under `Tab::schema([])` blocks.

**Files:**
- Modify: `app/Filament/Resources/StudentResource.php`
- Create: `tests/Feature/StudentFormTabsTest.php`

### - [ ] Step 1: Write the failing test

Create `tests/Feature/StudentFormTabsTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Filament\Resources\StudentResource\Pages\CreateStudent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class StudentFormTabsTest extends TestCase
{
    use RefreshDatabase;

    public function test_form_renders_top_level_tabs(): void
    {
        $this->seed();
        $sumit = User::where('email', 'sumit@davya.local')->first();
        $sumit->update(['must_change_password' => false]);
        $this->actingAs($sumit);

        Livewire::test(CreateStudent::class)
            ->assertSee('Identity')
            ->assertSee('Source & Stage')
            ->assertSee('Academic')
            ->assertSee('Deal')
            ->assertSee('Counselling')
            ->assertSee('History')
            ->assertSee('Closure')
            ->assertDontSee('Final allotment')
            ->assertDontSee('Logistics');
    }
}
```

### - [ ] Step 2: Run test — expect FAIL

```bash
php -d memory_limit=512M vendor/bin/phpunit --filter=StudentFormTabsTest
```
Expected: "Final allotment" still present or tabs not rendered.

### - [ ] Step 3: Restructure the form

Edit `app/Filament/Resources/StudentResource.php`. Replace the entire `public static function form(Form $form): Form` body with:

```php
use Filament\Forms\Components\Tabs;

public static function form(Form $form): Form
{
    return $form->schema([
        Tabs::make('student_form')
            ->columnSpanFull()
            ->tabs([
                Tabs\Tab::make('Identity')
                    ->icon('heroicon-o-identification')
                    ->schema([
                        TextInput::make('phone')->required()->unique(ignoreRecord: true)->tel(),
                        TextInput::make('name'),
                        TextInput::make('father_name'),
                        TextInput::make('phone_2')->tel()->label('Alternate phone'),
                        TextInput::make('email')->email()->maxLength(120),
                    ])->columns(2),

                Tabs\Tab::make('Source & Stage')
                    ->icon('heroicon-o-user-group')
                    ->schema([
                        // Owner — heads only.
                        Select::make('owner_id')
                            ->label('Owner')
                            ->relationship(
                                name: 'owner',
                                titleAttribute: 'name',
                                modifyQueryUsing: fn ($query) =>
                                    $query->whereHas('roles', fn ($q) => $q->whereIn('name', ['admin', 'head'])),
                            )
                            ->required()
                            ->searchable(),
                        Select::make('lead_source')
                            ->label('Lead Source')
                            ->options(fn () => User::where('is_active', true)->orderBy('name')->pluck('name', 'name'))
                            ->required()
                            ->searchable(),
                        TextInput::make('referrer_name')->label('Referrer name')->maxLength(120),

                        Select::make('stage')->options(PipelineStage::options())->required()->default('Lead Captured')
                            ->live()
                            ->afterStateUpdated(function ($state, $record, $set) {
                                if (! $record) {
                                    return;
                                }
                                $out = (new StageTransitionValidator)->forStageChange($record, $state);
                                foreach ($out['hard'] as $err) {
                                    Notification::make()->danger()->title('Stage change blocked')->body($err)->send();
                                    $set('stage', $record->getOriginal('stage'));
                                    return;
                                }
                                foreach ($out['soft'] as $warn) {
                                    Notification::make()->warning()->title('Stage changed — incomplete')->body($warn)->send();
                                }
                            }),
                        Select::make('student_response')->options([
                            'Ready' => 'Ready',
                            'Not Interested' => 'Not Interested',
                            'Needs Time' => 'Needs Time',
                        ]),
                    ])->columns(2),

                Tabs\Tab::make('Academic')
                    ->icon('heroicon-o-academic-cap')
                    ->schema([
                        TextInput::make('exam_appeared'),
                        TextInput::make('twelfth_marks'),
                        TextInput::make('rank')->maxLength(40),
                        Select::make('category')->options(['Delhi' => 'Delhi', 'Outside' => 'Outside']),
                        TextInput::make('state')->maxLength(40),
                        TextInput::make('course')->columnSpan(3),
                        TextInput::make('preference_r1')->label('1st choice')->required()->maxLength(120),
                        TextInput::make('preference_r2')->label('2nd choice (optional)')->maxLength(120),
                        TextInput::make('preference_r3')->label('3rd choice (optional)')->maxLength(120),
                    ])->columns(3),

                Tabs\Tab::make('Deal')
                    ->icon('heroicon-o-banknotes')
                    ->schema([
                        TextInput::make('deal_amount')->numeric()->prefix('₹'),
                        Select::make('plan')->options(['Online' => 'Online', 'Offline' => 'Offline', 'All' => 'All']),
                    ])->columns(2),
                    // Payments relation manager renders as a sub-tab below the form (see getRelations()).

                Tabs\Tab::make('Counselling')
                    ->icon('heroicon-o-key')
                    ->schema([
                        Toggle::make('is_ipu_registered'),
                        TextInput::make('ipu_user_id'),
                        TextInput::make('ipu_login_code')
                            ->label('IPU login code')
                            ->maxLength(60)
                            ->helperText('Shared with the student during counselling.'),
                        TextInput::make('current_round'),
                        Toggle::make('seat_fee_due')->disabled(),
                    ])->columns(2),
                    // Round history relation manager renders as a sub-tab below the form.

                Tabs\Tab::make('History')
                    ->icon('heroicon-o-clock')
                    ->schema([
                        // Notes + activity render as relation managers below — placeholder so the tab still renders on create.
                        \Filament\Forms\Components\Placeholder::make('activity_hint')
                            ->content('Notes and activity are shown in the tabs below the form.')
                            ->label(''),
                    ]),

                Tabs\Tab::make('Closure')
                    ->icon('heroicon-o-x-circle')
                    ->badge(fn ($record) => $record?->stage === 'Closed' ? 'Closed' : null)
                    ->badgeColor('danger')
                    ->schema([
                        Select::make('close_reason')->options([
                            'Not Interested' => 'Not Interested',
                            'Backed Out — Forfeit' => 'Backed Out — Forfeit',
                            'Backed Out — Partial Refund' => 'Backed Out — Partial Refund',
                            'Completed' => 'Completed',
                            'Other' => 'Other',
                        ]),
                        TextInput::make('refund_amount')->numeric()->prefix('₹'),
                        Textarea::make('re_entry_reason')->rows(2),
                        Textarea::make('description')->rows(3)->label('Description / freeform notes'),
                        Textarea::make('extra_notes')->rows(3)->label('Extra notes'),
                    ])->columns(2),
            ])
            ->persistTabInQueryString(),
    ]);
}
```

Notes:
- The `Logistics`, `Final allotment`, and `First payment (optional)` sections are all removed from the form body above — just not present.
- Relation managers stay as they are today (rendered by Filament as sub-tabs below the form). The order in `getRelations()` controls which appears first — put `PaymentsRelationManager` first so it's closest to the Deal tab visually.

### - [ ] Step 4: Reorder `getRelations()`

In the same file:

```php
public static function getRelations(): array
{
    return [
        PaymentsRelationManager::class,
        RoundHistoryRelationManager::class,
        NotesRelationManager::class,
        MeetingsRelationManager::class,
        ActivityRelationManager::class,
    ];
}
```

### - [ ] Step 5: Run tabs test + regressions

```bash
php -d memory_limit=512M vendor/bin/phpunit --filter='StudentFormTabsTest|StudentResource|StudentSourceOwnerForm'
```
Expected: all green.

### - [ ] Step 6: Commit

```bash
git add app/Filament/Resources/StudentResource.php tests/Feature/StudentFormTabsTest.php
git commit -m "feat(form): top-level tabs (Identity / Source&Stage / Academic / Deal / Counselling / History / Closure)"
```

---

## Task 15: Deploy + smoke

**Files:** none (deploy checklist)

### - [ ] Step 1: Run the complete test suite

```bash
php -d memory_limit=512M vendor/bin/phpunit
```
Expected: all green. DEPR lines are ignored.

### - [ ] Step 2: Manual smoke on local — new student flow

Visit `/admin/students/create`:
- Confirm tabs render (Identity / Source & Stage / Academic / Deal / Counselling / History / Closure)
- Confirm Owner dropdown lists only Sumit / Sonam / Nikhil
- Confirm Referrer name is a freeform text input
- Fill a student, save. Open the record — activity tab shows "Lead captured from …" row.

### - [ ] Step 3: Manual smoke — soft warning

On the kanban page, drag a student from Lead Captured → Meeting Scheduled without a meeting. Expect: student moves; yellow banner appears; "Meeting Scheduled incomplete" text present.

### - [ ] Step 4: Manual smoke — hard block

Drag a student to Closed without setting close_reason. Expect: red banner; student snaps back to the original stage.

### - [ ] Step 5: Manual smoke — ipu_login_code

On an existing student, open the Counselling tab. Confirm the input is plain (not masked). Type a value, save, open a fresh browser tab — verify the plain value is visible.

### - [ ] Step 6: Tag milestone + push

```bash
git tag v11-pipeline-overhaul
git push origin feature/student-pipeline-overhaul --tags
```

### - [ ] Step 7: Prod deploy

Per `docs/DEPLOY.md` — FTP/SSH steps unchanged; run `php artisan migrate` on prod using `/opt/alt/php84/usr/bin/php` (see memory `reference_shared-infra.md`).

Post-deploy checks:
- `php artisan migrate:status` — confirm all 5 new migrations ran.
- `php artisan tinker --execute='echo \App\Models\Student::where("stage","Onboarded")->count()'` — expect `0`.
- Open `/admin/students/{id}/edit` — tabs render, ipu field plain-text, referrer_name is text input.
- Log into `/admin/kanban` — drag a test student, confirm soft warning UX.

---

## Post-deploy hygiene (not in this plan)

- Drop `students.referrer_id` once no rows rely on it for visibility (one release cycle).
- Drop `students.current_round` (replaced by rounds-as-stages; column unused after this release).
- Drop `students.meeting_date` denormalized cache after validating no consumers remain.

---

## Spec coverage checklist

| Spec section | Plan task(s) |
|---|---|
| §1 Form restructure to tabs | Task 14 |
| §2 Source & Owner reshape + owner heads-only + referrer_name | Tasks 4, 5, 14 |
| §3 12-stage pipeline + enum + mapping | Tasks 1, 4, 8 |
| §4 Gate rules (soft + hard) | Tasks 2, 3 |
| §5 IPU login code (rename + plain text + delete reveal) | Task 6 |
| §6 Drop Final-allotment | Task 7 |
| §7 Humanized activity log | Tasks 9, 10, 11, 12, 13 |
| §8 Test plan | Covered by per-task tests |
| §9 Deployment notes | Task 15 |
| §10 Future work (SP#2/SP#3) | Out of scope — spec-only |
