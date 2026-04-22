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
 * Nikhil sees a lead when:
 *   owner_id    IN [Nikhil, Nisha]  OR
 *   referrer_id IN [Nikhil, Nisha]
 * This covers the common failure where a lead falls through to admin
 * ownership but Nikhil (or Nisha) is recorded as the referrer.
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

    public function test_nikhil_does_not_see_leads_with_no_connection_to_him(): void
    {
        $nikhil = $this->nikhil();
        // No owner_name, no referrer_name → falls through to admin (Sumit), and Nikhil
        // is neither owner nor referrer. Stays invisible.
        $s = app(LeadIntakeService::class)->ingest([
            'phone' => '7280000334',
            'course' => 'BCA',
        ])['student'];

        $sumit = User::where('email', 'sumit@davya.local')->firstOrFail();
        $this->assertSame($sumit->id, $s->owner_id);

        $visible = Student::visibleTo($nikhil)->where('phone', '7280000334')->first();
        $this->assertNull($visible, 'admin-fallback lead with no Nikhil/Nisha connection stays invisible');
    }

    public function test_nikhil_sees_lead_where_he_is_referrer_even_if_owner_is_admin(): void
    {
        // This is the reported-bug shape: lead has referrer_id = Nikhil but somehow
        // owner_id is admin (e.g., n8n Set node didn't set owner_name). With referrer-aware
        // scope, Nikhil can now see + edit this lead.
        $nikhil = $this->nikhil();
        $sumit = User::where('email', 'sumit@davya.local')->firstOrFail();

        $s = Student::create([
            'phone' => '7280000337',
            'course' => 'BCA',
            'owner_id' => $sumit->id,
            'referrer_id' => $nikhil->id,
            'lead_source' => 'Walk-in / Self',
        ]);

        $visible = Student::visibleTo($nikhil)->where('phone', '7280000337')->first();
        $this->assertNotNull($visible, 'Nikhil must see leads where he is the referrer');

        $policy = new \App\Policies\StudentPolicy();
        $this->assertTrue($policy->update($nikhil, $s), 'Nikhil must be able to edit the lead he referred');
    }

    public function test_nikhil_sees_lead_where_nisha_is_referrer(): void
    {
        // Nisha (Nikhil's team member) referred a lead that got admin ownership.
        // Head should see anything his team referred, not just what they own.
        $nikhil = $this->nikhil();
        $nisha = User::where('email', 'nisha@davya.local')->firstOrFail();
        $sumit = User::where('email', 'sumit@davya.local')->firstOrFail();

        $s = Student::create([
            'phone' => '7280000338',
            'course' => 'BCA',
            'owner_id' => $sumit->id,
            'referrer_id' => $nisha->id,
            'lead_source' => 'Walk-in / Self',
        ]);

        $visible = Student::visibleTo($nikhil)->where('phone', '7280000338')->first();
        $this->assertNotNull($visible, 'Nikhil must see leads his team-member Nisha referred');
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
