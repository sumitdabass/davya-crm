<?php

namespace Tests\Unit\Rank;

use App\Services\Rank\BenchmarkRoundStrategy;
use Tests\TestCase;

class BenchmarkRoundStrategyTest extends TestCase
{
    private BenchmarkRoundStrategy $s;

    protected function setUp(): void
    {
        parent::setUp();
        $this->s = new BenchmarkRoundStrategy;
    }

    /** @test */
    public function ipu_general_uses_sliding(): void
    {
        $this->assertSame('sliding', $this->s->pick('ipu', 'general', ['1', '3', 'sliding']));
    }

    /** @test */
    public function ipu_general_falls_back_to_highest_numeric_when_no_sliding(): void
    {
        $this->assertSame('3', $this->s->pick('ipu', 'general', ['1', '2', '3']));
    }

    /** @test */
    public function ipu_reserved_uses_round_3(): void
    {
        $this->assertSame('3', $this->s->pick('ipu', 'sc', ['1', '2', '3', 'sliding']));
    }

    /** @test */
    public function ipu_reserved_falls_back_to_highest_present_at_most_3(): void
    {
        $this->assertSame('2', $this->s->pick('ipu', 'obc', ['1', '2']));
    }

    /** @test */
    public function dtu_uses_highest_numeric_round(): void
    {
        $this->assertSame('5', $this->s->pick('dtu', 'general', ['1', '2', '5']));
        $this->assertSame('4', $this->s->pick('dtu', 'sc', ['1', '4']));
    }

    /** @test */
    public function returns_null_when_no_rounds_present(): void
    {
        $this->assertNull($this->s->pick('dtu', 'general', []));
    }
}
