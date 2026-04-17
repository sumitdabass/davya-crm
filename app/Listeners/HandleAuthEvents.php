<?php

namespace App\Listeners;

use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class HandleAuthEvents
{
    public function handleLogin(Login $event): void
    {
        $user = $event->user;
        $currentSessionId = session()->getId();

        // Kill all other sessions for this user — one active session at a time.
        // Relies on database session driver; a no-op for other drivers.
        if (config('session.driver') === 'database' && $user && isset($user->id)) {
            DB::table(config('session.table', 'sessions'))
                ->where('user_id', $user->id)
                ->where('id', '!=', $currentSessionId)
                ->delete();
        }

        Log::channel(config('logging.default'))->info('auth.login', [
            'user_id' => $user?->id,
            'email'   => $user?->email,
            'ip'      => request()->ip(),
            'ua'      => substr((string) request()->userAgent(), 0, 200),
        ]);
    }

    public function handleLogout(Logout $event): void
    {
        Log::channel(config('logging.default'))->info('auth.logout', [
            'user_id' => $event->user?->id,
            'email'   => $event->user?->email ?? null,
            'ip'      => request()->ip(),
        ]);
    }

    public function handleFailed(Failed $event): void
    {
        Log::channel(config('logging.default'))->warning('auth.failed', [
            'attempted_email' => $event->credentials['email'] ?? null,
            'ip'              => request()->ip(),
            'ua'              => substr((string) request()->userAgent(), 0, 200),
        ]);
    }
}
