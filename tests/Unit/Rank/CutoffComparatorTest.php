<?php

namespace Tests\Unit\Rank;

use App\Services\Rank\CutoffComparator;
use PHPUnit\Framework\TestCase;

class CutoffComparatorTest extends TestCase
{
    /** @test */
    public function projects_final_round_using_prior_year_slide(): void
    {
        // prior year slid R1 6000 -> R5 12000 (x2); newer R1 9000 -> projected R5 18000.
        $this->assertSame(18000, CutoffComparator::projectedFinal(6000, 12000, 9000));
        // prior x1.5 (20000 -> 30000); newer 16000 -> 24000.
        $this->assertSame(24000, CutoffComparator::projectedFinal(20000, 30000, 16000));
    }

    /** @test */
    public function collapses_to_actual_when_anchor_is_the_final_round(): void
    {
        // When the newer year's anchor IS the final round, prior anchor == prior final,
        // so the slide is 1 and the projection equals the actual newer value.
        $this->assertSame(15000, CutoffComparator::projectedFinal(12000, 12000, 15000));
    }

    /** @test */
    public function guards_against_zero_prior_anchor(): void
    {
        $this->assertSame(0, CutoffComparator::projectedFinal(0, 12000, 9000));
    }
}
