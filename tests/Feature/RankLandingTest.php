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

    private function keys(array $cards): array
    {
        return array_map(fn ($c) => $c['key'], $cards);
    }

    public function test_admin_sees_predict_manage_and_legacy_cards(): void
    {
        $admin = $this->unblock(User::where('email', 'sumit@davya.local')->firstOrFail());
        $this->actingAs($admin);

        // Legacy admins get both datasets' predict cards + 6 manage cards + the legacy lookup.
        $keys = $this->keys(RankRegistry::cardsFor($admin));
        $this->assertContains('predict-ipu', $keys);
        $this->assertContains('predict-dtu', $keys);
        $this->assertContains('manage-cutoffs', $keys);
        $this->assertContains('legacy-lookup', $keys);
        $this->assertCount(9, $keys); // 2 predict + 6 manage + 1 legacy

        $this->assertTrue(RankLanding::canAccess());

        Livewire::actingAs($admin)
            ->test(RankLanding::class)
            ->assertSee('Predict')
            ->assertSee('Universities')
            ->assertSee('Cutoffs')
            ->assertSee('IPU Rank Lookup');
    }

    public function test_rank_admin_only_user_sees_full_landing(): void
    {
        $u = User::factory()->create();
        $u->assignRole('rank-admin');
        $this->unblock($u);
        $this->actingAs($u);

        $this->assertCount(9, RankRegistry::cardsFor($u));
        $this->assertTrue(RankLanding::canAccess());
    }

    public function test_head_cannot_access(): void
    {
        $sonam = $this->unblock(User::where('email', 'sonam@davya.local')->firstOrFail());
        $this->actingAs($sonam);

        $this->assertFalse(RankLanding::canAccess());
        $this->assertSame([], RankRegistry::cardsFor($sonam));
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
        $this->assertSame([], RankRegistry::cardsFor(null));
    }
}
