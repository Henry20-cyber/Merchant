<?php

namespace Tests\Feature\Payment;

use App\Domains\Organization\Models\Business;
use App\Domains\Payment\Contracts\PaymentGateway;
use App\Domains\Payment\Models\Payment;
use App\Domains\Payment\Services\PaymentConfirmationService;
use App\Domains\Subscription\Models\Subscription;
use App\Domains\Subscription\Models\SubscriptionPlan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Mockery;
use Tests\TestCase;

class PaymentConfirmationServiceTest extends TestCase
{
    use RefreshDatabase;

    private PaymentGateway $gateway;

    protected function setUp(): void
    {
        parent::setUp();

        $this->gateway = Mockery::mock(
            PaymentGateway::class
        );

        $this->app->instance(
            PaymentGateway::class,
            $this->gateway
        );
    }

    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    /**
     * Create a test business.
     */
    private function createBusiness(): Business
    {
        return Business::factory()->create();
    }

    /**
     * Create an active subscription plan.
     */
    private function createPlan(
        string $billingInterval = 'monthly'
    ): SubscriptionPlan {
        return SubscriptionPlan::factory()->create([
            'price' => '10000.00',
            'currency' => 'NGN',
            'billing_interval' => $billingInterval,

            'paystack_plan_code' =>
                $billingInterval === 'yearly'
                    ? 'PLN_test_yearly'
                    : 'PLN_test_monthly',

            'is_active' => true,
        ]);
    }

    /**
     * Create a pending subscription payment.
     */
    private function createPayment(
        Business $business,
        SubscriptionPlan $plan,
        string $reference = 'MERCHANTOS-TEST-001'
    ): Payment {
        return Payment::factory()->create([
            'business_id' => $business->id,
            'subscription_id' => null,
            'sale_id' => null,

            'amount' => '10000.00',
            'method' => 'paystack',
            'status' => 'pending',
            'reference' => $reference,

            'metadata' => [
                'type' => 'subscription',
                'business_id' => $business->id,
                'plan_id' => $plan->id,
                'plan_slug' => $plan->slug,
                'billing_interval' =>
                    $plan->billing_interval,
            ],
        ]);
    }

    /**
     * Successful Paystack verification response.
     */
    private function successfulVerification(
        Payment $payment,
        string $authorizationCode = 'AUTH_TEST_001',
        string $customerCode = 'CUS_TEST_001'
    ): array {
        return [
            'success' => true,
            'status' => 'success',
            'reference' => $payment->reference,

            /*
             * Payment amount is ₦10,000.
             * Paystack uses kobo.
             */
            'amount' => 1_000_000,

            'currency' => 'NGN',

            'authorization_code' =>
                $authorizationCode,

            'customer_code' =>
                $customerCode,

            'raw' => [],
        ];
    }

    /**
     * Successful Paystack recurring subscription response.
     */
    private function successfulProviderSubscription(
        string $subscriptionCode = 'SUB_TEST_001',
        string $customerCode = 'CUS_TEST_001',
        string $emailToken = 'EMAIL_TOKEN_TEST_001'
    ): array {
        return [
            'success' => true,

            'subscription_code' =>
                $subscriptionCode,

            'customer_code' =>
                $customerCode,

            'email_token' =>
                $emailToken,

            'raw' => [],
        ];
    }

    /**
     * Successful subscription payment is marked paid.
     */
    public function test_successful_subscription_payment_is_marked_paid(): void
    {
        $business = $this->createBusiness();

        $plan = $this->createPlan();

        $payment = $this->createPayment(
            $business,
            $plan,
            'MERCHANTOS-TEST-001'
        );

        $this->gateway
            ->shouldReceive('verify')
            ->once()
            ->with($payment->reference)
            ->andReturn(
                $this->successfulVerification(
                    $payment,
                    'AUTH_TEST_001',
                    'CUS_TEST_001'
                )
            );

        $this->gateway
            ->shouldReceive('createSubscription')
            ->once()
            ->with([
                'customer_code' => 'CUS_TEST_001',
                'plan_code' => 'PLN_test_monthly',
                'authorization_code' => 'AUTH_TEST_001',
            ])
            ->andReturn(
                $this->successfulProviderSubscription(
                    'SUB_TEST_001',
                    'CUS_TEST_001',
                    'EMAIL_TOKEN_TEST_001'
                )
            );

        app(PaymentConfirmationService::class)
            ->confirm($payment->reference);

        $payment->refresh();

        expect($payment->status)
            ->toBe('paid');

        expect($payment->paid_at)
            ->not->toBeNull();
    }

    /**
     * Successful payment creates an active subscription.
     */
    public function test_successful_payment_creates_active_subscription(): void
    {
        $business = $this->createBusiness();

        $plan = $this->createPlan();

        $payment = $this->createPayment(
            $business,
            $plan,
            'MERCHANTOS-TEST-002'
        );

        $this->gateway
            ->shouldReceive('verify')
            ->once()
            ->with($payment->reference)
            ->andReturn(
                $this->successfulVerification(
                    $payment,
                    'AUTH_TEST_002',
                    'CUS_TEST_002'
                )
            );

        $this->gateway
            ->shouldReceive('createSubscription')
            ->once()
            ->with([
                'customer_code' => 'CUS_TEST_002',
                'plan_code' => 'PLN_test_monthly',
                'authorization_code' => 'AUTH_TEST_002',
            ])
            ->andReturn(
                $this->successfulProviderSubscription(
                    'SUB_TEST_002',
                    'CUS_TEST_002',
                    'EMAIL_TOKEN_TEST_002'
                )
            );

        app(PaymentConfirmationService::class)
            ->confirm($payment->reference);

        $subscription = Subscription::query()
            ->where('business_id', $business->id)
            ->first();

        expect($subscription)
            ->not->toBeNull();

        expect($subscription->status)
            ->toBe('active');

        expect($subscription->plan_id)
            ->toBe($plan->id);

        expect($subscription->provider)
            ->toBe('paystack');

        expect($subscription->provider_customer_code)
            ->toBe('CUS_TEST_002');

        expect($subscription->provider_authorization_code)
            ->toBe('AUTH_TEST_002');

        expect($subscription->provider_subscription_code)
            ->toBe('SUB_TEST_002');

        expect($subscription->provider_email_token)
            ->toBe('EMAIL_TOKEN_TEST_002');

        expect($payment->fresh()->subscription_id)
            ->toBe($subscription->id);
    }

    /**
     * Monthly subscription receives a one-month period.
     */
    public function test_monthly_subscription_gets_one_month_period(): void
    {
        $business = $this->createBusiness();

        $plan = $this->createPlan('monthly');

        $payment = $this->createPayment(
            $business,
            $plan,
            'MERCHANTOS-TEST-MONTHLY'
        );

        $this->gateway
            ->shouldReceive('verify')
            ->once()
            ->andReturn(
                $this->successfulVerification(
                    $payment,
                    'AUTH_MONTHLY',
                    'CUS_MONTHLY'
                )
            );

        $this->gateway
            ->shouldReceive('createSubscription')
            ->once()
            ->with([
                'customer_code' => 'CUS_MONTHLY',
                'plan_code' => 'PLN_test_monthly',
                'authorization_code' => 'AUTH_MONTHLY',
            ])
            ->andReturn(
                $this->successfulProviderSubscription(
                    'SUB_MONTHLY',
                    'CUS_MONTHLY',
                    'EMAIL_MONTHLY'
                )
            );

        $before = now();

        app(PaymentConfirmationService::class)
            ->confirm($payment->reference);

        $after = now();

        $subscription = Subscription::query()
            ->where('business_id', $business->id)
            ->firstOrFail();

        expect($subscription->current_period_start)
            ->not->toBeNull();

        expect($subscription->current_period_end)
            ->not->toBeNull();

        expect(
            $subscription->current_period_end->greaterThan(
                $subscription->current_period_start
            )
        )->toBeTrue();

        expect(
            $subscription->current_period_end->lessThanOrEqualTo(
                $after->copy()->addMonth()->addSecond()
            )
        )->toBeTrue();

        expect(
            $subscription->current_period_start->greaterThanOrEqualTo(
                $before->subSecond()
            )
        )->toBeTrue();
    }

    /**
     * Yearly subscription receives a one-year period.
     */
    public function test_yearly_subscription_gets_one_year_period(): void
    {
        $business = $this->createBusiness();

        $plan = $this->createPlan('yearly');

        $payment = $this->createPayment(
            $business,
            $plan,
            'MERCHANTOS-TEST-YEARLY'
        );

        $this->gateway
            ->shouldReceive('verify')
            ->once()
            ->andReturn(
                $this->successfulVerification(
                    $payment,
                    'AUTH_YEARLY',
                    'CUS_YEARLY'
                )
            );

        $this->gateway
            ->shouldReceive('createSubscription')
            ->once()
            ->with([
                'customer_code' => 'CUS_YEARLY',
                'plan_code' => 'PLN_test_yearly',
                'authorization_code' => 'AUTH_YEARLY',
            ])
            ->andReturn(
                $this->successfulProviderSubscription(
                    'SUB_YEARLY',
                    'CUS_YEARLY',
                    'EMAIL_YEARLY'
                )
            );

        $before = now();

        app(PaymentConfirmationService::class)
            ->confirm($payment->reference);

        $after = now();

        $subscription = Subscription::query()
            ->where('business_id', $business->id)
            ->firstOrFail();

        expect(
            $subscription->current_period_end->greaterThan(
                $subscription->current_period_start
            )
        )->toBeTrue();

        expect(
            $subscription->current_period_end->lessThanOrEqualTo(
                $after->copy()->addYear()->addSecond()
            )
        )->toBeTrue();

        expect(
            $subscription->current_period_start->greaterThanOrEqualTo(
                $before->subSecond()
            )
        )->toBeTrue();
    }

    /**
     * Existing trial subscription becomes active.
     */
    public function test_existing_trial_subscription_becomes_active(): void
    {
        $business = $this->createBusiness();

        $oldPlan = $this->createPlan('monthly');

        $newPlan = SubscriptionPlan::factory()->create([
            'price' => '10000.00',
            'currency' => 'NGN',
            'billing_interval' => 'monthly',
            'paystack_plan_code' => 'PLN_test_new_monthly',
            'is_active' => true,
        ]);

        $subscription = Subscription::factory()->create([
            'business_id' => $business->id,
            'plan_id' => $oldPlan->id,
            'status' => 'trial',
            'provider' => null,
            'provider_customer_code' => null,
            'provider_authorization_code' => null,
            'provider_subscription_code' => null,
            'provider_email_token' => null,
        ]);

        $payment = Payment::factory()->create([
            'business_id' => $business->id,
            'subscription_id' => null,
            'sale_id' => null,
            'amount' => '10000.00',
            'method' => 'paystack',
            'status' => 'pending',
            'reference' => 'MERCHANTOS-TEST-TRIAL',
            'metadata' => [
                'type' => 'subscription',
                'business_id' => $business->id,
                'plan_id' => $newPlan->id,
                'plan_slug' => $newPlan->slug,
                'billing_interval' => 'monthly',
            ],
        ]);

        $this->gateway
            ->shouldReceive('verify')
            ->once()
            ->andReturn(
                $this->successfulVerification(
                    $payment,
                    'AUTH_TRIAL',
                    'CUS_TRIAL'
                )
            );

        $this->gateway
            ->shouldReceive('createSubscription')
            ->once()
            ->with([
                'customer_code' => 'CUS_TRIAL',
                'plan_code' => 'PLN_test_new_monthly',
                'authorization_code' => 'AUTH_TRIAL',
            ])
            ->andReturn(
                $this->successfulProviderSubscription(
                    'SUB_TRIAL',
                    'CUS_TRIAL',
                    'EMAIL_TRIAL'
                )
            );

        app(PaymentConfirmationService::class)
            ->confirm($payment->reference);

        $subscription->refresh();

        expect($subscription->status)
            ->toBe('active');

        expect($subscription->plan_id)
            ->toBe($newPlan->id);

        expect($subscription->provider)
            ->toBe('paystack');

        expect($subscription->provider_customer_code)
            ->toBe('CUS_TRIAL');

        expect($subscription->provider_authorization_code)
            ->toBe('AUTH_TRIAL');

        expect($subscription->provider_subscription_code)
            ->toBe('SUB_TRIAL');

        expect($subscription->provider_email_token)
            ->toBe('EMAIL_TRIAL');
    }

    /**
     * Wrong amount is rejected.
     */
    public function test_wrong_amount_is_rejected(): void
    {
        $business = $this->createBusiness();

        $plan = $this->createPlan();

        $payment = $this->createPayment(
            $business,
            $plan,
            'MERCHANTOS-TEST-WRONG-AMOUNT'
        );

        $this->gateway
            ->shouldReceive('verify')
            ->once()
            ->andReturn([
                'success' => true,
                'status' => 'success',
                'reference' => $payment->reference,
                'amount' => 999_999,
                'currency' => 'NGN',
                'authorization_code' => 'AUTH_WRONG_AMOUNT',
                'customer_code' => 'CUS_WRONG_AMOUNT',
                'raw' => [],
            ]);

        $this->gateway
            ->shouldNotReceive('createSubscription');

        expect(
            fn () => app(
                PaymentConfirmationService::class
            )->confirm($payment->reference)
        )->toThrow(
            ValidationException::class
        );

        expect($payment->fresh()->status)
            ->toBe('pending');
    }

    /**
     * Wrong currency is rejected.
     */
    public function test_wrong_currency_is_rejected(): void
    {
        $business = $this->createBusiness();

        $plan = $this->createPlan();

        $payment = $this->createPayment(
            $business,
            $plan,
            'MERCHANTOS-TEST-WRONG-CURRENCY'
        );

        $this->gateway
            ->shouldReceive('verify')
            ->once()
            ->andReturn([
                'success' => true,
                'status' => 'success',
                'reference' => $payment->reference,
                'amount' => 1_000_000,
                'currency' => 'USD',
                'authorization_code' => 'AUTH_WRONG_CURRENCY',
                'customer_code' => 'CUS_WRONG_CURRENCY',
                'raw' => [],
            ]);

        $this->gateway
            ->shouldNotReceive('createSubscription');

        expect(
            fn () => app(
                PaymentConfirmationService::class
            )->confirm($payment->reference)
        )->toThrow(
            ValidationException::class
        );

        expect($payment->fresh()->status)
            ->toBe('pending');
    }

    /**
     * A failed Paystack transaction is persisted as failed.
     */
    public function test_failed_transaction_marks_pending_payment_failed(): void
    {
        $business = $this->createBusiness();

        $plan = $this->createPlan();

        $payment = $this->createPayment(
            $business,
            $plan,
            'MERCHANTOS-TEST-FAILED'
        );

        $this->gateway
            ->shouldReceive('verify')
            ->once()
            ->with($payment->reference)
            ->andReturn([
                'success' => true,
                'status' => 'failed',
                'reference' => $payment->reference,
                'amount' => 1_000_000,
                'currency' => 'NGN',
                'authorization_code' => null,
                'customer_code' => null,
                'raw' => [],
            ]);

        $this->gateway
            ->shouldNotReceive('createSubscription');

        $result = app(
            PaymentConfirmationService::class
        )->confirm($payment->reference);

        expect($result->status)
            ->toBe('failed');

        expect($payment->fresh()->status)
            ->toBe('failed');

        expect(
            Subscription::query()
                ->where('business_id', $business->id)
                ->exists()
        )->toBeFalse();
    }

    /**
     * Unknown payment reference is rejected.
     */
    public function test_unknown_reference_is_rejected(): void
    {
        $this->gateway
            ->shouldNotReceive('verify');

        expect(
            fn () => app(
                PaymentConfirmationService::class
            )->confirm('DOES-NOT-EXIST')
        )->toThrow(
            ValidationException::class
        );
    }

    /**
     * Duplicate confirmation does not fulfil twice.
     */
    public function test_duplicate_confirmation_does_not_fulfil_twice(): void
    {
        $business = $this->createBusiness();

        $plan = $this->createPlan();

        $payment = $this->createPayment(
            $business,
            $plan,
            'MERCHANTOS-TEST-DUPLICATE'
        );

        $this->gateway
            ->shouldReceive('verify')
            ->once()
            ->with($payment->reference)
            ->andReturn(
                $this->successfulVerification(
                    $payment,
                    'AUTH_DUPLICATE',
                    'CUS_DUPLICATE'
                )
            );

        $this->gateway
            ->shouldReceive('createSubscription')
            ->once()
            ->with([
                'customer_code' => 'CUS_DUPLICATE',
                'plan_code' => 'PLN_test_monthly',
                'authorization_code' => 'AUTH_DUPLICATE',
            ])
            ->andReturn(
                $this->successfulProviderSubscription(
                    'SUB_DUPLICATE',
                    'CUS_DUPLICATE',
                    'EMAIL_DUPLICATE'
                )
            );

        $service = app(
            PaymentConfirmationService::class
        );

        /*
         * First confirmation.
         */
        $service->confirm(
            $payment->reference
        );

        /*
         * Second confirmation.
         *
         * No provider calls should occur.
         */
        $service->confirm(
            $payment->reference
        );

        expect(
            Subscription::query()
                ->where('business_id', $business->id)
                ->count()
        )->toBe(1);

        expect(
            Payment::query()
                ->where('reference', $payment->reference)
                ->count()
        )->toBe(1);

        expect(
            $payment->fresh()->status
        )->toBe('paid');
    }

    /**
     * Provider billing identifiers are persisted.
     */
    public function test_successful_payment_stores_provider_billing_identifiers(): void
    {
        $business = $this->createBusiness();

        $plan = $this->createPlan();

        $payment = $this->createPayment(
            $business,
            $plan,
            'MERCHANTOS-TEST-PROVIDER'
        );

        $this->gateway
            ->shouldReceive('verify')
            ->once()
            ->andReturn(
                $this->successfulVerification(
                    $payment,
                    'AUTH_PROVIDER',
                    'CUS_PROVIDER'
                )
            );

        $this->gateway
            ->shouldReceive('createSubscription')
            ->once()
            ->with([
                'customer_code' => 'CUS_PROVIDER',
                'plan_code' => 'PLN_test_monthly',
                'authorization_code' => 'AUTH_PROVIDER',
            ])
            ->andReturn(
                $this->successfulProviderSubscription(
                    'SUB_PROVIDER',
                    'CUS_PROVIDER',
                    'EMAIL_PROVIDER'
                )
            );

        app(PaymentConfirmationService::class)
            ->confirm($payment->reference);

        $subscription = Subscription::query()
            ->where('business_id', $business->id)
            ->firstOrFail();

        expect($subscription->provider)
            ->toBe('paystack');

        expect($subscription->provider_customer_code)
            ->toBe('CUS_PROVIDER');

        expect($subscription->provider_authorization_code)
            ->toBe('AUTH_PROVIDER');

        expect($subscription->provider_subscription_code)
            ->toBe('SUB_PROVIDER');

        expect($subscription->provider_email_token)
            ->toBe('EMAIL_PROVIDER');
    }

    /**
     * Missing Paystack authorization information is rejected.
     */
    public function test_missing_paystack_authorization_information_is_rejected(): void
    {
        $business = $this->createBusiness();

        $plan = $this->createPlan();

        $payment = $this->createPayment(
            $business,
            $plan,
            'MERCHANTOS-TEST-NO-AUTH'
        );

        $this->gateway
            ->shouldReceive('verify')
            ->once()
            ->andReturn([
                'success' => true,
                'status' => 'success',
                'reference' => $payment->reference,
                'amount' => 1_000_000,
                'currency' => 'NGN',
                'authorization_code' => null,
                'customer_code' => null,
                'raw' => [],
            ]);

        $this->gateway
            ->shouldNotReceive('createSubscription');

        expect(
            fn () => app(
                PaymentConfirmationService::class
            )->confirm($payment->reference)
        )->toThrow(
            ValidationException::class
        );

        /*
         * The transaction rolls back.
         */
        expect($payment->fresh()->status)
            ->toBe('pending');

        expect(
            Subscription::query()
                ->where('business_id', $business->id)
                ->exists()
        )->toBeFalse();
    }

    /**
     * A plan without a Paystack plan code cannot be used
     * for recurring billing.
     */
    public function test_plan_without_paystack_plan_code_is_rejected(): void
    {
        $business = $this->createBusiness();

        $plan = SubscriptionPlan::factory()->create([
            'price' => '10000.00',
            'currency' => 'NGN',
            'billing_interval' => 'monthly',
            'paystack_plan_code' => null,
            'is_active' => true,
        ]);

        $payment = $this->createPayment(
            $business,
            $plan,
            'MERCHANTOS-TEST-NO-PLAN-CODE'
        );

        $this->gateway
            ->shouldReceive('verify')
            ->once()
            ->andReturn(
                $this->successfulVerification(
                    $payment,
                    'AUTH_NO_PLAN',
                    'CUS_NO_PLAN'
                )
            );

        $this->gateway
            ->shouldNotReceive('createSubscription');

        expect(
            fn () => app(
                PaymentConfirmationService::class
            )->confirm($payment->reference)
        )->toThrow(
            ValidationException::class
        );

        expect($payment->fresh()->status)
            ->toBe('pending');
    }

    /**
     * Provider subscription must return a subscription code.
     */
    public function test_missing_provider_subscription_code_is_rejected(): void
    {
        $business = $this->createBusiness();

        $plan = $this->createPlan();

        $payment = $this->createPayment(
            $business,
            $plan,
            'MERCHANTOS-TEST-NO-SUB-CODE'
        );

        $this->gateway
            ->shouldReceive('verify')
            ->once()
            ->andReturn(
                $this->successfulVerification(
                    $payment,
                    'AUTH_NO_SUB',
                    'CUS_NO_SUB'
                )
            );

        $this->gateway
            ->shouldReceive('createSubscription')
            ->once()
            ->andReturn([
                'success' => true,
                'subscription_code' => null,
                'customer_code' => 'CUS_NO_SUB',
                'email_token' => 'EMAIL_NO_SUB',
                'raw' => [],
            ]);

        expect(
            fn () => app(
                PaymentConfirmationService::class
            )->confirm($payment->reference)
        )->toThrow(
            ValidationException::class
        );

        /*
         * The whole local transaction rolls back.
         */
        expect($payment->fresh()->status)
            ->toBe('pending');

        expect(
            Subscription::query()
                ->where('business_id', $business->id)
                ->exists()
        )->toBeFalse();
    }
}