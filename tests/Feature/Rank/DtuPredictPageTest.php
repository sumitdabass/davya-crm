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
    public function dtu_predict_passes_dtu_context_and_filters_within_reach(): void
    {
        // Test the results logic directly (no Livewire mount -> no ranks DB read).
        $mock = Mockery::mock(DatasetCutoffPredictor::class);
        $mock->shouldReceive('predict')->once()
            ->with(Mockery::on(fn (PredictorContext $c) => $c->datasetToken === 'dtu' && $c->rank === 11000))
            ->andReturn(['rows' => [
                ['institute' => 'DTU', 'branch' => 'CSE', 'women_only' => false, 'final_round' => '5', 'final_cr' => 11352, 'r1_cr' => 9000, 'chance' => 'LIKELY'],
                ['institute' => 'DTU', 'branch' => 'Civil', 'women_only' => false, 'final_round' => '5', 'final_cr' => 500000, 'r1_cr' => 400000, 'chance' => 'UNLIKELY'],
            ], 'reach_count' => 1]);
        $this->app->instance(DatasetCutoffPredictor::class, $mock);

        $page = new DtuPredict;
        $page->data = [
            'user_rank' => 11000, 'gender' => 'male', 'category' => 'general',
            'sub_category' => 'gender_neutral', 'region' => 'delhi', 'within_reach_only' => true,
        ];
        $result = $page->getResultsProperty();

        $this->assertTrue($result['submitted']);
        $this->assertCount(1, $result['rows']);          // UNLIKELY filtered out by the toggle
        $this->assertSame('DTU', $result['rows'][0]['institute']);
        $this->assertSame('LIKELY', $result['rows'][0]['chance']);
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
