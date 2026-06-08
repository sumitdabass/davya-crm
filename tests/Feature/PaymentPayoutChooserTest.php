<?php

namespace Tests\Feature;

use App\Filament\Resources\StudentResource\Pages\EditStudent;
use App\Models\Payout;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class PaymentPayoutChooserTest extends TestCase
{
    use RefreshDatabase;

    private function studentFor(User $sumit): Student
    {
        return Student::create([
            'phone' => '910000'.random_int(1000, 9999),
            'name' => 'SegmentTester',
            'owner_id' => $sumit->id,
            'referrer_id' => $sumit->id,
            'lead_source' => 'Sumit',
        ]);
    }

    private function edit(Student $student)
    {
        return Livewire::test(EditStudent::class, ['record' => $student->getRouteKey()]);
    }

    private function sumit(): User
    {
        $this->seed();
        $sumit = User::where('email', 'sumit@davya.local')->firstOrFail();
        $this->actingAs($sumit);

        return $sumit;
    }

    public function test_edit_deal_updates_deal_amount(): void
    {
        $sumit = $this->sumit();
        $student = $this->studentFor($sumit);

        $this->edit($student)
            ->callAction('editDeal', data: ['deal_amount' => 250000])
            ->assertHasNoActionErrors();

        $this->assertEquals(250000.0, (float) $student->fresh()->deal_amount);
    }

    public function test_manage_payment_add_creates_payment_with_recorder(): void
    {
        $sumit = $this->sumit();
        $student = $this->studentFor($sumit);

        $this->edit($student)
            ->callAction('managePayment', data: [
                'entry_action' => 'add',
                'type' => 'advance', 'amount' => 10000, 'mode' => 'cash',
                'received_at' => now()->toDateTimeString(),
            ])
            ->assertHasNoActionErrors();

        $this->assertEquals(1, $student->payments()->count());
        $this->assertEquals($sumit->id, $student->payments()->first()->recorded_by_user_id);
    }

    public function test_manage_payment_add_with_file_upload_resolves_proof_url(): void
    {
        Storage::fake('drive');
        $sumit = $this->sumit();
        $student = $this->studentFor($sumit);

        $this->edit($student)
            ->callAction('managePayment', data: [
                'entry_action' => 'add',
                'type' => 'advance', 'amount' => 2500,
                'received_at' => now()->toDateTimeString(),
                'proof_upload' => [UploadedFile::fake()->image('proof.png')],
            ])
            ->assertHasNoActionErrors();

        $this->assertStringContainsString('payment-proofs/', (string) $student->payments()->latest('id')->first()->proof_url);
    }

    public function test_manage_payment_add_url_fallback_persists_proof_url(): void
    {
        Storage::fake('drive');
        $sumit = $this->sumit();
        $student = $this->studentFor($sumit);

        $this->edit($student)
            ->callAction('managePayment', data: [
                'entry_action' => 'add',
                'type' => 'advance', 'amount' => 1500,
                'received_at' => now()->toDateTimeString(),
                'proof_url' => 'https://drive.google.com/file/d/manual-url/view',
            ])
            ->assertHasNoActionErrors();

        $this->assertSame(
            'https://drive.google.com/file/d/manual-url/view',
            $student->payments()->latest('id')->first()->proof_url
        );
    }

    public function test_manage_payment_update_updates_selected_record(): void
    {
        $sumit = $this->sumit();
        $student = $this->studentFor($sumit);
        $payment = $student->payments()->create([
            'type' => 'advance', 'amount' => 10000, 'mode' => 'cash',
            'received_at' => now(), 'recorded_by_user_id' => $sumit->id,
        ]);

        $this->edit($student)
            ->callAction('managePayment', data: [
                'entry_action' => 'update', 'payment_id' => $payment->id,
                'type' => 'partial', 'amount' => 25000, 'mode' => 'upi',
                'received_at' => now()->toDateTimeString(),
            ])
            ->assertHasNoActionErrors();

        $this->assertEquals(25000.0, (float) $payment->fresh()->amount);
        $this->assertEquals('partial', $payment->fresh()->type);
    }

    public function test_manage_payment_delete_removes_selected_record(): void
    {
        $sumit = $this->sumit();
        $student = $this->studentFor($sumit);
        $payment = $student->payments()->create([
            'type' => 'advance', 'amount' => 10000, 'mode' => 'cash',
            'received_at' => now(), 'recorded_by_user_id' => $sumit->id,
        ]);

        $this->edit($student)
            ->callAction('managePayment', data: [
                'entry_action' => 'delete', 'payment_id' => $payment->id,
            ])
            ->assertHasNoActionErrors();

        $this->assertDatabaseMissing('payments', ['id' => $payment->id]);
    }

    public function test_manage_payout_add_creates_payout_with_recorder(): void
    {
        $sumit = $this->sumit();
        $student = $this->studentFor($sumit);

        $this->edit($student)
            ->callAction('managePayout', data: [
                'entry_action' => 'add',
                'payee_type' => 'college', 'payee_name' => 'GGSIPU',
                'amount' => 40000, 'status' => 'to_pay',
            ])
            ->assertHasNoActionErrors();

        $payout = $student->payouts()->first();
        $this->assertNotNull($payout);
        $this->assertEquals(40000.0, (float) $payout->amount);
        $this->assertEquals($sumit->id, $payout->recorded_by_user_id);
    }

    public function test_manage_payout_update_updates_selected_record(): void
    {
        $sumit = $this->sumit();
        $student = $this->studentFor($sumit);
        $payout = Payout::factory()->create([
            'student_id' => $student->id, 'amount' => 30000,
            'payee_type' => 'college', 'status' => 'to_pay',
            'recorded_by_user_id' => $sumit->id,
        ]);

        $this->edit($student)
            ->callAction('managePayout', data: [
                'entry_action' => 'update', 'payout_id' => $payout->id,
                'payee_type' => 'college', 'amount' => 55000,
                'status' => 'paid', 'paid_at' => now()->toDateTimeString(),
            ])
            ->assertHasNoActionErrors();

        $this->assertEquals(55000.0, (float) $payout->fresh()->amount);
        $this->assertEquals('paid', $payout->fresh()->status);
    }

    public function test_manage_payout_delete_removes_selected_record(): void
    {
        $sumit = $this->sumit();
        $student = $this->studentFor($sumit);
        $payout = Payout::factory()->create([
            'student_id' => $student->id, 'amount' => 30000,
            'recorded_by_user_id' => $sumit->id,
        ]);

        $this->edit($student)
            ->callAction('managePayout', data: [
                'entry_action' => 'delete', 'payout_id' => $payout->id,
            ])
            ->assertHasNoActionErrors();

        $this->assertDatabaseMissing('payouts', ['id' => $payout->id]);
    }
}
