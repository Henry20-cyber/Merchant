<?php

namespace App\Http\Middleware;

use App\Domains\Organization\Models\Business;
use App\Domains\Organization\Services\BusinessContextService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use function getPermissionsTeamId;
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
         * API requests can explicitly provide the business
         * they want to operate against.
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

            /*
             * Set the Spatie permission team for this request.
             */
            setPermissionsTeamId($business->id);

            return $next($request);
        }

             /*
         * Fall back to MerchantOS's existing business context.
         */
        $context = app(BusinessContextService::class);

        $business = $context->current($user);

        if ($business) {
            setPermissionsTeamId($business->id);
        }

        logger()->debug('MerchantOS permission team', [
            'user_id' => $user->id,
            'team_id' => getPermissionsTeamId(),
            'business_context' => $business?->id,
        ]);

        return $next($request);
    }
}