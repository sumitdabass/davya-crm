<?php

namespace Tests\Unit;

use App\Support\MoneyFormat;
use PHPUnit\Framework\TestCase;

class MoneyFormatTest extends TestCase
{
    public function test_format_cases(): void
    {
        $this->assertSame('0',     MoneyFormat::indianShort(0));
        $this->assertSame('500',   MoneyFormat::indianShort(500));
        $this->assertSame('80K',   MoneyFormat::indianShort(80_000));
        $this->assertSame('1.25L', MoneyFormat::indianShort(1_25_000));
        $this->assertSame('2.00Cr',MoneyFormat::indianShort(2_00_00_000));
    }

    public function test_to_indian_words_zero_and_null(): void
    {
        $this->assertSame('Zero Rupees', MoneyFormat::toIndianWords(0));
        $this->assertSame('Zero Rupees', MoneyFormat::toIndianWords(null));
        $this->assertSame('Zero Rupees', MoneyFormat::toIndianWords(-5));
    }

    public function test_to_indian_words_small_amounts(): void
    {
        $this->assertSame('One Rupees', MoneyFormat::toIndianWords(1));
        $this->assertSame('Nineteen Rupees', MoneyFormat::toIndianWords(19));
        $this->assertSame('Twenty Rupees', MoneyFormat::toIndianWords(20));
        $this->assertSame('Twenty Five Rupees', MoneyFormat::toIndianWords(25));
        $this->assertSame('Ninety Nine Rupees', MoneyFormat::toIndianWords(99));
        $this->assertSame('One Hundred Rupees', MoneyFormat::toIndianWords(100));
        $this->assertSame('One Hundred Twenty Three Rupees', MoneyFormat::toIndianWords(123));
    }

    public function test_to_indian_words_thousands_lakhs_crores(): void
    {
        $this->assertSame('One Thousand Rupees', MoneyFormat::toIndianWords(1_000));
        $this->assertSame(
            'Twelve Thousand Three Hundred Forty Five Rupees',
            MoneyFormat::toIndianWords(12_345)
        );
        $this->assertSame(
            'One Lakh Twenty Five Thousand Rupees',
            MoneyFormat::toIndianWords(1_25_000)
        );
        $this->assertSame(
            'Two Crore Fifty Lakh Rupees',
            MoneyFormat::toIndianWords(2_50_00_000)
        );
        $this->assertSame(
            'One Hundred Twenty Three Crore Forty Five Lakh Sixty Seven Thousand Eight Hundred Ninety Rupees',
            MoneyFormat::toIndianWords(1_23_45_67_890)
        );
    }

    public function test_to_indian_words_with_paise(): void
    {
        $this->assertSame(
            'One Thousand Two Hundred Thirty Four Rupees and Fifty Paise',
            MoneyFormat::toIndianWords(1234.50)
        );
        $this->assertSame(
            'Ninety Nine Rupees and Ninety Nine Paise',
            MoneyFormat::toIndianWords(99.99)
        );
    }
}
