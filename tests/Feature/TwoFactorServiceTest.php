<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\TwoFactorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

class TwoFactorServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_generated_secret_is_long_enough_to_be_usable(): void
    {
        $s = (new TwoFactorService)->generateSecret();
        $this->assertGreaterThanOrEqual(16, strlen($s));
    }

    public function test_verify_code_accepts_a_valid_current_code(): void
    {
        $svc = new TwoFactorService;
        $secret = $svc->generateSecret();
        $validCode = (new Google2FA)->getCurrentOtp($secret);

        $this->assertTrue($svc->verifyCode($secret, $validCode));
    }

    public function test_verify_code_rejects_a_bad_code(): void
    {
        $svc = new TwoFactorService;
        $secret = $svc->generateSecret();

        $this->assertFalse($svc->verifyCode($secret, '000000'));
        $this->assertFalse($svc->verifyCode($secret, 'abcdef'));
        $this->assertFalse($svc->verifyCode($secret, '12345'));
    }

    public function test_otpauth_uri_contains_issuer_and_email(): void
    {
        $this->seed();
        $sumit = User::where('email', 'sumit@davya.local')->first();
        $uri = (new TwoFactorService)->otpauthUri($sumit, 'ABC123');

        $this->assertStringContainsString('Davya%20CRM', $uri);
        $this->assertStringContainsString('sumit%40davya.local', $uri);
        $this->assertStringContainsString('secret=ABC123', $uri);
    }

    public function test_recovery_codes_are_ten_distinct_codes(): void
    {
        $codes = (new TwoFactorService)->generateRecoveryCodes();
        $this->assertCount(10, $codes);
        $this->assertCount(10, array_unique($codes), 'all codes must be distinct');
    }

    public function test_consume_recovery_code_removes_it_and_returns_true(): void
    {
        $this->seed();
        $sumit = User::where('email', 'sumit@davya.local')->first();
        $codes = (new TwoFactorService)->generateRecoveryCodes();
        $sumit->totp_recovery_codes = json_encode($codes);
        $sumit->save();

        $svc = new TwoFactorService;
        $this->assertTrue($svc->consumeRecoveryCode($sumit, $codes[3]));

        $fresh = $sumit->fresh();
        $remaining = json_decode($fresh->totp_recovery_codes, true);
        $this->assertCount(9, $remaining);
        $this->assertNotContains($codes[3], $remaining);

        // Second use of the same code should fail
        $this->assertFalse($svc->consumeRecoveryCode($fresh, $codes[3]));
    }
}
