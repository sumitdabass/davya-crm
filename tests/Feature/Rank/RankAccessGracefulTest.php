<?php

namespace Tests\Feature\Rank;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Regression: the rank access helpers run inside Filament nav rendering
 * (DtuPredict/IpuPredict::canAccess) on EVERY admin page. If the scoped
 * permissions are not seeded, the helper must return false — never throw
 * PermissionDoesNotExist, which would 500 the whole admin panel.
 *
 * Note: RankRoleSeeder is intentionally NOT seeded here.
 */
class RankAccessGracefulTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function predict_helper_returns_false_when_permission_absent(): void
    {
        $u = User::factory()->create();

        $this->assertFalse($u->canRankPredict('dtu'));
        $this->assertFalse($u->canRankPredict('ipu'));
    }

    /** @test */
    public function analyse_helper_returns_false_when_permission_absent(): void
    {
        $u = User::factory()->create();

        $this->assertFalse($u->canRankAnalyse('dtu'));
        $this->assertFalse($u->canRankAnalyse('ipu'));
    }

    /** @test */
    public function rank_datasets_is_empty_when_permissions_absent(): void
    {
        $u = User::factory()->create();

        $this->assertSame([], $u->rankDatasets());
    }
}
