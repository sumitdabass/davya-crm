<?php

namespace Tests\Unit\Performance;

use App\Services\Performance\Scorer;
use App\Services\Performance\SignalSet;
use App\Services\Performance\TeamMaxes;
use PHPUnit\Framework\TestCase;

class ScorerTest extends TestCase
{
    private Scorer $scorer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->scorer = new Scorer($this->config());
    }

    public function test_zero_signals_against_zero_team_max_yields_coaching_tier(): void
    {
        $signals = SignalSet::empty();
        $teamMax = TeamMaxes::empty();

        $result = $this->scorer->score($signals, $teamMax);

        // pipeline_health base 100 with no penalties × 10% weight = 10 points;
        // everything else is 0. Result: low single-digit-tens score, Coaching tier.
        $this->assertLessThanOrEqual(15, $result->score);
        $this->assertSame('Coaching', $result->tier);
    }

    public function test_top_performer_in_team_of_one_scores_highest(): void
    {
        // 5 wins, 500k deal, 80% rank prob, 200k advance, 10 cases, 8 meetings, 2 open, 0 balance, 0 stale
        $signals = new SignalSet(
            closedWon: 5,
            dealWonAmount: 500_000,
            rankProbAvg: 80,
            advanceReceived: 200_000,
            casesCaptured: 10,
            meetingsHeld: 8,
            openLeads: 2,
            balanceAmount: 0,
            staleOpen: 0,
        );

        $teamMax = new TeamMaxes(
            closedWon: 5,
            dealWonAmount: 500_000,
            advanceReceived: 200_000,
        );

        $result = $this->scorer->score($signals, $teamMax);

        // 25% × 100 (won_norm) + 25% × 100 (deal_norm) + 15% × 80 (rank_prob)
        // + 10% × 100 (advance_norm) + 10% × 50 (conv: 5/10) + 5% × 62.5 (meeting: 5/8)
        // + 10% × pipeline_health
        // pipeline_health = 100 − 0 (balance/0 won handling) − 0 + min(10, 2/2=1) → ~101 clamped to 100
        // = 25 + 25 + 12 + 10 + 5 + 3.125 + 10 = 90.125 → ~90
        $this->assertGreaterThanOrEqual(85, $result->score);
        $this->assertContains($result->tier, ['Star', 'Strong']);
    }

    public function test_min_sample_floor_falls_back_to_team_avg(): void
    {
        // User with 0 cases, 0 meetings — no denominator data, conversion + meeting rates undefined
        $signals = new SignalSet(
            closedWon: 0,
            dealWonAmount: 0,
            rankProbAvg: 50,
            advanceReceived: 0,
            casesCaptured: 0,
            meetingsHeld: 0,
            openLeads: 0,
            balanceAmount: 0,
            staleOpen: 0,
        );
        $teamMax = TeamMaxes::empty();
        $teamAvg = ['conversion_rate' => 30, 'meeting_win_rate' => 40];

        $result = $this->scorer->score($signals, $teamMax, $teamAvg);

        $this->assertSame(30.0, $result->breakdown['sub_scores']['conversion_rate_norm']);
        $this->assertSame(40.0, $result->breakdown['sub_scores']['meeting_win_rate_norm']);
    }

    public function test_above_floor_uses_actual_rates(): void
    {
        $signals = new SignalSet(
            closedWon: 2,
            dealWonAmount: 100_000,
            rankProbAvg: 50,
            advanceReceived: 50_000,
            casesCaptured: 4,    // conversion = 2/4 = 50%
            meetingsHeld: 4,     // meeting_win = 2/4 = 50%; cases+meetings = 8 ≥ 3
            openLeads: 0,
            balanceAmount: 0,
            staleOpen: 0,
        );
        $teamMax = new TeamMaxes(
            closedWon: 2,
            dealWonAmount: 100_000,
            advanceReceived: 50_000,
        );

        $result = $this->scorer->score($signals, $teamMax, ['conversion_rate' => 99, 'meeting_win_rate' => 99]);

        // Above floor → uses 50/50, ignores team avg
        $this->assertSame(50.0, $result->breakdown['sub_scores']['conversion_rate_norm']);
        $this->assertSame(50.0, $result->breakdown['sub_scores']['meeting_win_rate_norm']);
    }

    public function test_pipeline_health_balance_penalty(): void
    {
        // High balance vs low won = collection failure
        $signals = new SignalSet(
            closedWon: 1,
            dealWonAmount: 100_000,
            rankProbAvg: 0,
            advanceReceived: 0,
            casesCaptured: 1,
            meetingsHeld: 0,
            openLeads: 0,
            balanceAmount: 500_000,   // 5× deal_won → ratio 5.0 × 30 = 150 → capped at 50
            staleOpen: 0,
        );
        $teamMax = new TeamMaxes(closedWon: 1, dealWonAmount: 100_000, advanceReceived: 0);

        $result = $this->scorer->score($signals, $teamMax);
        $ph = $result->breakdown['sub_scores']['pipeline_health'];

        // base 100 − 50 (cap) − 0 stale + 0 open bonus = 50
        $this->assertSame(50.0, $ph);
    }

    public function test_pipeline_health_stale_penalty(): void
    {
        $signals = new SignalSet(
            closedWon: 0, dealWonAmount: 0, rankProbAvg: 0, advanceReceived: 0,
            casesCaptured: 0, meetingsHeld: 0,
            openLeads: 5, balanceAmount: 0, staleOpen: 5,
        );
        $teamMax = TeamMaxes::empty();

        $result = $this->scorer->score($signals, $teamMax);
        $ph = $result->breakdown['sub_scores']['pipeline_health'];

        // base 100 − 0 balance − min(20, 5×5=25)=20 + min(10, 5/2=2)=2 = 82
        $this->assertSame(82.0, $ph);
    }

    public function test_tier_boundaries(): void
    {
        $cases = [
            [85, 'Star'],
            [84, 'Strong'],
            [70, 'Strong'],
            [69, 'Solid'],
            [55, 'Solid'],
            [54, 'Growth'],
            [40, 'Growth'],
            [39, 'Coaching'],
            [0,  'Coaching'],
        ];

        foreach ($cases as [$score, $expectedTier]) {
            $this->assertSame(
                $expectedTier,
                $this->scorer->tierFor($score),
                "score=$score expected $expectedTier"
            );
        }
    }

    public function test_normalize_with_zero_team_max_returns_zero(): void
    {
        $signals = new SignalSet(
            closedWon: 5, dealWonAmount: 100_000, rankProbAvg: 0, advanceReceived: 0,
            casesCaptured: 0, meetingsHeld: 0,
            openLeads: 0, balanceAmount: 0, staleOpen: 0,
        );
        // team max 0 → normalize returns 0 even if user has nonzero raw value
        $teamMax = TeamMaxes::empty();

        $result = $this->scorer->score($signals, $teamMax);

        $this->assertSame(0.0, $result->breakdown['sub_scores']['closed_won_norm']);
        $this->assertSame(0.0, $result->breakdown['sub_scores']['deal_won_norm']);
    }

    public function test_breakdown_includes_all_raw_signals_and_sub_scores(): void
    {
        $signals = new SignalSet(
            closedWon: 3, dealWonAmount: 200_000, rankProbAvg: 60, advanceReceived: 80_000,
            casesCaptured: 6, meetingsHeld: 5, openLeads: 4, balanceAmount: 50_000, staleOpen: 1,
        );
        $teamMax = new TeamMaxes(closedWon: 5, dealWonAmount: 400_000, advanceReceived: 100_000);

        $result = $this->scorer->score($signals, $teamMax);

        foreach ([
            'closed_won', 'deal_won_amount', 'rank_prob_avg', 'advance_received',
            'cases_captured', 'meetings_held', 'open_leads', 'balance_amount', 'stale_open',
            'conversion_rate', 'meeting_win_rate',
        ] as $k) {
            $this->assertArrayHasKey($k, $result->breakdown, "missing key: $k");
        }
        $this->assertArrayHasKey('sub_scores', $result->breakdown);
        foreach ([
            'closed_won_norm', 'deal_won_norm', 'rank_prob_avg_norm', 'advance_received_norm',
            'conversion_rate_norm', 'meeting_win_rate_norm', 'pipeline_health',
        ] as $k) {
            $this->assertArrayHasKey($k, $result->breakdown['sub_scores'], "missing sub_score: $k");
        }
    }

    private function config(): array
    {
        return [
            'tiers' => [
                ['min' => 85, 'label' => 'Star'],
                ['min' => 70, 'label' => 'Strong'],
                ['min' => 55, 'label' => 'Solid'],
                ['min' => 40, 'label' => 'Growth'],
                ['min' => 0, 'label' => 'Coaching'],
            ],
            'weights' => [
                'closed_won' => 0.25,
                'deal_won_amount' => 0.25,
                'rank_prob_avg' => 0.15,
                'advance_received' => 0.10,
                'conversion_rate' => 0.10,
                'meeting_win_rate' => 0.05,
                'pipeline_health' => 0.10,
            ],
            'pipeline_health' => [
                'balance_penalty_factor' => 30,
                'balance_penalty_cap' => 50,
                'stale_penalty_per_lead' => 5,
                'stale_penalty_cap' => 20,
                'open_bonus_per_two' => 1,
                'open_bonus_cap' => 10,
            ],
            'min_sample_floor' => 3,
        ];
    }
}
