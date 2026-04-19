<?php

declare(strict_types=1);

namespace Tests\Feature\Finance;

use Tests\TestCase;

class FinanceAssistantTest extends TestCase
{
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

    public function test_returns_stub_reply_text_on_200(): void
    {
        $response = $this->postAssistant([
            'slack_message_id' => '1776570058.279209',
            'slack_channel'    => 'C0ATAQ8KFF1',
            'slack_user_id'    => 'U123',
            'question_text'    => 'show recent captures',
            'intent'           => 'recent_captures',
        ]);
        $response->assertStatus(200)->assertJsonStructure(['reply_text']);
    }
}
