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

        $this->rollbackBackfillMigration();

        $future = now()->addDays(2)->startOfMinute();

        $studentId = DB::table('students')->insertGetId([
            'name' => 'Future Student',
            'phone' => '9990000001',
            'course' => 'BBA',
            'stage' => 'Lead Captured',
            'lead_source' => 'Test',
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

        $this->rollbackBackfillMigration();

        $past = now()->subDays(3)->startOfMinute();

        $studentId = DB::table('students')->insertGetId([
            'name' => 'Past Student',
            'phone' => '9990000002',
            'course' => 'MBA',
            'stage' => 'Meeting Done',
            'lead_source' => 'Test',
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

        $this->rollbackBackfillMigration();

        $studentId = DB::table('students')->insertGetId([
            'name' => 'No Meeting Student',
            'phone' => '9990000003',
            'course' => 'BBA',
            'stage' => 'Lead Captured',
            'lead_source' => 'Test',
            'owner_id' => $owner->id,
            'meeting_date' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Artisan::call('migrate', ['--force' => true]);

        $this->assertSame(0, Meeting::where('student_id', $studentId)->count());
    }

    /**
     * Mark the backfill migration as un-run and clear any Meeting rows it produced,
     * so the subsequent `migrate` re-applies it. This replaces the brittle
     * `migrate:rollback --step=1` pattern which assumed the backfill was the tail
     * of the migrations list.
     */
    private function rollbackBackfillMigration(): void
    {
        DB::table('migrations')
            ->where('migration', '2026_04_23_000100_backfill_meetings_from_students')
            ->delete();
        DB::statement('DELETE FROM meetings');
    }
}
