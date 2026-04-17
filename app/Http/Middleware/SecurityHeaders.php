<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        // HTTPS enforcement — tells browsers to refuse HTTP for a year, include subdomains.
        // Safe because AutoSSL is live on davyas.ipu.co.in. Do not apply in local/test.
        if (app()->environment('production')) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        // Clickjacking protection — refuse to render inside any <frame>/<iframe>.
        $response->headers->set('X-Frame-Options', 'DENY');

        // Stop browsers from MIME-sniffing text/plain into text/html (XSS mitigation).
        $response->headers->set('X-Content-Type-Options', 'nosniff');

        // Don't leak full URL in Referer to third-party destinations.
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');

        // Disable powerful browser features we never use on this admin panel.
        $response->headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=()');

        return $response;
    }
}
