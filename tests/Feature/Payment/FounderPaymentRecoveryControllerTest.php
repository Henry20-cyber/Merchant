<?php

namespace Tests\Feature\Payment;

use App\Domains\Organization\Models\Business;
use App\Domains\Payment\Contracts\PaymentGateway;
use App\Domains\Payment\Models\Payment;
use App\Domains\Payment\Models\SubscriptionCheckoutRecovery;
use App\Domains\Subscription\Models\SubscriptionPlan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Mockery;

class FounderPaymentRecoveryControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_founder_can_allow_new_attempt_only_after_all_attempts_failed_and_terms_match(): void
    {
        $business = Business::factory()->create();
        $plan = SubscriptionPlan::factory()->create([
            'price' => '3000.00',
            'currency' => 'NGN',
            'billing_interval' => 'monthly',
            'paystack_plan_code' => 'PLN_manual_review_test',
            'is_active' => true,
        ]);
        $recovery = SubscriptionCheckoutRecovery::query()->create([
            'business_id' => $business->id,
            'plan_id' => $plan->id,
            'purchase_terms' => [
                'plan_id' => $plan->id,
                'amount' => $plan->price,
                'currency' => $plan->currency,
                'billing_interval' => $plan->billing_interval,
                'paystack_plan_code' => $plan->paystack_plan_code,
                'subscription_id' => null,
            ],
            'status' => 'manual_review',
            'manual_review_at' => now(),
            'manual_review_reason' => 'Provider delivery was delayed.',
        ]);
        $payment = Payment::factory()->create([
            'business_id' => $business->id,
            'sale_id' => null,
            'amount' => $plan->price,
            'method' => 'paystack',
            'status' => 'failed',
            'reference' => 'MERCHANTOS-REVIEW-FAILED',
            'checkout_recovery_id' => $recovery->id,
            'attempt_number' => 1,
            'metadata' => ['type' => 'subscription', 'plan_id' => $plan->id],
        ]);
        $founder = User::factory()->create(['email' => 'founder@example.test']);
        config()->set('app.founder_email', 'founder@example.test');
        $gateway = Mockery::mock(PaymentGateway::class);
        $gateway->shouldReceive('verify')->once()->with($payment->reference)->andReturn([
            'success' => true,
            'status' => 'failed',
            'reference' => $payment->reference,
            'amount' => 300000,
            'currency' => 'NGN',
        ]);
        $this->app->instance(PaymentGateway::class, $gateway);

        $this->actingAs($founder)
            ->postJson('/api/founder/payment-recoveries/' . $recovery->id . '/resolve', [
                'decision' => 'allow_new_attempt',
                'resolution' => 'Provider confirmed the first attempt failed.',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'retryable');

        expect($payment->fresh()->status)->toBe('failed')
            ->and($recovery->fresh()->status)->toBe('retryable')
            ->and($recovery->fresh()->manual_review_resolved_by)->toBe($founder->id);
    }

    public function test_manual_review_cannot_allow_retry_while_an_attempt_is_pending(): void
    {
        $business = Business::factory()->create();
        $plan = SubscriptionPlan::factory()->create([
            'price' => '3000.00', 'currency' => 'NGN', 'billing_interval' => 'monthly',
            'paystack_plan_code' => 'PLN_pending_review_test', 'is_active' => true,
        ]);
        $recovery = SubscriptionCheckoutRecovery::query()->create([
            'business_id' => $business->id,
            'plan_id' => $plan->id,
            'purchase_terms' => [
                'plan_id' => $plan->id, 'amount' => $plan->price, 'currency' => $plan->currency,
                'billing_interval' => $plan->billing_interval, 'paystack_plan_code' => $plan->paystack_plan_code,
                'subscription_id' => null,
            ],
            'status' => 'manual_review',
            'manual_review_at' => now(),
        ]);
        Payment::factory()->create([
            'business_id' => $business->id, 'sale_id' => null, 'amount' => $plan->price,
            'method' => 'paystack', 'status' => 'pending', 'reference' => 'MERCHANTOS-REVIEW-PENDING',
            'checkout_recovery_id' => $recovery->id, 'attempt_number' => 1,
            'metadata' => ['type' => 'subscription', 'plan_id' => $plan->id],
        ]);
        $founder = User::factory()->create(['email' => 'founder@example.test']);
        config()->set('app.founder_email', 'founder@example.test');
        $gateway = Mockery::mock(PaymentGateway::class);
        $gateway->shouldNotReceive('verify');
        $this->app->instance(PaymentGateway::class, $gateway);

        $this->actingAs($founder)
            ->postJson('/api/founder/payment-recoveries/' . $recovery->id . '/resolve', [
                'decision' => 'allow_new_attempt',
                'resolution' => 'Please allow another attempt.',
            ])
            ->assertStatus(409);

        expect($recovery->fresh()->status)->toBe('manual_review')
            ->and($recovery->fresh()->manual_review_resolved_at)->toBeNull();
    }
}
