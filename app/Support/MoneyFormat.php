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

    /**
     * Indian English words for a Rupee amount.
     *   125000  → "One Lakh Twenty Five Thousand Rupees"
     *   1234.50 → "One Thousand Two Hundred Thirty Four Rupees and Fifty Paise"
     * Falls back to "Zero Rupees" on null/zero/negative.
     */
    public static function toIndianWords(float|int|null $amount): string
    {
        $value = (float) ($amount ?? 0);
        if ($value <= 0) {
            return 'Zero Rupees';
        }
        $rupees = (int) floor($value);
        $paise  = (int) round(($value - $rupees) * 100);
        // Carry overflow when 99.995 → 99.00 + 100 paise.
        if ($paise === 100) {
            $rupees++;
            $paise = 0;
        }
        $words = self::indianIntegerWords($rupees).' Rupees';
        if ($paise > 0) {
            $words .= ' and '.self::twoDigitWords($paise).' Paise';
        }

        return $words;
    }

    private static function indianIntegerWords(int $n): string
    {
        if ($n === 0) {
            return 'Zero';
        }
        $parts = [];
        $crore = intdiv($n, 1_00_00_000);
        $n %= 1_00_00_000;
        $lakh = intdiv($n, 1_00_000);
        $n %= 1_00_000;
        $thousand = intdiv($n, 1_000);
        $n %= 1_000;
        $hundred = intdiv($n, 100);
        $rest = $n % 100;

        if ($crore) {
            $parts[] = ($crore > 99 ? self::indianIntegerWords($crore) : self::twoDigitWords($crore)).' Crore';
        }
        if ($lakh) {
            $parts[] = self::twoDigitWords($lakh).' Lakh';
        }
        if ($thousand) {
            $parts[] = self::twoDigitWords($thousand).' Thousand';
        }
        if ($hundred) {
            $parts[] = self::twoDigitWords($hundred).' Hundred';
        }
        if ($rest) {
            $parts[] = self::twoDigitWords($rest);
        }

        return implode(' ', $parts);
    }

    private static function twoDigitWords(int $n): string
    {
        static $ones = [
            0 => '',          1 => 'One',       2 => 'Two',       3 => 'Three',
            4 => 'Four',      5 => 'Five',      6 => 'Six',       7 => 'Seven',
            8 => 'Eight',     9 => 'Nine',      10 => 'Ten',      11 => 'Eleven',
            12 => 'Twelve',   13 => 'Thirteen', 14 => 'Fourteen', 15 => 'Fifteen',
            16 => 'Sixteen',  17 => 'Seventeen',18 => 'Eighteen', 19 => 'Nineteen',
        ];
        static $tens = [
            0 => '',     2 => 'Twenty', 3 => 'Thirty', 4 => 'Forty',
            5 => 'Fifty', 6 => 'Sixty', 7 => 'Seventy', 8 => 'Eighty', 9 => 'Ninety',
        ];
        if ($n < 20) {
            return $ones[$n];
        }
        $t = intdiv($n, 10);
        $o = $n % 10;

        return $o === 0 ? $tens[$t] : $tens[$t].' '.$ones[$o];
    }
}
