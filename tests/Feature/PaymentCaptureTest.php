<?php

namespace Tests\Feature;

use App\Models\LedgerEntry;
use App\Models\Payment;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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

    public function test_slack_message_id_race_returns_409_not_500(): void
    {
        // Pre-create the student so the controller skips Student::create and
        // the pre-check SELECT on payments fires first.
        $nisha = User::where('email', 'nisha@davya.local')->first();
        $student = Student::create([
            'phone' => '9100000011', 'name' => 'Existing Race',
            'owner_id' => $nisha->team_head_id ?? $nisha->id,
            'referrer_id' => $nisha->id,
            'lead_source' => 'Nisha', 'stage' => 'Lead Captured',
        ]);

        // DB::listen fires AFTER each statement at the outer transaction scope
        // (before the controller's DB::transaction savepoint). A raw insert
        // here survives the savepoint rollback triggered by the unique-constraint
        // failure on Payment::create, simulating a concurrent writer.
        $slackId = 'RACE.PAY';
        $raced = false;
        DB::listen(function ($q) use (&$raced, $slackId, $student) {
            if ($raced) return;
            if (!str_contains($q->sql, 'payments')) return;
            if (!str_starts_with(strtolower(ltrim($q->sql)), 'select')) return;
            if (!in_array($slackId, $q->bindings, true)) return;
            $raced = true;
            DB::table('payments')->insert([
                'student_id'       => $student->id,
                'type'             => 'full',
                'amount'           => 1,
                'received_at'      => now(),
                'slack_message_id' => $slackId,
                'created_at'       => now(),
                'updated_at'       => now(),
            ]);
        });

        $resp = $this->postPayload(['student_phone' => '9100000011', 'slack_message_id' => $slackId]);
        $resp->assertStatus(409)->assertJson(['error' => 'duplicate_slack_message']);
        $this->assertNotNull($resp->json('existing_id'));
        $this->assertSame(1, Payment::where('slack_message_id', $slackId)->count());
    }

}
