<?php

namespace App\Services\Rank;

class BenchmarkRoundStrategy
{
    /**
     * @param  string  $datasetToken  'ipu' | 'dtu'
     * @param  string  $category      'general' | 'ews' | 'obc' | 'sc' | 'st' | ...
     * @param  array<int,string>  $available  round keys present for this cell
     * @return string|null  chosen round key, or null if none available
     */
    public function pick(string $datasetToken, string $category, array $available): ?string
    {
        if ($available === []) {
            return null;
        }

        $isGeneral = mb_strtolower(trim($category)) === 'general';

        if ($datasetToken === 'ipu') {
            if ($isGeneral) {
                if (in_array('sliding', $available, true)) {
                    return 'sliding';
                }
                return $this->highestNumeric($available);
            }
            if (in_array('3', $available, true)) {
                return '3';
            }
            return $this->highestNumeric($available, 3);
        }

        return $this->highestNumeric($available);
    }

    /** Highest numeric round (optionally capped), ignoring 'sliding'. */
    private function highestNumeric(array $available, ?int $cap = null): ?string
    {
        $nums = [];
        foreach ($available as $r) {
            if (is_numeric($r) && ($cap === null || (int) $r <= $cap)) {
                $nums[] = (int) $r;
            }
        }
        if ($nums === []) {
            return null;
        }
        rsort($nums);

        return (string) $nums[0];
    }
}
