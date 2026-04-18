<?php

namespace Tests\Feature;

use App\Models\Expense;
use App\Models\LedgerEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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

    public function test_slack_message_id_race_returns_409_not_500(): void
    {
        // See PaymentCaptureTest::test_slack_message_id_race_returns_409_not_500
        // for the DB::listen-outside-savepoint rationale.
        $slackId = 'E.RACE';
        $raced = false;
        DB::listen(function ($q) use (&$raced, $slackId) {
            if ($raced) return;
            if (!str_contains($q->sql, 'expenses')) return;
            if (!str_starts_with(strtolower(ltrim($q->sql)), 'select')) return;
            if (!in_array($slackId, $q->bindings, true)) return;
            $raced = true;
            DB::table('expenses')->insert([
                'amount'           => 1,
                'paid_at'          => now(),
                'slack_message_id' => $slackId,
                'created_at'       => now(),
                'updated_at'       => now(),
            ]);
        });
        $resp = $this->postPayload(['slack_message_id' => $slackId]);
        $resp->assertStatus(409)->assertJson(['error' => 'duplicate_slack_message']);
        $this->assertNotNull($resp->json('existing_id'));
        $this->assertSame(1, Expense::where('slack_message_id', $slackId)->count());
    }
}
