<?php

namespace App\Services\Rank;

use App\Models\Rank\Course;
use App\Models\Rank\Cutoff;
use App\Models\Rank\University;
use App\Rank\RankDataset;

class DatasetCutoffPredictor
{
    /** Sub-categories only available to female candidates. */
    private const FEMALE_ONLY_SUBS = ['girl', 'single_girl'];

    /** Institute names that admit women only. */
    private const WOMEN_ONLY_INSTITUTES = ['IGDTUW'];

    public function __construct(
        private RankPredictor $predictor = new RankPredictor,
        private BenchmarkRoundStrategy $rounds = new BenchmarkRoundStrategy,
    ) {}

    /**
     * @return array{rows: array<int, array{institute:string, branch:string, chance:string, final_round:string, final_cr:int, r1_cr:int, women_only:bool}>, reach_count:int}
     */
    public function predict(PredictorContext $ctx): array
    {
        $empty = ['rows' => [], 'reach_count' => 0];
        if ($ctx->rank <= 0) {
            return $empty;
        }

        if ($ctx->isMale() && in_array($ctx->subCategory, self::FEMALE_ONLY_SUBS, true)) {
            return $empty;
        }

        $universityIds = University::whereIn('code', RankDataset::universityCodes($ctx->datasetToken))
            ->pluck('id')->all();
        if ($universityIds === []) {
            return $empty;
        }

        $courseId = $ctx->courseId;
        if (RankDataset::courseFixedToBtech($ctx->datasetToken)) {
            $courseId = Course::whereIn('university_id', $universityIds)->where('name', 'B.Tech')->value('id');
        }
        if (! $courseId) {
            return $empty;
        }

        $query = Cutoff::with(['institute', 'branch'])
            ->whereIn('university_id', $universityIds)
            ->where('course_id', $courseId)
            ->where('region', $ctx->region);
        // Category / sub_category only apply to datasets that carry that breakdown
        // (DTU/JAC). IPU's legacy cutoffs have NULL category columns, so filtering on
        // them would exclude every row.
        if (RankDataset::hasCategoryDimension($ctx->datasetToken)) {
            $query->where('category', $ctx->category);
            if ($ctx->subCategory !== null) {
                $query->where('sub_category', $ctx->subCategory);
            }
        }
        if ($ctx->branchIds !== null) {
            $query->whereIn('branch_id', $ctx->branchIds);
        }
        if ($ctx->year !== null) {
            $query->where('year', $ctx->year);
        }

        $cutoffs = $query->get();
        if ($ctx->year === null) {
            if (RankDataset::usesPerInstituteYear($ctx->datasetToken)) {
                // JAC institutes publish round cutoffs independently — each uses its
                // OWN latest available year, so one that hasn't posted the newest year
                // yet (e.g. IGDTUW R1 2026) keeps showing its prior year instead of
                // vanishing while DTU/NSUT advance.
                $maxYear = [];
                foreach ($cutoffs as $c) {
                    $maxYear[$c->institute_id] = max($maxYear[$c->institute_id] ?? 0, (int) $c->year);
                }
                $cutoffs = $cutoffs->filter(fn ($c) => (int) $c->year === $maxYear[$c->institute_id]);
            } else {
                // IPU: one counselling cycle — a single dataset-wide latest year.
                $max = 0;
                foreach ($cutoffs as $c) {
                    $max = max($max, (int) $c->year);
                }
                $cutoffs = $cutoffs->filter(fn ($c) => (int) $c->year === $max);
            }
        }

        $groups = [];
        foreach ($cutoffs as $c) {
            $instName = $c->institute?->name ?? '—';
            if ($ctx->isMale() && in_array($instName, self::WOMEN_ONLY_INSTITUTES, true)) {
                continue;
            }
            $key = $c->institute_id.'|'.$c->branch_id;
            $groups[$key] ??= [
                'institute' => $instName,
                'branch' => $c->branch?->name ?? '—',
                'women_only' => in_array($instName, self::WOMEN_ONLY_INSTITUTES, true),
                'rounds' => [],
            ];
            $groups[$key]['rounds'][$c->round] = (int) $c->max_rank;
        }

        $rows = [];
        foreach ($groups as $g) {
            $present = array_keys($g['rounds']);
            $round = $this->rounds->pick($ctx->datasetToken, $ctx->category, $present);
            if ($round === null) {
                continue;
            }
            $cr = $g['rounds'][$round];
            $r1 = $g['rounds']['1'] ?? $g['rounds'][min($present)] ?? $cr;
            $rows[] = [
                'institute' => $g['institute'],
                'branch' => $g['branch'],
                'women_only' => $g['women_only'],
                'final_round' => $round,
                'final_cr' => $cr,
                'r1_cr' => $r1,
                'chance' => $this->predictor->chance($ctx->rank, $cr),
            ];
        }

        usort($rows, fn ($a, $b) => $a['final_cr'] <=> $b['final_cr']);
        $reach = count(array_filter($rows, fn ($r) => $r['chance'] !== 'UNLIKELY'));

        return ['rows' => $rows, 'reach_count' => $reach];
    }
}
