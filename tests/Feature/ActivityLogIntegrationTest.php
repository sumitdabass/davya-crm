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

        $events = Activity::where('subject_id', $s->id)->pluck('event')->all();
        $this->assertContains('meeting_scheduled', $events);

        $row = Activity::where('subject_id', $s->id)->where('event', 'meeting_scheduled')->first();
        $this->assertStringContainsString('Scheduled meeting', $row->description);
    }

    public function test_meeting_cancelled_logs_humanized_row(): void
    {
        $this->seed();
        $sumit = User::where('email', 'sumit@davya.local')->first();
        $this->actingAs($sumit);
        $s = Student::create([
            'phone' => '9999970003', 'name' => 'T', 'owner_id' => $sumit->id,
            'lead_source' => 'Website', 'stage' => 'Lead Captured',
        ]);
        $m = Meeting::create([
            'student_id' => $s->id, 'owner_id' => $sumit->id,
            'scheduled_at' => now()->addDay(), 'status' => 'scheduled',
            'created_by_id' => $sumit->id, 'notes' => 'Counselling',
        ]);
        Activity::query()->delete();

        $m->update(['status' => 'cancelled']);

        $events = Activity::where('subject_id', $s->id)->pluck('event')->all();
        $this->assertContains('meeting_cancelled', $events);
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
        $this->assertContains('meeting_scheduled', $events);
        $this->assertContains('stage_changed', $events);
    }
}
