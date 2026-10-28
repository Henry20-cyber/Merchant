<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureFounderAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $founderEmail = (string) config('app.founder_email');

        // Fail closed when deployment configuration is missing.
        if (!$user || $founderEmail === '' || !hash_equals(mb_strtolower($founderEmail), mb_strtolower((string) $user->email))) {
            return response()->json([
                'success' => false,
                'message' => 'Founder access is restricted.',
            ], 403);
        }

        return $next($request);
    }
}
