<?php

namespace App\Filament\Auth;

use DanHarrin\LivewireRateLimiting\Exceptions\TooManyRequestsException;
use Filament\Facades\Filament;
use Filament\Http\Responses\Auth\Contracts\LoginResponse;
use Filament\Models\Contracts\FilamentUser;
use Filament\Pages\Auth\Login as BaseLogin;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

class LockoutLogin extends BaseLogin
{
    private const MAX_FAILS    = 5;
    private const LOCKOUT_MIN  = 15;

    public function authenticate(): ?LoginResponse
    {
        $data = $this->form->getState();
        $emailKey = $this->lockoutKey($data['email'] ?? '');

        // Account-level lockout first (per email, independent of IP).
        // Checked BEFORE Filament's IP-based rateLimit so lockout always surfaces
        // as a form error even when IP throttle would otherwise return silently.
        if (RateLimiter::tooManyAttempts($emailKey, self::MAX_FAILS)) {
            $seconds = RateLimiter::availableIn($emailKey);
            throw ValidationException::withMessages([
                'data.email' => "Account temporarily locked. Try again in {$this->formatSeconds($seconds)}.",
            ]);
        }

        try {
            $this->rateLimit(self::MAX_FAILS);
        } catch (TooManyRequestsException $exception) {
            $this->getRateLimitedNotification($exception)?->send();
            return null;
        }

        if (! Filament::auth()->attempt($this->getCredentialsFromFormData($data), $data['remember'] ?? false)) {
            RateLimiter::hit($emailKey, self::LOCKOUT_MIN * 60);
            $this->throwFailureValidationException();
        }

        $user = Filament::auth()->user();

        if (($user instanceof FilamentUser) && (! $user->canAccessPanel(Filament::getCurrentPanel()))) {
            Filament::auth()->logout();
            RateLimiter::hit($emailKey, self::LOCKOUT_MIN * 60);
            $this->throwFailureValidationException();
        }

        // Success clears prior failures for this email.
        RateLimiter::clear($emailKey);

        session()->regenerate();

        return app(LoginResponse::class);
    }

    public static function lockoutKey(string $email): string
    {
        return 'login-fails:'.sha1(strtolower(trim($email)));
    }

    private function formatSeconds(int $seconds): string
    {
        if ($seconds < 60) {
            return "{$seconds} seconds";
        }
        $min = (int) ceil($seconds / 60);
        return "{$min} minute".($min === 1 ? '' : 's');
    }
}
