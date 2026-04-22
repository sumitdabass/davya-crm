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
            'lead_source' => 'Test',
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
