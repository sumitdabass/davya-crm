<?php

namespace Tests\Feature\Rank;

use App\Filament\Pages\RankLanding;
use App\Models\User;
use Database\Seeders\Rank\RankRoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RankLandingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RankRoleSeeder::class);
    }

    /** @test */
    public function predict_cards_groups_filter_by_role(): void
    {
        $u = User::factory()->create();
        $u->assignRole('rank-ipu-predict');
        $this->actingAs($u);

        $page = new RankLanding;
        $this->assertCount(1, $page->getPredictCards());
        $this->assertSame([], $page->getManageCards());
    }

    /** @test */
    public function landing_renders_for_authed_rank_user(): void
    {
        $u = User::factory()->create(['must_change_password' => false, 'is_active' => true]);
        $u->assignRole('rank-admin');
        $this->actingAs($u);

        $this->get('/admin/rank')->assertOk()->assertSee('Predict');
    }

    /** @test */
    public function landing_access_denied_for_no_role_user(): void
    {
        $this->assertFalse(RankLanding::canAccess());
    }
}
