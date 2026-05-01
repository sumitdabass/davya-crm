<?php

namespace App\Services\Rank;

class RankPredictor
{
    public function cushionPct(int $rank, int $max): int
    {
        if ($max <= 0) {
            return 0;
        }

        return (int) round(($max - $rank) / $max * 100);
    }

    /**
     * @return 'safe'|'probable'|'reach'
     */
    public function bucket(int $rank, int $max): string
    {
        $cushion = $this->cushionPct($rank, $max);
        if ($cushion >= 25) {
            return 'safe';
        }
        if ($cushion >= 10) {
            return 'probable';
        }

        return 'reach';
    }

    /**
     * Eligibility: rank inside [min, max] AND cushion ≤ 50%.
     *
     * @param  array{min:int,max:int}  $cell
     */
    public function isEligible(int $rank, array $cell): bool
    {
        if ($rank < $cell['min'] || $rank > $cell['max']) {
            return false;
        }
        if ($this->cushionPct($rank, $cell['max']) > 50) {
            return false;
        }

        return true;
    }

    /**
     * @param  array{max:int}|null  $earlier
     * @param  array{max:int}|null  $later
     */
    public function yoyDeltaPct(?array $earlier, ?array $later): ?int
    {
        if (! $earlier || ! $later || $earlier['max'] <= 0) {
            return null;
        }

        return (int) round(($later['max'] - $earlier['max']) / $earlier['max'] * 100);
    }
}
