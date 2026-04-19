<?php

declare(strict_types=1);

namespace Tests\Feature\Finance;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FinanceAssistantTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'test-token';

    protected function setUp(): void
    {
        parent::setUp();
        config(['finance.capture_token' => self::TOKEN]);
    }

    private function postAssistant(array $body = [])
    {
        return $this->postJson('/api/finance/assistant', $body, [
            'X-Finance-Token' => self::TOKEN,
        ]);
    }

    public function test_rejects_missing_token_with_401(): void
    {
        $this->postJson('/api/finance/assistant', [])->assertStatus(401);
    }

    public function test_rejects_missing_required_fields_with_422(): void
    {
        $this->postAssistant([])
            ->assertStatus(422)
            ->assertJsonValidationErrors([
                'slack_message_id',
                'slack_channel',
                'slack_user_id',
                'question_text',
                'intent',
            ]);
    }

    public function test_rejects_invalid_intent_with_422(): void
    {
        $this->postAssistant([
            'slack_message_id' => '1776570058.279209',
            'slack_channel'    => 'C0ATAQ8KFF1',
            'slack_user_id'    => 'U123',
            'question_text'    => 'what',
            'intent'           => 'delete_all_payments',
        ])->assertJsonValidationErrors(['intent']);
    }

    public function test_rejects_reversed_time_range_with_422(): void
    {
        $this->postAssistant([
            'slack_message_id' => '1776570058.279209',
            'slack_channel'    => 'C0ATAQ8KFF1',
            'slack_user_id'    => 'U123',
            'question_text'    => 'x',
            'intent'           => 'recent_captures',
            'time_range'       => ['from' => '2026-04-19', 'to' => '2026-04-01'],
        ])->assertJsonValidationErrors(['time_range.to']);
    }

    public function test_returns_answerer_reply_via_the_full_pipeline(): void
    {
        // Seed: 2 Marketing expenses in April 2026
        \App\Models\Expense::factory()->create([
            'category' => 'Marketing',
            'amount'   => 5000,
            'paid_at'  => '2026-04-15',
        ]);
        \App\Models\Expense::factory()->create([
            'category' => 'Marketing',
            'amount'   => 3200,
            'paid_at'  => '2026-04-10',
        ]);

        $this->mock(\App\Services\Finance\GeminiClient::class, function ($mock) {
            $mock->shouldReceive('generate')->once()->andReturn('Total: ₹8,200 across 2 expenses.');
        });

        $this->postJson('/api/finance/assistant', [
            'slack_message_id' => '1776570058.279209',
            'slack_channel'    => 'C0ATAQ8KFF1',
            'slack_user_id'    => 'U123',
            'question_text'    => 'what did i spend on fb ads this month',
            'intent'           => 'spend_by_category',
            'time_range'       => ['from' => '2026-04-01', 'to' => '2026-04-30'],
            'filter'           => ['category' => 'Marketing'],
        ], [
            'X-Finance-Token' => 'test-token',
        ])->assertStatus(200)
          ->assertJson(['reply_text' => 'Total: ₹8,200 across 2 expenses.']);
    }
}
