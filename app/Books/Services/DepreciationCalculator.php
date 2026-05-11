<?php

namespace App\Books\Services;

use App\Models\Book\Asset;
use App\Models\Book\FiscalYear;

class DepreciationCalculator
{
    public function yearlyDepFor(Asset $asset, FiscalYear $fy): float
    {
        return 0.0; // stub — filled in Task 12
    }

    public function accumulatedDepThrough(Asset $asset, FiscalYear $fy): float
    {
        return 0.0;
    }

    public function bookValueAtEndOf(Asset $asset, FiscalYear $fy): float
    {
        return (float) $asset->original_value;
    }
}
