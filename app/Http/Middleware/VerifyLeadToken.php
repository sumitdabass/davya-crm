<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class VerifyLeadToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $expected = (string) config('leads.capture_token');
        $provided = (string) $request->header('X-Lead-Token', '');

        if ($expected === '' || ! hash_equals($expected, $provided)) {
            return response()->json(['error' => 'unauthorized'], 401);
        }

        return $next($request);
    }
}
