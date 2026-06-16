<?php

namespace Tests\Feature\Rank;

use App\Models\User;
use App\Rank\RankAccess;
use Database\Seeders\Rank\RankRoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RankAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RankRoleSeeder::class);
    }

    /** @test */
    public function scoped_predict_role_exposes_only_that_dataset_predict(): void
    {
        $u = User::factory()->create();
        $u->assignRole('rank-ipu-predict');

        $this->assertSame(['ipu'], RankAccess::predictableDatasets($u));
        $this->assertSame([], RankAccess::analysableDatasets($u));
        $this->assertTrue(RankAccess::canSeeAnyRankTool($u));
        $this->assertSame([], RankAccess::analysableUniversityCodes($u));
    }

    /** @test */
    public function scoped_analyse_role_exposes_dataset_codes(): void
    {
        $u = User::factory()->create();
        $u->assignRole('rank-dtu-analyse');

        $this->assertSame(['dtu'], RankAccess::analysableDatasets($u));
        $this->assertSame(['JAC'], RankAccess::analysableUniversityCodes($u));
    }

    /** @test */
    public function legacy_admin_gets_all_datasets_and_codes(): void
    {
        $u = User::factory()->create();
        $u->assignRole('rank-admin');

        $this->assertSame(['ipu', 'dtu'], RankAccess::predictableDatasets($u));
        $this->assertSame(['ipu', 'dtu'], RankAccess::analysableDatasets($u));
        $this->assertEqualsCanonicalizing(['IPU', 'JAC'], RankAccess::analysableUniversityCodes($u));
    }

    /** @test */
    public function no_rank_role_user_sees_nothing(): void
    {
        $u = User::factory()->create();

        $this->assertFalse(RankAccess::canSeeAnyRankTool($u));
        $this->assertSame([], RankAccess::predictableDatasets($u));
        $this->assertSame([], RankAccess::analysableDatasets($u));
        $this->assertFalse(RankAccess::isLegacyAdmin($u));
    }
}
