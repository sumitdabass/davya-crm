<?php

namespace Tests\Feature;

use App\Models\Investment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InvestmentResourceTest extends TestCase
{
    use RefreshDatabase;

    public function test_manual_investment_renders_D_prefix(): void
    {
        $i = Investment::create([
            'asset_name' => 'Tata Motors',
            'amount' => 50000,
            'direction' => 'out',
            'transacted_at' => now(),
            'slack_message_id' => null,
        ]);
        $this->assertSame("D{$i->id}", $i->display_id);
    }

    public function test_slack_captured_investment_renders_hash_prefix(): void
    {
        $i = Investment::create([
            'asset_name' => 'Tata Motors',
            'amount' => 50000,
            'direction' => 'out',
            'transacted_at' => now(),
            'slack_message_id' => '1776582096.431769',
        ]);
        $this->assertSame("#{$i->id}", $i->display_id);
    }
}
