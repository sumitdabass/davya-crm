<?php

namespace Tests\Feature;

use App\Models\Expense;
use App\Models\LedgerEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class ExpenseCaptureTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'test-finance-token-abcdef0123456789';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        config(['finance.capture_token' => self::TOKEN]);
        Route::post('/api/finance/expenses', [\App\Http\Controllers\FinanceExpenseController::class, 'store'])
            ->middleware(\App\Http\Middleware\VerifyFinanceToken::class);
    }

    private function postPayload(array $overrides = [], ?string $token = self::TOKEN)
    {
        $payload = array_merge([
            'amount' => 5000,
            'category' => 'Marketing',
            'description' => 'fb ads April',
            'paid_at' => '2026-04-17T10:00:00+05:30',
            'slack_message_id' => 'E.'.uniqid(),
            'raw_input' => 'paid 5k for fb ads',
        ], $overrides);
        $headers = $token === null ? [] : ['X-Finance-Token' => $token];
        return $this->postJson('/api/finance/expenses', $payload, $headers);
    }

    public function test_happy_path_creates_expense_and_ledger_row(): void
    {
        $this->postPayload()->assertCreated()->assertJson(['ledger_entries' => 1]);
        $e = Expense::first();
        $this->assertNotNull($e);
        $this->assertSame('2026-04-17 04:30:00', $e->paid_at->utc()->format('Y-m-d H:i:s'));
        $l = LedgerEntry::first();
        $this->assertSame('davya', $l->account);
        $this->assertSame('-5000.00', (string) $l->delta_amount);
        $this->assertSame('expense', $l->source_type);
        $this->assertSame($e->id, $l->source_id);
    }

    public function test_missing_token_returns_401(): void
    {
        $this->postPayload([], token: null)->assertStatus(401);
    }

    public function test_missing_amount_returns_422(): void
    {
        $this->postPayload(['amount' => null])->assertStatus(422)->assertJsonValidationErrors('amount');
    }

    public function test_missing_slack_message_id_returns_422(): void
    {
        $this->postPayload(['slack_message_id' => null])->assertStatus(422)->assertJsonValidationErrors('slack_message_id');
    }

    public function test_duplicate_slack_message_id_returns_409(): void
    {
        $first = $this->postPayload(['slack_message_id' => 'E.DUPE']);
        $first->assertCreated();
        $this->postPayload(['slack_message_id' => 'E.DUPE'])
            ->assertStatus(409)
            ->assertJson(['error' => 'duplicate_slack_message', 'existing_id' => $first->json('id')]);
    }
}
