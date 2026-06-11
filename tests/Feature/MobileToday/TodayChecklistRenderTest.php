<?php

namespace Tests\Feature\MobileToday;

use App\Models\Meeting;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class TodayChecklistRenderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    private function admin(): User
    {
        // Seeded admin (Spatie `admin` role); no `role` column on users.
        // Clear must_change_password so the panel doesn't 302 to the change-pw screen.
        $u = User::where('email', 'sumit@davya.local')->first();
        $u->update(['must_change_password' => false]);

        return $u;
    }

    public function test_today_renders_stats_strip_and_sections_with_peek_dispatch(): void
    {
        $user = $this->admin();
        $student = Student::factory()->create(['name' => 'Shubham']);

        // No MeetingFactory exists in this codebase; create directly like
        // MeetingVisibilityTest does (owner_id / created_by_id / mode required).
        Meeting::create([
            'student_id' => $student->id,
            'owner_id' => $user->id,
            'created_by_id' => $user->id,
            'mode' => 'in_person',
            'scheduled_at' => Carbon::now('Asia/Kolkata')->setTime(11, 0),
            'status' => 'scheduled',
        ]);

        $res = $this->actingAs($user)->get('/admin/today');

        $res->assertOk();
        $res->assertSee('davya-today', false);             // wrapper
        $res->assertSee('dt-stats', false);                // stats strip
        $res->assertSee('Meetings today', false);          // section label
        $res->assertSee('Shubham', false);                 // row
        $res->assertSee("open-student-peek', { studentId: {$student->id} }", false); // row dispatch
    }
}
