<?php

namespace App\Services\Rank;

use App\Models\Rank\Course;
use App\Models\Rank\Cutoff;
use App\Models\Rank\QualifyingExam;
use App\Models\Rank\University;
use App\Models\Student;

class StudentChoicePredictor
{
    public function __construct(private RankPredictor $predictor = new RankPredictor) {}

    /**
     * @return array<int, array{rank:int, college:string, branch:string, probability_pct:int, bucket:string}>
     */
    public function topChoices(Student $student, int $limit = 3): array
    {
        $rank = (int) ($student->rank ?? 0);
        if ($rank <= 0) {
            return [];
        }

        $region = $this->mapRegion($student->category);
        $predictionRound = $region === 'delhi' ? 'sliding' : '3';
        $predictionRegion = 'delhi'; // matches the RankLookup convention — delhi cutoffs are the predictor signal

        $ipu = University::where('code', 'IPU')->first();
        $btech = $ipu ? Course::where('university_id', $ipu->id)->where('name', 'B.Tech')->first() : null;
        $jee = QualifyingExam::where('code', 'JEE_MAIN')->first();
        if (! $ipu || ! $btech || ! $jee) {
            return [];
        }

        $year = (int) (Cutoff::where('university_id', $ipu->id)->where('course_id', $btech->id)->max('year') ?? 0);
        if ($year === 0) {
            return [];
        }

        $cutoffs = Cutoff::with(['institute', 'branch'])
            ->where('university_id', $ipu->id)
            ->where('course_id', $btech->id)
            ->where('qualifying_exam_id', $jee->id)
            ->where('year', $year)
            ->where('region', $predictionRegion)
            ->get();

        $byKey = [];
        foreach ($cutoffs as $c) {
            $key = $c->institute_id.'|'.$c->branch_id.'|'.($c->shift ?? '');
            if (! isset($byKey[$key])) {
                $byKey[$key] = [
                    'institute' => $c->institute?->name ?? '—',
                    'branch'    => $c->branch?->name ?? '—',
                    'rounds'    => ['1' => null, '2' => null, '3' => null, 'sliding' => null],
                ];
            }
            $byKey[$key]['rounds'][$c->round] = ['min' => (int) $c->min_rank, 'max' => (int) $c->max_rank];
        }

        $eligible = [];
        foreach ($byKey as $row) {
            $cell = $row['rounds'][$predictionRound] ?? null;
            if (! $cell || ! $this->predictor->isEligible($rank, $cell)) {
                continue;
            }
            $cushion = $this->predictor->cushionPct($rank, $cell['max']);
            $eligible[] = [
                'institute'        => $row['institute'],
                'branch'           => $row['branch'],
                'prediction_max'   => $cell['max'],
                'cushion_pct'      => $cushion,
                'bucket'           => $this->predictor->bucket($rank, $cell['max']),
                'priority'         => CollegePreferenceOrder::sortKey($row['institute']),
                'probability_pct'  => $this->probabilityFromCushion($cushion),
            ];
        }

        if ($eligible === []) {
            return [];
        }

        usort($eligible, function (array $a, array $b): int {
            return $a['priority'] <=> $b['priority']
                ?: $a['prediction_max'] <=> $b['prediction_max']
                ?: strcasecmp($a['institute'], $b['institute']);
        });

        // Pick the toughest-cutoff branch per college so each Choice slot
        // surfaces a distinct college. Without this, USICT's CSE/IT/EEE
        // can fill all 3 slots and the operator never sees the next-best
        // college.
        $perCollege = [];
        foreach ($eligible as $row) {
            $key = $row['institute'];
            if (! isset($perCollege[$key])) {
                $perCollege[$key] = $row;
            }
        }
        $top = array_slice(array_values($perCollege), 0, $limit);

        $out = [];
        foreach ($top as $i => $row) {
            $out[] = [
                'rank'            => $i + 1,
                'college'         => $row['institute'],
                'branch'          => $row['branch'],
                'probability_pct' => $row['probability_pct'],
                'bucket'          => $row['bucket'],
            ];
        }

        return $out;
    }

    private function mapRegion(?string $category): string
    {
        return match (mb_strtolower(trim((string) $category))) {
            'delhi'          => 'delhi',
            'outside',
            'outside delhi',
            'outside_delhi'  => 'outside_delhi',
            default          => 'delhi',
        };
    }

    /**
     * Cushion → display probability. Filtering already removed rank > max.
     * Range maps cushion 0–50 onto probability ~5–95 so all four colour
     * buckets in the peek drawer can actually fire:
     *   ≤ 10% → red, ≤ 30% → yellow, ≤ 60% → orange, > 60% → green.
     *   cushion 0  → 5   (red, borderline-eligible)
     *   cushion 5  → 10  (red boundary)
     *   cushion 15 → 30  (yellow boundary)
     *   cushion 30 → 60  (orange boundary)
     *   cushion 50 → 95  (green, very safe)
     */
    private function probabilityFromCushion(int $cushion): int
    {
        return (int) round(min(95, max(5, $cushion * 2)));
    }
}
