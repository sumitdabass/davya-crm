<?php

namespace Tests\Feature;

use App\Filament\Pages\RankLanding;
use App\Models\User;
use App\Rank\RankRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class RankLandingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        // The rank-admin role is created by the rank module migrations; seed it
        // here for the test DB which doesn't run those.
        Role::firstOrCreate(['name' => 'rank-admin', 'guard_name' => 'web']);
    }

    private function unblock(User $u): User
    {
        $u->must_change_password = false;
        $u->save();
        return $u;
    }

    public function test_admin_sees_primary_plus_all_eight_manage_cards(): void
    {
        $admin = $this->unblock(User::where('email', 'sumit@davya.local')->firstOrFail());
        $this->actingAs($admin);

        $cards = RankRegistry::accessibleFor($admin);
        $this->assertCount(9, $cards);

        $primary = array_filter($cards, fn ($c) => ($c['group'] ?? null) === 'primary');
        $manage  = array_filter($cards, fn ($c) => ($c['group'] ?? null) === 'manage');
        $this->assertCount(1, $primary);
        $this->assertCount(8, $manage);

        $this->assertTrue(RankLanding::canAccess());

        Livewire::actingAs($admin)
            ->test(RankLanding::class)
            ->assertSee('Rank Lookup')
            ->assertSee('Universities')
            ->assertSee('Cutoffs')
            ->assertSee('Admission Processes');
    }

    public function test_rank_admin_only_user_sees_full_landing(): void
    {
        $u = User::factory()->create();
        $u->assignRole('rank-admin');
        $this->unblock($u);
        $this->actingAs($u);

        $this->assertCount(9, RankRegistry::accessibleFor($u));
        $this->assertTrue(RankLanding::canAccess());
    }

    public function test_head_cannot_access(): void
    {
        $sonam = $this->unblock(User::where('email', 'sonam@davya.local')->firstOrFail());
        $this->actingAs($sonam);

        $this->assertFalse(RankLanding::canAccess());
        $this->assertSame([], RankRegistry::accessibleFor($sonam));
    }

    public function test_member_cannot_access(): void
    {
        $nisha = $this->unblock(User::where('email', 'nisha@davya.local')->firstOrFail());
        $this->actingAs($nisha);

        $this->assertFalse(RankLanding::canAccess());
    }

    public function test_guest_cannot_access(): void
    {
        $this->assertFalse(RankLanding::canAccess());
        $this->assertSame([], RankRegistry::accessibleFor(null));
    }
}
