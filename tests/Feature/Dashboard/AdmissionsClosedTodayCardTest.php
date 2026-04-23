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

    public function test_counts_students_moved_to_admission_confirmed_today(): void
    {
        $admin = User::where('email', 'sumit@davya.local')->first();
        // Use "Closed" stage (which is where "Admission Confirmed" was remapped to)
        $closedStageId = Stage::where('name', 'Closed')->value('id');
        $this->assertNotNull($closedStageId, 'Closed stage must exist in seed.');

        Student::create([
            'phone' => '9222000001',
            'name' => 'Admitted Today',
            'owner_id' => $admin->id,
            'lead_source' => 'Website',
            'stage' => 'Admission Confirmed',
            'stage_id' => $closedStageId,
        ]);

        $old = Student::create([
            'phone' => '9222000002',
            'name' => 'Admitted Yesterday',
            'owner_id' => $admin->id,
            'lead_source' => 'Website',
            'stage' => 'Admission Confirmed',
            'stage_id' => $closedStageId,
        ]);
        $old->updated_at = now()->subDay();
        $old->save();

        $card = new AdmissionsClosedTodayCard;
        $this->assertSame(1, $card->drillDown($admin)->query->count());
    }
}
