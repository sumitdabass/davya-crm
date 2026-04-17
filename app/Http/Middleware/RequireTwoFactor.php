<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class RequireTwoFactor
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! Auth::check()) {
            return $next($request);
        }

        $user = Auth::user();
        if (! $user->hasTwoFactorEnabled()) {
            return $next($request);
        }

        if ($request->session()->get('two_factor_verified') === true) {
            return $next($request);
        }

        if ($request->routeIs(
            'filament.admin.pages.two-factor-challenge',
            'filament.admin.auth.logout',
        )) {
            return $next($request);
        }

        return redirect()->route('filament.admin.pages.two-factor-challenge');
    }
}
