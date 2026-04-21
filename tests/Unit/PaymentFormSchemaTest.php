<?php

namespace Tests\Unit;

use App\Filament\Resources\Shared\PaymentFormSchema;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PaymentFormSchemaTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('drive');
    }

    public function test_resolves_upload_path_to_drive_url_and_unsets_upload_key(): void
    {
        Storage::disk('drive')->put('payment-proofs/test.png', 'fake-bytes');

        $data = [
            'amount'       => 5000,
            'type'         => 'advance',
            'proof_upload' => 'payment-proofs/test.png',
            'proof_url'    => null,
        ];

        $out = PaymentFormSchema::resolveProofUpload($data);

        $this->assertArrayNotHasKey('proof_upload', $out);
        $this->assertNotNull($out['proof_url']);
        $this->assertStringContainsString('payment-proofs/test.png', $out['proof_url']);
    }

    public function test_upload_wins_over_existing_url(): void
    {
        Storage::disk('drive')->put('payment-proofs/winner.pdf', 'fake-bytes');

        $data = [
            'proof_upload' => 'payment-proofs/winner.pdf',
            'proof_url'    => 'https://manual-url.example/keepme',
        ];

        $out = PaymentFormSchema::resolveProofUpload($data);

        $this->assertArrayNotHasKey('proof_upload', $out);
        $this->assertStringContainsString('payment-proofs/winner.pdf', $out['proof_url']);
        $this->assertStringNotContainsString('manual-url.example', $out['proof_url']);
    }

    public function test_no_upload_preserves_existing_url(): void
    {
        $data = [
            'proof_upload' => null,
            'proof_url'    => 'https://drive.google.com/file/d/abc/view',
        ];

        $out = PaymentFormSchema::resolveProofUpload($data);

        $this->assertArrayNotHasKey('proof_upload', $out);
        $this->assertSame('https://drive.google.com/file/d/abc/view', $out['proof_url']);
    }

    public function test_no_upload_and_no_url_leaves_proof_url_null(): void
    {
        $data = ['proof_upload' => null, 'proof_url' => null];

        $out = PaymentFormSchema::resolveProofUpload($data);

        $this->assertArrayNotHasKey('proof_upload', $out);
        $this->assertNull($out['proof_url']);
    }

    public function test_missing_keys_are_tolerated(): void
    {
        $data = ['amount' => 100, 'type' => 'advance'];

        $out = PaymentFormSchema::resolveProofUpload($data);

        $this->assertArrayNotHasKey('proof_upload', $out);
        $this->assertSame(100, $out['amount']);
        $this->assertSame('advance', $out['type']);
    }

    public function test_empty_string_upload_is_ignored(): void
    {
        $data = [
            'proof_upload' => '',
            'proof_url'    => 'https://keep.example',
        ];

        $out = PaymentFormSchema::resolveProofUpload($data);

        $this->assertArrayNotHasKey('proof_upload', $out);
        $this->assertSame('https://keep.example', $out['proof_url']);
    }
}
