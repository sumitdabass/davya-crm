<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class RequirePasswordChange
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! Auth::check()) {
            return $next($request);
        }

        if (! Auth::user()->must_change_password) {
            return $next($request);
        }

        // Allow the change-password page itself and logout
        if ($request->routeIs('filament.admin.pages.change-password', 'filament.admin.auth.logout')) {
            return $next($request);
        }

        return redirect()->route('filament.admin.pages.change-password');
    }
}
