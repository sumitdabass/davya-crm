<?php

namespace App\Services\Performance;

final class Scorer
{
    public function __construct(private readonly array $config) {}

    /**
     * @param  array{conversion_rate?: float, meeting_win_rate?: float}  $teamAvg
     *                                                                             Used as fallback when min-sample floor not met.
     */
    public function score(SignalSet $signals, TeamMaxes $teamMax, array $teamAvg = []): ScoreResult
    {
        $weights = $this->config['weights'];
        $floor = (int) $this->config['min_sample_floor'];

        $closedWonNorm = $this->normalize($signals->closedWon, $teamMax->closedWon);
        $dealWonNorm = $this->normalize($signals->dealWonAmount, $teamMax->dealWonAmount);
        $rankProbNorm = (float) max(0, min(100, $signals->rankProbAvg));
        $advanceNorm = $this->normalize($signals->advanceReceived, $teamMax->advanceReceived);

        $sampleSize = $signals->casesCaptured + $signals->meetingsHeld;

        if ($sampleSize >= $floor) {
            $conversionRate = $signals->casesCaptured > 0
                ? $signals->closedWon / $signals->casesCaptured
                : 0.0;
            $meetingWinRate = $signals->meetingsHeld > 0
                ? $signals->closedWon / $signals->meetingsHeld
                : 0.0;
            $conversionNorm = (float) max(0, min(100, $conversionRate * 100));
            $meetingWinNorm = (float) max(0, min(100, $meetingWinRate * 100));
        } else {
            $conversionRate = 0.0;
            $meetingWinRate = 0.0;
            $conversionNorm = (float) max(0, min(100, (float) ($teamAvg['conversion_rate'] ?? 0)));
            $meetingWinNorm = (float) max(0, min(100, (float) ($teamAvg['meeting_win_rate'] ?? 0)));
        }

        $pipelineHealth = $this->pipelineHealth($signals);

        $score = $weights['closed_won'] * $closedWonNorm
               + $weights['deal_won_amount'] * $dealWonNorm
               + $weights['rank_prob_avg'] * $rankProbNorm
               + $weights['advance_received'] * $advanceNorm
               + $weights['conversion_rate'] * $conversionNorm
               + $weights['meeting_win_rate'] * $meetingWinNorm
               + $weights['pipeline_health'] * $pipelineHealth;

        $finalScore = (int) max(0, min(100, round($score)));
        $tier = $this->tierFor($finalScore);

        $breakdown = $signals->toArray() + [
            'conversion_rate' => round($conversionRate, 4),
            'meeting_win_rate' => round($meetingWinRate, 4),
            'sub_scores' => [
                'closed_won_norm' => $closedWonNorm,
                'deal_won_norm' => $dealWonNorm,
                'rank_prob_avg_norm' => $rankProbNorm,
                'advance_received_norm' => $advanceNorm,
                'conversion_rate_norm' => $conversionNorm,
                'meeting_win_rate_norm' => $meetingWinNorm,
                'pipeline_health' => $pipelineHealth,
            ],
        ];

        return new ScoreResult($finalScore, $tier, $breakdown);
    }

    public function tierFor(int $score): string
    {
        foreach ($this->config['tiers'] as $tier) {
            if ($score >= $tier['min']) {
                return $tier['label'];
            }
        }

        return 'Coaching';
    }

    private function normalize(int $value, int $teamMax): float
    {
        if ($teamMax <= 0) {
            return 0.0;
        }

        return (float) max(0, min(100, ($value / $teamMax) * 100));
    }

    private function pipelineHealth(SignalSet $signals): float
    {
        $cfg = $this->config['pipeline_health'];

        $balanceRatio = $signals->dealWonAmount > 0
            ? $signals->balanceAmount / $signals->dealWonAmount
            : ($signals->balanceAmount > 0 ? PHP_INT_MAX : 0);
        $balancePenalty = min((float) $cfg['balance_penalty_cap'], $balanceRatio * $cfg['balance_penalty_factor']);

        $stalePenalty = min((float) $cfg['stale_penalty_cap'], $signals->staleOpen * $cfg['stale_penalty_per_lead']);

        $openBonus = min((float) $cfg['open_bonus_cap'], floor($signals->openLeads / 2) * $cfg['open_bonus_per_two']);

        $health = 100.0 - $balancePenalty - $stalePenalty + $openBonus;

        return (float) max(0, min(100, $health));
    }
}
