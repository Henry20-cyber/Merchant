<?php

namespace Tests\Feature\Subscription;

use App\Domains\Organization\Models\Business;
use App\Domains\Organization\Models\BusinessUser;
use App\Models\User;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use App\Domains\Payment\Contracts\PaymentGateway;
use App\Domains\Payment\Models\Payment;
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
            'paystack_plan_code' => 'PLN_test_account',
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
            ->andReturn([
                'success' => true,
                'authorization_url' => 'https://checkout.paystack.com/test',
                'access_code' => 'test_access_code',
                'reference' => 'MERCHANTOS-TEST',
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
            ->andReturn([
                'success' => true,
                'authorization_url' => 'https://checkout.paystack.com/test',
                'access_code' => 'test',
                'reference' => 'MERCHANTOS-TEST',
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
            ->andReturn([
                'success' => true,
                'authorization_url' => 'https://checkout.paystack.com/test',
                'access_code' => 'test',
                'reference' => 'MERCHANTOS-TEST',
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
        ->andReturn([
            'success' => true,
            'authorization_url' => 'https://checkout.paystack.com/test',
            'access_code' => 'test',
            'reference' => 'MERCHANTOS-TEST',
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
        ->andReturn([
            'success' => true,
            'authorization_url' => 'https://checkout.paystack.com/test',
            'access_code' => 'test',
            'reference' => 'MERCHANTOS-TEST',
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
}
