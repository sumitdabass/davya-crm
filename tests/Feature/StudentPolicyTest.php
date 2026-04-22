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

    public function test_head_can_view_lead_routed_by_lead_source_even_when_non_admin_owned(): void
    {
        // Mirror of the scope test, via $user->can('view', $student).
        $nikhil = User::where('email', 'nikhil@davya.local')->firstOrFail();
        $kapil = User::where('email', 'kapil@davya.local')->firstOrFail();

        $s = Student::create([
            'phone' => '9700000001',
            'name' => 'S',
            'owner_id' => $kapil->id,
            'referrer_id' => null,
            'lead_source' => 'Sheet:Nikhil',
        ]);

        $this->assertTrue($nikhil->can('view', $s), 'policy view must allow head when lead_source names his team');
    }

    public function test_member_cannot_update_teammate_lead(): void
    {
        // Poonam + Neetu both report to Sonam. Poonam sees Neetu's lead (team-wide view),
        // but must NOT be able to edit it under the new rule.
        $poonam = User::where('email', 'poonam@davya.local')->firstOrFail();
        $neetu = User::where('email', 'neetu@davya.local')->firstOrFail();

        $s = Student::create([
            'phone' => '9800000001',
            'name' => 'S',
            'owner_id' => $neetu->id,
            'referrer_id' => $neetu->id,
            'lead_source' => 'Neetu',
        ]);

        $this->assertTrue($poonam->can('view', $s), 'team-wide view stays intact');
        $this->assertFalse($poonam->can('update', $s), 'counsellor must NOT edit teammate lead');
    }

    public function test_member_can_update_own_lead(): void
    {
        $poonam = User::where('email', 'poonam@davya.local')->firstOrFail();

        $s = Student::create([
            'phone' => '9800000002',
            'name' => 'S',
            'owner_id' => $poonam->id,
            'referrer_id' => $poonam->id,
            'lead_source' => 'Poonam',
        ]);

        $this->assertTrue($poonam->can('update', $s), 'counsellor edits own lead');
    }

    public function test_member_can_update_lead_where_they_are_referrer(): void
    {
        $poonam = User::where('email', 'poonam@davya.local')->firstOrFail();
        $neetu = User::where('email', 'neetu@davya.local')->firstOrFail();

        $s = Student::create([
            'phone' => '9800000003',
            'name' => 'S',
            'owner_id' => $neetu->id,
            'referrer_id' => $poonam->id,
            'lead_source' => 'Neetu',
        ]);

        $this->assertTrue($poonam->can('update', $s), 'counsellor edits lead where she is referrer');
    }

    public function test_head_can_update_teammate_lead(): void
    {
        // Regression lock: heads keep team-wide edit.
        $sonam = User::where('email', 'sonam@davya.local')->firstOrFail();
        $poonam = User::where('email', 'poonam@davya.local')->firstOrFail();

        $s = Student::create([
            'phone' => '9800000004',
            'name' => 'S',
            'owner_id' => $poonam->id,
            'referrer_id' => $poonam->id,
            'lead_source' => 'Poonam',
        ]);

        $this->assertTrue($sonam->can('update', $s), 'head edits any team lead');
    }

    public function test_admin_can_delete_student(): void
    {
        $sumit = User::where('email', 'sumit@davya.local')->firstOrFail();
        $nikhil = User::where('email', 'nikhil@davya.local')->firstOrFail();
        $s = Student::create([
            'phone' => '9900000001',
            'name' => 'S',
            'owner_id' => $nikhil->id,
            'referrer_id' => $nikhil->id,
            'lead_source' => 'Nikhil',
        ]);
        $this->assertTrue($sumit->can('delete', $s), 'admin deletes any lead');
    }

    public function test_head_cannot_delete_team_lead(): void
    {
        $nikhil = User::where('email', 'nikhil@davya.local')->firstOrFail();
        $s = Student::create([
            'phone' => '9900000002',
            'name' => 'S',
            'owner_id' => $nikhil->id,
            'referrer_id' => $nikhil->id,
            'lead_source' => 'Nikhil',
        ]);
        $this->assertFalse($nikhil->can('delete', $s), 'head must NOT delete team lead');
    }

    public function test_member_cannot_delete_own_lead(): void
    {
        // Even on his own lead, a counsellor cannot delete.
        $nisha = User::where('email', 'nisha@davya.local')->firstOrFail();
        $s = Student::create([
            'phone' => '9900000003',
            'name' => 'S',
            'owner_id' => $nisha->id,
            'referrer_id' => $nisha->id,
            'lead_source' => 'Nisha',
        ]);
        $this->assertFalse($nisha->can('delete', $s), 'counsellor must NOT delete (even own)');
    }
}
