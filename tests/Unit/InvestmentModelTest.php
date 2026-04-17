<?php
namespace Tests\Unit;

use App\Models\Investment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InvestmentModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_investment_enforces_direction_enum_via_db(): void
    {
        $this->expectException(\Illuminate\Database\QueryException::class);
        Investment::create([
            'asset_name' => 'Tata',
            'amount' => 1000,
            'direction' => 'sideways',
            'transacted_at' => now(),
            'slack_message_id' => 'C3.1.1',
        ]);
    }

    public function test_investment_casts_and_accepts_both_directions(): void
    {
        $out = Investment::create([
            'asset_name' => 'Tata','amount' => 1000,
            'direction' => 'out','transacted_at' => now(),
            'slack_message_id' => 'C3.2.1',
        ]);
        $in = Investment::create([
            'asset_name' => 'Tata','amount' => 1200,
            'direction' => 'in','transacted_at' => now(),
            'slack_message_id' => 'C3.3.1',
        ]);
        $this->assertSame('out', $out->fresh()->direction);
        $this->assertSame('in', $in->fresh()->direction);
    }
}
