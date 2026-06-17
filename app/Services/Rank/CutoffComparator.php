<?php

namespace App\Services\Rank;

use App\Models\Rank\Branch;
use App\Models\Rank\Cutoff;
use App\Models\Rank\Institute;
use App\Models\Rank\University;
use App\Rank\RankDataset;

/**
 * Year-on-year cutoff comparison for a dataset, projecting the newer year's final
 * round from its latest published round using the prior year's round-to-final slide.
 *
 * Recomputed live from the cutoffs table, so it self-updates as each new round is
 * imported: while only R1 of the newer year exists it projects the final round; once
 * the actual final round lands the projection collapses to the real number.
 */
class CutoffComparator
{
    /**
     * Project a year's final-round closing rank from its latest published round,
     * scaling by the prior year's (anchor-round -> final-round) slide. Pure math.
     */
    public static function projectedFinal(int $priorAnchor, int $priorFinal, int $newerAnchor): int
    {
        if ($priorAnchor <= 0) {
            return 0;
        }

        return (int) round($newerAnchor * ($priorFinal / $priorAnchor));
    }

    /**
     * @return array{
     *   rows: array<int, array{institute:string, branch:string, prior_final:int, newer_anchor:int,
     *          anchor_round:string, projected:int, delta:int, pct:float, direction:string, is_actual:bool}>,
     *   prior_year:?int, newer_year:?int, final_round:?string, up:int, down:int, anchor_rounds:array<int,string>
     * }
     */
    public function compare(string $datasetToken, string $region, string $category, ?string $subCategory): array
    {
        $empty = ['rows' => [], 'prior_year' => null, 'newer_year' => null, 'final_round' => null, 'up' => 0, 'down' => 0, 'anchor_rounds' => []];

        $uniIds = University::whereIn('code', RankDataset::universityCodes($datasetToken))->pluck('id')->all();
        if ($uniIds === []) {
            return $empty;
        }

        $q = Cutoff::whereIn('university_id', $uniIds)->where('region', $region);
        if (RankDataset::hasCategoryDimension($datasetToken)) {
            $q->where('category', $category);
            if ($subCategory !== null) {
                $q->where('sub_category', $subCategory);
            }
        }
        $rows = $q->get(['institute_id', 'branch_id', 'year', 'round', 'max_rank']);
        if ($rows->isEmpty()) {
            return $empty;
        }

        $years = $rows->pluck('year')->map(fn ($y) => (int) $y)->unique()->sort()->values();
        if ($years->count() < 2) {
            return $empty;
        }
        $newer = (int) $years->last();
        $prior = (int) $years->get($years->count() - 2);

        // [institute|branch][year][round] = closing rank
        $idx = [];
        foreach ($rows as $c) {
            $idx[$c->institute_id.'|'.$c->branch_id][(int) $c->year][(string) $c->round] = (int) $c->max_rank;
        }

        $instN = Institute::pluck('name', 'id');
        $brN = Branch::pluck('name', 'id');
        $out = [];
        $up = $down = 0;
        $finalRound = null;
        $anchorRounds = [];

        foreach ($idx as $key => $byYear) {
            if (! isset($byYear[$prior], $byYear[$newer])) {
                continue;
            }
            [$instId, $branchId] = explode('|', $key);
            $priorR = $byYear[$prior];
            $newerR = $byYear[$newer];
            $finalR = (string) max(array_map('intval', array_keys($priorR)));   // prior year's last round
            $anchorR = (string) max(array_map('intval', array_keys($newerR)));  // newer year's latest round so far
            if (! isset($priorR[$finalR], $priorR[$anchorR], $newerR[$anchorR]) || $priorR[$anchorR] <= 0) {
                continue;
            }

            $projected = self::projectedFinal($priorR[$anchorR], $priorR[$finalR], $newerR[$anchorR]);
            $delta = $projected - $priorR[$finalR];

            $out[] = [
                'institute' => $instN[$instId] ?? '—',
                'branch' => $brN[$branchId] ?? '—',
                'prior_final' => $priorR[$finalR],
                'newer_anchor' => $newerR[$anchorR],
                'anchor_round' => $anchorR,
                'projected' => $projected,
                'delta' => $delta,
                'pct' => $priorR[$finalR] > 0 ? round(100 * $delta / $priorR[$finalR], 1) : 0.0,
                'direction' => $delta > 0 ? 'up' : ($delta < 0 ? 'down' : 'same'),
                'is_actual' => $anchorR === $finalR,
            ];
            $finalRound = $finalR;
            $anchorRounds[$anchorR] = $anchorR;
            $delta > 0 ? $up++ : ($delta < 0 ? $down++ : null);
        }

        usort($out, fn ($a, $b) => [$a['institute'], $a['newer_anchor']] <=> [$b['institute'], $b['newer_anchor']]);

        return [
            'rows' => $out, 'prior_year' => $prior, 'newer_year' => $newer,
            'final_round' => $finalRound, 'up' => $up, 'down' => $down,
            'anchor_rounds' => array_values($anchorRounds),
        ];
    }
}
