<?php

namespace Tests\Feature\Dashboard;

use App\Dashboard\Cards\Stat\LeadsCapturedTodayCard;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LeadsCapturedTodayCardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    public function test_counts_students_created_today_in_scope(): void
    {
        $admin = User::where('email', 'sumit@davya.local')->first();

        Student::create([
            'phone' => '9111000001',
            'name' => 'Today A',
            'owner_id' => $admin->id,
            'lead_source' => 'Website',
            'stage' => 'Lead Captured',
        ]);

        $old = Student::create([
            'phone' => '9111000002',
            'name' => 'Yesterday',
            'owner_id' => $admin->id,
            'lead_source' => 'Website',
            'stage' => 'Lead Captured',
        ]);
        $old->created_at = now()->subDay();
        $old->save();

        $card = new LeadsCapturedTodayCard;
        $this->assertSame(1, $card->drillDown($admin)->query->count());
    }
}
