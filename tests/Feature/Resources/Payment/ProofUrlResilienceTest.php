<?php

namespace Tests\Feature\Resources\Payment;

use App\Filament\Resources\Shared\PaymentFormSchema;
use Illuminate\Contracts\Filesystem\Cloud;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ProofUrlResilienceTest extends TestCase
{
    use RefreshDatabase;

    public function test_resolve_proof_upload_returns_null_when_drive_throws(): void
    {
        // Adaptation: url() is declared on the Cloud contract, not the base
        // Filesystem contract — mocking Filesystem fails with
        // "method url cannot be configured". Cloud extends Filesystem.
        $fake = $this->createMock(Cloud::class);
        $fake->method('url')
            ->willThrowException(new \RuntimeException('Drive auth expired'));

        Storage::set(PaymentFormSchema::DRIVE_DISK, $fake);

        $result = PaymentFormSchema::resolveProofUpload([
            'proof_upload' => 'pending/abc123.pdf',
            'amount' => 1000,
        ]);

        $this->assertArrayNotHasKey('proof_upload', $result, 'transient key must be stripped');
        $this->assertNull($result['proof_url'], 'proof_url must be null on Drive failure');
    }

    public function test_resolve_proof_upload_keeps_existing_proof_url_when_no_new_upload(): void
    {
        $result = PaymentFormSchema::resolveProofUpload([
            'proof_url' => 'https://drive.google.com/existing.pdf',
        ]);
        $this->assertSame('https://drive.google.com/existing.pdf', $result['proof_url']);
    }
}
