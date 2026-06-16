<?php

namespace Tests\Unit\Rank;

use App\Services\Rank\RankPredictor;
use Tests\TestCase;

class RankPredictorChanceTest extends TestCase
{
    private RankPredictor $p;

    protected function setUp(): void
    {
        parent::setUp();
        $this->p = new RankPredictor;
    }

    /** @test */
    public function chance_labels_by_ratio_to_closing_rank(): void
    {
        $cr = 100000;
        $this->assertSame('SAFE',       $this->p->chance(80000, $cr));
        $this->assertSame('SAFE',       $this->p->chance(85000, $cr));
        $this->assertSame('LIKELY',     $this->p->chance(100000, $cr));
        $this->assertSame('BORDERLINE', $this->p->chance(108000, $cr));
        $this->assertSame('STRETCH',    $this->p->chance(125000, $cr));
        $this->assertSame('UNLIKELY',   $this->p->chance(125001, $cr));
    }

    /** @test */
    public function within_reach_is_anything_but_unlikely(): void
    {
        $this->assertTrue($this->p->withinReach(125000, 100000));
        $this->assertFalse($this->p->withinReach(200000, 100000));
    }

    /** @test */
    public function chance_handles_zero_closing(): void
    {
        $this->assertSame('UNLIKELY', $this->p->chance(50000, 0));
    }
}
