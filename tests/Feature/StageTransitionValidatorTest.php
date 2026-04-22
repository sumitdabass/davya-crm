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
            'phone' => '9999900001',
            'name' => 'Test',
            'owner_id' => $owner->id,
            'referrer_id' => null,
            'lead_source' => 'Website',
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
            'student_id' => $this->s->id,
            'owner_id' => $this->s->owner_id,
            'scheduled_at' => now()->addDay(),
            'status' => 'scheduled',
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
            'student_id' => $this->s->id,
            'type' => 'advance',
            'amount' => 100,
            'received_at' => now(),
            'recorded_by_user_id' => $this->s->owner_id,
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
            'student_id' => $this->s->id,
            'round_name' => 'Online_R1',
            'outcome' => 'Not Allotted',
        ]);
        $out = $this->v->forStageChange($this->s, 'Round 1');
        $this->assertSame([], $out['soft']);
    }

    public function test_seat_allotted_without_college_is_soft(): void
    {
        RoundHistory::create([
            'student_id' => $this->s->id,
            'round_name' => 'Online_R1',
            'outcome' => 'Allotted — Fee Paid',
            'allotted_college' => null,
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
