<?php

namespace Tests\Feature;

use App\Models\Expense;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExpenseResourceTest extends TestCase
{
    use RefreshDatabase;

    public function test_manual_expense_renders_D_prefix(): void
    {
        $e = Expense::create([
            'amount' => 1000,
            'description' => 'Manual test',
            'paid_at' => now(),
            'slack_message_id' => null,
        ]);
        $this->assertSame("D{$e->id}", $e->display_id, 'manual rows must use D prefix');
    }

    public function test_slack_captured_expense_renders_hash_prefix(): void
    {
        $e = Expense::create([
            'amount' => 2500,
            'description' => 'Captured from Slack',
            'paid_at' => now(),
            'slack_message_id' => '1776767527.655079',
        ]);
        $this->assertSame("#{$e->id}", $e->display_id, 'slack rows must use # prefix');
    }
}
