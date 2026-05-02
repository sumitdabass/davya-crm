<?php

namespace Tests\Feature\Performance;

use App\Models\User;
use App\Models\UserPerformanceScore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserPerformanceScoreModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_factory_creates_valid_record(): void
    {
        $score = UserPerformanceScore::factory()->create();

        $this->assertNotNull($score->id);
        $this->assertNotNull($score->user_id);
        $this->assertGreaterThanOrEqual(0, $score->score);
        $this->assertLessThanOrEqual(100, $score->score);
        $this->assertContains($score->tier, ['Star', 'Strong', 'Solid', 'Growth', 'Coaching']);
    }

    public function test_signal_breakdown_and_team_max_snapshot_are_arrays(): void
    {
        $score = UserPerformanceScore::factory()->create([
            'signal_breakdown' => ['closed_won' => 5, 'deal_won_amount' => 100000],
            'team_max_snapshot' => ['closed_won' => 10, 'deal_won_amount' => 200000],
        ]);

        $score->refresh();

        $this->assertIsArray($score->signal_breakdown);
        $this->assertSame(5, $score->signal_breakdown['closed_won']);
        $this->assertIsArray($score->team_max_snapshot);
        $this->assertSame(10, $score->team_max_snapshot['closed_won']);
    }

    public function test_period_start_and_period_end_cast_to_date(): void
    {
        $score = UserPerformanceScore::factory()->create([
            'period_start' => '2026-05-01',
            'period_end' => '2026-05-31',
        ]);

        $this->assertSame('2026-05-01', $score->period_start->format('Y-m-d'));
        $this->assertSame('2026-05-31', $score->period_end->format('Y-m-d'));
    }

    public function test_belongs_to_user_relation(): void
    {
        $user = User::factory()->create();
        $score = UserPerformanceScore::factory()->create(['user_id' => $user->id]);

        $this->assertInstanceOf(User::class, $score->user);
        $this->assertSame($user->id, $score->user->id);
    }
}
