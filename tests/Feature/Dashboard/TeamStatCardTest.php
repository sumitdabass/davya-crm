<?php

namespace Tests\Feature\Dashboard;

use App\Dashboard\Cards\Stat\TeamStatCard;
use App\Dashboard\CardRegistry;
use App\Models\Stage;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TeamStatCardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        CardRegistry::reset();
    }

    private function admin(): User
    {
        $u = User::where('email', 'sumit@davya.local')->first();
        $u->must_change_password = false; $u->save();
        return $u;
    }

    private function sonam(): User
    {
        return User::where('email', 'sonam@davya.local')->first();
    }

    private function nikhil(): User
    {
        return User::where('email', 'nikhil@davya.local')->first();
    }

    public function test_card_id_and_label(): void
    {
        $card = new TeamStatCard($this->sonam(), TeamStatCard::METRIC_LEADS_CAPTURED);
        $this->assertSame('team.'.$this->sonam()->id.'.leads_captured', $card->id());
        $this->assertSame('Sonam — Leads Captured Today', $card->label());
    }

    public function test_leads_captured_counts_only_team_students(): void
    {
        $sonam = $this->sonam();
        $nikhil = $this->nikhil();

        Student::create([
            'phone' => '9880000001', 'name' => 'Sonam Lead', 'owner_id' => $sonam->id,
            'lead_source' => 'Website', 'stage' => 'Lead Captured',
        ]);
        Student::create([
            'phone' => '9880000002', 'name' => 'Nikhil Lead', 'owner_id' => $nikhil->id,
            'lead_source' => 'Website', 'stage' => 'Lead Captured',
        ]);

        $sonamCard = new TeamStatCard($sonam, TeamStatCard::METRIC_LEADS_CAPTURED);
        $this->assertSame(1, $sonamCard->drillDown($this->admin())->query->count());

        $nikhilCard = new TeamStatCard($nikhil, TeamStatCard::METRIC_LEADS_CAPTURED);
        $this->assertSame(1, $nikhilCard->drillDown($this->admin())->query->count());
    }

    public function test_admin_can_see_any_team_card(): void
    {
        $card = new TeamStatCard($this->sonam(), TeamStatCard::METRIC_LEADS_CAPTURED);
        $this->assertTrue($card->isAvailableFor($this->admin()));
    }

    public function test_head_can_see_only_their_own_team_card(): void
    {
        $sonam = $this->sonam();
        $nikhil = $this->nikhil();

        $sonamCard = new TeamStatCard($sonam, TeamStatCard::METRIC_LEADS_CAPTURED);
        $nikhilCard = new TeamStatCard($nikhil, TeamStatCard::METRIC_LEADS_CAPTURED);

        $this->assertTrue($sonamCard->isAvailableFor($sonam));
        $this->assertFalse($nikhilCard->isAvailableFor($sonam));
        $this->assertTrue($nikhilCard->isAvailableFor($nikhil));
        $this->assertFalse($sonamCard->isAvailableFor($nikhil));
    }

    public function test_counsellor_cannot_see_team_cards(): void
    {
        $counsellor = User::whereHas('roles', fn ($q) => $q->where('name', 'counsellor'))->first()
            ?? User::factory()->create();
        $card = new TeamStatCard($this->sonam(), TeamStatCard::METRIC_LEADS_CAPTURED);
        $this->assertFalse($card->isAvailableFor($counsellor));
    }

    public function test_registry_generates_one_card_per_head_per_metric(): void
    {
        $headCount = User::role('head')->whereHas('teamMembers')->count();
        $ids = array_map(fn ($c) => $c->id(), CardRegistry::all());
        $teamCardIds = array_filter($ids, fn ($id) => str_starts_with($id, 'team.'));
        $this->assertCount($headCount * 3, $teamCardIds);
    }
}
