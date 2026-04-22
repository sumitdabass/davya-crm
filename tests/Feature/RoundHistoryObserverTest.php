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
