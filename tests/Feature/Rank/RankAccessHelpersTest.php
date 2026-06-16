<?php

namespace Tests\Feature\Rank;

use App\Models\User;
use Database\Seeders\Rank\RankRoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RankAccessHelpersTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RankRoleSeeder::class);
    }

    /** @test */
    public function predict_only_ipu_user_can_predict_ipu_not_dtu(): void
    {
        $u = User::factory()->create();
        $u->assignRole('rank-ipu-predict');

        $this->assertTrue($u->canRankPredict('ipu'));
        $this->assertFalse($u->canRankAnalyse('ipu'));
        $this->assertFalse($u->canRankPredict('dtu'));
        $this->assertSame(['ipu'], $u->rankDatasets());
    }

    /** @test */
    public function dtu_analyse_user_sees_only_dtu(): void
    {
        $u = User::factory()->create();
        $u->assignRole('rank-dtu-analyse');

        $this->assertTrue($u->canRankAnalyse('dtu'));
        $this->assertFalse($u->canRankPredict('dtu'));
        $this->assertSame(['dtu'], $u->rankDatasets());
    }

    /** @test */
    public function user_with_both_datasets_lists_both(): void
    {
        $u = User::factory()->create();
        $u->assignRole(['rank-ipu-predict', 'rank-dtu-analyse']);

        $this->assertSame(['ipu', 'dtu'], $u->rankDatasets());
    }
}
