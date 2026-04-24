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
}
