<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class AbsoluteSessionTimeout
{
    private const MAX_SESSION_SECONDS = 7 * 24 * 60 * 60;

    public function handle(Request $request, Closure $next): Response
    {
        if (! Auth::check()) {
            return $next($request);
        }

        $loginAt = $request->session()->get('_login_at');
        if (! $loginAt) {
            $request->session()->put('_login_at', now()->timestamp);
            return $next($request);
        }

        if (now()->timestamp - $loginAt > self::MAX_SESSION_SECONDS) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect('/admin/login')
                ->with('status', 'Session expired (7-day max). Please log in again.');
        }

        return $next($request);
    }
}
