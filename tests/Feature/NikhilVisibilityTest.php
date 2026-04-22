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

    public function test_nikhil_sees_admin_owned_unallocated_pool(): void
    {
        $nikhil = $this->nikhil();
        // No owner_name, no referrer_name → falls through to admin (Sumit),
        // referrer_id is null. This is the shared "unallocated" pool — all
        // heads and their team members can see and claim these.
        $s = app(LeadIntakeService::class)->ingest([
            'phone' => '7280000334',
            'course' => 'BCA',
        ])['student'];

        $sumit = User::where('email', 'sumit@davya.local')->firstOrFail();
        $this->assertSame($sumit->id, $s->owner_id);
        $this->assertNull($s->referrer_id);

        $visible = Student::visibleTo($nikhil)->where('phone', '7280000334')->first();
        $this->assertNotNull($visible, 'unallocated admin-owned lead goes into the shared pool — visible to heads');
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

    public function test_cross_head_referrals_stay_private(): void
    {
        // After removing the cross-head rule: Nikhil does NOT see Sonam-referred
        // leads, and Sonam does NOT see Nikhil-referred or Nikhil-owned leads.
        $nikhil = $this->nikhil();
        $sonam = User::where('email', 'sonam@davya.local')->firstOrFail();
        $sumit = User::where('email', 'sumit@davya.local')->firstOrFail();

        // Sonam-referred, admin-owned: only Sonam sees it.
        $a = Student::create([
            'phone' => '7280000339', 'course' => 'BCA',
            'owner_id' => $sumit->id, 'referrer_id' => $sonam->id,
            'lead_source' => 'Walk-in / Self',
        ]);
        $this->assertNull(Student::visibleTo($nikhil)->where('id', $a->id)->first(),
            'Nikhil must NOT see leads referred only by Sonam');
        $this->assertNotNull(Student::visibleTo($sonam)->where('id', $a->id)->first(),
            'Sonam still sees her own referrals');

        // Nikhil-owned + Nikhil-referred: only Nikhil sees it (this is the
        // exact leak reported in prod for phone 7280000331).
        $b = Student::create([
            'phone' => '7280000340', 'course' => 'BCA',
            'owner_id' => $nikhil->id, 'referrer_id' => $nikhil->id,
            'lead_source' => 'Nikhil',
        ]);
        $this->assertNull(Student::visibleTo($sonam)->where('id', $b->id)->first(),
            'Sonam must NOT see Nikhil-owned leads');
        $this->assertNotNull(Student::visibleTo($nikhil)->where('id', $b->id)->first(),
            'Nikhil still sees his own leads');
    }

    public function test_nikhil_sees_admin_owned_lead_sourced_from_his_team(): void
    {
        // Sumit is both owner and referrer, but lead_source = "Sheet:Nikhil"
        // (or plain "Nikhil"/"Nisha") indicates the lead came from Nikhil's team.
        $nikhil = $this->nikhil();
        $sumit = User::where('email', 'sumit@davya.local')->firstOrFail();

        foreach (['Sheet:Nikhil', 'Nikhil', 'Nisha', 'Sheet:Nisha'] as $i => $src) {
            $phone = '728000035'.$i;
            Student::create([
                'phone' => $phone,
                'course' => 'BCA',
                'owner_id' => $sumit->id,
                'referrer_id' => $sumit->id,
                'lead_source' => $src,
            ]);
            $visible = Student::visibleTo($nikhil)->where('phone', $phone)->first();
            $this->assertNotNull($visible, "Nikhil must see admin-owned lead with lead_source={$src}");
        }

        $policy = new \App\Policies\StudentPolicy();
        $s = Student::where('phone', '7280000350')->first();
        $this->assertTrue($policy->update($nikhil, $s), 'Nikhil can edit admin-owned lead from his team source');
    }

    public function test_nikhil_does_not_see_admin_owned_lead_sourced_from_sonam_team(): void
    {
        // Sumit owns + referred, lead_source points to Sonam's team — stays with Sonam.
        $nikhil = $this->nikhil();
        $sumit = User::where('email', 'sumit@davya.local')->firstOrFail();

        foreach (['Sheet:Sonam', 'Poonam', 'Neetu'] as $i => $src) {
            $phone = '728000036'.$i;
            Student::create([
                'phone' => $phone,
                'course' => 'BCA',
                'owner_id' => $sumit->id,
                'referrer_id' => $sumit->id,
                'lead_source' => $src,
            ]);
            $visible = Student::visibleTo($nikhil)->where('phone', $phone)->first();
            $this->assertNull($visible, "Nikhil must NOT see Sonam-team-sourced lead (lead_source={$src})");
        }
    }

    public function test_sumit_owned_and_referred_with_walkin_source_stays_admin_only(): void
    {
        // Guard against over-matching: lead_source = "Walk-in / Self" is not a
        // team label, so no head should see it.
        $nikhil = $this->nikhil();
        $sonam = User::where('email', 'sonam@davya.local')->firstOrFail();
        $sumit = User::where('email', 'sumit@davya.local')->firstOrFail();

        Student::create([
            'phone' => '7280000370',
            'course' => 'BCA',
            'owner_id' => $sumit->id,
            'referrer_id' => $sumit->id,
            'lead_source' => 'Walk-in / Self',
        ]);

        $this->assertNull(Student::visibleTo($nikhil)->where('phone', '7280000370')->first());
        $this->assertNull(Student::visibleTo($sonam)->where('phone', '7280000370')->first());
    }

    public function test_member_shares_team_visibility_with_head(): void
    {
        // A member of Nikhil's team (Nisha) now sees everything Nikhil sees.
        // Lets all team members handle the same bucket of leads.
        $nisha = User::where('email', 'nisha@davya.local')->firstOrFail();
        $nikhil = $this->nikhil();

        // Nikhil-owned lead: Nisha sees it (same team).
        $nikhilLead = Student::create([
            'phone' => '7280000341', 'course' => 'BCA',
            'owner_id' => $nikhil->id, 'referrer_id' => $nikhil->id,
            'lead_source' => 'Nikhil',
        ]);
        $this->assertNotNull(Student::visibleTo($nisha)->where('id', $nikhilLead->id)->first(),
            'Nisha (member) must see Nikhil-owned leads — same team');

        // Sonam-owned lead: Nisha does NOT see it (different team).
        $sonam = User::where('email', 'sonam@davya.local')->firstOrFail();
        $sonamLead = Student::create([
            'phone' => '7280000342', 'course' => 'BCA',
            'owner_id' => $sonam->id, 'referrer_id' => $sonam->id,
            'lead_source' => 'Sonam',
        ]);
        $this->assertNull(Student::visibleTo($nisha)->where('id', $sonamLead->id)->first(),
            'Nisha must NOT see Sonam-team leads — different team');
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
