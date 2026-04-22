<?php

namespace Tests\Feature;

use App\Filament\Resources\StudentResource\Pages\CreateStudent;
use App\Models\Payment;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class StudentFirstPaymentTest extends TestCase
{
    use RefreshDatabase;

    private User $sumit;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('drive');
        $this->seed();
        $this->sumit = User::where('email', 'sumit@davya.local')->firstOrFail();
        $this->actingAs($this->sumit);
    }

    private function baseStudentData(): array
    {
        return [
            'phone'         => '9100000301',
            'name'          => 'InlineTester',
            'owner_id'      => $this->sumit->id,
            'referrer_id'   => $this->sumit->id,
            'lead_source'   => 'Sumit',
            'stage'         => 'Lead Captured',
            'preference_r1' => 'ABC College',
        ];
    }

    public function test_creating_student_with_first_payment_persists_one_payment(): void
    {
        $data = $this->baseStudentData() + [
            'first_payment' => [
                'type'        => 'advance',
                'amount'      => 5000,
                'received_at' => now()->toDateTimeString(),
            ],
        ];

        Livewire::test(CreateStudent::class)
            ->fillForm($data)
            ->call('create')
            ->assertHasNoFormErrors();

        $student = Student::where('phone', '9100000301')->firstOrFail();
        $payments = Payment::where('student_id', $student->id)->get();

        $this->assertCount(1, $payments);
        $this->assertSame('advance', $payments[0]->type);
        $this->assertSame('5000.00', $payments[0]->amount);
        $this->assertSame($this->sumit->id, $payments[0]->recorded_by_user_id);
    }

    public function test_creating_student_without_first_payment_creates_no_payment(): void
    {
        Livewire::test(CreateStudent::class)
            ->fillForm($this->baseStudentData())
            ->call('create')
            ->assertHasNoFormErrors();

        $student = Student::where('phone', '9100000301')->firstOrFail();
        $this->assertSame(0, Payment::where('student_id', $student->id)->count());
    }

    public function test_first_payment_amount_without_type_blocks_submission(): void
    {
        $data = $this->baseStudentData() + [
            'first_payment' => [
                'amount'      => 5000,
                'type'        => null,
                'received_at' => null,
            ],
        ];

        Livewire::test(CreateStudent::class)
            ->fillForm($data)
            ->call('create')
            ->assertHasFormErrors(['first_payment.type']);

        $this->assertSame(0, Student::where('phone', '9100000301')->count());
        $this->assertSame(0, Payment::count());
    }

    public function test_first_payment_url_fallback_persists_proof_url_verbatim(): void
    {
        $data = $this->baseStudentData() + [
            'first_payment' => [
                'type'        => 'advance',
                'amount'      => 7500,
                'received_at' => now()->toDateTimeString(),
                'proof_url'   => 'https://drive.google.com/file/d/fallback/view',
            ],
        ];

        Livewire::test(CreateStudent::class)
            ->fillForm($data)
            ->call('create')
            ->assertHasNoFormErrors();

        $student = Student::where('phone', '9100000301')->firstOrFail();
        $payment = Payment::where('student_id', $student->id)->firstOrFail();
        $this->assertSame('https://drive.google.com/file/d/fallback/view', $payment->proof_url);
    }

    public function test_first_payment_file_upload_resolves_to_proof_url(): void
    {
        $file = UploadedFile::fake()->image('proof.png');

        $data = $this->baseStudentData() + [
            'first_payment' => [
                'type'         => 'advance',
                'amount'       => 9999,
                'received_at'  => now()->toDateTimeString(),
                'proof_upload' => [$file],
            ],
        ];

        Livewire::test(CreateStudent::class)
            ->fillForm($data)
            ->call('create')
            ->assertHasNoFormErrors();

        $student = Student::where('phone', '9100000301')->firstOrFail();
        $payment = Payment::where('student_id', $student->id)->firstOrFail();

        $this->assertNotNull($payment->proof_url);
        $this->assertStringContainsString('payment-proofs/', $payment->proof_url);
    }
}
