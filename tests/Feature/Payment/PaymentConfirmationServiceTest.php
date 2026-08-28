<?php

namespace Tests\Feature\Payment;

use App\Domains\Organization\Models\Business;
use App\Domains\Payment\Contracts\PaymentGateway;
use App\Domains\Payment\Models\Payment;
use App\Domains\Payment\Services\PaymentConfirmationService;
use App\Domains\Subscription\Models\Subscription;
use App\Domains\Subscription\Models\SubscriptionPlan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class PaymentConfirmationServiceTest extends TestCase
{
    use RefreshDatabase;

    private PaymentGateway $gateway;

    protected function setUp(): void
    {
        parent::setUp();

        $this->gateway = $this->mock(PaymentGateway::class);

        $this->app->instance(
            PaymentGateway::class,
            $this->gateway
        );
    }

    public function test_successful_subscription_payment_is_marked_paid(): void
    {
        $business = Business::factory()->create();

        $plan = SubscriptionPlan::factory()->create([
            'slug' => 'medium-monthly',
            'price' => '10000.00',
            'currency' => 'NGN',
            'billing_interval' => 'monthly',
            'is_active' => true,
        ]);

        $payment = Payment::factory()->create([
            'business_id' => $business->id,
            'subscription_id' => null,
            'sale_id' => null,
            'amount' => '10000.00',
            'method' => 'paystack',
            'status' => 'pending',
            'reference' => 'MERCHANTOS-TEST-001',
            'metadata' => [
                'type' => 'subscription',
                'business_id' => $business->id,
                'subscription_id' => null,
                'plan_id' => $plan->id,
                'plan_slug' => $plan->slug,
                'billing_interval' => 'monthly',
            ],
            'paid_at' => null,
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
                'raw' => [],
            ]);

        $confirmed = app(PaymentConfirmationService::class)
            ->confirm($payment->reference);

        expect($confirmed->status)
            ->toBe('paid');

        expect($confirmed->paid_at)
            ->not->toBeNull();
    }

    public function test_successful_payment_creates_active_subscription(): void
    {
        $business = Business::factory()->create();

        $plan = SubscriptionPlan::factory()->create([
            'slug' => 'medium-monthly',
            'price' => '10000.00',
            'currency' => 'NGN',
            'billing_interval' => 'monthly',
            'is_active' => true,
        ]);

        $payment = Payment::factory()->create([
            'business_id' => $business->id,
            'subscription_id' => null,
            'sale_id' => null,
            'amount' => '10000.00',
            'method' => 'paystack',
            'status' => 'pending',
            'reference' => 'MERCHANTOS-TEST-002',
            'metadata' => [
                'type' => 'subscription',
                'business_id' => $business->id,
                'subscription_id' => null,
                'plan_id' => $plan->id,
                'plan_slug' => $plan->slug,
                'billing_interval' => 'monthly',
            ],
            'paid_at' => null,
        ]);

        $this->gateway
            ->shouldReceive('verify')
            ->once()
            ->andReturn([
                'success' => true,
                'status' => 'success',
                'reference' => $payment->reference,
                'amount' => 1_000_000,
                'currency' => 'NGN',
                'raw' => [],
            ]);

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
    }

    public function test_monthly_subscription_gets_one_month_period(): void
    {
        Carbon::setTestNow(
            Carbon::create(2026, 8, 27, 12, 0, 0)
        );

        $business = Business::factory()->create();

        $plan = SubscriptionPlan::factory()->create([
            'price' => '10000.00',
            'currency' => 'NGN',
            'billing_interval' => 'monthly',
            'is_active' => true,
        ]);

        $payment = Payment::factory()->create([
            'business_id' => $business->id,
            'amount' => '10000.00',
            'method' => 'paystack',
            'status' => 'pending',
            'reference' => 'MERCHANTOS-TEST-003',
            'metadata' => [
                'type' => 'subscription',
                'business_id' => $business->id,
                'plan_id' => $plan->id,
                'plan_slug' => $plan->slug,
                'billing_interval' => 'monthly',
            ],
        ]);

        $this->gateway
            ->shouldReceive('verify')
            ->once()
            ->andReturn([
                'success' => true,
                'status' => 'success',
                'reference' => $payment->reference,
                'amount' => 1_000_000,
                'currency' => 'NGN',
                'raw' => [],
            ]);

        app(PaymentConfirmationService::class)
            ->confirm($payment->reference);

        $subscription = Subscription::query()
            ->where('business_id', $business->id)
            ->firstOrFail();

        expect($subscription->current_period_start->toDateTimeString())
            ->toBe('2026-08-27 12:00:00');

        expect($subscription->current_period_end->toDateTimeString())
            ->toBe('2026-09-27 12:00:00');

        Carbon::setTestNow();
    }

    public function test_yearly_subscription_gets_one_year_period(): void
    {
        Carbon::setTestNow(
            Carbon::create(2026, 8, 27, 12, 0, 0)
        );

        $business = Business::factory()->create();

        $plan = SubscriptionPlan::factory()->create([
            'price' => '120000.00',
            'currency' => 'NGN',
            'billing_interval' => 'yearly',
            'is_active' => true,
        ]);

        $payment = Payment::factory()->create([
            'business_id' => $business->id,
            'amount' => '120000.00',
            'method' => 'paystack',
            'status' => 'pending',
            'reference' => 'MERCHANTOS-TEST-004',
            'metadata' => [
                'type' => 'subscription',
                'business_id' => $business->id,
                'plan_id' => $plan->id,
                'plan_slug' => $plan->slug,
                'billing_interval' => 'yearly',
            ],
        ]);

        $this->gateway
            ->shouldReceive('verify')
            ->once()
            ->andReturn([
                'success' => true,
                'status' => 'success',
                'reference' => $payment->reference,
                'amount' => 12_000_000,
                'currency' => 'NGN',
                'raw' => [],
            ]);

        app(PaymentConfirmationService::class)
            ->confirm($payment->reference);

        $subscription = Subscription::query()
            ->where('business_id', $business->id)
            ->firstOrFail();

        expect($subscription->current_period_end->toDateTimeString())
            ->toBe('2027-08-27 12:00:00');

        Carbon::setTestNow();
    }

    public function test_existing_trial_subscription_becomes_active(): void
    {
        $business = Business::factory()->create();

        $oldPlan = SubscriptionPlan::factory()->create([
            'price' => '5000.00',
            'billing_interval' => 'monthly',
            'currency' => 'NGN',
            'is_active' => true,
        ]);

        $newPlan = SubscriptionPlan::factory()->create([
            'price' => '10000.00',
            'billing_interval' => 'monthly',
            'currency' => 'NGN',
            'is_active' => true,
        ]);

        $subscription = Subscription::factory()->create([
            'business_id' => $business->id,
            'plan_id' => $oldPlan->id,
            'status' => 'trial',
        ]);

        $payment = Payment::factory()->create([
            'business_id' => $business->id,
            'subscription_id' => $subscription->id,
            'amount' => '10000.00',
            'method' => 'paystack',
            'status' => 'pending',
            'reference' => 'MERCHANTOS-TEST-005',
            'metadata' => [
                'type' => 'subscription',
                'business_id' => $business->id,
                'subscription_id' => $subscription->id,
                'plan_id' => $newPlan->id,
                'plan_slug' => $newPlan->slug,
                'billing_interval' => 'monthly',
            ],
        ]);

        $this->gateway
            ->shouldReceive('verify')
            ->once()
            ->andReturn([
                'success' => true,
                'status' => 'success',
                'reference' => $payment->reference,
                'amount' => 1_000_000,
                'currency' => 'NGN',
                'raw' => [],
            ]);

        app(PaymentConfirmationService::class)
            ->confirm($payment->reference);

        $subscription->refresh();

        expect($subscription->status)
            ->toBe('active');

        expect($subscription->plan_id)
            ->toBe($newPlan->id);

        expect($subscription->provider)
            ->toBe('paystack');
    }

    public function test_wrong_amount_is_rejected(): void
    {
        $business = Business::factory()->create();

        $plan = SubscriptionPlan::factory()->create([
            'price' => '10000.00',
            'currency' => 'NGN',
            'billing_interval' => 'monthly',
            'is_active' => true,
        ]);

        $payment = Payment::factory()->create([
            'business_id' => $business->id,
            'amount' => '10000.00',
            'method' => 'paystack',
            'status' => 'pending',
            'reference' => 'MERCHANTOS-TEST-006',
            'metadata' => [
                'type' => 'subscription',
                'business_id' => $business->id,
                'plan_id' => $plan->id,
            ],
        ]);

        $this->gateway
            ->shouldReceive('verify')
            ->once()
            ->andReturn([
                'success' => true,
                'status' => 'success',
                'reference' => $payment->reference,
                'amount' => 500_000,
                'currency' => 'NGN',
                'raw' => [],
            ]);

        expect(fn () =>
            app(PaymentConfirmationService::class)
                ->confirm($payment->reference)
        )->toThrow(\Illuminate\Validation\ValidationException::class);

        $payment->refresh();

        expect($payment->status)
            ->toBe('pending');
    }

    public function test_wrong_currency_is_rejected(): void
    {
        $business = Business::factory()->create();

        $plan = SubscriptionPlan::factory()->create([
            'price' => '10000.00',
            'currency' => 'NGN',
            'billing_interval' => 'monthly',
            'is_active' => true,
        ]);

        $payment = Payment::factory()->create([
            'business_id' => $business->id,
            'amount' => '10000.00',
            'method' => 'paystack',
            'status' => 'pending',
            'reference' => 'MERCHANTOS-TEST-007',
            'metadata' => [
                'type' => 'subscription',
                'business_id' => $business->id,
                'plan_id' => $plan->id,
            ],
        ]);

        $this->gateway
            ->shouldReceive('verify')
            ->once()
            ->andReturn([
                'success' => true,
                'status' => 'success',
                'reference' => $payment->reference,
                'amount' => 1_000_000,
                'currency' => 'USD',
                'raw' => [],
            ]);

        expect(fn () =>
            app(PaymentConfirmationService::class)
                ->confirm($payment->reference)
        )->toThrow(\Illuminate\Validation\ValidationException::class);

        $payment->refresh();

        expect($payment->status)
            ->toBe('pending');
    }

    public function test_failed_transaction_marks_pending_payment_failed(): void
    {
        $payment = Payment::factory()->pending()->create([
            'method' => 'paystack',
            'reference' => 'MERCHANTOS-TEST-008',
        ]);

        $this->gateway
            ->shouldReceive('verify')
            ->once()
            ->andReturn([
                'success' => false,
                'status' => 'failed',
                'reference' => $payment->reference,
                'amount' => null,
                'currency' => null,
                'raw' => [],
            ]);

        expect(fn () =>
            app(PaymentConfirmationService::class)
                ->confirm($payment->reference)
        )->toThrow(\Illuminate\Validation\ValidationException::class);

        $payment->refresh();

        expect($payment->status)
            ->toBe('failed');
    }

    public function test_unknown_reference_is_rejected(): void
    {
        $this->gateway
            ->shouldReceive('verify')
            ->never();

        expect(fn () =>
            app(PaymentConfirmationService::class)
                ->confirm('MERCHANTOS-DOES-NOT-EXIST')
        )->toThrow(\Illuminate\Validation\ValidationException::class);
    }

    public function test_duplicate_confirmation_does_not_fulfil_twice(): void
    {
        $business = Business::factory()->create();

        $plan = SubscriptionPlan::factory()->create([
            'price' => '10000.00',
            'currency' => 'NGN',
            'billing_interval' => 'monthly',
            'is_active' => true,
        ]);

        $payment = Payment::factory()->create([
            'business_id' => $business->id,
            'amount' => '10000.00',
            'method' => 'paystack',
            'status' => 'pending',
            'reference' => 'MERCHANTOS-TEST-009',
            'metadata' => [
                'type' => 'subscription',
                'business_id' => $business->id,
                'plan_id' => $plan->id,
                'plan_slug' => $plan->slug,
                'billing_interval' => 'monthly',
            ],
        ]);

        $this->gateway
            ->shouldReceive('verify')
            ->once()
            ->andReturn([
                'success' => true,
                'status' => 'success',
                'reference' => $payment->reference,
                'amount' => 1_000_000,
                'currency' => 'NGN',
                'raw' => [],
            ]);

        $service = app(PaymentConfirmationService::class);

        $service->confirm($payment->reference);

        $subscription = Subscription::query()
            ->where('business_id', $business->id)
            ->firstOrFail();

        $periodEnd = $subscription->current_period_end;

        /*
         * Second confirmation should return immediately.
         */
        $service->confirm($payment->reference);

        expect(
            Subscription::query()
                ->where('business_id', $business->id)
                ->count()
        )->toBe(1);

        $subscription->refresh();

        expect($subscription->current_period_end->equalTo($periodEnd))
            ->toBeTrue();

        expect(
            Payment::query()
                ->where('reference', $payment->reference)
                ->where('status', 'paid')
                ->count()
        )->toBe(1);
    }
}