<?php

namespace Tests\Feature;

use App\Filament\Resources\StudentResource\Pages\EditStudent;
use App\Filament\Resources\StudentResource\RelationManagers\PaymentsRelationManager;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class PaymentsRelationManagerTest extends TestCase
{
    use RefreshDatabase;

    public function test_create_payment_defaults_recorded_by_user_id_to_current_user(): void
    {
        $this->seed();

        $sumit = User::where('email', 'sumit@davya.local')->firstOrFail();
        $student = Student::create([
            'phone' => '9100000099',
            'name' => 'TestStudent',
            'owner_id' => $sumit->id,
            'referrer_id' => $sumit->id,
            'lead_source' => 'Sumit',
        ]);

        $this->actingAs($sumit);

        Livewire::test(PaymentsRelationManager::class, [
            'ownerRecord' => $student,
            'pageClass' => EditStudent::class,
        ])
            ->callTableAction('create', data: [
                'type' => 'advance',
                'amount' => 10000,
                'mode' => 'cash',
                'received_at' => now()->toDateTimeString(),
            ])
            ->assertHasNoTableActionErrors();

        $this->assertEquals(1, $student->payments()->count());
        $this->assertEquals($sumit->id, $student->payments()->first()->recorded_by_user_id);
    }

    public function test_payments_tab_accepts_file_upload_and_resolves_to_proof_url(): void
    {
        \Illuminate\Support\Facades\Storage::fake('drive');
        $this->seed();

        $sumit = User::where('email', 'sumit@davya.local')->firstOrFail();
        $student = Student::create([
            'phone'       => '9100000201',
            'name'        => 'UploadTester',
            'owner_id'    => $sumit->id,
            'referrer_id' => $sumit->id,
            'lead_source' => 'Sumit',
        ]);

        $this->actingAs($sumit);

        $file = \Illuminate\Http\UploadedFile::fake()->image('proof.png');

        Livewire::test(PaymentsRelationManager::class, [
            'ownerRecord' => $student,
            'pageClass'   => EditStudent::class,
        ])
            ->callTableAction('create', data: [
                'type'                => 'advance',
                'amount'              => 2500,
                'received_at'         => now()->toDateTimeString(),
                'proof_upload'        => [$file],
                'recorded_by_user_id' => $sumit->id,
            ])
            ->assertHasNoTableActionErrors();

        $payment = $student->payments()->latest('id')->first();
        $this->assertNotNull($payment);
        $this->assertNotNull($payment->proof_url);
        $this->assertStringContainsString('payment-proofs/', $payment->proof_url);
    }

    public function test_payments_tab_url_fallback_still_persists_proof_url_unchanged(): void
    {
        \Illuminate\Support\Facades\Storage::fake('drive');
        $this->seed();

        $sumit = User::where('email', 'sumit@davya.local')->firstOrFail();
        $student = Student::create([
            'phone'       => '9100000202',
            'name'        => 'UrlFallbackTester',
            'owner_id'    => $sumit->id,
            'referrer_id' => $sumit->id,
            'lead_source' => 'Sumit',
        ]);

        $this->actingAs($sumit);

        Livewire::test(PaymentsRelationManager::class, [
            'ownerRecord' => $student,
            'pageClass'   => EditStudent::class,
        ])
            ->callTableAction('create', data: [
                'type'                => 'advance',
                'amount'              => 1500,
                'received_at'         => now()->toDateTimeString(),
                'proof_url'           => 'https://drive.google.com/file/d/manual-url/view',
                'recorded_by_user_id' => $sumit->id,
            ])
            ->assertHasNoTableActionErrors();

        $payment = $student->payments()->latest('id')->first();
        $this->assertSame('https://drive.google.com/file/d/manual-url/view', $payment->proof_url);
    }
}
