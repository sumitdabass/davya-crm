<?php

namespace Tests\Feature\Performance;

use Tests\TestCase;

class PerformanceConfigTest extends TestCase
{
    public function test_config_file_exposes_terminal_stages(): void
    {
        $stages = config('performance.terminal_stages');
        $this->assertEquals(['Admission Confirmed', 'Closed'], $stages);
    }

    public function test_weights_sum_to_one(): void
    {
        $weights = config('performance.weights');
        $sum = array_sum($weights);
        $this->assertEqualsWithDelta(1.0, $sum, 0.0001, 'Weights must sum to 1.0; got '.$sum);
    }

    public function test_tier_cutoffs_are_descending(): void
    {
        $tiers = config('performance.tiers');
        $mins = array_column($tiers, 'min');
        $sorted = $mins;
        rsort($sorted);
        $this->assertEquals($sorted, $mins, 'Tiers must be ordered highest→lowest cutoff');
    }

    public function test_min_sample_floor_and_stale_threshold_present(): void
    {
        $this->assertSame(3, config('performance.min_sample_floor'));
        $this->assertSame(60, config('performance.stale_threshold_days'));
    }

    public function test_pipeline_health_constants_present(): void
    {
        $ph = config('performance.pipeline_health');
        $this->assertSame(30, $ph['balance_penalty_factor']);
        $this->assertSame(50, $ph['balance_penalty_cap']);
        $this->assertSame(5, $ph['stale_penalty_per_lead']);
        $this->assertSame(20, $ph['stale_penalty_cap']);
        $this->assertSame(1, $ph['open_bonus_per_two']);
        $this->assertSame(10, $ph['open_bonus_cap']);
    }
}
