<?php
namespace Tests\Unit;

use App\Models\Expense;
use App\Models\Investment;
use App\Models\Payment;
use App\Models\Student;
use App\Models\User;
use App\Services\Finance\LedgerRoutingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LedgerRoutingServiceTest extends TestCase
{
    use RefreshDatabase;

    protected LedgerRoutingService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->svc = new LedgerRoutingService();
    }

    private function makeStudent(string $referrerEmail, string $phone): Student
    {
        $referrer = User::where('email', $referrerEmail)->firstOrFail();
        return Student::create([
            'phone' => $phone, 'name' => 'T',
            'owner_id' => $referrer->team_head_id ?? $referrer->id,
            'referrer_id' => $referrer->id,
            'lead_source' => $referrer->name,
            'stage' => 'Lead Captured',
        ]);
    }

    private function makePayment(Student $student, float $amount): Payment
    {
        return Payment::create([
            'student_id' => $student->id,
            'type' => 'full',
            'amount' => $amount,
            'received_at' => now(),
            'recorded_by_user_id' => null,
            'slack_message_id' => uniqid('TEST.'),
            'raw_input' => 'test',
        ]);
    }

    public function test_freelancer_referral_routes_100pct_to_davya(): void
    {
        $student = $this->makeStudent('kapil@davya.local', '9100000001');
        $p = $this->makePayment($student, 30000);
        $rows = $this->svc->routePayment($p);
        $this->assertCount(1, $rows);
        $this->assertSame('davya', $rows[0]['account']);
        $this->assertSame('30000.00', (string) $rows[0]['delta_amount']);
    }

    public function test_head_with_0pct_split_routes_100pct_to_davya(): void
    {
        $student = $this->makeStudent('sumit@davya.local', '9100000002');
        $p = $this->makePayment($student, 40000);
        $rows = $this->svc->routePayment($p);
        $this->assertCount(1, $rows);
        $this->assertSame('davya', $rows[0]['account']);
        $this->assertSame('40000.00', (string) $rows[0]['delta_amount']);
    }

    public function test_head_with_60pct_split_routes_60_40(): void
    {
        $student = $this->makeStudent('nikhil@davya.local', '9100000003');
        $p = $this->makePayment($student, 50000);
        $rows = $this->svc->routePayment($p);
        $this->assertCount(2, $rows);
        $this->assertSame('nikhil', $rows[0]['account']);
        $this->assertSame('30000.00', (string) $rows[0]['delta_amount']);
        $this->assertSame('davya',  $rows[1]['account']);
        $this->assertSame('20000.00', (string) $rows[1]['delta_amount']);
    }

    public function test_member_referral_rolls_up_to_head_split(): void
    {
        $student = $this->makeStudent('nisha@davya.local', '9100000004');
        $p = $this->makePayment($student, 50000);
        $rows = $this->svc->routePayment($p);
        $this->assertCount(2, $rows);
        $this->assertSame('nikhil', $rows[0]['account']);
        $this->assertSame('30000.00', (string) $rows[0]['delta_amount']);
        $this->assertSame('davya', $rows[1]['account']);
        $this->assertSame('20000.00', (string) $rows[1]['delta_amount']);
    }

    public function test_member_under_0pct_head_routes_100_to_davya(): void
    {
        $student = $this->makeStudent('poonam@davya.local', '9100000005');
        $p = $this->makePayment($student, 40000);
        $rows = $this->svc->routePayment($p);
        $this->assertCount(1, $rows);
        $this->assertSame('davya', $rows[0]['account']);
        $this->assertSame('40000.00', (string) $rows[0]['delta_amount']);
    }

    public function test_ledger_account_names_are_always_lowercase(): void
    {
        $student = $this->makeStudent('nikhil@davya.local', '9100000006');
        $p = $this->makePayment($student, 10000);
        $rows = $this->svc->routePayment($p);
        foreach ($rows as $r) {
            $this->assertSame(strtolower($r['account']), $r['account']);
        }
    }

    public function test_split_math_rounds_to_two_decimals_preserving_total(): void
    {
        $student = $this->makeStudent('nikhil@davya.local', '9100000007');
        $p = $this->makePayment($student, 33333);
        $rows = $this->svc->routePayment($p);
        $sum = array_sum(array_map(fn ($r) => (float) $r['delta_amount'], $rows));
        $this->assertEqualsWithDelta(33333.00, $sum, 0.001);
    }

    public function test_expense_debits_davya(): void
    {
        $e = Expense::create([
            'amount' => 5000, 'category' => 'Marketing',
            'paid_at' => now(), 'slack_message_id' => 'E.1',
            'raw_input' => 'fb ads',
        ]);
        $rows = $this->svc->routeExpense($e);
        $this->assertCount(1, $rows);
        $this->assertSame('davya', $rows[0]['account']);
        $this->assertSame('-5000.00', (string) $rows[0]['delta_amount']);
    }

    public function test_investment_out_debits_davya(): void
    {
        $i = Investment::create([
            'asset_name' => 'Tata', 'amount' => 100000,
            'direction' => 'out', 'transacted_at' => now(),
            'slack_message_id' => 'I.1',
        ]);
        $rows = $this->svc->routeInvestment($i);
        $this->assertCount(1, $rows);
        $this->assertSame('davya', $rows[0]['account']);
        $this->assertSame('-100000.00', (string) $rows[0]['delta_amount']);
    }

    public function test_investment_in_credits_davya(): void
    {
        $i = Investment::create([
            'asset_name' => 'Tata', 'amount' => 120000,
            'direction' => 'in', 'transacted_at' => now(),
            'slack_message_id' => 'I.2',
        ]);
        $rows = $this->svc->routeInvestment($i);
        $this->assertCount(1, $rows);
        $this->assertSame('davya', $rows[0]['account']);
        $this->assertSame('120000.00', (string) $rows[0]['delta_amount']);
    }

    public function test_returned_rows_include_source_type_and_source_id(): void
    {
        $student = $this->makeStudent('nikhil@davya.local', '9100000008');
        $p = $this->makePayment($student, 10000);
        $rows = $this->svc->routePayment($p);
        foreach ($rows as $r) {
            $this->assertSame('payment', $r['source_type']);
            $this->assertSame($p->id, $r['source_id']);
            $this->assertArrayHasKey('note', $r);
        }
    }
}
