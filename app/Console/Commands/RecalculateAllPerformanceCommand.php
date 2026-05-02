<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Models\UserPerformanceScore;
use App\Services\Performance\Scorer;
use App\Services\Performance\SignalCollector;
use App\Services\Performance\SignalSet;
use App\Services\Performance\TeamMaxes;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class RecalculateAllPerformanceCommand extends Command
{
    protected $signature = 'performance:recalculate {--month= : YYYY-MM (defaults to current month)}';

    protected $description = 'Recompute user performance scores for the given month. Idempotent — upserts on (user_id, period_start).';

    public function handle(): int
    {
        $period = $this->resolvePeriod();
        [$start, $end] = $period;

        $config = config('performance');
        $collector = new SignalCollector(
            terminalStages: $config['terminal_stages'],
            staleThresholdDays: (int) $config['stale_threshold_days'],
        );
        $scorer = new Scorer($config);

        // Pass 1: scoring set = active users who own ≥1 student
        $userIds = User::query()
            ->where('is_active', true)
            ->whereExists(function ($q) {
                $q->select(DB::raw(1))
                  ->from('students')
                  ->whereColumn('students.owner_id', 'users.id');
            })
            ->orderBy('id')
            ->pluck('id');

        if ($userIds->isEmpty()) {
            $this->info('No active users with owned students; nothing to recalculate.');
            return self::SUCCESS;
        }

        $rawSignals = [];
        $usersById = User::whereIn('id', $userIds)->get()->keyBy('id');
        foreach ($userIds as $id) {
            $rawSignals[$id] = $collector->collect($usersById[$id], $start, $end);
        }

        // Pass 2: team max + team avg
        $teamMax = $this->computeTeamMax($rawSignals);
        $teamAvg = $this->computeTeamAvg($rawSignals, (int) $config['min_sample_floor']);

        // Pass 3: score + upsert
        $written = 0;
        foreach ($rawSignals as $userId => $signals) {
            $result = $scorer->score($signals, $teamMax, $teamAvg);

            UserPerformanceScore::updateOrCreate(
                ['user_id' => $userId, 'period_start' => $start],
                [
                    'period_end'       => $end,
                    'score'            => $result->score,
                    'tier'             => $result->tier,
                    'signal_breakdown' => $result->breakdown,
                    'team_max_snapshot'=> $teamMax->toArray(),
                    'calculated_at'    => now(),
                ]
            );
            $written++;
        }

        $this->info("Recalculated $written user(s) for period {$start->toDateString()} – {$end->toDateString()}");
        return self::SUCCESS;
    }

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function resolvePeriod(): array
    {
        $monthOpt = $this->option('month');
        if ($monthOpt) {
            $start = CarbonImmutable::createFromFormat('Y-m-d', $monthOpt . '-01')->startOfMonth();
        } else {
            $start = CarbonImmutable::now('Asia/Kolkata')->startOfMonth();
        }
        return [$start, $start->endOfMonth()];
    }

    /**
     * @param array<int, SignalSet> $rawSignals
     */
    private function computeTeamMax(array $rawSignals): TeamMaxes
    {
        $maxClosedWon = 0;
        $maxDealWon   = 0;
        $maxAdvance   = 0;
        foreach ($rawSignals as $s) {
            $maxClosedWon = max($maxClosedWon, $s->closedWon);
            $maxDealWon   = max($maxDealWon,   $s->dealWonAmount);
            $maxAdvance   = max($maxAdvance,   $s->advanceReceived);
        }
        return new TeamMaxes($maxClosedWon, $maxDealWon, $maxAdvance);
    }

    /**
     * @param array<int, SignalSet> $rawSignals
     * @return array{conversion_rate: float, meeting_win_rate: float}
     */
    private function computeTeamAvg(array $rawSignals, int $floor): array
    {
        $convRates = [];
        $mwRates   = [];
        foreach ($rawSignals as $s) {
            if (($s->casesCaptured + $s->meetingsHeld) < $floor) {
                continue;
            }
            $convRates[] = $s->casesCaptured > 0 ? ($s->closedWon / $s->casesCaptured) * 100 : 0;
            $mwRates[]   = $s->meetingsHeld > 0  ? ($s->closedWon / $s->meetingsHeld)  * 100 : 0;
        }
        return [
            'conversion_rate'  => $convRates ? array_sum($convRates) / count($convRates) : 0.0,
            'meeting_win_rate' => $mwRates   ? array_sum($mwRates)   / count($mwRates)   : 0.0,
        ];
    }
}
