<?php

namespace App\Books\Services;

use App\Models\Book\Asset;
use App\Models\Book\FiscalYear;
use Carbon\CarbonImmutable;

class DepreciationCalculator
{
    public function yearlyDepFor(Asset $asset, FiscalYear $fy): float
    {
        $start = CarbonImmutable::parse($fy->start_date);
        $end = CarbonImmutable::parse($fy->end_date);
        $depStart = CarbonImmutable::parse($asset->dep_started_at);

        if ($depStart->greaterThan($end)) {
            return 0.0;
        }

        $effectiveStart = $depStart->greaterThan($start) ? $depStart : $start;
        // Carbon 3's diffInDays returns a signed float; use abs() to keep day counts positive.
        $daysInFy = (int) abs($end->diffInDays($start)) + 1;
        $effectiveDays = (int) abs($end->diffInDays($effectiveStart)) + 1;
        $rate = (float) $asset->dep_percent / 100.0;

        if ($asset->method === 'wdv') {
            $bookValueAtStart = $this->bookValueAtEndOfPriorTo($asset, $fy);
            $proration = min(1.0, $effectiveDays / $daysInFy);

            return round($bookValueAtStart * $rate * $proration, 2);
        }

        // straight line
        $proration = min(1.0, $effectiveDays / $daysInFy);

        return round((float) $asset->original_value * $rate * $proration, 2);
    }

    public function accumulatedDepThrough(Asset $asset, FiscalYear $through): float
    {
        $accum = 0.0;
        $priorYears = FiscalYear::where('company_id', $through->company_id)
            ->where('end_date', '<=', $through->end_date)
            ->where('start_date', '>=', $asset->dep_started_at)
            ->orderBy('start_date')->get();
        foreach ($priorYears as $fy) {
            $accum += $this->yearlyDepFor($asset, $fy);
        }

        return round($accum, 2);
    }

    public function bookValueAtEndOf(Asset $asset, FiscalYear $fy): float
    {
        return round(
            (float) $asset->original_value - $this->accumulatedDepThrough($asset, $fy),
            2
        );
    }

    private function bookValueAtEndOfPriorTo(Asset $asset, FiscalYear $fy): float
    {
        $prior = FiscalYear::where('company_id', $fy->company_id)
            ->where('end_date', '<', $fy->start_date)
            ->orderByDesc('end_date')->first();
        if (! $prior) {
            return (float) $asset->original_value;
        }

        return $this->bookValueAtEndOf($asset, $prior);
    }
}
