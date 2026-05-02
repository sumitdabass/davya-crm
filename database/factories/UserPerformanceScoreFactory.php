<?php

namespace Database\Factories;

use App\Models\User;
use App\Models\UserPerformanceScore;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<UserPerformanceScore>
 */
class UserPerformanceScoreFactory extends Factory
{
    protected $model = UserPerformanceScore::class;

    public function definition(): array
    {
        $start = now()->startOfMonth()->toDateString();
        $end   = now()->endOfMonth()->toDateString();
        $score = $this->faker->numberBetween(0, 100);

        return [
            'user_id'           => User::factory(),
            'period_start'      => $start,
            'period_end'        => $end,
            'score'             => $score,
            'tier'              => $this->tierFor($score),
            'signal_breakdown'  => [
                'closed_won' => 0, 'deal_won_amount' => 0, 'rank_prob_avg' => 0,
                'advance_received' => 0, 'cases_captured' => 0, 'meetings_held' => 0,
                'open_leads' => 0, 'balance_amount' => 0, 'stale_open' => 0,
                'conversion_rate' => 0, 'meeting_win_rate' => 0,
                'sub_scores' => [],
            ],
            'team_max_snapshot' => [
                'closed_won' => 0, 'deal_won_amount' => 0,
                'advance_received' => 0,
            ],
            'calculated_at'     => now(),
        ];
    }

    private function tierFor(int $score): string
    {
        return match (true) {
            $score >= 85 => 'Star',
            $score >= 70 => 'Strong',
            $score >= 55 => 'Solid',
            $score >= 40 => 'Growth',
            default      => 'Coaching',
        };
    }
}
