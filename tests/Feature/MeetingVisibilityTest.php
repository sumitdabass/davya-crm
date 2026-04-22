<?php

namespace Tests\Feature;

use App\Models\Meeting;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MeetingVisibilityTest extends TestCase
{
    use RefreshDatabase;

    private function studentOwnedBy(User $owner): Student
    {
        return Student::create([
            'name' => 'Test '.$owner->name,
            'phone' => '999'.str_pad((string) $owner->id, 7, '0', STR_PAD_LEFT),
            'course' => 'BBA',
            'stage' => 'Lead Captured',
            'lead_source' => 'Direct',
            'owner_id' => $owner->id,
        ]);
    }

    private function meetingFor(Student $student, User $owner): Meeting
    {
        return Meeting::create([
            'student_id' => $student->id,
            'owner_id' => $owner->id,
            'scheduled_at' => now()->addDay(),
            'mode' => 'in_person',
            'status' => 'scheduled',
            'created_by_id' => $owner->id,
        ]);
    }

    public function test_admin_sees_all_meetings(): void
    {
        $this->seed();
        $sumit  = User::where('email', 'sumit@davya.local')->firstOrFail();
        $sonam  = User::where('email', 'sonam@davya.local')->firstOrFail();
        $nikhil = User::where('email', 'nikhil@davya.local')->firstOrFail();

        $this->meetingFor($this->studentOwnedBy($sonam), $sonam);
        $this->meetingFor($this->studentOwnedBy($nikhil), $nikhil);

        $this->assertSame(2, Meeting::visibleTo($sumit)->count());
    }

    public function test_head_only_sees_own_team_meetings(): void
    {
        $this->seed();
        $sonam  = User::where('email', 'sonam@davya.local')->firstOrFail();
        $nikhil = User::where('email', 'nikhil@davya.local')->firstOrFail();
        $nisha  = User::where('email', 'nisha@davya.local')->firstOrFail();

        $this->meetingFor($this->studentOwnedBy($sonam), $sonam);
        $this->meetingFor($this->studentOwnedBy($nikhil), $nikhil);
        $this->meetingFor($this->studentOwnedBy($nisha), $nisha);

        $this->assertSame(2, Meeting::visibleTo($nikhil)->count());
        $this->assertSame(1, Meeting::visibleTo($sonam)->count());
    }

    public function test_member_sees_team_meetings(): void
    {
        $this->seed();
        $nikhil = User::where('email', 'nikhil@davya.local')->firstOrFail();
        $nisha  = User::where('email', 'nisha@davya.local')->firstOrFail();

        $this->meetingFor($this->studentOwnedBy($nikhil), $nikhil);
        $this->meetingFor($this->studentOwnedBy($nisha), $nisha);

        $this->assertSame(2, Meeting::visibleTo($nisha)->count());
    }

    public function test_null_user_sees_nothing(): void
    {
        $this->seed();
        $sonam = User::where('email', 'sonam@davya.local')->firstOrFail();
        $this->meetingFor($this->studentOwnedBy($sonam), $sonam);

        $this->assertSame(0, Meeting::visibleTo(null)->count());
    }
}
