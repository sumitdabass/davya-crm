<?php

namespace Tests\Feature\MobileToday;

use App\Models\Meeting;
use App\Models\Payment;
use App\Models\Student;
use App\Models\User;
use App\Today\ChecklistSections;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class ChecklistSectionsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    private function admin(): User
    {
        // Seeded admin (Spatie `admin` role) so Student::scopeVisibleTo returns all.
        return User::where('email', 'sumit@davya.local')->first();
    }

    public function test_meetings_today_returns_only_todays_meetings(): void
    {
        $viewer = $this->admin();
        $student = Student::factory()->create(['name' => 'Shubham']);

        // No MeetingFactory exists in this codebase; create directly like
        // MeetingVisibilityTest does (owner_id / created_by_id / mode required).
        Meeting::create([
            'student_id' => $student->id,
            'owner_id' => $viewer->id,
            'created_by_id' => $viewer->id,
            'mode' => 'in_person',
            'scheduled_at' => Carbon::now('Asia/Kolkata')->setTime(11, 0),
            'status' => 'scheduled',
        ]);
        Meeting::create([
            'student_id' => $student->id,
            'owner_id' => $viewer->id,
            'created_by_id' => $viewer->id,
            'mode' => 'in_person',
            'scheduled_at' => Carbon::now('Asia/Kolkata')->addDays(2),
            'status' => 'scheduled',
        ]);

        $rows = (new ChecklistSections)->forCard('today_meetings', $viewer);

        $this->assertCount(1, $rows);
        $this->assertSame($student->id, $rows[0]['student_id']);
        $this->assertSame('Shubham', $rows[0]['title']);
        $this->assertSame('11:00', $rows[0]['time']);
    }

    public function test_payments_to_chase_rows_carry_pending_amount(): void
    {
        $viewer = $this->admin();
        $s = Student::factory()->create(['name' => 'Raghav', 'deal_amount' => 50000, 'stage' => 'Advance Received']);
        Payment::factory()->create(['student_id' => $s->id, 'amount' => 25000]);

        $rows = (new ChecklistSections)->forCard('payments_to_chase', $viewer);

        $this->assertCount(1, $rows);
        $this->assertSame($s->id, $rows[0]['student_id']);
        $this->assertEqualsWithDelta(25000.0, $rows[0]['amount'], 0.01);
    }

    public function test_payments_received_today_matches_todays_payments(): void
    {
        $viewer = $this->admin();
        $s = Student::factory()->create(['name' => 'Latika']);
        Payment::factory()->create([
            'student_id' => $s->id,
            'amount' => 10000,
            'received_at' => Carbon::now('Asia/Kolkata')->setTime(9, 40),
        ]);
        Payment::factory()->create([
            'student_id' => $s->id,
            'amount' => 5000,
            'received_at' => Carbon::now('Asia/Kolkata')->subDays(3),
        ]);

        $rows = (new ChecklistSections)->forCard('today_payments', $viewer);

        $this->assertCount(1, $rows);
        $this->assertSame('09:40', $rows[0]['time']);
        $this->assertEqualsWithDelta(10000.0, $rows[0]['amount'], 0.01);
    }

    public function test_unknown_card_returns_empty(): void
    {
        $viewer = $this->admin();
        $this->assertSame([], (new ChecklistSections)->forCard('nope', $viewer));
    }
}
