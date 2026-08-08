<?php

namespace App\Http\Middleware;

use App\Domains\Organization\Services\BusinessContextService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use function setPermissionsTeamId;

class SetCurrentBusiness
{
    public function handle(
        Request $request,
        Closure $next
    ): Response {
        $user = $request->user();

        if ($user) {
            $context = app(BusinessContextService::class);

            $business = $context->current($user);

            setPermissionsTeamId($business?->id);
        } else {
            setPermissionsTeamId(null);
        }

        return $next($request);
    }
}