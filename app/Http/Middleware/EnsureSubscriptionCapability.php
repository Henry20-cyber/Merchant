<?php

namespace App\Http\Middleware;

use App\Domains\Subscription\Services\SubscriptionCapabilityService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureSubscriptionCapability
{
    public function __construct(
        private SubscriptionCapabilityService $capabilityService,
    ) {
    }

    public function handle(
        Request $request,
        Closure $next,
        string $capability
    ): Response {
        $business = $request->attributes->get(
            'current_business'
        );

        if (! $business) {
            return response()->json([
                'success' => false,
                'message' => 'No business context is available.',
                'code' => 'BUSINESS_CONTEXT_REQUIRED',
            ], 404);
        }

        $subscription = $business->subscription;

        if (! $subscription) {
            return response()->json([
                'success' => false,
                'message' => 'This business does not have an active subscription.',
                'code' => 'SUBSCRIPTION_REQUIRED',
            ], 403);
        }

        if (! $this->capabilityService->allows(
            $subscription,
            $capability
        )) {
            return response()->json([
                'success' => false,
                'message' => 'Your current subscription plan does not include this feature.',
                'code' => 'SUBSCRIPTION_CAPABILITY_REQUIRED',
                'capability' => $capability,
            ], 403);
        }

        return $next($request);
    }
}
