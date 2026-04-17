<?php

namespace Tests\Feature;

use App\Filament\Auth\LockoutLogin;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Livewire;
use Tests\TestCase;

class AccountLockoutTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();

        // Set a known password for Sumit so we can test success-after-lockout.
        $sumit = User::where('email', 'sumit@davya.local')->first();
        $sumit->password = Hash::make('CorrectPass2026!');
        $sumit->must_change_password = false;
        $sumit->save();
    }

    public function test_five_failed_logins_lock_the_account_for_fifteen_minutes(): void
    {
        for ($i = 0; $i < 5; $i++) {
            Livewire::test(LockoutLogin::class)
                ->fillForm(['email' => 'sumit@davya.local', 'password' => 'WRONG'])
                ->call('authenticate');
        }

        $key = LockoutLogin::lockoutKey('sumit@davya.local');
        $this->assertTrue(RateLimiter::tooManyAttempts($key, 5), 'After 5 failed attempts, RateLimiter should report too-many.');

        Livewire::test(LockoutLogin::class)
            ->fillForm(['email' => 'sumit@davya.local', 'password' => 'CorrectPass2026!'])
            ->call('authenticate')
            ->assertHasErrors(['data.email']);
    }

    public function test_successful_login_clears_failure_counter(): void
    {
        for ($i = 0; $i < 3; $i++) {
            Livewire::test(LockoutLogin::class)
                ->fillForm(['email' => 'sumit@davya.local', 'password' => 'WRONG'])
                ->call('authenticate');
        }

        $key = LockoutLogin::lockoutKey('sumit@davya.local');
        $this->assertSame(3, RateLimiter::attempts($key));

        Livewire::test(LockoutLogin::class)
            ->fillForm(['email' => 'sumit@davya.local', 'password' => 'CorrectPass2026!'])
            ->call('authenticate')
            ->assertHasNoFormErrors();

        $this->assertSame(0, RateLimiter::attempts($key));
    }

    public function test_lockout_is_per_email_not_shared_across_accounts(): void
    {
        for ($i = 0; $i < 5; $i++) {
            Livewire::test(LockoutLogin::class)
                ->fillForm(['email' => 'sumit@davya.local', 'password' => 'WRONG'])
                ->call('authenticate');
        }
        $this->assertTrue(RateLimiter::tooManyAttempts(LockoutLogin::lockoutKey('sumit@davya.local'), 5));

        // Another account should still be able to log in (not locked).
        $this->assertFalse(RateLimiter::tooManyAttempts(LockoutLogin::lockoutKey('nisha@davya.local'), 5));
    }

    public function test_lockout_key_is_case_insensitive_on_email(): void
    {
        $k1 = LockoutLogin::lockoutKey('Sumit@Davya.local');
        $k2 = LockoutLogin::lockoutKey('sumit@davya.local');
        $this->assertSame($k1, $k2);
    }
}
