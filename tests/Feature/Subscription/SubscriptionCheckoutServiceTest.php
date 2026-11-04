<?php

namespace Tests\Feature\Subscription;

use App\Domains\Organization\Models\Business;
use App\Domains\Organization\Models\BusinessUser;
use App\Models\User;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use App\Domains\Payment\Contracts\PaymentGateway;
use App\Domains\Payment\Models\Payment;
use App\Domains\Payment\Models\SubscriptionCheckoutRecovery;
use App\Domains\Payment\Services\PaystackSubscriptionProvisioningService;
use App\Domains\Subscription\Models\Subscription;
use App\Domains\Subscription\Models\SubscriptionPlan;
use App\Domains\Subscription\Services\SubscriptionCheckoutService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Mockery;
use Tests\TestCase;

class SubscriptionCheckoutServiceTest extends TestCase
{
    use RefreshDatabase;


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

    private function makePlan(
        string $slug = 'medium-monthly',
        string $price = '10000.00',
        string $interval = 'monthly',
    ): SubscriptionPlan {
        return SubscriptionPlan::factory()->create([
            'name' => 'Medium Monthly',
            'slug' => $slug,
            'price' => $price,
            'currency' => 'NGN',
            'billing_interval' => $interval,
            'paystack_plan_code' => 'PLN_test_account_' . strtolower(bin2hex(random_bytes(5))),
            'is_active' => true,
        ]);
    }

    private function makeBusiness(): Business
    {
        return Business::factory()->create();
    }

    private function gatewayMock(): Mockery\MockInterface
    {
        $mock = Mockery::mock(PaymentGateway::class);

        $this->app->instance(
            PaymentGateway::class,
            $mock
        );

        return $mock;
    }


    public function test_checkout_rejects_actor_from_another_business_before_gateway_call(): void
    {
        $business = $this->makeBusiness();

        $otherBusiness = $this->makeBusiness();
        $actor = $this->makeBillingActor($otherBusiness);
        $plan = $this->makePlan();

        $gateway = $this->gatewayMock();
        $gateway->shouldNotReceive('initialize');

        expect(fn () => app(SubscriptionCheckoutService::class)->checkout(
            $actor,
            $business,
            $plan,
            'merchant@example.com'
        ))->toThrow(\Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException::class);

        expect(Payment::where('business_id', $business->id)->exists())->toBeFalse();
    }

    public function test_checkout_creates_pending_payment(): void
    {
        $business = $this->makeBusiness();

        $actor = $this->makeBillingActor($business);

        $plan = $this->makePlan();

        $gateway = $this->gatewayMock();

        $gateway
            ->shouldReceive('initialize')
            ->once()
            ->andReturnUsing(fn (array $data) => [
                'success' => true,
                'authorization_url' => 'https://checkout.paystack.com/test',
                'access_code' => 'test_access_code',
                'reference' => $data['reference'],
                'raw' => [],
            ]);

        $service = app(
            SubscriptionCheckoutService::class
        );

        $result = $service->checkout(
            $actor,
            $business,
            $plan,
            'merchant@example.com'
        );

        expect($result['authorization_url'])
            ->toBe('https://checkout.paystack.com/test');

        $payment = Payment::where('business_id', $business->id)
            ->whereNull('sale_id')
            ->whereNull('subscription_id')
            ->where('status', 'pending')
            ->first();

        expect($payment)
            ->not->toBeNull();

        expect($payment->metadata['type'])
            ->toBe('subscription');

        expect($payment->metadata['plan_id'])
            ->toBe($plan->id);
        expect($payment->checkout_recovery_id)->not->toBeNull()
            ->and($payment->attempt_number)->toBe(1)
            ->and($result['recovery_id'])->toBe($payment->checkout_recovery_id);
    }

    public function test_active_free_subscription_can_checkout_paid_monthly_and_annual_plans(): void
    {
        $free = SubscriptionPlan::factory()->create([
            'slug' => 'free',
            'price' => '0.00',
            'paystack_plan_code' => null,
        ]);

        $paidPlans = [
            SubscriptionPlan::factory()->create([
                'name' => 'Starter Monthly',
                'slug' => 'starter-monthly',
                'price' => '3000.00',
                'currency' => 'NGN',
                'billing_interval' => 'monthly',
                'paystack_plan_code' => 'PLN_test_starter_monthly',
                'is_active' => true,
            ]),
            SubscriptionPlan::factory()->create([
                'name' => 'Starter Annual',
                'slug' => 'starter-annual',
                'price' => '33000.00',
                'currency' => 'NGN',
                'billing_interval' => 'yearly',
                'paystack_plan_code' => 'PLN_test_starter_annual',
                'is_active' => true,
            ]),
        ];

        foreach ($paidPlans as $plan) {
            $business = $this->makeBusiness();
            $actor = $this->makeBillingActor($business);
            $subscription = Subscription::create(['business_id' => $business->id, 'plan_id' => $free->id, 'status' => 'active']);
            $gateway = $this->gatewayMock();
            $gateway->shouldReceive('initialize')->once()->andReturnUsing(fn (array $data) => [
                'success' => true, 'authorization_url' => 'https://checkout.paystack.com/test', 'access_code' => 'test', 'reference' => $data['reference'],
            ]);

            $result = app(SubscriptionCheckoutService::class)->checkout($actor, $business, $plan, 'merchant@example.com');
            $payment = Payment::where('business_id', $business->id)->firstOrFail();
            expect($result['payment_id'])->toBe($payment->id)
                ->and($payment->status)->toBe('pending')
                ->and($payment->subscription_id)->toBe($subscription->id)
                ->and($subscription->fresh()->plan_id)->toBe($free->id);
        }
    }

    public function test_active_subscription_with_non_free_zero_price_plan_is_rejected(): void
    {
        $business = $this->makeBusiness();
        $actor = $this->makeBillingActor($business);
        $freeish = SubscriptionPlan::factory()->create(['slug' => 'trial', 'price' => '0.00']);
        Subscription::create(['business_id' => $business->id, 'plan_id' => $freeish->id, 'status' => 'active']);
        $gateway = $this->gatewayMock();
        $gateway->shouldNotReceive('initialize');
        expect(fn () => app(SubscriptionCheckoutService::class)->checkout($actor, $business, $this->makePlan(), 'merchant@example.com'))
            ->toThrow(ValidationException::class);
        expect(Payment::where('business_id', $business->id)->exists())->toBeFalse();
    }

    public function test_checkout_uses_plan_price(): void
    {
        $business = $this->makeBusiness();

        $actor = $this->makeBillingActor($business);

        $plan = $this->makePlan(
            price: '12500.00'
        );

        $gateway = $this->gatewayMock();

        $gateway
            ->shouldReceive('initialize')
            ->once()
            ->with(Mockery::on(function (array $data) {
                return $data['amount'] === 1250000;
            }))
            ->andReturnUsing(fn (array $data) => [
                'success' => true,
                'authorization_url' => 'https://checkout.paystack.com/test',
                'access_code' => 'test',
                'reference' => $data['reference'],
                'raw' => [],
            ]);

        $service = app(
            SubscriptionCheckoutService::class
        );

        $service->checkout(
            $actor,
            $business,
            $plan,
            'merchant@example.com'
        );
    }

    public function test_checkout_rejects_paid_plan_without_paystack_recurring_configuration(): void
    {
        $business = $this->makeBusiness();

        $actor = $this->makeBillingActor($business);
        $plan = $this->makePlan();
        $plan->forceFill(['paystack_plan_code' => null])->save();

        $gateway = $this->gatewayMock();
        $gateway->shouldNotReceive('initialize');

        expect(fn () => app(SubscriptionCheckoutService::class)->checkout(
            $actor,
            $business,
            $plan,
            'merchant@example.com'
        ))->toThrow(ValidationException::class);
    }

    public function test_checkout_rejects_new_payment_when_recurring_subscription_already_exists(): void
    {
        $business = $this->makeBusiness();

        $actor = $this->makeBillingActor($business);
        $plan = $this->makePlan();

        Subscription::create([
            'business_id' => $business->id,
            'plan_id' => $plan->id,
            'status' => 'active',
            'provider' => 'paystack',
            'provider_subscription_code' => 'SUB_test_existing',
            'current_period_start' => now(),
            'current_period_end' => now()->addMonth(),
        ]);

        $gateway = $this->gatewayMock();
        $gateway->shouldNotReceive('initialize');

        expect(fn () => app(SubscriptionCheckoutService::class)->checkout(
            $actor,
            $business,
            $plan,
            'merchant@example.com'
        ))->toThrow(ValidationException::class);
    }

    public function test_checkout_rejects_inactive_plan(): void
    {
        $business = $this->makeBusiness();

        $actor = $this->makeBillingActor($business);

        $plan = $this->makePlan();

        $plan->update([
            'is_active' => false,
        ]);

        $gateway = $this->gatewayMock();
        $gateway->shouldReceive('initialize')->never();

        $service = app(
            SubscriptionCheckoutService::class
        );

        expect(fn() => $service->checkout(
            $actor,
            $business,
            $plan,
            'merchant@example.com'
        ))->toThrow(
            ValidationException::class
        );
    }

    public function test_checkout_rejects_plan_from_wrong_currency(): void
    {
        $business = $this->makeBusiness();

        $actor = $this->makeBillingActor($business);

        $plan = $this->makePlan();

        $plan->update([
            'currency' => 'USD',
        ]);

        $gateway = $this->gatewayMock();
        $gateway->shouldReceive('initialize')->never();

        $service = app(
            SubscriptionCheckoutService::class
        );

        expect(fn() => $service->checkout(
            $actor,
            $business,
            $plan,
            'merchant@example.com'
        ))->toThrow(
            ValidationException::class
        );
    }

    public function test_checkout_does_not_activate_subscription(): void
    {
        $business = $this->makeBusiness();

        $actor = $this->makeBillingActor($business);

        $plan = $this->makePlan();

        $gateway = $this->gatewayMock();

        $gateway
            ->shouldReceive('initialize')
            ->once()
            ->andReturnUsing(fn (array $data) => [
                'success' => true,
                'authorization_url' => 'https://checkout.paystack.com/test',
                'access_code' => 'test',
                'reference' => $data['reference'],
                'raw' => [],
            ]);

        $service = app(
            SubscriptionCheckoutService::class
        );

        $service->checkout(
            $actor,
            $business,
            $plan,
            'merchant@example.com'
        );

        expect(
            Subscription::where('business_id', $business->id)
                ->where('status', 'active')
                ->exists()
        )->toBeFalse();
    }

    public function test_failed_gateway_initialization_does_not_leave_paid_payment(): void
    {
        $business = $this->makeBusiness();

        $actor = $this->makeBillingActor($business);

        $plan = $this->makePlan();

        $gateway = $this->gatewayMock();

        $gateway
            ->shouldReceive('initialize')
            ->once()
            ->andReturn([
                'success' => false,
                'authorization_url' => null,
                'access_code' => null,
                'reference' => null,
                'raw' => [],
            ]);

        $service = app(
            SubscriptionCheckoutService::class
        );

        expect(fn() => $service->checkout(
            $actor,
            $business,
            $plan,
            'merchant@example.com'
        ))->toThrow(
            \RuntimeException::class
        );

        expect(
            Payment::where('business_id', $business->id)
                ->where('status', 'paid')
                ->count()
        )->toBe(0);
    }

     public function test_starter_monthly_price_is_sent_to_paystack_in_kobo(): void
{
    $business = $this->makeBusiness();

    $actor = $this->makeBillingActor($business);

    $plan = $this->makePlan(
        slug: 'low-monthly',
        price: '3000.00',
        interval: 'monthly',
    );

    $gateway = $this->gatewayMock();

    $gateway
        ->shouldReceive('initialize')
        ->once()
        ->with(Mockery::on(function (array $data) {
            return $data['amount'] === 300000;
        }))
        ->andReturnUsing(fn (array $data) => [
            'success' => true,
            'authorization_url' => 'https://checkout.paystack.com/test',
            'access_code' => 'test',
            'reference' => $data['reference'],
            'raw' => [],
        ]);

    $service = app(
        SubscriptionCheckoutService::class
    );

    $service->checkout(
        $actor,
        $business,
        $plan,
        'merchant@example.com'
    );
}

public function test_starter_yearly_price_is_sent_to_paystack_in_kobo(): void
{
    $business = $this->makeBusiness();

    $actor = $this->makeBillingActor($business);

    $plan = $this->makePlan(
        slug: 'low-yearly',
        price: '30000.00',
        interval: 'yearly',
    );

    $gateway = $this->gatewayMock();

    $gateway
        ->shouldReceive('initialize')
        ->once()
        ->with(Mockery::on(function (array $data) {
            return $data['amount'] === 3000000;
        }))
        ->andReturnUsing(fn (array $data) => [
            'success' => true,
            'authorization_url' => 'https://checkout.paystack.com/test',
            'access_code' => 'test',
            'reference' => $data['reference'],
            'raw' => [],
        ]);

    $service = app(
        SubscriptionCheckoutService::class
    );

    $service->checkout(
        $actor,
        $business,
        $plan,
        'merchant@example.com'
    );
}


    public function test_checkout_rejects_active_subscription_without_provider_code(): void
    {
        $business = $this->makeBusiness();
        $actor = $this->makeBillingActor($business);
        $plan = $this->makePlan();

        Subscription::create([
            'business_id' => $business->id,
            'plan_id' => $plan->id,
            'status' => 'active',
            'provider' => null,
            'provider_customer_code' => null,
            'provider_authorization_code' => null,
            'provider_subscription_code' => null,
            'provider_email_token' => null,
            'current_period_start' => now(),
            'current_period_end' => now()->addMonth(),
        ]);

        $gateway = $this->gatewayMock();
        $gateway->shouldNotReceive('initialize');

        expect(fn () => app(SubscriptionCheckoutService::class)->checkout(
            $actor,
            $business,
            $plan,
            'merchant@example.com'
        ))->toThrow(ValidationException::class);

        expect(Payment::where('business_id', $business->id)->count())->toBe(0);
    }

    public function test_checkout_recovers_existing_pending_payment_without_initializing_another(): void
    {
        $business = $this->makeBusiness();
        $actor = $this->makeBillingActor($business);
        $plan = $this->makePlan();

        Payment::factory()->create([
            'business_id' => $business->id,
            'subscription_id' => null,
            'sale_id' => null,
            'amount' => $plan->price,
            'method' => 'paystack',
            'status' => 'pending',
            'reference' => 'MERCHANTOS-PENDING-CHECKOUT-EXISTING',
            'metadata' => [
                'type' => 'subscription',
                'business_id' => $business->id,
                'plan_id' => $plan->id,
            ],
        ]);

        $gateway = $this->gatewayMock();
        $gateway->shouldReceive('verify')->once()->with('MERCHANTOS-PENDING-CHECKOUT-EXISTING')->andReturn([
            'success' => true,
            'status' => 'pending',
            'reference' => 'MERCHANTOS-PENDING-CHECKOUT-EXISTING',
            'amount' => 1000000,
            'currency' => 'NGN',
        ]);
        $gateway->shouldNotReceive('initialize');

        $result = app(SubscriptionCheckoutService::class)->checkout(
            $actor,
            $business,
            $plan,
            'merchant@example.com'
        );

        expect($result['status'])->toBe('pending')
            ->and($result['reconciling'])->toBeTrue();

        expect(
            Payment::where('business_id', $business->id)
                ->where('status', 'pending')
                ->count()
        )->toBe(1);
    }

    public function test_pending_checkout_returns_stored_session_without_claiming_it_is_verified_usable(): void
    {
        $business = $this->makeBusiness();
        $actor = $this->makeBillingActor($business);
        $plan = $this->makePlan();
        Payment::factory()->create([
            'business_id' => $business->id,
            'subscription_id' => null,
            'sale_id' => null,
            'amount' => $plan->price,
            'method' => 'paystack',
            'status' => 'pending',
            'reference' => 'MERCHANTOS-PENDING-WITH-URL',
            'metadata' => [
                'type' => 'subscription',
                'business_id' => $business->id,
                'plan_id' => $plan->id,
                'plan_slug' => $plan->slug,
                'billing_interval' => $plan->billing_interval,
                'currency' => 'NGN',
                'paystack_plan_code' => $plan->paystack_plan_code,
                'checkout' => [
                    'authorization_url' => 'https://checkout.paystack.com/original-session',
                    'access_code' => 'original-access',
                ],
            ],
        ]);
        $gateway = $this->gatewayMock();
        $gateway->shouldReceive('verify')->once()->andReturn([
            'success' => true, 'status' => 'pending', 'reference' => 'MERCHANTOS-PENDING-WITH-URL',
            'amount' => 1000000, 'currency' => 'NGN',
        ]);
        $gateway->shouldNotReceive('initialize');

        $result = app(SubscriptionCheckoutService::class)->checkout($actor, $business, $plan, 'merchant@example.com');

        expect($result['authorization_url'])->toBe('https://checkout.paystack.com/original-session')
            ->and($result['access_code'])->toBe('original-access')
            ->and($result['resume_possible'])->toBeNull()
            ->and($result['session_state'])->toBe('stored_unverified');
    }

    public function test_verified_paystack_session_timeout_is_reported_as_expired_and_retryable(): void
    {
        $business = $this->makeBusiness();
        $actor = $this->makeBillingActor($business);
        $plan = $this->makePlan();
        Payment::factory()->create([
            'business_id' => $business->id,
            'subscription_id' => null,
            'sale_id' => null,
            'amount' => $plan->price,
            'method' => 'paystack',
            'status' => 'pending',
            'reference' => 'MERCHANTOS-EXPIRED-SESSION',
            'metadata' => [
                'type' => 'subscription',
                'business_id' => $business->id,
                'plan_id' => $plan->id,
                'plan_slug' => $plan->slug,
                'billing_interval' => $plan->billing_interval,
                'currency' => 'NGN',
                'paystack_plan_code' => $plan->paystack_plan_code,
            ],
        ]);
        $gateway = $this->gatewayMock();
        $gateway->shouldReceive('verify')->once()->andReturn([
            'success' => true,
            'status' => 'failed',
            'reference' => 'MERCHANTOS-EXPIRED-SESSION',
            'amount' => 1000000,
            'currency' => 'NGN',
            'gateway_response' => 'Transaction cannot be completed. Session timeout exceeded.',
        ]);
        $gateway->shouldNotReceive('initialize');

        $result = app(SubscriptionCheckoutService::class)->checkout($actor, $business, $plan, 'merchant@example.com');

        expect($result['status'])->toBe('failed')
            ->and($result['outcome'])->toBe('session_expired')
            ->and($result['session_expired'])->toBeTrue()
            ->and($result['retry_available'])->toBeTrue();
    }

    public function test_checkout_rejects_missing_initialization_reference_without_creating_another_attempt(): void
    {
        $business = $this->makeBusiness();
        $actor = $this->makeBillingActor($business);
        $plan = $this->makePlan();
        $gateway = $this->gatewayMock();
        $gateway->shouldReceive('initialize')->once()->andReturn([
            'success' => true,
            'authorization_url' => 'https://checkout.paystack.com/test',
            'access_code' => 'access',
            'reference' => null,
        ]);

        expect(fn () => app(SubscriptionCheckoutService::class)->checkout($actor, $business, $plan, 'merchant@example.com'))
            ->toThrow(\RuntimeException::class);
        expect(Payment::where('business_id', $business->id)->where('status', 'pending')->count())->toBe(1)
            ->and(Payment::where('business_id', $business->id)->where('status', 'paid')->count())->toBe(0);
    }

    public function test_checkout_rejects_mismatched_initialization_reference_without_retrying_initialization(): void
    {
        $business = $this->makeBusiness();
        $actor = $this->makeBillingActor($business);
        $plan = $this->makePlan();
        $gateway = $this->gatewayMock();
        $gateway->shouldReceive('initialize')->once()->andReturn([
            'success' => true,
            'authorization_url' => 'https://checkout.paystack.com/test',
            'access_code' => 'access',
            'reference' => 'MERCHANTOS-WRONG-REFERENCE',
        ]);

        expect(fn () => app(SubscriptionCheckoutService::class)->checkout($actor, $business, $plan, 'merchant@example.com'))
            ->toThrow(\RuntimeException::class);
        expect(Payment::where('business_id', $business->id)->where('status', 'pending')->count())->toBe(1)
            ->and(Payment::where('business_id', $business->id)->where('status', 'paid')->count())->toBe(0);
    }

    public function test_definitive_failure_returns_retry_available_and_next_request_creates_new_reference(): void
    {
        $business = $this->makeBusiness();
        $actor = $this->makeBillingActor($business);
        $plan = $this->makePlan();
        $oldReference = 'MERCHANTOS-RECOVERY-FAILED';
        $oldPayment = Payment::factory()->create([
            'business_id' => $business->id, 'subscription_id' => null, 'sale_id' => null,
            'amount' => $plan->price, 'method' => 'paystack', 'status' => 'pending', 'reference' => $oldReference,
            'metadata' => ['type' => 'subscription', 'business_id' => $business->id, 'plan_id' => $plan->id,
                'plan_slug' => $plan->slug, 'billing_interval' => $plan->billing_interval, 'currency' => 'NGN',
                'paystack_plan_code' => $plan->paystack_plan_code],
        ]);
        $gateway = $this->gatewayMock();
        $gateway->shouldReceive('verify')->once()->with($oldReference)->andReturn([
            'success' => true, 'status' => 'failed', 'reference' => $oldReference, 'amount' => 1000000, 'currency' => 'NGN',
        ]);
        $gateway->shouldReceive('initialize')->once()->andReturnUsing(fn (array $data) => [
            'success' => true, 'authorization_url' => 'https://checkout.paystack.com/new',
            'access_code' => 'new-access', 'reference' => $data['reference'],
        ]);

        $firstResponse = app(SubscriptionCheckoutService::class)->checkout($actor, $business, $plan, 'merchant@example.com');
        expect($firstResponse['status'])->toBe('failed')
            ->and($firstResponse['retry_available'])->toBeTrue()
            ->and($oldPayment->fresh()->status)->toBe('failed')
            ->and(Payment::where('business_id', $business->id)->count())->toBe(1);

        $secondResponse = app(SubscriptionCheckoutService::class)->checkout(
            $actor,
            $business,
            $plan,
            'merchant@example.com',
            $firstResponse['recovery_id'],
        );
        $newPayment = Payment::where('business_id', $business->id)->where('id', '<>', $oldPayment->id)->firstOrFail();
        expect($secondResponse['reference'])->not->toBe($oldReference)
            ->and(Payment::where('business_id', $business->id)->count())->toBe(2)
            ->and($newPayment->checkout_recovery_id)->toBe($oldPayment->fresh()->checkout_recovery_id)
            ->and($newPayment->attempt_number)->toBe(2)
            ->and($oldPayment->fresh()->checkoutRecovery->status)->toBe('pending');
    }

    public function test_legacy_failed_attempt_is_reverified_before_a_new_charge(): void
    {
        $business = $this->makeBusiness();
        $actor = $this->makeBillingActor($business);
        $plan = $this->makePlan();
        $recovery = SubscriptionCheckoutRecovery::query()->create([
            'business_id' => $business->id,
            'plan_id' => $plan->id,
            'purchase_terms' => [
                'plan_id' => $plan->id,
                'plan_slug' => $plan->slug,
                'amount' => $plan->price,
                'currency' => 'NGN',
                'billing_interval' => $plan->billing_interval,
                'paystack_plan_code' => $plan->paystack_plan_code,
                'subscription_id' => null,
            ],
            'status' => 'retryable',
        ]);
        $oldPayment = Payment::factory()->create([
            'business_id' => $business->id,
            'subscription_id' => null,
            'sale_id' => null,
            'amount' => $plan->price,
            'method' => 'paystack',
            'status' => 'failed',
            'reference' => 'MERCHANTOS-LEGACY-FAILED-RETRY',
            'checkout_recovery_id' => $recovery->id,
            'attempt_number' => 1,
            'metadata' => [
                'type' => 'subscription', 'business_id' => $business->id,
                'plan_id' => $plan->id, 'plan_slug' => $plan->slug,
                'billing_interval' => $plan->billing_interval, 'currency' => 'NGN',
                'paystack_plan_code' => $plan->paystack_plan_code,
            ],
        ]);
        $gateway = $this->gatewayMock();
        $gateway->shouldReceive('verify')->once()->with($oldPayment->reference)->andReturn([
            'success' => true, 'status' => 'failed', 'reference' => $oldPayment->reference,
            'amount' => 1000000, 'currency' => 'NGN',
        ]);
        $gateway->shouldReceive('initialize')->once()->andReturnUsing(fn (array $data) => [
            'success' => true, 'authorization_url' => 'https://checkout.paystack.com/retry',
            'access_code' => 'retry-access', 'reference' => $data['reference'],
        ]);

        $result = app(SubscriptionCheckoutService::class)->checkout($actor, $business, $plan, 'merchant@example.com', $recovery->id);

        expect($result['reference'])->not->toBe($oldPayment->reference)
            ->and($oldPayment->fresh()->last_provider_status)->toBe('failed')
            ->and(Payment::where('checkout_recovery_id', $recovery->id)->count())->toBe(2);
    }

    public function test_legacy_failed_attempt_that_verifies_as_paid_is_reconciled_without_a_new_charge(): void
    {
        $business = $this->makeBusiness();
        $actor = $this->makeBillingActor($business);
        $free = SubscriptionPlan::factory()->create(['slug' => 'free', 'price' => '0.00', 'paystack_plan_code' => null]);
        $plan = $this->makePlan();
        $subscription = Subscription::create(['business_id' => $business->id, 'plan_id' => $free->id, 'status' => 'active']);
        $recovery = SubscriptionCheckoutRecovery::query()->create([
            'business_id' => $business->id,
            'subscription_id' => $subscription->id,
            'plan_id' => $plan->id,
            'purchase_terms' => [
                'plan_id' => $plan->id,
                'plan_slug' => $plan->slug,
                'amount' => $plan->price,
                'currency' => 'NGN',
                'billing_interval' => $plan->billing_interval,
                'paystack_plan_code' => $plan->paystack_plan_code,
                'subscription_id' => $subscription->id,
            ],
            'status' => 'retryable',
        ]);
        $payment = Payment::factory()->create([
            'business_id' => $business->id,
            'subscription_id' => $subscription->id,
            'sale_id' => null,
            'amount' => $plan->price,
            'method' => 'paystack',
            'status' => 'failed',
            'reference' => 'MERCHANTOS-LATE-VERIFIED-SUCCESS',
            'checkout_recovery_id' => $recovery->id,
            'attempt_number' => 1,
            'metadata' => [
                'type' => 'subscription',
                'business_id' => $business->id,
                'subscription_id' => $subscription->id,
                'plan_id' => $plan->id,
                'plan_slug' => $plan->slug,
                'billing_interval' => $plan->billing_interval,
                'currency' => 'NGN',
                'paystack_plan_code' => $plan->paystack_plan_code,
            ],
        ]);
        $gateway = $this->gatewayMock();
        $gateway->shouldReceive('verify')->once()->with($payment->reference)->andReturn([
            'success' => true,
            'status' => 'success',
            'reference' => $payment->reference,
            'amount' => 1000000,
            'currency' => 'NGN',
            'authorization_code' => 'AUTH_LATE_SUCCESS',
            'customer_code' => 'CUS_LATE_SUCCESS',
        ]);
        $gateway->shouldNotReceive('initialize');
        $provisioner = Mockery::mock(PaystackSubscriptionProvisioningService::class);
        $provisioner->shouldReceive('provision')->once()->andReturnUsing(fn (Subscription $item) => $item);
        $this->app->instance(PaystackSubscriptionProvisioningService::class, $provisioner);

        $result = app(SubscriptionCheckoutService::class)->checkout(
            $actor,
            $business,
            $plan,
            'merchant@example.com',
            $recovery->id,
        );

        expect($result['status'])->toBe('paid')
            ->and($result['outcome'])->toBe('succeeded')
            ->and($payment->fresh()->status)->toBe('paid')
            ->and($subscription->fresh()->plan_id)->toBe($plan->id)
            ->and(Payment::where('business_id', $business->id)->count())->toBe(1);
    }

    public function test_explicit_recovery_cannot_silently_use_changed_plan_terms(): void
    {
        $business = $this->makeBusiness();
        $actor = $this->makeBillingActor($business);
        $plan = $this->makePlan();
        $recovery = SubscriptionCheckoutRecovery::query()->create([
            'business_id' => $business->id,
            'plan_id' => $plan->id,
            'purchase_terms' => [
                'plan_id' => $plan->id, 'plan_slug' => $plan->slug,
                'amount' => '10000.00', 'currency' => 'NGN',
                'billing_interval' => 'monthly', 'paystack_plan_code' => $plan->paystack_plan_code,
                'subscription_id' => null,
            ],
            'status' => 'retryable',
        ]);
        Payment::factory()->create([
            'business_id' => $business->id, 'subscription_id' => null, 'sale_id' => null,
            'amount' => '10000.00', 'method' => 'paystack', 'status' => 'failed',
            'reference' => 'MERCHANTOS-CHANGED-TERMS-FAILED',
            'checkout_recovery_id' => $recovery->id, 'attempt_number' => 1,
            'last_provider_status' => 'failed', 'last_provider_checked_at' => now(),
            'metadata' => ['type' => 'subscription', 'business_id' => $business->id,
                'plan_id' => $plan->id, 'plan_slug' => $plan->slug,
                'billing_interval' => 'monthly', 'currency' => 'NGN',
                'paystack_plan_code' => $plan->paystack_plan_code],
        ]);
        $plan->forceFill(['price' => '11000.00'])->save();
        $gateway = $this->gatewayMock();
        $gateway->shouldNotReceive('initialize');

        expect(fn () => app(SubscriptionCheckoutService::class)->checkout(
            $actor,
            $business,
            $plan,
            'merchant@example.com',
            $recovery->id,
        ))->toThrow(ValidationException::class);

        expect(Payment::where('business_id', $business->id)->count())->toBe(1);
    }

    public function test_pending_payment_for_another_plan_blocks_checkout(): void
    {
        $business = $this->makeBusiness();
        $actor = $this->makeBillingActor($business);
        $pendingPlan = $this->makePlan('pending-plan');
        $selectedPlan = $this->makePlan('selected-plan');
        Payment::factory()->create([
            'business_id' => $business->id,
            'subscription_id' => null,
            'sale_id' => null,
            'amount' => $pendingPlan->price,
            'method' => 'paystack',
            'status' => 'pending',
            'reference' => 'MERCHANTOS-PENDING-OTHER-PLAN',
            'metadata' => ['type' => 'subscription', 'business_id' => $business->id, 'plan_id' => $pendingPlan->id],
        ]);
        $gateway = $this->gatewayMock();
        $gateway->shouldNotReceive('verify');
        $gateway->shouldNotReceive('initialize');

        expect(fn () => app(SubscriptionCheckoutService::class)->checkout(
            $actor,
            $business,
            $selectedPlan,
            'merchant@example.com',
        ))->toThrow(ValidationException::class);

        expect(Payment::where('business_id', $business->id)->count())->toBe(1);
    }

    public function test_pending_payment_cannot_be_resumed_with_a_mismatched_recovery_id(): void
    {
        $business = $this->makeBusiness();
        $actor = $this->makeBillingActor($business);
        $plan = $this->makePlan();
        $recovery = SubscriptionCheckoutRecovery::query()->create([
            'business_id' => $business->id,
            'plan_id' => $plan->id,
            'purchase_terms' => [
                'plan_id' => $plan->id, 'plan_slug' => $plan->slug,
                'amount' => $plan->price, 'currency' => 'NGN',
                'billing_interval' => $plan->billing_interval,
                'paystack_plan_code' => $plan->paystack_plan_code,
                'subscription_id' => null,
            ],
            'status' => 'pending',
        ]);
        Payment::factory()->create([
            'business_id' => $business->id, 'subscription_id' => null, 'sale_id' => null,
            'amount' => $plan->price, 'method' => 'paystack', 'status' => 'pending',
            'reference' => 'MERCHANTOS-PENDING-RECOVERY-ID',
            'checkout_recovery_id' => $recovery->id, 'attempt_number' => 1,
            'metadata' => ['type' => 'subscription', 'business_id' => $business->id, 'plan_id' => $plan->id],
        ]);
        $gateway = $this->gatewayMock();
        $gateway->shouldNotReceive('verify');
        $gateway->shouldNotReceive('initialize');

        expect(fn () => app(SubscriptionCheckoutService::class)->checkout(
            $actor,
            $business,
            $plan,
            'merchant@example.com',
            (string) \Illuminate\Support\Str::uuid(),
        ))->toThrow(ValidationException::class);

        expect(Payment::where('business_id', $business->id)->count())->toBe(1);
    }

    public function test_manual_review_recovery_blocks_new_checkout(): void
    {
        $business = $this->makeBusiness();
        $actor = $this->makeBillingActor($business);
        $plan = $this->makePlan();
        SubscriptionCheckoutRecovery::query()->create([
            'business_id' => $business->id,
            'plan_id' => $plan->id,
            'purchase_terms' => [],
            'status' => 'manual_review',
            'manual_review_at' => now(),
        ]);
        $gateway = $this->gatewayMock();
        $gateway->shouldNotReceive('initialize');

        expect(fn () => app(SubscriptionCheckoutService::class)->checkout(
            $actor,
            $business,
            $plan,
            'merchant@example.com',
        ))->toThrow(ValidationException::class);

        expect(Payment::where('business_id', $business->id)->count())->toBe(0);
    }

    public function test_successful_recovery_cannot_be_retried(): void
    {
        $business = $this->makeBusiness();
        $actor = $this->makeBillingActor($business);
        $plan = $this->makePlan();
        $subscription = Subscription::factory()->create([
            'business_id' => $business->id,
            'plan_id' => $plan->id,
            'status' => 'active',
        ]);
        $payment = Payment::factory()->create([
            'business_id' => $business->id, 'subscription_id' => $subscription->id, 'sale_id' => null,
            'amount' => $plan->price, 'method' => 'paystack', 'status' => 'paid',
            'reference' => 'MERCHANTOS-SUCCEEDED-RECOVERY', 'paid_at' => now(),
        ]);
        $recovery = SubscriptionCheckoutRecovery::query()->create([
            'business_id' => $business->id,
            'subscription_id' => $subscription->id,
            'plan_id' => $plan->id,
            'purchase_terms' => ['plan_id' => $plan->id, 'amount' => $plan->price, 'currency' => 'NGN',
                'billing_interval' => $plan->billing_interval, 'paystack_plan_code' => $plan->paystack_plan_code,
                'subscription_id' => $subscription->id],
            'status' => 'succeeded',
            'winning_payment_id' => $payment->id,
        ]);
        $payment->forceFill(['checkout_recovery_id' => $recovery->id, 'attempt_number' => 1])->save();
        $gateway = $this->gatewayMock();
        $gateway->shouldNotReceive('initialize');

        expect(fn () => app(SubscriptionCheckoutService::class)->checkout(
            $actor,
            $business,
            $plan,
            'merchant@example.com',
            $recovery->id,
        ))->toThrow(ValidationException::class);

        expect(Payment::where('business_id', $business->id)->count())->toBe(1)
            ->and($recovery->fresh()->status)->toBe('succeeded');
    }

    public function test_abandoned_attempt_can_retry_with_same_recovery_and_next_attempt_number(): void
    {
        $business = $this->makeBusiness();
        $actor = $this->makeBillingActor($business);
        $plan = $this->makePlan();
        $recovery = SubscriptionCheckoutRecovery::query()->create([
            'business_id' => $business->id,
            'plan_id' => $plan->id,
            'purchase_terms' => [
                'plan_id' => $plan->id, 'plan_slug' => $plan->slug,
                'amount' => $plan->price, 'currency' => 'NGN',
                'billing_interval' => $plan->billing_interval,
                'paystack_plan_code' => $plan->paystack_plan_code,
                'subscription_id' => null,
            ],
            'status' => 'pending',
        ]);
        $oldPayment = Payment::factory()->create([
            'business_id' => $business->id, 'subscription_id' => null, 'sale_id' => null,
            'amount' => $plan->price, 'method' => 'paystack', 'status' => 'pending',
            'reference' => 'MERCHANTOS-ABANDONED-ATTEMPT',
            'checkout_recovery_id' => $recovery->id, 'attempt_number' => 1,
            'metadata' => ['type' => 'subscription', 'business_id' => $business->id,
                'plan_id' => $plan->id, 'plan_slug' => $plan->slug,
                'billing_interval' => $plan->billing_interval, 'currency' => 'NGN',
                'paystack_plan_code' => $plan->paystack_plan_code],
        ]);
        $gateway = $this->gatewayMock();
        $gateway->shouldReceive('verify')->once()->with($oldPayment->reference)->andReturn([
            'success' => true, 'status' => 'abandoned', 'reference' => $oldPayment->reference,
            'amount' => 1000000, 'currency' => 'NGN',
        ]);
        $gateway->shouldReceive('initialize')->once()->andReturnUsing(fn (array $data): array => [
            'success' => true, 'authorization_url' => 'https://checkout.paystack.com/new-attempt',
            'access_code' => 'new-attempt-access', 'reference' => $data['reference'],
        ]);

        $failedResponse = app(SubscriptionCheckoutService::class)->checkout($actor, $business, $plan, 'merchant@example.com');
        $retryResponse = app(SubscriptionCheckoutService::class)->checkout(
            $actor, $business, $plan, 'merchant@example.com', $recovery->id,
        );
        $newPayment = Payment::query()->where('checkout_recovery_id', $recovery->id)
            ->where('id', '<>', $oldPayment->id)->firstOrFail();

        expect($failedResponse['retry_available'])->toBeTrue()
            ->and($failedResponse['outcome'])->toBe('abandoned')
            ->and($oldPayment->fresh()->status)->toBe('failed')
            ->and($retryResponse['reference'])->not->toBe($oldPayment->reference)
            ->and($newPayment->attempt_number)->toBe(2)
            ->and($newPayment->checkout_recovery_id)->toBe($recovery->id);
    }

    public function test_unmatched_legacy_retry_terms_create_a_separate_group_only_after_failure_is_definitive(): void
    {
        $business = $this->makeBusiness();
        $actor = $this->makeBillingActor($business);
        $plan = $this->makePlan();
        $oldRecovery = SubscriptionCheckoutRecovery::query()->create([
            'business_id' => $business->id,
            'plan_id' => $plan->id,
            'purchase_terms' => ['plan_id' => $plan->id, 'amount' => $plan->price, 'currency' => 'NGN',
                'billing_interval' => $plan->billing_interval, 'paystack_plan_code' => $plan->paystack_plan_code,
                'subscription_id' => null],
            'status' => 'retryable',
        ]);
        $oldPayment = Payment::factory()->create([
            'business_id' => $business->id, 'subscription_id' => null, 'sale_id' => null,
            'amount' => $plan->price, 'method' => 'paystack', 'status' => 'failed',
            'reference' => 'MERCHANTOS-LEGACY-TERMS-CHANGED',
            'checkout_recovery_id' => $oldRecovery->id, 'attempt_number' => 1,
            'last_provider_status' => 'failed', 'last_provider_checked_at' => now(),
            'metadata' => ['type' => 'subscription', 'business_id' => $business->id,
                'plan_id' => $plan->id, 'plan_slug' => $plan->slug,
                'billing_interval' => $plan->billing_interval, 'currency' => 'NGN',
                'paystack_plan_code' => $plan->paystack_plan_code],
        ]);
        $plan->forceFill(['price' => '11000.00'])->save();
        $gateway = $this->gatewayMock();
        $gateway->shouldReceive('initialize')->once()->andReturnUsing(fn (array $data): array => [
            'success' => true, 'authorization_url' => 'https://checkout.paystack.com/new-terms',
            'access_code' => 'new-terms-access', 'reference' => $data['reference'],
        ]);

        $response = app(SubscriptionCheckoutService::class)->checkout($actor, $business, $plan, 'merchant@example.com');
        $newPayment = Payment::query()->where('business_id', $business->id)
            ->where('id', '<>', $oldPayment->id)->firstOrFail();

        expect($newPayment->checkout_recovery_id)->not->toBe($oldRecovery->id)
            ->and($newPayment->attempt_number)->toBe(1)
            ->and($newPayment->amount)->toBe('11000.00')
            ->and($oldPayment->fresh()->status)->toBe('failed')
            ->and($oldRecovery->fresh()->status)->toBe('retryable')
            ->and($response['recovery_id'])->toBe($newPayment->checkout_recovery_id);
    }

}
