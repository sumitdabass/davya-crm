<?php

namespace Tests\Feature;

use App\Models\Payment;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

class PaymentObserverTest extends TestCase
{
    use RefreshDatabase;

    public function test_payment_created_logs_humanized_row(): void
    {
        $this->seed();
        $sumit = User::where('email', 'sumit@davya.local')->first();
        $this->actingAs($sumit);
        $s = Student::create([
            'phone' => '9999940001', 'name' => 'T', 'owner_id' => $sumit->id,
            'lead_source' => 'Website', 'stage' => 'Lead Captured',
        ]);
        Activity::query()->delete();

        Payment::create([
            'student_id' => $s->id, 'amount' => 10000,
            'received_at' => now(), 'type' => 'advance',
            'recorded_by_user_id' => $sumit->id,
        ]);

        $a = Activity::where('subject_id', $s->id)->latest('id')->first();
        $this->assertNotNull($a);
        $this->assertSame('payment_added', $a->event);
        $this->assertStringContainsString('₹10,000', $a->description);
        $this->assertStringContainsString('advance', $a->description);
    }
}
