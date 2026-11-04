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

        $this->actingAs($founder)
            ->postJson('/api/founder/payment-recoveries/' . $recovery->id . '/resolve', [
                'decision' => 'allow_new_attempt',
                'resolution' => 'This repeated resolution must not pass.',
            ])
            ->assertStatus(409);
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

    public function test_provider_verification_exception_leaves_recovery_in_manual_review(): void
    {
        [$business, $plan, $recovery, $payment, $founder] = $this->makeReviewFixture();
        $gateway = Mockery::mock(PaymentGateway::class);
        $gateway->shouldReceive('verify')->once()->with($payment->reference)
            ->andThrow(new \RuntimeException('Provider verification unavailable.'));
        $this->app->instance(PaymentGateway::class, $gateway);

        $this->actingAs($founder)
            ->postJson('/api/founder/payment-recoveries/' . $recovery->id . '/resolve', [
                'decision' => 'allow_new_attempt',
                'resolution' => 'Verification was unavailable; leave this in review.',
            ])
            ->assertStatus(409);

        expect($payment->fresh()->status)->toBe('failed')
            ->and($recovery->fresh()->status)->toBe('manual_review')
            ->and($recovery->fresh()->manual_review_resolved_at)->toBeNull();
    }

    public function test_attempt_added_during_verification_is_detected_before_retry_approval(): void
    {
        [$business, $plan, $recovery, $payment, $founder] = $this->makeReviewFixture();
        $gateway = Mockery::mock(PaymentGateway::class);
        $gateway->shouldReceive('verify')->once()->with($payment->reference)->andReturnUsing(
            function () use ($business, $plan, $recovery, $payment): array {
                Payment::factory()->create([
                    'business_id' => $business->id, 'sale_id' => null, 'amount' => $plan->price,
                    'method' => 'paystack', 'status' => 'pending',
                    'reference' => 'MERCHANTOS-REVIEW-ADDED-DURING-VERIFY',
                    'checkout_recovery_id' => $recovery->id, 'attempt_number' => 2,
                    'metadata' => ['type' => 'subscription', 'plan_id' => $plan->id],
                ]);

                return [
                    'success' => true, 'status' => 'failed', 'reference' => $payment->reference,
                    'amount' => 300000, 'currency' => 'NGN',
                ];
            },
        );
        $this->app->instance(PaymentGateway::class, $gateway);

        $this->actingAs($founder)
            ->postJson('/api/founder/payment-recoveries/' . $recovery->id . '/resolve', [
                'decision' => 'allow_new_attempt',
                'resolution' => 'A concurrent attempt should prevent approval.',
            ])
            ->assertStatus(409);

        expect($recovery->fresh()->status)->toBe('manual_review')
            ->and($recovery->fresh()->manual_review_resolved_at)->toBeNull()
            ->and($recovery->payments()->count())->toBe(2);
    }

    public function test_attempt_mutated_during_verification_is_detected_before_retry_approval(): void
    {
        [$business, $plan, $recovery, $payment, $founder] = $this->makeReviewFixture();
        $gateway = Mockery::mock(PaymentGateway::class);
        $gateway->shouldReceive('verify')->once()->with($payment->reference)->andReturnUsing(
            function () use ($payment): array {
                $payment->forceFill(['amount' => '3100.00'])->save();

                return [
                    'success' => true, 'status' => 'failed', 'reference' => $payment->reference,
                    'amount' => 300000, 'currency' => 'NGN',
                ];
            },
        );
        $this->app->instance(PaymentGateway::class, $gateway);

        $this->actingAs($founder)
            ->postJson('/api/founder/payment-recoveries/' . $recovery->id . '/resolve', [
                'decision' => 'allow_new_attempt',
                'resolution' => 'A changed payment amount must prevent approval.',
            ])
            ->assertStatus(409);

        expect($recovery->fresh()->status)->toBe('manual_review')
            ->and($recovery->fresh()->manual_review_resolved_at)->toBeNull();
    }

    public function test_recovery_with_winning_payment_cannot_be_approved_for_retry(): void
    {
        [$business, $plan, $recovery, $payment, $founder] = $this->makeReviewFixture();
        $winner = Payment::factory()->create([
            'business_id' => $business->id, 'sale_id' => null, 'amount' => $plan->price,
            'method' => 'paystack', 'status' => 'paid', 'reference' => 'MERCHANTOS-REVIEW-WINNER',
        ]);
        $recovery->forceFill(['winning_payment_id' => $winner->id])->save();
        $gateway = Mockery::mock(PaymentGateway::class);
        $gateway->shouldNotReceive('verify');
        $this->app->instance(PaymentGateway::class, $gateway);

        $this->actingAs($founder)
            ->postJson('/api/founder/payment-recoveries/' . $recovery->id . '/resolve', [
                'decision' => 'allow_new_attempt', 'resolution' => 'This group already has a winner.',
            ])
            ->assertStatus(409);
        expect($recovery->fresh()->status)->toBe('manual_review');
    }

    public function test_incomplete_recovery_terms_cannot_be_approved_for_retry(): void
    {
        [$business, $plan, $recovery, $payment, $founder] = $this->makeReviewFixture();
        $recovery->forceFill(['purchase_terms' => [
            'plan_id' => $plan->id,
            'amount' => $plan->price,
            'currency' => 'NGN',
            'billing_interval' => $plan->billing_interval,
            'subscription_id' => null,
        ]])->save();
        $gateway = Mockery::mock(PaymentGateway::class);
        $gateway->shouldReceive('verify')->once()->with($payment->reference)->andReturn([
            'success' => true, 'status' => 'failed', 'reference' => $payment->reference,
            'amount' => 300000, 'currency' => 'NGN',
        ]);
        $this->app->instance(PaymentGateway::class, $gateway);

        $this->actingAs($founder)
            ->postJson('/api/founder/payment-recoveries/' . $recovery->id . '/resolve', [
                'decision' => 'allow_new_attempt', 'resolution' => 'Captured terms are incomplete.',
            ])
            ->assertStatus(409);

        expect($recovery->fresh()->status)->toBe('manual_review')
            ->and($recovery->fresh()->manual_review_resolved_at)->toBeNull();
    }

    public function test_record_only_keeps_recovery_in_manual_review_and_does_not_verify_attempts(): void
    {
        [, , $recovery, , $founder] = $this->makeReviewFixture();
        $gateway = Mockery::mock(PaymentGateway::class);
        $gateway->shouldNotReceive('verify');
        $this->app->instance(PaymentGateway::class, $gateway);

        $this->actingAs($founder)
            ->postJson('/api/founder/payment-recoveries/' . $recovery->id . '/resolve', [
                'decision' => 'record_only',
                'resolution' => 'Recorded support review without permitting a retry.',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'manual_review');

        expect($recovery->fresh()->status)->toBe('manual_review')
            ->and($recovery->fresh()->manual_review_resolved_at)->toBeNull()
            ->and($recovery->fresh()->manual_review_resolution)->toBe('Recorded support review without permitting a retry.');
    }

    private function makeReviewFixture(): array
    {
        $business = Business::factory()->create();
        $plan = SubscriptionPlan::factory()->create([
            'price' => '3000.00', 'currency' => 'NGN', 'billing_interval' => 'monthly',
            'paystack_plan_code' => 'PLN_founder_review_' . bin2hex(random_bytes(4)), 'is_active' => true,
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
            'manual_review_reason' => 'Needs provider confirmation.',
        ]);
        $payment = Payment::factory()->create([
            'business_id' => $business->id, 'sale_id' => null, 'amount' => $plan->price,
            'method' => 'paystack', 'status' => 'failed', 'reference' => 'MERCHANTOS-REVIEW-' . strtoupper(bin2hex(random_bytes(4))),
            'checkout_recovery_id' => $recovery->id, 'attempt_number' => 1,
            'metadata' => ['type' => 'subscription', 'plan_id' => $plan->id],
        ]);
        $founder = User::factory()->create(['email' => 'founder@example.test']);
        config()->set('app.founder_email', 'founder@example.test');

        return [$business, $plan, $recovery, $payment, $founder];
    }
}
