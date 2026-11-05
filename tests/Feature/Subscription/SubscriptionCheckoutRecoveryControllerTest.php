<?php

namespace Tests\Feature\Subscription;

use App\Domains\Organization\Models\Business;
use App\Domains\Organization\Models\BusinessUser;
use App\Domains\Payment\Models\Payment;
use App\Domains\Payment\Models\SubscriptionCheckoutRecovery;
use App\Domains\Subscription\Models\SubscriptionPlan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class SubscriptionCheckoutRecoveryControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_retry_is_exposed_only_for_a_failed_attempt_with_definitive_provider_failure(): void
    {
        $business = Business::factory()->create();
        $actor = User::factory()->create();
        BusinessUser::create(['business_id' => $business->id, 'user_id' => $actor->id, 'status' => 'active', 'joined_at' => now()]);
        $permission = Permission::firstOrCreate(['name' => 'subscription.manage', 'guard_name' => 'web']);
        setPermissionsTeamId($business->id);
        $role = Role::firstOrCreate(['name' => 'Retry History Owner', 'guard_name' => 'web', 'team_id' => $business->id], ['is_system' => true]);
        $role->givePermissionTo($permission);
        $actor->assignRole($role);
        $plan = SubscriptionPlan::factory()->create(['name' => 'Starter', 'slug' => 'low-monthly', 'price' => '3000.00', 'currency' => 'NGN', 'billing_interval' => 'monthly', 'paystack_plan_code' => 'PLN_PRIVATE_CODE', 'is_active' => true]);
        $recovery = SubscriptionCheckoutRecovery::query()->create([
            'business_id' => $business->id,
            'plan_id' => $plan->id,
            'purchase_terms' => ['plan_id' => $plan->id, 'plan_slug' => $plan->slug, 'plan_name' => $plan->name, 'amount' => $plan->price, 'currency' => $plan->currency, 'billing_interval' => $plan->billing_interval, 'paystack_plan_code' => $plan->paystack_plan_code, 'subscription_id' => null],
            'status' => 'retryable',
        ]);
        Payment::factory()->create([
            'business_id' => $business->id,
            'sale_id' => null,
            'amount' => $plan->price,
            'method' => 'paystack',
            'status' => 'failed',
            'reference' => 'MERCHANTOS-DEFINITIVE-FAILURE',
            'checkout_recovery_id' => $recovery->id,
            'attempt_number' => 1,
            'last_provider_status' => 'failed',
            'metadata' => ['type' => 'subscription'],
        ]);

        $this->actingAs($actor)->withHeader('X-Business-ID', $business->id)
            ->getJson('/api/businesses/current/subscription/checkout-recoveries')
            ->assertOk()
            ->assertJsonPath('data.0.retry_allowed', true)
            ->assertJsonPath('data.0.plan.name', 'Starter')
            ->assertJsonPath('data.0.attempts.0.last_provider_status', 'failed')
            ->assertJsonMissing(['paystack_plan_code' => 'PLN_PRIVATE_CODE']);
    }

    public function test_recovery_history_is_scoped_to_the_active_business_and_hides_provider_plan_code(): void
    {
        $business = Business::factory()->create();
        $otherBusiness = Business::factory()->create();
        $actor = User::factory()->create();
        BusinessUser::create([
            'business_id' => $business->id,
            'user_id' => $actor->id,
            'status' => 'active',
            'joined_at' => now(),
        ]);
        $permission = Permission::firstOrCreate(['name' => 'subscription.manage', 'guard_name' => 'web']);
        setPermissionsTeamId($business->id);
        $role = Role::firstOrCreate([
            'name' => 'Recovery History Owner',
            'guard_name' => 'web',
            'team_id' => $business->id,
        ], ['is_system' => true]);
        $role->givePermissionTo($permission);
        $actor->assignRole($role);

        $plan = SubscriptionPlan::factory()->create(['paystack_plan_code' => 'PLN_PRIVATE_CODE']);
        $recovery = SubscriptionCheckoutRecovery::query()->create([
            'business_id' => $business->id,
            'plan_id' => $plan->id,
            'purchase_terms' => [
                'plan_id' => $plan->id,
                'plan_slug' => $plan->slug,
                'plan_name' => $plan->name,
                'amount' => $plan->price,
                'currency' => $plan->currency,
                'billing_interval' => $plan->billing_interval,
                'paystack_plan_code' => 'PLN_PRIVATE_CODE',
                'subscription_id' => null,
            ],
            'status' => 'pending',
        ]);
        Payment::factory()->create([
            'business_id' => $business->id,
            'sale_id' => null,
            'amount' => $plan->price,
            'method' => 'paystack',
            'status' => 'pending',
            'reference' => 'MERCHANTOS-HISTORY-ATTEMPT',
            'checkout_recovery_id' => $recovery->id,
            'attempt_number' => 1,
            'metadata' => ['type' => 'subscription', 'checkout' => ['authorization_url' => 'https://checkout.paystack.com/secret-session']],
        ]);
        SubscriptionCheckoutRecovery::query()->create([
            'business_id' => $otherBusiness->id,
            'plan_id' => $plan->id,
            'purchase_terms' => [],
            'status' => 'pending',
        ]);

        $this->actingAs($actor)
            ->withHeader('X-Business-ID', $business->id)
            ->getJson('/api/businesses/current/subscription/checkout-recoveries')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $recovery->id)
            ->assertJsonPath('data.0.retry_allowed', false)
            ->assertJsonPath('data.0.requires_entitlement_recovery', false)
            ->assertJsonPath('data.0.attempts.0.stored_authorization_url', true)
            ->assertJsonPath('data.0.attempts.0.status', 'pending')
            ->assertJsonMissing(['paystack_plan_code' => 'PLN_PRIVATE_CODE'])
            ->assertJsonMissing(['authorization_url' => 'https://checkout.paystack.com/secret-session']);
    }
}
