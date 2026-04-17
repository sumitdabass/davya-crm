<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\TwoFactorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TwoFactorMiddlewareTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    public function test_middleware_passes_through_for_user_without_2fa(): void
    {
        $sumit = User::where('email', 'sumit@davya.local')->first();
        $sumit->must_change_password = false;
        $sumit->save();
        // Ensure no 2FA
        $this->assertFalse($sumit->hasTwoFactorEnabled());

        $this->actingAs($sumit)->get('/admin')->assertStatus(200);
    }

    public function test_middleware_redirects_to_challenge_if_2fa_enabled_and_not_verified(): void
    {
        $sumit = User::where('email', 'sumit@davya.local')->first();
        $sumit->must_change_password = false;
        $sumit->save();
        $sumit->totp_secret = (new TwoFactorService)->generateSecret();
        $sumit->totp_confirmed_at = now();
        $sumit->save();

        $this->actingAs($sumit);
        $resp = $this->get('/admin');

        $resp->assertRedirect(route('filament.admin.pages.two-factor-challenge'));
    }

    public function test_middleware_lets_user_through_once_session_flag_is_set(): void
    {
        $sumit = User::where('email', 'sumit@davya.local')->first();
        $sumit->must_change_password = false;
        $sumit->save();
        $sumit->totp_secret = (new TwoFactorService)->generateSecret();
        $sumit->totp_confirmed_at = now();
        $sumit->save();

        $this->actingAs($sumit)
            ->withSession(['two_factor_verified' => true])
            ->get('/admin')
            ->assertStatus(200);
    }
}
