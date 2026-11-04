<?php

namespace Tests\Feature\Payment;

use App\Domains\Organization\Models\Business;
use App\Domains\Payment\Contracts\PaymentGateway;
use App\Domains\Payment\Models\Payment;
use App\Domains\Payment\Models\SubscriptionCheckoutRecovery;
use App\Domains\Sales\Models\Sale;
use App\Models\User;
use App\Domains\Payment\Services\PaymentConfirmationService;
use App\Domains\Payment\Services\PaystackSubscriptionProvisioningService;
use App\Domains\Subscription\Models\Subscription;
use App\Domains\Subscription\Models\SubscriptionPlan;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Validation\ValidationException;
use Mockery;
use Tests\TestCase;

class PaymentConfirmationServiceTest extends TestCase
{
    use RefreshDatabase;

    private PaymentGateway $gateway;
    private PaystackSubscriptionProvisioningService $provisioner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->gateway = Mockery::mock(
            PaymentGateway::class
        );

        $this->app->instance(PaymentGateway::class, $this->gateway);
        $this->provisioner = Mockery::mock(PaystackSubscriptionProvisioningService::class);
        $this->provisioner->shouldReceive('provision')->zeroOrMoreTimes()->byDefault()->andReturnUsing(function (Subscription $subscription): Subscription {
            if (! $subscription->provider_subscription_code) {
                $canProvision = filled($subscription->provider_authorization_code)
                    && filled($subscription->provider_customer_code)
                    && filled($subscription->plan?->paystack_plan_code);
                $subscription->forceFill([
                    'provider_subscription_code' => $canProvision
                        ? str_replace('AUTH_', 'SUB_', (string) $subscription->provider_authorization_code)
                        : null,
                    'provider_email_token' => $canProvision
                        ? str_replace('AUTH_', 'EMAIL_', (string) $subscription->provider_authorization_code)
                        : null,
                    'provisioning_status' => $canProvision ? 'provisioned' : 'manual_review',
                ])->save();
            }
            return $subscription->fresh();
        });
        $this->app->instance(PaystackSubscriptionProvisioningService::class, $this->provisioner);
    }

    protected function tearDown(): void
    {
        Mockery::close();

        Carbon::setTestNow();

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

            'paystack_plan_code' => 'PLN_test_' . $billingInterval . '_' . Str::lower((string) Str::uuid()),

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
                'currency' => $plan->currency,
                'paystack_plan_code' => $plan->paystack_plan_code,
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

    public function test_confirmed_sale_payment_updates_sale_payment_status(): void
    {
        $business = $this->createBusiness();
        $cashier = User::factory()->create();

        $sale = Sale::create([
            'business_id' => $business->id,
            'cashier_id' => $cashier->id,
            'customer_id' => null,
            'subtotal' => 10000,
            'discount' => 0,
            'vat_amount' => 0,
            'total' => 10000,
            'payment_method' => 'bank_transfer',
            'payment_status' => 'unpaid',
            'status' => 'completed',
        ]);

        $payment = Payment::factory()->create([
            'business_id' => $business->id,
            'subscription_id' => null,
            'sale_id' => $sale->id,
            'amount' => '10000.00',
            'method' => 'bank_transfer',
            'status' => 'pending',
            'reference' => 'MERCHANTOS-SALE-CONFIRM-001',
            'metadata' => ['type' => 'sale'],
        ]);

        $this->gateway
            ->shouldReceive('verify')
            ->once()
            ->with($payment->reference)
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

        $confirmedPayment = app(PaymentConfirmationService::class)
            ->confirm($payment->reference);

        expect($confirmedPayment->fresh()->status)
            ->toBe('paid');

        expect($sale->fresh()->payment_status)
            ->toBe('paid');
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
            ->toBe('EMAIL_TEST_002');

        expect($payment->fresh()->subscription_id)
            ->toBe($subscription->id);
    }

    public function test_successful_payment_upgrades_existing_active_free_subscription(): void
    {
        $business = $this->createBusiness();
        $free = SubscriptionPlan::factory()->create(['slug' => 'free', 'price' => '0.00', 'paystack_plan_code' => null]);
        $paid = $this->createPlan('monthly');
        $subscription = Subscription::factory()->create([
            'business_id' => $business->id,
            'plan_id' => $free->id,
            'status' => 'active',
            'provider' => null,
            'provider_subscription_code' => null,
            'provisioning_status' => 'not_required',
        ]);
        $payment = Payment::factory()->create([
            'business_id' => $business->id,
            'subscription_id' => $subscription->id,
            'sale_id' => null,
            'amount' => $paid->price,
            'method' => 'paystack',
            'status' => 'pending',
            'reference' => 'MERCHANTOS-FREE-UPGRADE-SUCCESS',
            'metadata' => [
                'type' => 'subscription', 'business_id' => $business->id,
                'subscription_id' => $subscription->id, 'plan_id' => $paid->id,
                'billing_interval' => 'monthly', 'currency' => 'NGN',
                'paystack_plan_code' => $paid->paystack_plan_code,
            ],
        ]);
        $this->gateway->shouldReceive('verify')->once()->with($payment->reference)->andReturn($this->successfulVerification($payment));

        app(PaymentConfirmationService::class)->confirm($payment->reference);

        expect($payment->fresh()->status)->toBe('paid')
            ->and($subscription->fresh()->status)->toBe('active')
            ->and($subscription->fresh()->plan_id)->toBe($paid->id);
    }

    public function test_only_one_successful_attempt_in_a_recovery_group_provisions_subscription(): void
    {
        $business = $this->createBusiness();
        $free = SubscriptionPlan::factory()->create(['slug' => 'free', 'price' => '0.00', 'paystack_plan_code' => null]);
        $plan = $this->createPlan();
        $subscription = Subscription::create(['business_id' => $business->id, 'plan_id' => $free->id, 'status' => 'active']);
        $recovery = SubscriptionCheckoutRecovery::query()->create([
            'business_id' => $business->id,
            'subscription_id' => $subscription->id,
            'plan_id' => $plan->id,
            'purchase_terms' => [
                'amount' => '10000.00',
                'currency' => 'NGN',
                'billing_interval' => 'monthly',
                'plan_id' => $plan->id,
                'paystack_plan_code' => $plan->paystack_plan_code,
                'subscription_id' => $subscription->id,
            ],
            'status' => 'pending',
        ]);
        $first = $this->createPayment($business, $plan, 'RECOVERY-WINNER');
        $first->forceFill(['subscription_id' => $subscription->id, 'checkout_recovery_id' => $recovery->id, 'attempt_number' => 1])->save();
        $second = $this->createPayment($business, $plan, 'RECOVERY-LATE-SUCCESS');
        $second->forceFill(['subscription_id' => $subscription->id, 'checkout_recovery_id' => $recovery->id, 'attempt_number' => 2])->save();

        $this->gateway->shouldReceive('verify')->twice()->andReturnUsing(function (string $reference) use ($first, $second): array {
            return $this->successfulVerification($reference === $first->reference ? $first : $second);
        });

        app(PaymentConfirmationService::class)->confirm($first->reference);
        app(PaymentConfirmationService::class)->confirm($second->reference);

        expect($recovery->fresh()->winning_payment_id)->toBe($first->id)
            ->and($recovery->fresh()->status)->toBe('manual_review')
            ->and($first->fresh()->status)->toBe('paid')
            ->and($second->fresh()->status)->toBe('paid')
            ->and($second->fresh()->metadata['entitlement_review'])->toBe('Another payment attempt already fulfilled this checkout purchase.')
            ->and($subscription->fresh()->plan_id)->toBe($plan->id);
    }

    public function test_bounded_reconciliation_keeps_inconclusive_payment_pending_and_schedules_retry(): void
    {
        $business = $this->createBusiness();
        $plan = $this->createPlan();
        $payment = $this->createPayment($business, $plan, 'BOUNDED-RECONCILIATION-PENDING');
        $recovery = SubscriptionCheckoutRecovery::query()->create([
            'business_id' => $business->id,
            'plan_id' => $plan->id,
            'purchase_terms' => [
                'plan_id' => $plan->id,
                'amount' => '10000.00',
                'currency' => 'NGN',
                'billing_interval' => $plan->billing_interval,
                'paystack_plan_code' => $plan->paystack_plan_code,
                'subscription_id' => null,
            ],
            'status' => 'pending',
        ]);
        $payment->forceFill([
            'checkout_recovery_id' => $recovery->id,
            'attempt_number' => 1,
            'created_at' => now()->subMinutes(10),
        ])->save();
        $this->gateway->shouldReceive('verify')->once()->with($payment->reference)->andReturn([
            'success' => true,
            'status' => 'pending',
            'reference' => $payment->reference,
            'amount' => 1_000_000,
            'currency' => 'NGN',
        ]);

        Artisan::call('payments:reconcile-subscription-checkouts', ['--limit' => 50]);
        Artisan::call('payments:reconcile-subscription-checkouts', ['--limit' => 50]);

        expect($payment->fresh()->status)->toBe('pending')
            ->and($payment->fresh()->reconciliation_attempts)->toBe(1)
            ->and($payment->fresh()->next_reconciliation_at)->not->toBeNull()
            ->and($recovery->fresh()->status)->toBe('pending');
    }

    public function test_successful_payment_upgrades_existing_active_free_subscription_to_annual_plan(): void
    {
        $business = $this->createBusiness();
        $free = SubscriptionPlan::factory()->create(['slug' => 'free', 'price' => '0.00', 'paystack_plan_code' => null]);
        $annual = $this->createPlan('yearly');
        $subscription = Subscription::factory()->create(['business_id' => $business->id, 'plan_id' => $free->id, 'status' => 'active', 'provisioning_status' => 'not_required']);
        $payment = Payment::factory()->create([
            'business_id' => $business->id, 'subscription_id' => $subscription->id, 'sale_id' => null,
            'amount' => $annual->price, 'method' => 'paystack', 'status' => 'pending', 'reference' => 'MERCHANTOS-FREE-UPGRADE-ANNUAL',
            'metadata' => ['type' => 'subscription', 'business_id' => $business->id, 'subscription_id' => $subscription->id,
                'plan_id' => $annual->id, 'billing_interval' => 'yearly', 'currency' => 'NGN', 'paystack_plan_code' => $annual->paystack_plan_code],
        ]);
        $this->gateway->shouldReceive('verify')->once()->with($payment->reference)->andReturn($this->successfulVerification($payment));

        app(PaymentConfirmationService::class)->confirm($payment->reference);

        expect($subscription->fresh()->status)->toBe('active')
            ->and($subscription->fresh()->plan_id)->toBe($annual->id)
            ->and(abs($subscription->fresh()->current_period_end->diffInDays($subscription->fresh()->current_period_start)))->toBeGreaterThan(360);
    }

    public function test_failed_and_inconclusive_free_upgrade_payments_leave_subscription_unchanged(): void
    {
        $free = SubscriptionPlan::factory()->create(['slug' => 'free', 'price' => '0.00', 'paystack_plan_code' => null]);
        foreach ([['failed', 'failed'], ['pending', 'pending']] as [$providerStatus, $localStatus]) {
            $business = $this->createBusiness();
            $paid = $this->createPlan();
            $subscription = Subscription::factory()->create(['business_id' => $business->id, 'plan_id' => $free->id, 'status' => 'active', 'provisioning_status' => 'not_required']);
            $payment = Payment::factory()->create([
                'business_id' => $business->id, 'subscription_id' => $subscription->id, 'amount' => $paid->price,
                'method' => 'paystack', 'status' => 'pending', 'reference' => 'MERCHANTOS-FREE-UPGRADE-' . strtoupper($providerStatus),
                'metadata' => ['type' => 'subscription', 'business_id' => $business->id, 'subscription_id' => $subscription->id,
                    'plan_id' => $paid->id, 'billing_interval' => 'monthly', 'currency' => 'NGN', 'paystack_plan_code' => $paid->paystack_plan_code],
            ]);
            $this->gateway->shouldReceive('verify')->once()->with($payment->reference)->andReturn([
                'success' => true, 'status' => $providerStatus, 'reference' => $payment->reference,
                'amount' => 1_000_000, 'currency' => 'NGN',
            ]);

            app(PaymentConfirmationService::class)->confirm($payment->reference);

            expect($payment->fresh()->status)->toBe($localStatus)
                ->and($subscription->fresh()->status)->toBe('active')
                ->and($subscription->fresh()->plan_id)->toBe($free->id);
        }
    }

    public function test_provider_verification_exception_leaves_free_upgrade_pending(): void
    {
        $business = $this->createBusiness();
        $free = SubscriptionPlan::factory()->create(['slug' => 'free', 'price' => '0.00', 'paystack_plan_code' => null]);
        $paid = $this->createPlan();
        $subscription = Subscription::factory()->create(['business_id' => $business->id, 'plan_id' => $free->id, 'status' => 'active', 'provisioning_status' => 'not_required']);
        $payment = Payment::factory()->create([
            'business_id' => $business->id, 'subscription_id' => $subscription->id, 'amount' => $paid->price,
            'method' => 'paystack', 'status' => 'pending', 'reference' => 'MERCHANTOS-FREE-UPGRADE-VERIFY-ERROR',
            'metadata' => ['type' => 'subscription', 'business_id' => $business->id, 'subscription_id' => $subscription->id,
                'plan_id' => $paid->id, 'billing_interval' => 'monthly', 'currency' => 'NGN', 'paystack_plan_code' => $paid->paystack_plan_code],
        ]);
        $this->gateway->shouldReceive('verify')->once()->with($payment->reference)->andThrow(new \RuntimeException('Gateway unavailable'));

        expect(fn () => app(PaymentConfirmationService::class)->confirm($payment->reference))->toThrow(\RuntimeException::class);
        expect($payment->fresh()->status)->toBe('pending')
            ->and($subscription->fresh()->status)->toBe('active')
            ->and($subscription->fresh()->plan_id)->toBe($free->id);
    }

    public function test_payment_for_another_business_cannot_modify_target_subscription(): void
    {
        $paymentBusiness = $this->createBusiness();
        $subscriptionBusiness = $this->createBusiness();
        $free = SubscriptionPlan::factory()->create(['slug' => 'free', 'price' => '0.00', 'paystack_plan_code' => null]);
        $paid = $this->createPlan();
        $subscription = Subscription::factory()->create(['business_id' => $subscriptionBusiness->id, 'plan_id' => $free->id, 'status' => 'active', 'provisioning_status' => 'not_required']);
        $payment = Payment::factory()->create([
            'business_id' => $paymentBusiness->id, 'subscription_id' => $subscription->id, 'amount' => $paid->price,
            'method' => 'paystack', 'status' => 'pending', 'reference' => 'MERCHANTOS-CROSS-BUSINESS',
            'metadata' => ['type' => 'subscription', 'business_id' => $paymentBusiness->id, 'subscription_id' => $subscription->id,
                'plan_id' => $paid->id, 'billing_interval' => 'monthly', 'currency' => 'NGN', 'paystack_plan_code' => $paid->paystack_plan_code],
        ]);
        $this->gateway->shouldReceive('verify')->once()->andReturn($this->successfulVerification($payment));

        app(PaymentConfirmationService::class)->confirm($payment->reference);

        expect($payment->fresh()->status)->toBe('paid')
            ->and($payment->fresh()->metadata['entitlement_review'])->toBeString()
            ->and($subscription->fresh()->plan_id)->toBe($free->id)
            ->and($subscription->fresh()->status)->toBe('active');
    }

    public function test_active_paid_subscription_is_not_overwritten_by_another_checkout_payment(): void
    {
        $business = $this->createBusiness();
        $currentPlan = $this->createPlan();
        $requestedPlan = $this->createPlan('yearly');
        $subscription = Subscription::factory()->create([
            'business_id' => $business->id, 'plan_id' => $currentPlan->id,
            'status' => 'active', 'provider_subscription_code' => null,
        ]);
        $payment = Payment::factory()->create([
            'business_id' => $business->id, 'subscription_id' => $subscription->id,
            'amount' => $requestedPlan->price, 'method' => 'paystack', 'status' => 'pending',
            'reference' => 'MERCHANTOS-ACTIVE-PAID-GUARD',
            'metadata' => ['type' => 'subscription', 'business_id' => $business->id,
                'subscription_id' => $subscription->id, 'plan_id' => $requestedPlan->id,
                'billing_interval' => 'yearly', 'currency' => 'NGN',
                'paystack_plan_code' => $requestedPlan->paystack_plan_code],
        ]);
        $this->gateway->shouldReceive('verify')->once()->andReturn($this->successfulVerification($payment));

        app(PaymentConfirmationService::class)->confirm($payment->reference);

        expect($payment->fresh()->status)->toBe('paid')
            ->and($payment->fresh()->metadata['entitlement_review'])->toBeString()
            ->and($subscription->fresh()->status)->toBe('active')
            ->and($subscription->fresh()->plan_id)->toBe($currentPlan->id);
    }

    public function test_payment_plan_terms_mismatch_does_not_change_free_subscription(): void
    {
        $business = $this->createBusiness();
        $free = SubscriptionPlan::factory()->create(['slug' => 'free', 'price' => '0.00', 'paystack_plan_code' => null]);
        $selectedPlan = $this->createPlan();
        $otherPlan = $this->createPlan('yearly');
        $subscription = Subscription::factory()->create([
            'business_id' => $business->id, 'plan_id' => $free->id,
            'status' => 'active', 'provisioning_status' => 'not_required',
        ]);
        $payment = Payment::factory()->create([
            'business_id' => $business->id, 'subscription_id' => $subscription->id,
            'amount' => $selectedPlan->price, 'method' => 'paystack', 'status' => 'pending',
            'reference' => 'MERCHANTOS-PLAN-TERMS-MISMATCH',
            'metadata' => ['type' => 'subscription', 'business_id' => $business->id,
                'subscription_id' => $subscription->id, 'plan_id' => $selectedPlan->id,
                'billing_interval' => 'monthly', 'currency' => 'NGN',
                'paystack_plan_code' => $otherPlan->paystack_plan_code],
        ]);
        $this->gateway->shouldReceive('verify')->once()->andReturn($this->successfulVerification($payment));

        app(PaymentConfirmationService::class)->confirm($payment->reference);

        expect($payment->fresh()->status)->toBe('paid')
            ->and($payment->fresh()->metadata['entitlement_review'])->toBeString()
            ->and($subscription->fresh()->plan_id)->toBe($free->id)
            ->and($subscription->fresh()->status)->toBe('active');
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

        Carbon::setTestNow(Carbon::parse('2025-01-31 10:30:00'));

        $before = now();

        app(PaymentConfirmationService::class)
            ->confirm($payment->reference);

        $after = now();

        $subscription = Subscription::query()
            ->where('business_id', $business->id)
            ->firstOrFail();

        $this->assertSame(
            '2025-01-31 10:30:00',
            $subscription->current_period_start->toDateTimeString(),
        );

        $this->assertSame(
            '2025-02-28 10:30:00',
            $subscription->current_period_end->toDateTimeString(),
        );

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

        Carbon::setTestNow(Carbon::parse('2024-02-29 10:30:00'));

        $before = now();

        app(PaymentConfirmationService::class)
            ->confirm($payment->reference);

        $after = now();

        $subscription = Subscription::query()
            ->where('business_id', $business->id)
            ->firstOrFail();

        $this->assertSame(
            '2024-02-29 10:30:00',
            $subscription->current_period_start->toDateTimeString(),
        );

        $this->assertSame(
            '2025-02-28 10:30:00',
            $subscription->current_period_end->toDateTimeString(),
        );

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
                'subscription_id' => $subscription->id,
                'plan_id' => $newPlan->id,
                'plan_slug' => $newPlan->slug,
                'billing_interval' => 'monthly',
                'currency' => $newPlan->currency,
                'paystack_plan_code' => $newPlan->paystack_plan_code,
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

        expect(
            fn() => app(
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

        expect(
            fn() => app(
                PaymentConfirmationService::class
            )->confirm($payment->reference)
        )->toThrow(
            ValidationException::class
        );

        expect($payment->fresh()->status)
            ->toBe('pending');
    }

    /**
     * Missing provider currency must fail closed for non-subscription payments.
     */
    public function test_missing_currency_is_rejected_for_sale_payment(): void
    {
        $business = $this->createBusiness();

        $payment = Payment::factory()->create([
            'business_id' => $business->id,
            'subscription_id' => null,
            'sale_id' => null,
            'amount' => '10000.00',
            'method' => 'paystack',
            'status' => 'pending',
            'reference' => 'MERCHANTOS-TEST-MISSING-CURRENCY',
            'metadata' => ['type' => 'sale'],
        ]);

        $this->gateway
            ->shouldReceive('verify')
            ->once()
            ->with($payment->reference)
            ->andReturn([
                'success' => true,
                'status' => 'success',
                'reference' => $payment->reference,
                'amount' => 1_000_000,
                'authorization_code' => null,
                'customer_code' => null,
                'raw' => [],
            ]);

        expect(
            fn() => app(PaymentConfirmationService::class)
                ->confirm($payment->reference)
        )->toThrow(ValidationException::class);

        expect($payment->fresh()->status)->toBe('pending');
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
            fn() => app(
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
    public function test_missing_paystack_authorization_information_keeps_payment_paid_and_marks_manual_review(): void
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

        app(PaymentConfirmationService::class)->confirm($payment->reference);

        expect($payment->fresh()->status)->toBe('paid');
        expect(Subscription::query()->where('business_id', $business->id)->first()->provisioning_status)->toBe('manual_review');
    }

    /**
     * A plan without a Paystack plan code cannot be used
     * for recurring billing.
     */
    public function test_plan_without_paystack_plan_code_is_marked_manual_review_after_capture(): void
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

        app(PaymentConfirmationService::class)->confirm($payment->reference);

        expect($payment->fresh()->status)->toBe('paid');
        expect(Subscription::query()->where('business_id', $business->id)->first()->provisioning_status)->toBe('manual_review');
    }

    public function test_provisioning_exception_does_not_roll_back_paid_payment_or_local_subscription(): void
    {
        $business = $this->createBusiness();
        $plan = $this->createPlan();
        $payment = $this->createPayment($business, $plan, 'PROVISION-FAIL');
        $this->gateway->shouldReceive('verify')->once()->andReturn($this->successfulVerification($payment));
        $this->provisioner->shouldReceive('provision')->once()->andThrow(new \RuntimeException('provider unavailable'));
        app(PaymentConfirmationService::class)->confirm($payment->reference);
        expect($payment->fresh()->status)->toBe('paid');
        expect($payment->fresh()->subscription_id)->not->toBeNull();
        expect(Subscription::find($payment->fresh()->subscription_id)->provisioning_status)->toBe('pending');
    }

    public function test_paid_confirmation_does_not_verify_provider_again(): void
    {
        $business = $this->createBusiness();
        $plan = $this->createPlan();
        $payment = $this->createPayment($business, $plan, 'PAID-NO-VERIFY');
        $payment->forceFill(['status' => 'paid', 'paid_at' => now()])->save();
        $this->gateway->shouldNotReceive('verify');
        app(PaymentConfirmationService::class)->confirm($payment->reference);

        expect($payment->fresh()->status)->toBe('paid');
    }

    public function test_paid_confirmation_resumes_pending_provisioning_for_exact_subscription(): void
    {
        $business = $this->createBusiness();
        $plan = $this->createPlan();
        $subscription = Subscription::factory()->create(['business_id' => $business->id, 'plan_id' => $plan->id, 'status' => 'active', 'provisioning_status' => 'pending', 'provider' => 'paystack']);
        $payment = $this->createPayment($business, $plan, 'PAID-RESUME');
        $payment->forceFill(['status' => 'paid', 'paid_at' => now(), 'subscription_id' => $subscription->id])->save();
        $this->gateway->shouldNotReceive('verify');
        app(PaymentConfirmationService::class)->confirm($payment->reference);

        expect($payment->fresh()->subscription_id)->toBe($subscription->id);
        $this->provisioner->shouldHaveReceived('provision')->once()->with(Mockery::on(fn($s) => $s->id === $subscription->id));
    }

    public function test_cancelled_subscription_is_not_reactivated_or_provisioned(): void
    {
        $this->assertTerminalSubscriptionIsUntouched('cancelled');
    }
    public function test_suspended_subscription_is_not_reactivated_or_provisioned(): void
    {
        $this->assertTerminalSubscriptionIsUntouched('suspended');
    }
    public function test_restricted_subscription_is_not_reactivated_or_provisioned(): void
    {
        $this->assertTerminalSubscriptionIsUntouched('restricted');
    }
    public function test_active_subscription_is_not_overwritten_or_provisioned(): void
    {
        $this->assertTerminalSubscriptionIsUntouched('active');
    }

    private function assertTerminalSubscriptionIsUntouched(string $status): void
    {
        $business = $this->createBusiness();
        $plan = $this->createPlan();
        $subscription = Subscription::factory()->create(['business_id' => $business->id, 'plan_id' => $plan->id, 'status' => $status, 'provisioning_status' => 'not_required', 'provider' => 'manual']);
        $payment = $this->createPayment($business, $plan, 'INELIGIBLE-' . $status);
        $payment->forceFill(['status' => 'paid', 'paid_at' => now(), 'subscription_id' => $subscription->id])->save();
        $this->gateway->shouldNotReceive('verify');
        app(PaymentConfirmationService::class)->confirm($payment->reference);
        expect($subscription->fresh()->status)->toBe($status);
        expect($subscription->fresh()->provider)->toBe('manual');
        $this->provisioner->shouldNotHaveReceived('provision');
    }

    public function test_exact_checkout_subscription_is_targeted(): void
    {
        $business = $this->createBusiness();
        $otherBusiness = $this->createBusiness();
        $oldPlan = $this->createPlan();
        $newPlan = $this->createPlan();
        $other = Subscription::factory()->create(['business_id' => $otherBusiness->id, 'plan_id' => $oldPlan->id, 'status' => 'trial', 'provisioning_status' => 'not_required']);
        $target = Subscription::factory()->create(['business_id' => $business->id, 'plan_id' => $oldPlan->id, 'status' => 'trial', 'provisioning_status' => 'not_required']);
        $payment = $this->createPayment($business, $newPlan, 'EXACT-CHECKOUT');
        $payment->forceFill(['subscription_id' => $target->id, 'metadata' => array_merge($payment->metadata, ['subscription_id' => $target->id])])->save();
        $this->gateway->shouldReceive('verify')->once()->andReturn($this->successfulVerification($payment, 'AUTH_EXACT', 'CUS_EXACT'));
        app(PaymentConfirmationService::class)->confirm($payment->reference);
        expect($target->fresh()->plan_id)->toBe($newPlan->id);
        expect($other->fresh()->plan_id)->toBe($oldPlan->id);
        expect($payment->fresh()->subscription_id)->toBe($target->id);
    }

    public function test_provisioner_receives_the_subscription_associated_with_payment(): void
    {
        $business = $this->createBusiness();
        $plan = $this->createPlan();
        $subscription = Subscription::factory()->create(['business_id' => $business->id, 'plan_id' => $plan->id, 'status' => 'trial', 'provisioning_status' => 'not_required']);
        $payment = $this->createPayment($business, $plan, 'RIGHT-SUBSCRIPTION');
        $payment->forceFill(['subscription_id' => $subscription->id])->save();
        $this->gateway->shouldReceive('verify')->once()->andReturn($this->successfulVerification($payment));
        app(PaymentConfirmationService::class)->confirm($payment->reference);

        expect($payment->fresh()->subscription_id)->toBe($subscription->id);
        $this->provisioner->shouldHaveReceived('provision')->once()->with(Mockery::on(fn($s) => $s->id === $subscription->id));
    }

    public function test_legacy_not_required_paid_subscription_is_not_resumed(): void
    {
        $business = $this->createBusiness();
        $plan = $this->createPlan();
        $subscription = Subscription::factory()->create(['business_id' => $business->id, 'plan_id' => $plan->id, 'status' => 'active', 'provisioning_status' => 'not_required']);
        $payment = $this->createPayment($business, $plan, 'LEGACY-NOT-REQUIRED');
        $payment->forceFill(['status' => 'paid', 'paid_at' => now(), 'subscription_id' => $subscription->id])->save();
        $this->gateway->shouldNotReceive('verify');
        app(PaymentConfirmationService::class)->confirm($payment->reference);

        expect($subscription->fresh()->status)->toBe('active');
        expect($subscription->fresh()->provisioning_status)->toBe('not_required');
        $this->provisioner->shouldNotHaveReceived('provision');
    }
}
