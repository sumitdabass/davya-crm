<?php

namespace Tests\Feature\Dashboard;

use App\Dashboard\Cards\Stat\AdmissionsClosedTodayCard;
use App\Models\Stage;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdmissionsClosedTodayCardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    public function test_counts_students_closed_with_completed_today(): void
    {
        $admin = User::where('email', 'sumit@davya.local')->first();
        $closedStageId = Stage::where('name', 'Closed')->value('id');
        $this->assertNotNull($closedStageId, 'Closed stage must exist in seed.');

        // Today: closed + completed (should count)
        Student::create([
            'phone' => '9222000001',
            'name' => 'Admitted Today',
            'owner_id' => $admin->id,
            'lead_source' => 'Website',
            'stage' => 'Closed',
            'close_reason' => 'Completed',
            'stage_id' => $closedStageId,
        ]);

        // Today: closed but with other close_reason (should NOT count)
        Student::create([
            'phone' => '9222000002',
            'name' => 'Backed Out Today',
            'owner_id' => $admin->id,
            'lead_source' => 'Website',
            'stage' => 'Closed',
            'close_reason' => 'Not Interested',
            'stage_id' => $closedStageId,
        ]);

        // Yesterday: closed + completed (should NOT count — not today)
        $old = Student::create([
            'phone' => '9222000003',
            'name' => 'Admitted Yesterday',
            'owner_id' => $admin->id,
            'lead_source' => 'Website',
            'stage' => 'Closed',
            'close_reason' => 'Completed',
            'stage_id' => $closedStageId,
        ]);
        $old->updated_at = now()->subDay();
        $old->save();

        $card = new AdmissionsClosedTodayCard;
        $this->assertSame(1, $card->drillDown($admin)->query->count());
    }

    public function test_drilldown_emits_admitted_college_from_latest_paid_round(): void
    {
        $admin = User::where('email', 'sumit@davya.local')->first();
        $closedStageId = Stage::where('name', 'Closed')->value('id');

        $student = Student::create([
            'phone' => '9333000001',
            'name' => 'Admitted via Round',
            'owner_id' => $admin->id,
            'lead_source' => 'Website',
            'stage' => 'Closed',
            'close_reason' => 'Completed',
            'stage_id' => $closedStageId,
        ]);

        \App\Models\RoundHistory::create([
            'student_id' => $student->id,
            'round_name' => 'Online_R2',
            'allotted_college' => 'MAIT',
            'allotted_course' => 'B.Tech CSE',
            'seat_fee_paid' => true,
            'fee_paid_at' => now(),
            'outcome' => 'Allotted — Fee Paid',
        ]);

        $card = new AdmissionsClosedTodayCard();
        $payload = $card->drillDown($admin);

        $this->assertNotNull($payload);
        $rows = $payload->query->get();
        $this->assertGreaterThanOrEqual(1, $rows->count());

        $found = $rows->firstWhere('id', $student->id);
        $this->assertNotNull($found, 'Drill-down query must include the admitted student.');

        $rendered = \App\Dashboard\RowFormatter::format($found, 'final_college');
        $this->assertSame('MAIT', $rendered);
    }
}
