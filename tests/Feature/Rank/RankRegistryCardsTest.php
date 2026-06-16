<?php

namespace Tests\Feature\Rank;

use App\Models\User;
use App\Rank\RankRegistry;
use Database\Seeders\Rank\RankRoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RankRegistryCardsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RankRoleSeeder::class);
    }

    private function keys(array $cards): array
    {
        return array_map(fn ($c) => $c['key'], $cards);
    }

    /** @test */
    public function ipu_predict_role_sees_only_ipu_predict_card(): void
    {
        $u = User::factory()->create();
        $u->assignRole('rank-ipu-predict');

        $cards = RankRegistry::cardsFor($u);
        // IPU-capable users also get the interim legacy lookup; no DTU/manage cards.
        $this->assertSame(['predict-ipu', 'legacy-lookup'], $this->keys($cards));
    }

    /** @test */
    public function dtu_analyse_role_sees_manage_cards_not_predict(): void
    {
        $u = User::factory()->create();
        $u->assignRole('rank-dtu-analyse');

        $keys = $this->keys(RankRegistry::cardsFor($u));
        $this->assertContains('manage-cutoffs', $keys);
        $this->assertContains('manage-institutes', $keys);
        $this->assertNotContains('predict-dtu', $keys);
        $this->assertNotContains('predict-ipu', $keys);
    }

    /** @test */
    public function legacy_admin_sees_predict_manage_and_legacy_lookup(): void
    {
        $u = User::factory()->create();
        $u->assignRole('rank-admin');

        $keys = $this->keys(RankRegistry::cardsFor($u));
        $this->assertContains('predict-ipu', $keys);
        $this->assertContains('predict-dtu', $keys);
        $this->assertContains('manage-cutoffs', $keys);
        $this->assertContains('legacy-lookup', $keys);
    }

    /** @test */
    public function ipu_user_sees_legacy_lookup_dtu_only_user_does_not(): void
    {
        $ipu = User::factory()->create();
        $ipu->assignRole('rank-ipu-predict');
        $this->assertContains('legacy-lookup', $this->keys(RankRegistry::cardsFor($ipu)));

        $dtu = User::factory()->create();
        $dtu->assignRole('rank-dtu-predict');
        $this->assertNotContains('legacy-lookup', $this->keys(RankRegistry::cardsFor($dtu)));
    }

    /** @test */
    public function no_role_user_sees_no_cards(): void
    {
        $this->assertSame([], RankRegistry::cardsFor(User::factory()->create()));
    }
}
