<?php

namespace App\Http\Middleware;

use App\Domains\Subscription\Services\SubscriptionService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureSubscriptionIsUsable
{
    public function handle(
        Request $request,
        Closure $next
    ): Response {
        $business = $request->attributes->get(
            'current_business'
        );

        if (! $business) {
            return response()->json([
                'success' => false,
                'message' => 'No business context is available.',
                'code' => 'BUSINESS_CONTEXT_REQUIRED',
            ], 403);
        }

        $subscription = $business->subscription;

        /*
         * No subscription means the business has not
         * completed its MerchantOS billing setup.
         */
        if (! $subscription) {
            return response()->json([
                'success' => false,
                'message' => 'A subscription is required to access MerchantOS.',
                'code' => 'SUBSCRIPTION_REQUIRED',
            ], 402);
        }

        $service = app(
            SubscriptionService::class
        );

        if (! $service->isUsable($subscription)) {
            return response()->json([
                'success' => false,
                'message' => 'Your subscription does not currently allow access to MerchantOS.',
                'code' => 'SUBSCRIPTION_INACTIVE',
                'subscription_status' => $subscription->status,
            ], 402);
        }

        return $next($request);
    }
}