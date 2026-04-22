<?php

namespace Tests\Feature;

use App\Models\Student;
use App\Models\User;
use App\Services\LeadIntakeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Locks the scope-visibility contract for head users.
 *
 * Nikhil's team: only Nisha (team_head_id = nikhil.id in UsersSeeder).
 * Nikhil should see: leads owned by Nikhil OR Nisha.
 * Nikhil should NOT see: leads owned by Sonam / Sumit / Poonam / Neetu / Kapil.
 */
class NikhilVisibilityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    private function nikhil(): User
    {
        return User::where('email', 'nikhil@davya.local')->firstOrFail();
    }

    public function test_nikhil_sees_leads_he_owns_directly(): void
    {
        $nikhil = $this->nikhil();
        $s = app(LeadIntakeService::class)->ingest([
            'phone' => '7280000331',
            'course' => 'BCA',
            'owner_name' => 'Nikhil',
        ])['student'];

        $this->assertSame($nikhil->id, $s->owner_id, 'direct ingest with owner_name=Nikhil must set owner to Nikhil');
        $visible = Student::visibleTo($nikhil)->where('phone', '7280000331')->first();
        $this->assertNotNull($visible, 'Nikhil must see leads he owns');
    }

    public function test_nikhil_sees_leads_nisha_owns(): void
    {
        $nikhil = $this->nikhil();
        // A lead referred by Nisha → resolveOwnership sets owner = her team_head_id = Nikhil.
        $s = app(LeadIntakeService::class)->ingest([
            'phone' => '7280000332',
            'course' => 'BBA',
            'referrer_name' => 'Nisha',
        ])['student'];

        // Per resolveOwnership: owner = referrer.team_head_id = Nikhil, referrer = Nisha.
        $this->assertSame($nikhil->id, $s->owner_id, 'Nisha-referred lead must be owned by her head Nikhil');

        $visible = Student::visibleTo($nikhil)->where('phone', '7280000332')->first();
        $this->assertNotNull($visible, 'Nikhil must see leads his team-member Nisha referred');
    }

    public function test_nikhil_does_not_see_leads_owned_by_sonam(): void
    {
        $nikhil = $this->nikhil();
        $s = app(LeadIntakeService::class)->ingest([
            'phone' => '7280000333',
            'course' => 'BCA',
            'owner_name' => 'Sonam',
        ])['student'];

        $this->assertNotSame($nikhil->id, $s->owner_id);
        $visible = Student::visibleTo($nikhil)->where('phone', '7280000333')->first();
        $this->assertNull($visible, 'Nikhil must NOT see leads owned by Sonam');
    }

    public function test_nikhil_does_not_see_leads_owned_by_sumit_admin_fallback(): void
    {
        $nikhil = $this->nikhil();
        // No owner_name, no referrer_name → falls through to admin (Sumit). This is the most
        // likely failure mode for the reported bug with 7280000331.
        $s = app(LeadIntakeService::class)->ingest([
            'phone' => '7280000334',
            'course' => 'BCA',
        ])['student'];

        $sumit = User::where('email', 'sumit@davya.local')->firstOrFail();
        $this->assertSame($sumit->id, $s->owner_id, 'lead without owner_name or referrer_name falls through to admin');

        $visible = Student::visibleTo($nikhil)->where('phone', '7280000334')->first();
        $this->assertNull($visible, 'Nikhil cannot see the admin-fallback lead — this is the scope hole');
    }

    public function test_nikhil_sees_but_cannot_edit_lead_outside_team(): void
    {
        // Sanity: the StudentPolicy::update delegates to view() so visibility IS the edit gate.
        $nikhil = $this->nikhil();
        $s = app(LeadIntakeService::class)->ingest([
            'phone' => '7280000335',
            'course' => 'BCA',
            'owner_name' => 'Sonam',
        ])['student'];

        $policy = new \App\Policies\StudentPolicy();
        $this->assertFalse($policy->view($nikhil, $s), 'view should be false for a Sonam-owned lead');
        $this->assertFalse($policy->update($nikhil, $s), 'update should mirror view');
    }

    public function test_nikhil_can_edit_leads_he_can_see(): void
    {
        $nikhil = $this->nikhil();
        $s = app(LeadIntakeService::class)->ingest([
            'phone' => '7280000336',
            'course' => 'BCA',
            'owner_name' => 'Nikhil',
        ])['student'];

        $policy = new \App\Policies\StudentPolicy();
        $this->assertTrue($policy->view($nikhil, $s), 'head views own lead');
        $this->assertTrue($policy->update($nikhil, $s), 'head updates own lead — edit rights already exist');
    }
}
