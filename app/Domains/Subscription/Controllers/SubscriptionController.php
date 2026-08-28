<?php

namespace App\Domains\Subscription\Controllers;

use App\Domains\Organization\Services\BusinessContextService;
use App\Domains\Subscription\Models\SubscriptionPlan;
use App\Domains\Subscription\Services\SubscriptionCheckoutService;
use App\Domains\Subscription\Services\SubscriptionService;
use App\Http\Requests\SubscriptionCheckoutRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SubscriptionController
{
    public function __construct(
        private SubscriptionService $subscriptionService,
        private SubscriptionCheckoutService $checkoutService,
        private BusinessContextService $businessContext,
    ) {
    }

    /**
     * Get the current business subscription.
     */
    public function current(Request $request): JsonResponse
    {
        $business = $this->businessContext->current(
            $request->user()
        );

        if (! $business) {
            return response()->json([
                'success' => false,
                'message' => 'No active business context.',
                'code' => 'BUSINESS_CONTEXT_REQUIRED',
            ], 400);
        }

        $subscription = $business
            ->subscription()
            ->with('plan')
            ->first();

        if (! $subscription) {
            return response()->json([
                'success' => true,
                'subscription' => null,
            ]);
        }

        return response()->json([
            'success' => true,
            'subscription' => [
                'id' => $subscription->id,
                'status' => $subscription->status,

                'starts_at' =>
                    $subscription->starts_at?->toISOString(),

                'current_period_start' =>
                    $subscription->current_period_start?->toISOString(),

                'current_period_end' =>
                    $subscription->current_period_end?->toISOString(),

                'grace_period_ends_at' =>
                    $subscription->grace_period_ends_at?->toISOString(),

                'cancelled_at' =>
                    $subscription->cancelled_at?->toISOString(),

                'ended_at' =>
                    $subscription->ended_at?->toISOString(),

                'provider' => $subscription->provider,

                'plan' => [
                    'id' => $subscription->plan->id,
                    'name' => $subscription->plan->name,
                    'slug' => $subscription->plan->slug,
                    'description' => $subscription->plan->description,
                    'price' => $subscription->plan->price,
                    'currency' => $subscription->plan->currency,
                    'billing_interval' =>
                        $subscription->plan->billing_interval,
                    'customer_limit' =>
                        $subscription->plan->customer_limit,
                    'user_limit' =>
                        $subscription->plan->user_limit,
                    'branch_limit' =>
                        $subscription->plan->branch_limit,
                    'features' =>
                        $subscription->plan->features,
                ],
            ],
        ]);
    }

    /**
     * Get all active subscription plans.
     */
    public function plans(): JsonResponse
    {
        $plans = SubscriptionPlan::query()
            ->where('is_active', true)
            ->orderBy('price')
            ->get([
                'id',
                'name',
                'slug',
                'description',
                'price',
                'currency',
                'billing_interval',
                'customer_limit',
                'user_limit',
                'branch_limit',
                'features',
            ]);

        return response()->json([
            'success' => true,
            'plans' => $plans,
        ]);
    }

    /**
     * Initialize checkout for a subscription plan.
     */
    public function checkout(
        SubscriptionCheckoutRequest $request
    ): JsonResponse {
        $business = $this->businessContext->current(
            $request->user()
        );

        if (! $business) {
            return response()->json([
                'success' => false,
                'message' => 'Business context is required.',
                'code' => 'BUSINESS_CONTEXT_REQUIRED',
            ], 400);
        }

        $plan = SubscriptionPlan::query()
            ->where('id', $request->validated('plan_id'))
            ->firstOrFail();

        $result = $this->checkoutService->checkout(
            $business,
            $plan,
            $request->validated('email'),
        );

        return response()->json([
            'success' => true,
            'message' => 'Subscription checkout initialized successfully.',
            'data' => $result,
        ]);
    }
}