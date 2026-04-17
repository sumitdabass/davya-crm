<?php
namespace Tests\Unit;

use App\Models\Expense;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExpenseModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_expense_casts_amount_as_decimal_and_paid_at_as_datetime(): void
    {
        $e = Expense::create([
            'amount' => 5000,
            'category' => 'Marketing',
            'description' => 'fb ads',
            'paid_at' => '2026-04-17 10:00:00',
            'slack_message_id' => 'C2.1.1',
            'raw_input' => 'paid 5k fb ads',
        ]);
        $fresh = $e->fresh();
        $this->assertSame('5000.00', (string) $fresh->amount);
        $this->assertInstanceOf(\Illuminate\Support\Carbon::class, $fresh->paid_at);
    }
}
