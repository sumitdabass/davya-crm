<?php

namespace Tests\Feature;

use App\Models\Investment;
use App\Models\LedgerEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InvestmentCaptureTest extends TestCase
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
            'asset_name' => 'Tata Motors',
            'amount' => 100000,
            'direction' => 'out',
            'transacted_at' => '2026-04-17T09:00:00+05:30',
            'slack_message_id' => 'I.'.uniqid(),
            'raw_input' => 'bought 100k tata motors',
        ], $overrides);
        $headers = $token === null ? [] : ['X-Finance-Token' => $token];
        return $this->postJson('/api/finance/investments', $payload, $headers);
    }

    public function test_direction_out_debits_davya(): void
    {
        $this->postPayload(['direction' => 'out', 'amount' => 100000])->assertCreated();
        $inv = Investment::first();
        $this->assertNotNull($inv);
        $this->assertSame('2026-04-17 03:30:00', $inv->transacted_at->utc()->format('Y-m-d H:i:s'));
        $l = LedgerEntry::first();
        $this->assertSame('davya', $l->account);
        $this->assertSame('-100000.00', (string) $l->delta_amount);
    }

    public function test_direction_in_credits_davya(): void
    {
        $this->postPayload(['direction' => 'in', 'amount' => 120000])->assertCreated();
        $l = LedgerEntry::first();
        $this->assertSame('davya', $l->account);
        $this->assertSame('120000.00', (string) $l->delta_amount);
    }

    public function test_invalid_direction_returns_422(): void
    {
        $this->postPayload(['direction' => 'sideways'])->assertStatus(422)->assertJsonValidationErrors('direction');
    }

    public function test_missing_asset_name_returns_422(): void
    {
        $this->postPayload(['asset_name' => null])->assertStatus(422)->assertJsonValidationErrors('asset_name');
    }

    public function test_missing_token_returns_401(): void
    {
        $this->postPayload([], token: null)->assertStatus(401);
    }

    public function test_duplicate_slack_message_id_returns_409(): void
    {
        $first = $this->postPayload(['slack_message_id' => 'I.DUPE']);
        $first->assertCreated();
        $this->postPayload(['slack_message_id' => 'I.DUPE'])->assertStatus(409);
    }
}
