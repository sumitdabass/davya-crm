<?php

namespace Tests\Feature;

use App\Models\RoundHistory;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class DashboardWidgetsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    private function student(string $phone, array $overrides = []): Student
    {
        $nikhil = User::where('email', 'nikhil@davya.local')->first();

        return Student::create(array_merge([
            'phone'       => $phone,
            'name'        => "Student $phone",
            'owner_id'    => $nikhil->id,
            'referrer_id' => $nikhil->id,
            'lead_source' => 'Nikhil',
            'stage'       => 'Counselling In Progress',
        ], $overrides));
    }

    // --- Seat Fee Pending ---

    public function test_seat_fee_pending_scope_returns_only_pending_fee_rows(): void
    {
        $s = $this->student('9100000001');
        $matching = RoundHistory::create([
            'student_id'       => $s->id,
            'round_name'       => 'Online_R1',
            'outcome'          => 'Allotted — Fee Pending',
            'seat_fee_paid'    => false,
            'seat_fee_amount'  => 10000,
            'allotted_college' => 'X College',
        ]);
        RoundHistory::create([
            'student_id'       => $s->id,
            'round_name'       => 'Online_R2',
            'outcome'          => 'Allotted — Fee Paid',
            'seat_fee_paid'    => true,
            'seat_fee_amount'  => 12000,
        ]);
        RoundHistory::create([
            'student_id'    => $s->id,
            'round_name'    => 'Online_R3',
            'outcome'       => 'Not Allotted',
            'seat_fee_paid' => false,
        ]);

        $rows = RoundHistory::seatFeePending()->get();

        $this->assertCount(1, $rows);
        $this->assertSame($matching->id, $rows->first()->id);
    }

    // --- Re-entry Candidates (latest per student) ---

    public function test_reentry_candidates_scope_returns_students_whose_latest_round_is_kicked_out(): void
    {
        $kickedStudent = $this->student('9100000010');
        $okStudent     = $this->student('9100000011');

        // kickedStudent: older Allotted, then Kicked Out (latest) → should match
        RoundHistory::create([
            'student_id' => $kickedStudent->id,
            'round_name' => 'Online_R1',
            'outcome'    => 'Allotted — Fee Pending',
            'seat_fee_paid' => false,
            'created_at' => Carbon::now()->subDays(5),
        ]);
        RoundHistory::create([
            'student_id' => $kickedStudent->id,
            'round_name' => 'Online_R2',
            'outcome'    => 'Kicked Out — Fee Unpaid',
            'seat_fee_paid' => false,
            'created_at' => Carbon::now()->subDays(1),
        ]);

        // okStudent: older Kicked Out, then Allotted (latest) → should NOT match
        RoundHistory::create([
            'student_id' => $okStudent->id,
            'round_name' => 'Online_R1',
            'outcome'    => 'Kicked Out — Fee Unpaid',
            'seat_fee_paid' => false,
            'created_at' => Carbon::now()->subDays(3),
        ]);
        RoundHistory::create([
            'student_id' => $okStudent->id,
            'round_name' => 'Online_Sliding',
            'outcome'    => 'Allotted — Fee Paid',
            'seat_fee_paid' => true,
            'created_at' => Carbon::now()->subDays(1),
        ]);

        $rows = RoundHistory::reEntryCandidates()->get();

        $this->assertCount(1, $rows);
        $this->assertSame($kickedStudent->id, $rows->first()->student_id);
    }

    // --- Stuck Leads ---

    public function test_stuck_scope_returns_students_not_updated_in_14_plus_days_excluding_terminal_stages(): void
    {
        $old1 = $this->student('9100000020', ['stage' => 'Meeting Scheduled']);
        $old2 = $this->student('9100000021', ['stage' => 'Onboarded']);
        $fresh = $this->student('9100000022', ['stage' => 'Meeting Scheduled']);
        $closedStale = $this->student('9100000023', ['stage' => 'Closed']);
        $admittedStale = $this->student('9100000024', ['stage' => 'Admission Confirmed']);

        // Backdate updated_at via direct update so model boot doesn't bump it
        Student::where('id', $old1->id)->update(['updated_at' => now()->subDays(20)]);
        Student::where('id', $old2->id)->update(['updated_at' => now()->subDays(15)]);
        Student::where('id', $closedStale->id)->update(['updated_at' => now()->subDays(30)]);
        Student::where('id', $admittedStale->id)->update(['updated_at' => now()->subDays(30)]);

        $ids = Student::stuck()->pluck('id')->sort()->values()->all();

        $this->assertSame([$old1->id, $old2->id], $ids);
        $this->assertNotContains($fresh->id, $ids);
        $this->assertNotContains($closedStale->id, $ids);
        $this->assertNotContains($admittedStale->id, $ids);
    }

    // --- Pipeline Summary aggregates ---

    public function test_pipeline_summary_returns_count_and_total_deal_per_stage(): void
    {
        $nikhil = User::where('email', 'nikhil@davya.local')->first();

        Student::create([
            'phone' => '9100000030', 'name' => 'A',
            'owner_id' => $nikhil->id, 'referrer_id' => $nikhil->id, 'lead_source' => 'Nikhil',
            'stage' => 'Lead Captured', 'deal_amount' => null,
        ]);
        Student::create([
            'phone' => '9100000031', 'name' => 'B',
            'owner_id' => $nikhil->id, 'referrer_id' => $nikhil->id, 'lead_source' => 'Nikhil',
            'stage' => 'Advance Received', 'deal_amount' => 50000,
        ]);
        Student::create([
            'phone' => '9100000032', 'name' => 'C',
            'owner_id' => $nikhil->id, 'referrer_id' => $nikhil->id, 'lead_source' => 'Nikhil',
            'stage' => 'Advance Received', 'deal_amount' => 75000,
        ]);

        $summary = \App\Services\PipelineSummary::compute();

        $this->assertSame(1, $summary['Lead Captured']['count']);
        $this->assertEqualsWithDelta(0.0, (float) $summary['Lead Captured']['total'], 0.01);

        $this->assertSame(2, $summary['Advance Received']['count']);
        $this->assertEqualsWithDelta(125000.0, (float) $summary['Advance Received']['total'], 0.01);

        $this->assertSame(0, $summary['Closed']['count']);
        $this->assertEqualsWithDelta(0.0, (float) $summary['Closed']['total'], 0.01);
    }
}
