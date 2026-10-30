<?php

namespace Tests\Feature\Subscription;

use App\Domains\Organization\Models\Business;
use App\Domains\Organization\Models\BusinessUser;
use App\Models\User;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use App\Domains\Payment\Contracts\PaymentGateway;
use App\Domains\Subscription\Models\Subscription;
use App\Domains\Subscription\Models\SubscriptionPlan;
use App\Domains\Subscription\Services\SubscriptionBillingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Mockery;
use Tests\TestCase;

class SubscriptionBillingServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_disabling_renewal_keeps_subscription_active(): void
    {
        $business = Business::factory()->create();

        $actor = $this->makeBillingActor($business);
        $actor = $this->makeBillingActor($business);
        $plan = $this->createPaidPlan();
        $subscription = $this->createSubscription($business, $plan);

        $gateway = Mockery::mock(PaymentGateway::class);
        $gateway->shouldReceive('disableSubscription')
            ->once()
            ->with('SUB_TEST_123', 'TOKEN_TEST_123')
            ->andReturn(['success' => true, 'raw' => []]);

        $result = app(SubscriptionBillingService::class, [
            'paymentGateway' => $gateway,
        ])->disableRenewal($subscription, $actor);

        expect($result->auto_renew)->toBeFalse();
        expect($result->status)->toBe('active');
        expect($result->cancelled_at)->not->toBeNull();
        expect($result->current_period_end->isFuture())->toBeTrue();
    }

    public function test_enabling_renewal_restores_auto_renewal(): void
    {
        $business = Business::factory()->create();

        $actor = $this->makeBillingActor($business);
        $plan = $this->createPaidPlan();
        $subscription = $this->createSubscription(
            $business,
            $plan,
            autoRenew: false,
        );

        $gateway = Mockery::mock(PaymentGateway::class);
        $gateway->shouldReceive('enableSubscription')
            ->once()
            ->with('SUB_TEST_123', 'TOKEN_TEST_123')
            ->andReturn(['success' => true, 'raw' => []]);

        $result = app(SubscriptionBillingService::class, [
            'paymentGateway' => $gateway,
        ])->enableRenewal($subscription, $actor);

        expect($result->auto_renew)->toBeTrue();
        expect($result->status)->toBe('active');
        expect($result->cancelled_at)->toBeNull();
    }


    public function test_member_without_billing_permission_cannot_change_renewal(): void
    {
        $business = Business::factory()->create();

        $actor = $this->makeBillingActor($business);
        $actor = User::factory()->create();
        BusinessUser::create([
            'business_id' => $business->id,
            'user_id' => $actor->id,
            'status' => 'active',
            'joined_at' => now(),
        ]);
        $plan = $this->createPaidPlan();
        $subscription = $this->createSubscription($business, $plan);

        $gateway = Mockery::mock(PaymentGateway::class);
        $gateway->shouldNotReceive('disableSubscription');

        expect(fn () => app(SubscriptionBillingService::class, [
            'paymentGateway' => $gateway,
        ])->disableRenewal($subscription, $actor))
            ->toThrow(\\Symfony\\Component\\HttpKernel\\Exception\\AccessDeniedHttpException::class);
    }

    public function test_free_plan_cannot_change_recurring_billing(): void
    {
        $business = Business::factory()->create();

        $actor = $this->makeBillingActor($business);
        $plan = SubscriptionPlan::factory()->create([
            'name' => 'Free',
            'slug' => 'free',
            'price' => 0,
            'currency' => 'NGN',
            'billing_interval' => 'monthly',
            'is_active' => true,
        ]);
        $subscription = $this->createSubscription($business, $plan);

        $gateway = Mockery::mock(PaymentGateway::class);
        $gateway->shouldNotReceive('disableSubscription');

        expect(fn () => app(SubscriptionBillingService::class, [
            'paymentGateway' => $gateway,
        ])->disableRenewal($subscription, $actor))
            ->toThrow(ValidationException::class);
    }


    private function makeBillingActor(Business $business): User
    {
        $actor = User::factory()->create();

        BusinessUser::create([
            'business_id' => $business->id,
            'user_id' => $actor->id,
            'status' => 'active',
            'joined_at' => now(),
        ]);

        $permission = Permission::firstOrCreate([
            'name' => 'subscription.manage',
            'guard_name' => 'web',
        ]);

        setPermissionsTeamId($business->id);
        $role = Role::firstOrCreate([
            'name' => 'Owner',
            'guard_name' => 'web',
            'team_id' => $business->id,
        ], ['is_system' => true]);
        $role->givePermissionTo($permission);
        $actor->assignRole($role);

        return $actor;
    }

    private function createPaidPlan(): SubscriptionPlan
    {
        return SubscriptionPlan::factory()->create([
            'name' => 'Business',
            'slug' => 'business-test',
            'price' => 10000,
            'currency' => 'NGN',
            'billing_interval' => 'monthly',
            'is_active' => true,
        ]);
    }

    private function createSubscription(
        Business $business,
        SubscriptionPlan $plan,
        bool $autoRenew = true,
    ): Subscription {
        return Subscription::factory()->create([
            'business_id' => $business->id,
            'plan_id' => $plan->id,
            'status' => 'active',
            'provider' => 'paystack',
            'provider_subscription_code' => 'SUB_TEST_123',
            'provider_email_token' => 'TOKEN_TEST_123',
            'auto_renew' => $autoRenew,
            'starts_at' => now()->subDay(),
            'current_period_start' => now()->subDay(),
            'current_period_end' => now()->addMonth(),
        ]);
    }
}
