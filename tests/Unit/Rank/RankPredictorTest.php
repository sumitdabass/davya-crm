<?php

namespace Tests\Unit\Rank;

use App\Services\Rank\RankPredictor;
use Tests\TestCase;

class RankPredictorTest extends TestCase
{
    private RankPredictor $p;

    protected function setUp(): void
    {
        parent::setUp();
        $this->p = new RankPredictor;
    }

    /** @test */
    public function cushion_pct_is_signed_percent_of_max(): void
    {
        $this->assertSame(50, $this->p->cushionPct(50000, 100000));   // (100k-50k)/100k = 50%
        $this->assertSame(20, $this->p->cushionPct(80000, 100000));   // 20%
        $this->assertSame(-10, $this->p->cushionPct(110000, 100000)); // rank > max, negative
    }

    /** @test */
    public function bucket_classifies_by_cushion(): void
    {
        $this->assertSame('safe', $this->p->bucket(60000, 100000));     // 40%
        $this->assertSame('safe', $this->p->bucket(75000, 100000));     // 25%
        $this->assertSame('probable', $this->p->bucket(80000, 100000)); // 20%
        $this->assertSame('probable', $this->p->bucket(90000, 100000)); // 10%
        $this->assertSame('reach', $this->p->bucket(95000, 100000));    // 5%
        $this->assertSame('reach', $this->p->bucket(100000, 100000));   // 0%
    }

    /** @test */
    public function is_eligible_drops_overqualified_and_underqualified(): void
    {
        // Within band, cushion ≤ 50: eligible
        $this->assertTrue($this->p->isEligible(50000, ['min' => 30000, 'max' => 100000])); // cushion 50%
        $this->assertTrue($this->p->isEligible(80000, ['min' => 30000, 'max' => 100000])); // 20%
        // rank > max: not eligible
        $this->assertFalse($this->p->isEligible(120000, ['min' => 30000, 'max' => 100000]));
        // rank < min: not eligible (over-competitive)
        $this->assertFalse($this->p->isEligible(10000, ['min' => 30000, 'max' => 100000]));
        // cushion > 50: not eligible (clutter)
        $this->assertFalse($this->p->isEligible(40000, ['min' => 30000, 'max' => 100000])); // 60%
    }

    /** @test */
    public function yoy_delta_pct_compares_two_max_ranks(): void
    {
        $this->assertSame(30, $this->p->yoyDeltaPct(['max' => 100000], ['max' => 130000]));   // +30%
        $this->assertSame(-50, $this->p->yoyDeltaPct(['max' => 200000], ['max' => 100000])); // -50%
        $this->assertNull($this->p->yoyDeltaPct(null, ['max' => 100000]));
        $this->assertNull($this->p->yoyDeltaPct(['max' => 100000], null));
    }
}
