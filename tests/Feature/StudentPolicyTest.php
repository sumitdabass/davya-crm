<?php

namespace Tests\Feature;

use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StudentPolicyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    public function test_admin_can_view_any_student(): void
    {
        $sumit = User::where('email', 'sumit@davya.local')->first();
        $nikhil = User::where('email', 'nikhil@davya.local')->first();
        $s = Student::create(['phone' => '9111111111', 'name' => 'S', 'owner_id' => $nikhil->id, 'referrer_id' => $nikhil->id, 'lead_source' => 'Nikhil']);
        $this->assertTrue($sumit->can('view', $s));
    }

    public function test_head_can_view_own_team_student(): void
    {
        $nikhil = User::where('email', 'nikhil@davya.local')->first();
        $nisha = User::where('email', 'nisha@davya.local')->first();
        $s = Student::create(['phone' => '9222222222', 'name' => 'S', 'owner_id' => $nisha->id, 'referrer_id' => $nisha->id, 'lead_source' => 'Nisha']);
        $this->assertTrue($nikhil->can('view', $s));
    }

    public function test_head_cannot_view_other_teams_student(): void
    {
        $nikhil = User::where('email', 'nikhil@davya.local')->first();
        $poonam = User::where('email', 'poonam@davya.local')->first();
        $s = Student::create(['phone' => '9333333333', 'name' => 'S', 'owner_id' => $poonam->id, 'referrer_id' => $poonam->id, 'lead_source' => 'Poonam']);
        $this->assertFalse($nikhil->can('view', $s));
    }

    public function test_member_can_only_view_own(): void
    {
        $nisha = User::where('email', 'nisha@davya.local')->first();
        $poonam = User::where('email', 'poonam@davya.local')->first();
        $own = Student::create(['phone' => '9444444444', 'name' => 'S', 'owner_id' => $nisha->id, 'referrer_id' => $nisha->id, 'lead_source' => 'Nisha']);
        $other = Student::create(['phone' => '9555555555', 'name' => 'S', 'owner_id' => $poonam->id, 'referrer_id' => $poonam->id, 'lead_source' => 'Poonam']);
        $this->assertTrue($nisha->can('view', $own));
        $this->assertFalse($nisha->can('view', $other));
    }

    public function test_freelancer_can_only_view_own(): void
    {
        $kapil = User::where('email', 'kapil@davya.local')->first();
        $sumit = User::where('email', 'sumit@davya.local')->first();
        $own = Student::create(['phone' => '9600000001', 'name' => 'S', 'owner_id' => $kapil->id, 'referrer_id' => $kapil->id, 'lead_source' => 'Kapil']);
        $other = Student::create(['phone' => '9600000002', 'name' => 'S', 'owner_id' => $sumit->id, 'referrer_id' => $sumit->id, 'lead_source' => 'Sumit']);
        $this->assertTrue($kapil->can('view', $own));
        $this->assertFalse($kapil->can('view', $other));
    }

    public function test_member_cannot_transfer_ownership(): void
    {
        $nisha = User::where('email', 'nisha@davya.local')->first();
        $s = Student::create(['phone' => '9666666666', 'name' => 'S', 'owner_id' => $nisha->id, 'referrer_id' => $nisha->id, 'lead_source' => 'Nisha']);
        $this->assertFalse($nisha->can('transfer', $s));
    }
}
