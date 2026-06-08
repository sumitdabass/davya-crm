<?php

namespace Tests\Feature;

use App\Filament\Resources\StudentResource\Pages\EditStudent;
use App\Filament\Resources\StudentResource\RelationManagers\PaymentsRelationManager;
use App\Models\Payout;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class PaymentPayoutChooserTest extends TestCase
{
    use RefreshDatabase;

    private function student(User $sumit): Student
    {
        return Student::create([
            'phone' => '910000'.random_int(1000, 9999),
            'name' => 'ChooserTester',
            'owner_id' => $sumit->id,
            'referrer_id' => $sumit->id,
            'lead_source' => 'Sumit',
        ]);
    }

    private function panel(Student $student)
    {
        return Livewire::test(PaymentsRelationManager::class, [
            'ownerRecord' => $student,
            'pageClass' => EditStudent::class,
        ]);
    }

    public function test_add_payment_creates_payment_with_recorder(): void
    {
        $this->seed();
        $sumit = User::where('email', 'sumit@davya.local')->firstOrFail();
        $this->actingAs($sumit);
        $student = $this->student($sumit);

        $this->panel($student)
            ->callTableAction('newPaymentPayout', data: [
                'entry_action' => 'add_payment',
                'type' => 'advance',
                'amount' => 10000,
                'mode' => 'cash',
                'received_at' => now()->toDateTimeString(),
            ])
            ->assertHasNoTableActionErrors();

        $this->assertEquals(1, $student->payments()->count());
        $this->assertEquals($sumit->id, $student->payments()->first()->recorded_by_user_id);
    }

    public function test_add_payout_creates_payout_with_recorder(): void
    {
        $this->seed();
        $sumit = User::where('email', 'sumit@davya.local')->firstOrFail();
        $this->actingAs($sumit);
        $student = $this->student($sumit);

        $this->panel($student)
            ->callTableAction('newPaymentPayout', data: [
                'entry_action' => 'add_payout',
                'payout_payee_type' => 'college',
                'payout_payee_name' => 'GGSIPU',
                'payout_amount' => 40000,
                'payout_status' => 'to_pay',
            ])
            ->assertHasNoTableActionErrors();

        $payout = $student->payouts()->first();
        $this->assertNotNull($payout);
        $this->assertEquals(40000.0, (float) $payout->amount);
        $this->assertEquals($sumit->id, $payout->recorded_by_user_id);
    }

    public function test_update_payment_updates_selected_record(): void
    {
        $this->seed();
        $sumit = User::where('email', 'sumit@davya.local')->firstOrFail();
        $this->actingAs($sumit);
        $student = $this->student($sumit);
        $payment = $student->payments()->create([
            'type' => 'advance', 'amount' => 10000, 'mode' => 'cash',
            'received_at' => now(), 'recorded_by_user_id' => $sumit->id,
        ]);

        $this->panel($student)
            ->callTableAction('newPaymentPayout', data: [
                'entry_action' => 'update_payment',
                'payment_id' => $payment->id,
                'type' => 'partial',
                'amount' => 25000,
                'mode' => 'upi',
                'received_at' => now()->toDateTimeString(),
            ])
            ->assertHasNoTableActionErrors();

        $this->assertEquals(25000.0, (float) $payment->fresh()->amount);
        $this->assertEquals('partial', $payment->fresh()->type);
    }

    public function test_update_payout_updates_selected_record(): void
    {
        $this->seed();
        $sumit = User::where('email', 'sumit@davya.local')->firstOrFail();
        $this->actingAs($sumit);
        $student = $this->student($sumit);
        $payout = Payout::factory()->create([
            'student_id' => $student->id, 'amount' => 30000,
            'payee_type' => 'college', 'status' => 'to_pay',
            'recorded_by_user_id' => $sumit->id,
        ]);

        $this->panel($student)
            ->callTableAction('newPaymentPayout', data: [
                'entry_action' => 'update_payout',
                'payout_id' => $payout->id,
                'payout_payee_type' => 'college',
                'payout_amount' => 55000,
                'payout_status' => 'paid',
                'payout_paid_at' => now()->toDateTimeString(),
            ])
            ->assertHasNoTableActionErrors();

        $fresh = $payout->fresh();
        $this->assertEquals(55000.0, (float) $fresh->amount);
        $this->assertEquals('paid', $fresh->status);
        $this->assertNotNull($fresh->paid_at);
    }
}
