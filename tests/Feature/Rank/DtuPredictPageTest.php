<?php

namespace Tests\Feature\Rank;

use App\Filament\Pages\Rank\DtuPredict;
use App\Models\User;
use App\Services\Rank\DatasetCutoffPredictor;
use App\Services\Rank\PredictorContext;
use Database\Seeders\Rank\RankRoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class DtuPredictPageTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /** @test */
    public function dtu_predict_uses_year_comparison_and_filters_within_reach(): void
    {
        // DTU shows the prior-vs-current-year comparison. Test the results logic
        // directly (no Livewire mount -> no ranks DB read).
        $mock = Mockery::mock(DatasetCutoffPredictor::class);
        $mock->shouldReceive('predictByYear')->once()
            ->with(Mockery::on(fn (PredictorContext $c) => $c->datasetToken === 'dtu' && $c->rank === 11000))
            ->andReturn(['rows' => [
                ['institute' => 'DTU', 'branch' => 'CSE', 'women_only' => false, 'cr_prior' => 11352, 'chance_prior' => 'LIKELY', 'cr_newer_r1' => 9000, 'chance_newer_r1' => 'BORDERLINE', 'cr_newer_proj' => 17000, 'chance_newer_proj' => 'SAFE', 'within_reach' => true],
                ['institute' => 'DTU', 'branch' => 'Civil', 'women_only' => false, 'cr_prior' => 500000, 'chance_prior' => 'UNLIKELY', 'cr_newer_r1' => 400000, 'chance_newer_r1' => 'UNLIKELY', 'cr_newer_proj' => 450000, 'chance_newer_proj' => 'UNLIKELY', 'within_reach' => false],
            ], 'prior_year' => 2025, 'newer_year' => 2026, 'reach_count' => 1]);
        $this->app->instance(DatasetCutoffPredictor::class, $mock);

        $page = new DtuPredict;
        $page->data = [
            'user_rank' => 11000, 'gender' => 'male', 'category' => 'general',
            'sub_category' => 'gender_neutral', 'region' => 'delhi', 'within_reach_only' => true,
        ];
        $result = $page->getResultsProperty();

        $this->assertTrue($result['submitted']);
        $this->assertTrue($result['year_comparison']);
        $this->assertSame(2025, $result['prior_year']);
        $this->assertSame(2026, $result['newer_year']);
        $this->assertCount(1, $result['rows']);          // out-of-reach Civil filtered by the toggle
        $this->assertSame('DTU', $result['rows'][0]['institute']);
        $this->assertSame(17000, $result['rows'][0]['cr_newer_proj']);
        $this->assertSame(1, $result['reach_count']);
    }

    /** @test */
    public function dtu_predict_denied_to_ipu_only_user(): void
    {
        $this->seed(RankRoleSeeder::class);
        $user = User::factory()->create();
        $user->assignRole('rank-ipu-predict');
        $this->actingAs($user);

        $this->assertFalse(DtuPredict::canAccess());
    }
}
