<?php

namespace Tests\Feature;

use App\Models\Meeting;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MeetingPolicyTest extends TestCase
{
    use RefreshDatabase;

    private function unblock(User $u): User
    {
        $u->must_change_password = false;
        $u->save();
        return $u;
    }

    private function sumit(): User  { return $this->unblock(User::where('email', 'sumit@davya.local')->firstOrFail()); }
    private function sonam(): User  { return $this->unblock(User::where('email', 'sonam@davya.local')->firstOrFail()); }
    private function nikhil(): User { return $this->unblock(User::where('email', 'nikhil@davya.local')->firstOrFail()); }
    private function nisha(): User  { return $this->unblock(User::where('email', 'nisha@davya.local')->firstOrFail()); }
    private function kapil(): User  { return $this->unblock(User::where('email', 'kapil@davya.local')->firstOrFail()); }

    private function studentOwnedBy(User $owner): Student
    {
        return Student::create([
            'name' => 'S '.$owner->name,
            'phone' => '999'.str_pad((string) $owner->id, 7, '0', STR_PAD_LEFT),
            'course' => 'BBA',
            'stage' => 'Lead Captured',
            'owner_id' => $owner->id,
            'lead_source' => 'Test',
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

    public function test_admin_can_do_everything(): void
    {
        $this->seed();
        $sumit = $this->sumit();
        $m = $this->meetingFor($this->studentOwnedBy($this->sonam()), $this->sonam());

        $this->assertTrue($sumit->can('viewAny', Meeting::class));
        $this->assertTrue($sumit->can('view', $m));
        $this->assertTrue($sumit->can('create', Meeting::class));
        $this->assertTrue($sumit->can('update', $m));
        $this->assertTrue($sumit->can('delete', $m));
    }

    public function test_head_cannot_see_other_heads_team_meeting_e1(): void
    {
        $this->seed();
        $nikhil = $this->nikhil();
        $meetingInSonamsTeam = $this->meetingFor($this->studentOwnedBy($this->sonam()), $this->sonam());

        $this->assertFalse($nikhil->can('view', $meetingInSonamsTeam), 'E1: head isolation');
        $this->assertFalse($nikhil->can('update', $meetingInSonamsTeam));
    }

    public function test_head_can_update_own_team_meeting(): void
    {
        $this->seed();
        $nikhil = $this->nikhil();
        $m = $this->meetingFor($this->studentOwnedBy($this->nisha()), $this->nisha());

        $this->assertTrue($nikhil->can('view', $m));
        $this->assertTrue($nikhil->can('update', $m));
        $this->assertTrue($nikhil->can('delete', $m));
    }

    public function test_member_cannot_update_teammates_meeting_e4(): void
    {
        $this->seed();
        $nisha = $this->nisha();

        $kapilTeammate = User::create([
            'name' => 'Raj',
            'email' => 'raj@davya.local',
            'password' => bcrypt('x'),
            'is_active' => true,
            'must_change_password' => false,
            'team_head_id' => $this->nikhil()->id,
        ]);
        $kapilTeammate->assignRole('member');

        $theirsMeeting = $this->meetingFor($this->studentOwnedBy($kapilTeammate), $kapilTeammate);

        $this->assertTrue($nisha->can('view', $theirsMeeting));
        $this->assertFalse($nisha->can('update', $theirsMeeting), 'E4: counsellor own only');
        $this->assertFalse($nisha->can('delete', $theirsMeeting), 'member cannot delete');
    }

    public function test_member_can_update_own_meeting(): void
    {
        $this->seed();
        $nisha = $this->nisha();
        $mine = $this->meetingFor($this->studentOwnedBy($nisha), $nisha);

        $this->assertTrue($nisha->can('update', $mine));
    }

    public function test_freelancer_only_sees_own(): void
    {
        $this->seed();
        $kapil = $this->kapil();
        $mine = $this->meetingFor($this->studentOwnedBy($kapil), $kapil);
        $not_mine = $this->meetingFor($this->studentOwnedBy($this->sonam()), $this->sonam());

        $this->assertTrue($kapil->can('view', $mine));
        $this->assertFalse($kapil->can('view', $not_mine));
        $this->assertFalse($kapil->can('delete', $mine), 'freelancer cannot delete');
    }
}
