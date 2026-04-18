<?php

namespace Tests\Feature;

use App\Models\LedgerEntry;
use App\Models\Payment;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PaymentCaptureTest extends TestCase
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
            'student_phone'    => '9100000001',
            'amount'           => 50000,
            'student_name'     => 'Priya Verma',
            'referrer_name'    => 'Nisha',
            'is_partial'       => false,
            'slack_message_id' => 'C1.'.uniqid(),
            'raw_input'        => '50k from priya via nisha',
        ], $overrides);
        $headers = $token === null ? [] : ['X-Finance-Token' => $token];
        return $this->postJson('/api/finance/payments', $payload, $headers);
    }

    public function test_missing_token_returns_401(): void
    {
        $this->postPayload([], token: null)->assertStatus(401);
        $this->assertSame(0, Payment::count());
        $this->assertSame(0, LedgerEntry::count());
    }

    public function test_wrong_token_returns_401(): void
    {
        $this->postPayload([], token: 'nope')->assertStatus(401);
    }

    public function test_new_student_with_member_referrer_creates_payment_and_two_ledger_rows(): void
    {
        $resp = $this->postPayload(['amount' => 50000, 'referrer_name' => 'Nisha']);
        $resp->assertCreated()->assertJsonStructure(['id','ledger_entries']);
        $this->assertSame(2, $resp->json('ledger_entries'));

        $student = Student::where('phone','9100000001')->first();
        $this->assertNotNull($student);
        $this->assertSame('Nisha', User::find($student->referrer_id)->name);

        $ledger = LedgerEntry::orderBy('id')->get();
        $this->assertCount(2, $ledger);
        $this->assertSame('nikhil', $ledger[0]->account);
        $this->assertSame('30000.00', (string) $ledger[0]->delta_amount);
        $this->assertSame('davya',  $ledger[1]->account);
        $this->assertSame('20000.00', (string) $ledger[1]->delta_amount);
    }

    public function test_existing_student_ignores_request_referrer_name(): void
    {
        $sonam = User::where('email','sonam@davya.local')->first();
        Student::create([
            'phone' => '9100000099', 'name' => 'Existing',
            'owner_id' => $sonam->id, 'referrer_id' => $sonam->id,
            'lead_source' => 'Sonam', 'stage' => 'Lead Captured',
        ]);
        $this->postPayload(['student_phone' => '9100000099','referrer_name' => 'Nisha','amount' => 40000])
             ->assertCreated();
        $rows = LedgerEntry::all();
        $this->assertCount(1, $rows);
        $this->assertSame('davya', $rows[0]->account);
        $this->assertSame('40000.00', (string) $rows[0]->delta_amount);
    }

    public function test_freelancer_referral_routes_to_davya_only(): void
    {
        $this->postPayload(['student_phone' => '9100000002','referrer_name' => 'Kapil','amount' => 30000])
             ->assertCreated();
        $rows = LedgerEntry::all();
        $this->assertCount(1, $rows);
        $this->assertSame('davya', $rows[0]->account);
        $this->assertSame('30000.00', (string) $rows[0]->delta_amount);
    }

    public function test_duplicate_slack_message_id_returns_409_with_existing_id(): void
    {
        $first = $this->postPayload(['slack_message_id' => 'DUPE.1'])->assertCreated();
        $firstId = $first->json('id');
        $second = $this->postPayload(['student_phone' => '9100000003','slack_message_id' => 'DUPE.1']);
        $second->assertStatus(409);
        $second->assertJson(['error' => 'duplicate_slack_message', 'existing_id' => $firstId]);
        $this->assertSame(1, Payment::where('slack_message_id','DUPE.1')->count());
    }

    public function test_missing_amount_returns_422(): void
    {
        $this->postPayload(['amount' => null])->assertStatus(422)->assertJsonValidationErrors('amount');
    }

    public function test_missing_phone_returns_422(): void
    {
        $this->postPayload(['student_phone' => ''])->assertStatus(422)->assertJsonValidationErrors('student_phone');
    }

    public function test_new_student_without_referrer_name_returns_422(): void
    {
        $this->postPayload(['student_phone' => '9100000004','referrer_name' => null])
             ->assertStatus(422)
             ->assertJsonValidationErrors('referrer_name');
    }

    public function test_unknown_referrer_for_new_student_returns_422(): void
    {
        $this->postPayload(['student_phone' => '9100000005','referrer_name' => 'SomeRandomName'])
             ->assertStatus(422)
             ->assertJsonValidationErrors('referrer_name');
        $this->assertSame(0, Payment::count());
    }

    public function test_new_student_without_name_gets_placeholder(): void
    {
        $this->postPayload(['student_phone' => '9100000006', 'student_name' => null, 'referrer_name' => 'Nisha'])
             ->assertCreated();
        $student = Student::where('phone', '9100000006')->first();
        $this->assertNotNull($student);
        $this->assertSame('Pending — 9100000006', $student->name);
    }
}
