<?php

namespace App\Http\Middleware;

use App\Domains\Organization\Models\Business;
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

        if (! $user) {
            setPermissionsTeamId(null);

            return $next($request);
        }

        /*
         * API requests should provide an explicit business context.
         */
        $businessId = $request->header('X-Business-ID');

        if ($businessId) {
            $business = Business::query()
                ->where('id', $businessId)
                ->whereHas('memberships', function ($query) use ($user) {
                    $query
                        ->where('user_id', $user->id)
                        ->where('status', 'active');
                })
                ->first();

            if (! $business) {
                return response()->json([
                    'success' => false,
                    'message' => 'You do not belong to this business.',
                ], 403);
            }

            setPermissionsTeamId($business->id);

            return $next($request);
        }

        /*
         * Fall back to the existing session-based context.
         */
        $context = app(BusinessContextService::class);

        $business = $context->current($user);

        if ($business) {
            setPermissionsTeamId($business->id);
        } else {
            setPermissionsTeamId(null);
        }

        return $next($request);
    }
}