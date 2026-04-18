<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class VerifyFinanceToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $expected = (string) config('finance.capture_token');
        $provided = (string) $request->header('X-Finance-Token', '');

        if ($expected === '' || ! hash_equals($expected, $provided)) {
            return response()->json(['error' => 'unauthorized'], 401);
        }

        return $next($request);
    }
}
