<?php

namespace Tests\Feature;

use App\Models\Payment;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PaymentTotalsTest extends TestCase
{
    use RefreshDatabase;

    public function test_refunds_subtract_from_total(): void
    {
        $this->seed();
        $sumit = User::where('email', 'sumit@davya.local')->first();
        $student = Student::create([
            'phone' => '9777777777',
            'name' => 'T',
            'owner_id' => $sumit->id,
            'referrer_id' => $sumit->id,
            'lead_source' => 'Sumit',
            'deal_amount' => 50000,
        ]);

        Payment::create(['student_id' => $student->id, 'type' => 'advance', 'amount' => 10000, 'received_at' => now(), 'recorded_by_user_id' => $sumit->id]);
        Payment::create(['student_id' => $student->id, 'type' => 'partial', 'amount' => 20000, 'received_at' => now(), 'recorded_by_user_id' => $sumit->id]);
        Payment::create(['student_id' => $student->id, 'type' => 'refund', 'amount' => 5000, 'received_at' => now(), 'recorded_by_user_id' => $sumit->id]);

        $this->assertEquals(25000, $student->fresh()->total_received);
        $this->assertEquals(25000, $student->fresh()->pending_amount);
    }

    public function test_refund_amount_stored_as_negative(): void
    {
        $this->seed();
        $sumit = User::where('email', 'sumit@davya.local')->first();
        $student = Student::create([
            'phone' => '9777777778',
            'name' => 'R',
            'owner_id' => $sumit->id,
            'referrer_id' => $sumit->id,
            'lead_source' => 'Sumit',
        ]);

        $refund = Payment::create(['student_id' => $student->id, 'type' => 'refund', 'amount' => 2500, 'received_at' => now(), 'recorded_by_user_id' => $sumit->id]);
        $this->assertLessThan(0, (float) $refund->fresh()->amount);
    }

    public function test_non_refund_negative_flipped_to_positive(): void
    {
        $this->seed();
        $sumit = User::where('email', 'sumit@davya.local')->first();
        $student = Student::create([
            'phone' => '9777777779',
            'name' => 'N',
            'owner_id' => $sumit->id,
            'referrer_id' => $sumit->id,
            'lead_source' => 'Sumit',
        ]);

        $advance = Payment::create(['student_id' => $student->id, 'type' => 'advance', 'amount' => -3000, 'received_at' => now(), 'recorded_by_user_id' => $sumit->id]);
        $this->assertEquals(3000, (float) $advance->fresh()->amount);
    }
}
