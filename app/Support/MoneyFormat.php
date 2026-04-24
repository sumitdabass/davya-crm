<?php

namespace App\Support;

class MoneyFormat
{
    /** Indian short: 1,25,000 → 1.25L, 80,000 → 80K, 2,00,00,000 → 2Cr. */
    public static function indianShort(float|int|null $amount): string
    {
        $n = (float) ($amount ?? 0);
        if ($n === 0.0)         { return '0'; }
        if ($n >= 1_00_00_000)  { return number_format($n / 1_00_00_000, 2) . 'Cr'; }
        if ($n >= 1_00_000)     { return number_format($n / 1_00_000, 2) . 'L'; }
        if ($n >= 1_000)        { return number_format($n / 1_000, 0) . 'K'; }
        return number_format($n, 0);
    }
}
