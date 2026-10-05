<?php

namespace Tests\Feature\Subscription;

use App\Domains\Customer\Models\Customer;
use App\Domains\Organization\Models\Branch;
use App\Domains\Organization\Models\Business;
use App\Domains\Organization\Models\BusinessUser;
use App\Domains\Subscription\Models\Subscription;
use App\Domains\Subscription\Models\SubscriptionPlan;
use App\Domains\Subscription\Services\SubscriptionLimitService;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class SubscriptionLimitServiceTest extends TestCase
{
    use RefreshDatabase;

    private SubscriptionLimitService $limitService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->limitService = app(SubscriptionLimitService::class);
    }

    private function createBusinessWithPlan(array $plan = []): array
    {
        $business = Business::factory()->create();

        $plan = SubscriptionPlan::factory()->create(array_merge([
            'customer_limit' => 2,
            'user_limit' => 2,
            'branch_limit' => 2,
            'is_active' => true,
        ], $plan));

        $subscription = Subscription::factory()->create([
            'business_id' => $business->id,
            'plan_id' => $plan->id,
            'status' => 'active',
            'current_period_end' => now()->addMonth(),
        ]);

        return [$business, $subscription];
    }

    public function test_customer_limit_is_enforced(): void
    {
        [$business] = $this->createBusinessWithPlan([
            'customer_limit' => 2,
        ]);

        Customer::factory()->count(2)->create([
            'business_id' => $business->id,
        ]);

        $this->expectException(ValidationException::class);

        $this->limitService->ensureCustomerCapacity($business);
    }

    public function test_customer_limit_allows_capacity(): void
    {
        [$business] = $this->createBusinessWithPlan([
            'customer_limit' => 2,
        ]);

        Customer::factory()->create([
            'business_id' => $business->id,
        ]);

        $this->limitService->ensureCustomerCapacity($business);

        $this->assertTrue(true);
    }

    public function test_null_customer_limit_is_unlimited(): void
    {
        [$business] = $this->createBusinessWithPlan([
            'customer_limit' => null,
        ]);

        Customer::factory()->count(5)->create([
            'business_id' => $business->id,
        ]);

        $this->limitService->ensureCustomerCapacity($business);

        $this->assertTrue(true);
    }

    public function test_user_limit_counts_only_active_memberships(): void
    {
        [$business] = $this->createBusinessWithPlan([
            'user_limit' => 2,
        ]);

        BusinessUser::create([
            'business_id' => $business->id,
            'user_id' => User::factory()->create()->id,
            'status' => 'active',
            'joined_at' => now(),
        ]);

        BusinessUser::create([
            'business_id' => $business->id,
            'user_id' => User::factory()->create()->id,
            'status' => 'inactive',
            'joined_at' => now(),
        ]);

        $this->limitService->ensureUserCapacity($business);

        $this->assertTrue(true);
    }

    public function test_user_limit_blocks_when_active_members_reach_limit(): void
    {
        [$business] = $this->createBusinessWithPlan([
            'user_limit' => 2,
        ]);

        BusinessUser::create([
            'business_id' => $business->id,
            'user_id' => User::factory()->create()->id,
            'status' => 'active',
            'joined_at' => now(),
        ]);

        BusinessUser::create([
            'business_id' => $business->id,
            'user_id' => User::factory()->create()->id,
            'status' => 'active',
            'joined_at' => now(),
        ]);

        $this->expectException(ValidationException::class);

        $this->limitService->ensureUserCapacity($business);
    }

    public function test_branch_limit_is_enforced(): void
    {
        [$business] = $this->createBusinessWithPlan([
            'branch_limit' => 1,
        ]);

        Branch::create([
            'business_id' => $business->id,
            'name' => 'Test Branch',
            'code' => 'TEST-001',
            'city' => 'Owerri',
            'state' => 'Imo',
            'country' => 'Nigeria',
            'is_head_office' => false,
        ]);

        $this->expectException(ValidationException::class);

        $this->limitService->ensureBranchCapacity($business);
    }

    public function test_missing_subscription_is_rejected(): void
    {
        $business = Business::factory()->create();

        $this->expectException(ValidationException::class);

        $this->limitService->ensureCustomerCapacity($business);
    }

    public function test_restricted_subscription_is_rejected(): void
    {
        [$business] = $this->createBusinessWithPlan();

        $business->subscription()->update([
            'status' => 'restricted',
        ]);

        $this->expectException(ValidationException::class);

        $this->limitService->ensureBranchCapacity($business);
    }
}
