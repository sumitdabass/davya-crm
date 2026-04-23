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
}
