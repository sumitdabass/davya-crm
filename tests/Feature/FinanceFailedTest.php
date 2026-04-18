<?php

namespace Tests\Feature;

use App\Models\FailedExtraction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FinanceFailedTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'test-finance-token-abcdef0123456789';

    protected function setUp(): void
    {
        parent::setUp();
        config(['finance.capture_token' => self::TOKEN]);
    }

    public function test_allows_repeated_slack_message_ids(): void
    {
        $post = fn (array $p) => $this->postJson('/api/finance/failed', $p, ['X-Finance-Token' => self::TOKEN]);
        $post(['slack_message_id' => 'C1.1.1','error_reason' => 'gemini invalid json'])->assertCreated();
        $post(['slack_message_id' => 'C1.1.1','error_reason' => 'retry: still invalid'])->assertCreated();
        $this->assertSame(2, FailedExtraction::count());
    }

    public function test_missing_token_returns_401(): void
    {
        $this->postJson('/api/finance/failed', ['slack_message_id'=>'x','error_reason'=>'y'])->assertStatus(401);
    }

    public function test_missing_error_reason_returns_422(): void
    {
        $this->postJson('/api/finance/failed', ['slack_message_id'=>'x'], ['X-Finance-Token' => self::TOKEN])
             ->assertStatus(422)->assertJsonValidationErrors('error_reason');
    }
}
