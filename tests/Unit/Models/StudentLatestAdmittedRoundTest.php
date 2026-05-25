<?php

namespace Tests\Unit\Models;

use App\Models\RoundHistory;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StudentLatestAdmittedRoundTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    public function test_returns_null_when_no_paid_round(): void
    {
        $student = $this->makeStudent('9111000001');
        $this->assertNull($student->latestAdmittedRound);

        // Unpaid round shouldn't qualify.
        RoundHistory::create([
            'student_id' => $student->id,
            'round_name' => 'Online_R1',
            'allotted_college' => 'X',
            'allotted_course' => 'Y',
            'seat_fee_paid' => false,
            'outcome' => 'Allotted — Fee Pending',
        ]);
        $this->assertNull($student->fresh()->latestAdmittedRound);
    }

    public function test_returns_latest_paid_round_by_fee_paid_at(): void
    {
        $student = $this->makeStudent('9111000002');

        $older = RoundHistory::create([
            'student_id' => $student->id,
            'round_name' => 'Online_R1',
            'allotted_college' => 'Old College',
            'allotted_course' => 'Old Course',
            'seat_fee_paid' => true,
            'fee_paid_at' => now()->subDays(10),
            'outcome' => 'Allotted — Fee Paid',
        ]);

        $newer = RoundHistory::create([
            'student_id' => $student->id,
            'round_name' => 'Online_R2',
            'allotted_college' => 'New College',
            'allotted_course' => 'New Course',
            'seat_fee_paid' => true,
            'fee_paid_at' => now()->subDay(),
            'outcome' => 'Allotted — Fee Paid',
        ]);

        $latest = $student->fresh()->latestAdmittedRound;
        $this->assertNotNull($latest);
        $this->assertSame($newer->id, $latest->id);
        $this->assertSame('New College', $latest->allotted_college);
    }

    private function makeStudent(string $phone): Student
    {
        $admin = User::where('email', 'sumit@davya.local')->first();
        return Student::create([
            'phone' => $phone,
            'name' => 'Test',
            'owner_id' => $admin->id,
            'lead_source' => 'Walk-in',
            'stage' => 'Lead Captured',
        ]);
    }
}
