<?php

namespace Tests\Feature\Subscription;

use App\Domains\Organization\Models\Business;
use App\Domains\Organization\Models\BusinessUser;
use App\Domains\Subscription\Models\Subscription;
use App\Domains\Subscription\Models\SubscriptionPlan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class SubscriptionAccessMiddlewareTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        /*
         * Temporary test endpoint.
         *
         * This lets us test the middleware independently
         * of Product, Sales, Inventory, etc.
         */
        Route::middleware([
            'auth:sanctum',
            'business.context',
            'subscription',
        ])->get(
            '/api/test/subscription-protected',
            function () {
                return response()->json([
                    'success' => true,
                    'message' => 'Access granted.',
                ]);
            }
        );
    }

    private function createBusinessWithUser(): array
    {
        $business = Business::factory()->create();

        $user = User::factory()->create();

        BusinessUser::create([
            'business_id' => $business->id,
            'user_id' => $user->id,
            'status' => 'active',
            'joined_at' => now(),
        ]);

        return [$business, $user];
    }

    private function createPlan(): SubscriptionPlan
    {
        return SubscriptionPlan::create([
            'name' => 'Test Plan',
            'slug' => 'test-plan-' . fake()->unique()->numberBetween(1, 999999),
            'price' => 5000,
            'billing_interval' => 'monthly',
            'customer_limit' => 50,
            'user_limit' => 3,
            'branch_limit' => 1,
            'features' => [
                'products' => true,
                'inventory' => true,
                'sales' => true,
                'customers' => true,
            ],
            'is_active' => true,
        ]);
    }

    private function createSubscription(
        Business $business,
        SubscriptionPlan $plan,
        string $status
    ): Subscription {
        return Subscription::create([
            'business_id' => $business->id,
            'plan_id' => $plan->id,

            'status' => $status,

            'starts_at' => now()->subDay(),

            'current_period_start' => now()->subDay(),

            'current_period_end' => now()->addDays(30),

            'grace_period_ends_at' => null,

            'provider' => null,

            'provider_subscription_id' => null,

            'provider_customer_id' => null,

            'provider_plan_code' => null,

            'cancelled_at' => null,

            'metadata' => null,
        ]);
    }

    public function test_business_without_subscription_is_blocked(): void
    {
        [$business, $user] = $this->createBusinessWithUser();

        $response = $this
            ->actingAs($user)
            ->withHeader(
                'X-Business-ID',
                $business->id
            )
            ->getJson('/api/test/subscription-protected');

        $response
            ->assertStatus(402)
            ->assertJsonPath(
                'code',
                'SUBSCRIPTION_REQUIRED'
            );
    }

    public function test_trial_subscription_allows_access(): void
    {
        [$business, $user] = $this->createBusinessWithUser();

        $plan = $this->createPlan();

        $this->createSubscription(
            $business,
            $plan,
            'trial'
        );

        $response = $this
            ->actingAs($user)
            ->withHeader(
                'X-Business-ID',
                $business->id
            )
            ->getJson('/api/test/subscription-protected');

        $response
            ->assertSuccessful()
            ->assertJsonPath(
                'success',
                true
            );
    }

    public function test_active_subscription_allows_access(): void
    {
        [$business, $user] = $this->createBusinessWithUser();

        $plan = $this->createPlan();

        $this->createSubscription(
            $business,
            $plan,
            'active'
        );

        $response = $this
            ->actingAs($user)
            ->withHeader(
                'X-Business-ID',
                $business->id
            )
            ->getJson('/api/test/subscription-protected');

        $response
            ->assertSuccessful();
    }

    public function test_grace_period_allows_access(): void
    {
        [$business, $user] = $this->createBusinessWithUser();

        $plan = $this->createPlan();

        $subscription = $this->createSubscription(
            $business,
            $plan,
            'grace_period'
        );

        $subscription->update([
            'grace_period_ends_at' => now()->addDays(7),
        ]);

        $response = $this
            ->actingAs($user)
            ->withHeader(
                'X-Business-ID',
                $business->id
            )
            ->getJson('/api/test/subscription-protected');

        $response
            ->assertSuccessful();
    }

    public function test_restricted_subscription_is_blocked(): void
    {
        [$business, $user] = $this->createBusinessWithUser();

        $plan = $this->createPlan();

        $this->createSubscription(
            $business,
            $plan,
            'restricted'
        );

        $response = $this
            ->actingAs($user)
            ->withHeader(
                'X-Business-ID',
                $business->id
            )
            ->getJson('/api/test/subscription-protected');

        $response
            ->assertStatus(402)
            ->assertJsonPath(
                'code',
                'SUBSCRIPTION_INACTIVE'
            );
    }

    public function test_suspended_subscription_is_blocked(): void
    {
        [$business, $user] = $this->createBusinessWithUser();

        $plan = $this->createPlan();

        $this->createSubscription(
            $business,
            $plan,
            'suspended'
        );

        $response = $this
            ->actingAs($user)
            ->withHeader(
                'X-Business-ID',
                $business->id
            )
            ->getJson('/api/test/subscription-protected');

        $response
            ->assertStatus(402)
            ->assertJsonPath(
                'code',
                'SUBSCRIPTION_INACTIVE'
            );
    }

    public function test_expired_grace_period_is_blocked(): void
    {
        [$business, $user] = $this->createBusinessWithUser();

        $plan = $this->createPlan();

        $subscription = $this->createSubscription(
            $business,
            $plan,
            'grace_period'
        );

        $subscription->update([
            'grace_period_ends_at' => now()->subDay(),
        ]);

        $response = $this
            ->actingAs($user)
            ->withHeader(
                'X-Business-ID',
                $business->id
            )
            ->getJson('/api/test/subscription-protected');

        $response
            ->assertStatus(402)
            ->assertJsonPath(
                'code',
                'SUBSCRIPTION_INACTIVE'
            );
    }
}
