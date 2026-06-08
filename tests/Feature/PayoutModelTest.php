<?php

namespace Tests\Feature;

use App\Models\Payout;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PayoutModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_amount_is_forced_positive_and_paid_at_set_when_paid(): void
    {
        $student = Student::factory()->create(['deal_amount' => 100000]);
        $user = User::factory()->create();
        $payout = Payout::create([
            'student_id' => $student->id,
            'payee_type' => 'college',
            'amount' => -5000,
            'status' => 'paid',
            'recorded_by_user_id' => $user->id,
        ]);
        $this->assertEquals(5000.0, (float) $payout->amount);
        $this->assertNotNull($payout->paid_at);
    }

    public function test_to_pay_clears_paid_at(): void
    {
        $student = Student::factory()->create();
        $user = User::factory()->create();
        $payout = Payout::factory()->paid()->create([
            'student_id' => $student->id,
            'recorded_by_user_id' => $user->id,
        ]);
        $payout->update(['status' => 'to_pay']);
        $this->assertNull($payout->fresh()->paid_at);
    }

    public function test_profit_accessors(): void
    {
        $student = Student::factory()->create(['deal_amount' => 100000]);
        $user = User::factory()->create();
        Payout::factory()->create(['student_id' => $student->id, 'amount' => 30000, 'status' => 'to_pay', 'recorded_by_user_id' => $user->id]);
        Payout::factory()->paid()->create(['student_id' => $student->id, 'amount' => 20000, 'recorded_by_user_id' => $user->id]);
        $student->refresh();
        $this->assertEquals(50000.0, $student->total_payouts);
        $this->assertEquals(20000.0, $student->payouts_paid);
        $this->assertEquals(30000.0, $student->payouts_outstanding);
        $this->assertEquals(50000.0, $student->expected_profit);
    }

    public function test_with_expected_profit_scope_selects_and_sorts(): void
    {
        $user = User::factory()->create();
        $a = Student::factory()->create(['deal_amount' => 100000]);
        $b = Student::factory()->create(['deal_amount' => 100000]);
        Payout::factory()->create(['student_id' => $a->id, 'amount' => 80000, 'recorded_by_user_id' => $user->id]);
        Payout::factory()->create(['student_id' => $b->id, 'amount' => 10000, 'recorded_by_user_id' => $user->id]);
        $rows = Student::withExpectedProfit()->orderBy('expected_profit', 'desc')->get();
        $this->assertEquals($b->id, $rows->first()->id);
        $this->assertEquals(20000.0, $rows->firstWhere('id', $a->id)->expected_profit);
    }
}
