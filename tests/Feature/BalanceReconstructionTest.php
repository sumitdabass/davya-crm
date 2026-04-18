<?php

namespace Tests\Feature;

use App\Models\LedgerEntry;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BalanceReconstructionTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'test-finance-token-abcdef0123456789';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        config(['finance.capture_token' => self::TOKEN]);
    }

    private function pay(string $phone, string $referrer, float $amount): void
    {
        $this->postJson('/api/finance/payments', [
            'student_phone' => $phone, 'amount' => $amount, 'referrer_name' => $referrer,
            'student_name' => 'S '.$phone,
            'slack_message_id' => 'BR.P.'.uniqid(),
            'raw_input' => "got {$amount} from {$referrer}",
        ], ['X-Finance-Token' => self::TOKEN])->assertCreated();
    }
    private function expense(float $amount, string $cat): void
    {
        $this->postJson('/api/finance/expenses', [
            'amount' => $amount, 'category' => $cat,
            'slack_message_id' => 'BR.E.'.uniqid(),
        ], ['X-Finance-Token' => self::TOKEN])->assertCreated();
    }
    private function invest(float $amount, string $direction, string $asset): void
    {
        $this->postJson('/api/finance/investments', [
            'amount' => $amount, 'direction' => $direction, 'asset_name' => $asset,
            'slack_message_id' => 'BR.I.'.uniqid(),
        ], ['X-Finance-Token' => self::TOKEN])->assertCreated();
    }

    public function test_mixed_sequence_produces_correct_balances(): void
    {
        $this->pay('9200000001', 'Nisha',  50000);
        $this->pay('9200000002', 'Nikhil', 40000);
        $this->pay('9200000003', 'Nisha',  30000);

        $this->pay('9200000011', 'Poonam', 45000);
        $this->pay('9200000012', 'Sonam',  35000);

        $this->pay('9200000021', 'Kapil',  25000);

        $this->expense(5000,  'Marketing');
        $this->expense(12000, 'Rent');

        $this->invest(100000, 'out', 'Tata Motors');
        $this->invest(8000,   'in',  'Binance');

        $davya  = (float) LedgerEntry::balanceFor('davya');
        $nikhil = (float) LedgerEntry::balanceFor('nikhil');

        $this->assertEqualsWithDelta(44000.00, $davya, 0.01);
        $this->assertEqualsWithDelta(72000.00, $nikhil, 0.01);
    }
}
