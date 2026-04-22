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
        $this->assertNotNull($a, 'a row should be logged');
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

    public function test_ipu_login_code_change_logs(): void
    {
        $this->seed();
        $sumit = User::where('email', 'sumit@davya.local')->first();
        $this->actingAs($sumit);
        $s = $this->studentFor($sumit);

        Activity::query()->delete();
        $s->update(['ipu_login_code' => 'abc123']);

        $events = Activity::where('subject_id', $s->id)->pluck('event')->all();
        $this->assertContains('ipu_code_changed', $events);
    }
}
