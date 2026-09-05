<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class DebugSession
{
    public function handle(Request $request, Closure $next): Response
    {
       logger()->channel('single')->info('MERCHANTOS SESSION DEBUG', [
    'path' => $request->path(),
    'method' => $request->method(),

    'session_id' => $request->hasSession()
        ? $request->session()->getId()
        : null,

    'session_token' => $request->hasSession()
        ? $request->session()->token()
        : null,

    'xsrf_header_present' => $request->hasHeader('X-XSRF-TOKEN'),

    'xsrf_header_length' =>
        strlen((string) $request->header('X-XSRF-TOKEN')),

    'session_cookie_present' =>
        $request->cookies->has(config('session.cookie')),

    'session_cookie_name' => config('session.cookie'),

    'all_cookie_names' => array_keys($request->cookies->all()),
]);

        return $next($request);
    }
}