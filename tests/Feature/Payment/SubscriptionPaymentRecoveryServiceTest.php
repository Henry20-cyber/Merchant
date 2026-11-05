<?php

namespace Tests\Feature\Payment;

use App\Domains\Organization\Models\Business;
use App\Domains\Payment\Contracts\PaymentGateway;
use App\Domains\Payment\Models\Payment;
use App\Domains\Payment\Models\SubscriptionCheckoutRecovery;
use App\Domains\Payment\Models\SubscriptionPaymentRecoveryAudit;
use App\Domains\Payment\Services\SubscriptionPaymentRecoveryService;
use App\Domains\Subscription\Models\Subscription;
use App\Domains\Subscription\Models\SubscriptionPlan;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class SubscriptionPaymentRecoveryServiceTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        $business = Business::factory()->create();
        $free = SubscriptionPlan::factory()->create(['name' => 'Free', 'slug' => 'free', 'price' => '0.00', 'paystack_plan_code' => null]);
        $plan = SubscriptionPlan::factory()->create(['name' => 'Starter', 'slug' => 'low-monthly', 'price' => '3000.00', 'currency' => 'NGN', 'billing_interval' => 'monthly', 'paystack_plan_code' => 'PLN_wy7zx4oss01vw2x']);
        $subscription = Subscription::factory()->create([
            'business_id' => $business->id,
            'plan_id' => $free->id,
            'status' => 'active',
            'provider' => null,
            'provider_customer_code' => null,
            'provider_customer_id' => null,
            'provider_authorization_code' => null,
            'provider_subscription_code' => null,
            'provisioning_status' => 'not_required',
            'auto_renew' => true,
        ]);
        $paidAt = Carbon::parse('2026-11-05 17:49:10');
        $payment = Payment::factory()->create([
            'business_id' => $business->id,
            'subscription_id' => $subscription->id,
            'sale_id' => null,
            'amount' => '3000.00',
            'method' => 'paystack',
            'status' => 'paid',
            'reference' => 'MERCHANTOS-9JEZCQRR8QYDUYFJ76G7',
            'paid_at' => $paidAt,
            'metadata' => [
                'type' => 'subscription',
                'plan_id' => $plan->id,
                'plan_slug' => $plan->slug,
                'business_id' => $business->id,
                'subscription_id' => $subscription->id,
                'billing_interval' => 'monthly',
                'entitlement_review' => 'Captured subscription terms are incomplete or differ from the current plan.',
            ],
        ]);
        $recovery = SubscriptionCheckoutRecovery::query()->create([
            'business_id' => $business->id,
            'subscription_id' => $subscription->id,
            'plan_id' => $plan->id,
            'purchase_terms' => ['plan_id' => $plan->id, 'plan_slug' => $plan->slug, 'amount' => '3000.00', 'subscription_id' => $subscription->id],
            'status' => 'manual_review',
            'winning_payment_id' => $payment->id,
            'manual_review_reason' => 'Historical paid payment has incomplete captured subscription terms.',
        ]);
        $payment->forceFill(['checkout_recovery_id' => $recovery->id, 'attempt_number' => null])->save();

        return compact('business', 'free', 'plan', 'subscription', 'payment', 'recovery', 'paidAt');
    }

    private function terms(array $fixture): array
    {
        return [
            'payment_id' => $fixture['payment']->id,
            'business_id' => $fixture['business']->id,
            'subscription_id' => $fixture['subscription']->id,
            'plan_id' => $fixture['plan']->id,
            'plan_slug' => 'low-monthly',
            'paystack_plan_code' => 'PLN_wy7zx4oss01vw2x',
            'amount_kobo' => 300000,
            'currency' => 'NGN',
            'billing_interval' => 'monthly',
        ];
    }

    private function mockVerifiedGateway(array $overrides = [], array $planOverrides = [], array $lookup = [], bool $expectPlanLookup = true, bool $expectSubscriptionLookup = true): PaymentGateway
    {
        $gateway = Mockery::mock(PaymentGateway::class);
        $gateway->shouldReceive('verify')->once()->andReturn(array_merge([
            'success' => true,
            'status' => 'success',
            'reference' => 'MERCHANTOS-9JEZCQRR8QYDUYFJ76G7',
            'amount' => 300000,
            'currency' => 'NGN',
            'customer_code' => 'CUS_verified_customer_123',
            'customer_id' => 9001,
            'authorization_code' => 'AUTH_reusable_but_no_consent',
        ], $overrides));
        if ($expectPlanLookup) {
            $gateway->shouldReceive('fetchPlan')->once()->with('PLN_wy7zx4oss01vw2x')->andReturn([
                'success' => true,
                'data' => array_merge(['id' => 501, 'plan_code' => 'PLN_wy7zx4oss01vw2x', 'amount' => 300000, 'currency' => 'NGN', 'interval' => 'monthly'], $planOverrides),
            ]);
        } else {
            $gateway->shouldNotReceive('fetchPlan');
        }
        if ($expectSubscriptionLookup) {
            $gateway->shouldReceive('listSubscriptions')->once()->andReturn($lookup ?: ['success' => true, 'data' => [], 'meta' => ['pageCount' => 0]]);
        } else {
            $gateway->shouldNotReceive('listSubscriptions');
        }
        $gateway->shouldNotReceive('createSubscription');
        $this->app->instance(PaymentGateway::class, $gateway);

        return $gateway;
    }

    public function test_dry_run_checks_provider_evidence_without_mutating_entitlement_or_payment(): void
    {
        $fixture = $this->fixture();
        $this->mockVerifiedGateway();
        $result = app(SubscriptionPaymentRecoveryService::class)->recover(
            $fixture['payment']->reference,
            $this->terms($fixture),
            User::factory()->create(),
        );
        expect($result['eligible'])->toBeTrue()
            ->and($result['executed'])->toBeFalse()
            ->and($fixture['payment']->fresh()->status)->toBe('paid')
            ->and($fixture['payment']->fresh()->paid_at->equalTo($fixture['paidAt']))
            ->and($fixture['subscription']->fresh()->plan_id)->toBe($fixture['free']->id)
            ->and(SubscriptionPaymentRecoveryAudit::query()->count())->toBe(0);
    }

    public function test_execution_recovers_original_payment_idempotently_without_recurring_provisioning(): void
    {
        $fixture = $this->fixture();
        $this->mockVerifiedGateway();
        $actor = User::factory()->create();
        $service = app(SubscriptionPaymentRecoveryService::class);
        $result = $service->recover($fixture['payment']->reference, $this->terms($fixture), $actor, true);

        $payment = $fixture['payment']->fresh();
        $subscription = $fixture['subscription']->fresh();
        expect($result['executed'])->toBeTrue()
            ->and($payment->status)->toBe('paid')
            ->and($payment->reference)->toBe('MERCHANTOS-9JEZCQRR8QYDUYFJ76G7')
            ->and($payment->amount)->toBe('3000.00')
            ->and($payment->paid_at->equalTo($fixture['paidAt']))
            ->and($subscription->plan_id)->toBe($fixture['plan']->id)
            ->and($subscription->current_period_start->equalTo($fixture['paidAt']))
            ->and($subscription->current_period_end->equalTo($fixture['paidAt']->copy()->addMonthNoOverflow()))
            ->and($subscription->provider_customer_code)->toBe('CUS_verified_customer_123')
            ->and($subscription->provider_authorization_code)->toBeNull()
            ->and($subscription->provider_subscription_code)->toBeNull()
            ->and($subscription->auto_renew)->toBeFalse()
            ->and($subscription->provisioning_status)->toBe('not_required')
            ->and($fixture['recovery']->fresh()->status)->toBe('succeeded')
            ->and($fixture['recovery']->fresh()->manual_review_resolved_by)->toBe($actor->id)
            ->and(data_get($payment->metadata, 'entitlement_review'))->toBeNull()
            ->and(SubscriptionPaymentRecoveryAudit::query()->where('payment_id', $payment->id)->count())->toBe(1);

        $repeat = $service->recover($fixture['payment']->reference, $this->terms($fixture), $actor, true);
        expect($repeat['already_recovered'])->toBeTrue()
            ->and($subscription->fresh()->current_period_end->equalTo($fixture['paidAt']->copy()->addMonthNoOverflow()))
            ->and(SubscriptionPaymentRecoveryAudit::query()->where('payment_id', $payment->id)->count())->toBe(1);
    }

    public function test_mismatched_provider_reference_is_rejected_without_state_change(): void
    {
        $fixture = $this->fixture();
        $this->mockVerifiedGateway(['reference' => 'SOME-OTHER-REFERENCE'], [], [], false, false);
        expect(fn () => app(SubscriptionPaymentRecoveryService::class)->recover(
            $fixture['payment']->reference,
            $this->terms($fixture),
            User::factory()->create(),
            true,
        ))->toThrow(RuntimeException::class, 'Paystack transaction verification');
        expect($fixture['subscription']->fresh()->plan_id)->toBe($fixture['free']->id)
            ->and(SubscriptionPaymentRecoveryAudit::query()->where('payment_id', $fixture['payment']->id)->count())->toBe(1)
            ->and(SubscriptionPaymentRecoveryAudit::query()->where('payment_id', $fixture['payment']->id)->first()->event_type)->toBe('subscription_entitlement_recovery_blocked');
    }

    public function test_conflicting_local_paid_payment_blocks_recovery(): void
    {
        $fixture = $this->fixture();
        $conflictingPayment = Payment::factory()->create([
            'business_id' => $fixture['business']->id,
            'subscription_id' => $fixture['subscription']->id,
            'status' => 'paid', 'method' => 'paystack', 'amount' => '3000.00', 'reference' => 'OTHER-PAID-REF',
            'metadata' => ['type' => 'subscription'],
        ]);
        expect(Payment::query()->where('reference', 'OTHER-PAID-REF')->value('subscription_id'))
            ->toBe($fixture['subscription']->id)
            ->and(Payment::query()->where('status', 'paid')->where('subscription_id', $fixture['subscription']->id)->count())
            ->toBe(2)
            ->and((string) $conflictingPayment->id)->not->toBe((string) $fixture['payment']->id);

        $gateway = Mockery::mock(PaymentGateway::class);
        $gateway->shouldNotReceive('verify');
        $gateway->shouldNotReceive('fetchPlan');
        $gateway->shouldNotReceive('listSubscriptions');
        $gateway->shouldNotReceive('createSubscription');
        $this->app->instance(PaymentGateway::class, $gateway);

        $originalPaymentState = $fixture['payment']->fresh()->only([
            'status', 'reference', 'amount', 'paid_at', 'metadata', 'subscription_id', 'checkout_recovery_id', 'attempt_number', 'updated_at',
        ]);
        $conflictingPaymentState = $conflictingPayment->fresh()->only([
            'status', 'reference', 'amount', 'paid_at', 'metadata', 'subscription_id', 'checkout_recovery_id', 'attempt_number', 'updated_at',
        ]);
        $originalSubscriptionState = $fixture['subscription']->fresh()->only([
            'plan_id', 'status', 'current_period_start', 'current_period_end', 'auto_renew', 'updated_at',
        ]);
        $originalWinnerId = $fixture['recovery']->fresh()->winning_payment_id;

        expect(fn () => app(SubscriptionPaymentRecoveryService::class)->recover(
            $fixture['payment']->reference,
            $this->terms($fixture),
            User::factory()->create(),
        ))->toThrow(RuntimeException::class, 'Another successful payment');

        expect($fixture['payment']->fresh()->only(array_keys($originalPaymentState)))
            ->toEqual($originalPaymentState)
            ->and($conflictingPayment->fresh()->only(array_keys($conflictingPaymentState)))
            ->toEqual($conflictingPaymentState)
            ->and($fixture['subscription']->fresh()->only(array_keys($originalSubscriptionState)))
            ->toEqual($originalSubscriptionState)
            ->and($fixture['recovery']->fresh()->winning_payment_id)
            ->toBe($originalWinnerId)
            ->and(SubscriptionPaymentRecoveryAudit::query()->where('payment_id', $fixture['payment']->id)->where('event_type', 'subscription_entitlement_recovered')->exists())
            ->toBeFalse();
    }

    public function test_mismatched_provider_plan_terms_are_rejected(): void
    {
        $fixture = $this->fixture();
        $this->mockVerifiedGateway([], ['amount' => 300100], [], true, false);
        expect(fn () => app(SubscriptionPaymentRecoveryService::class)->recover(
            $fixture['payment']->reference,
            $this->terms($fixture),
            User::factory()->create(),
            true,
        ))->toThrow(RuntimeException::class, 'Paystack plan identity');
        expect($fixture['subscription']->fresh()->plan_id)->toBe($fixture['free']->id);
    }

    public function test_existing_matching_provider_subscription_blocks_recovery(): void
    {
        $fixture = $this->fixture();
        $this->mockVerifiedGateway([], [], [
            'success' => true,
            'data' => [[
                'subscription_code' => 'SUB_EXISTING',
                'customer' => ['customer_code' => 'CUS_verified_customer_123'],
                'plan' => ['plan_code' => 'PLN_wy7zx4oss01vw2x'],
            ]],
            'meta' => ['pageCount' => 1],
        ]);
        expect(fn () => app(SubscriptionPaymentRecoveryService::class)->recover(
            $fixture['payment']->reference,
            $this->terms($fixture),
            User::factory()->create(),
            true,
        ))->toThrow(RuntimeException::class, 'matching Paystack subscription already exists');
    }

    public function test_duplicate_recovery_winner_claim_blocks_provider_calls(): void
    {
        $fixture = $this->fixture();
        SubscriptionCheckoutRecovery::query()->create([
            'business_id' => $fixture['business']->id,
            'subscription_id' => $fixture['subscription']->id,
            'plan_id' => $fixture['plan']->id,
            'purchase_terms' => [],
            'status' => 'manual_review',
            'winning_payment_id' => $fixture['payment']->id,
        ]);
        $gateway = Mockery::mock(PaymentGateway::class);
        $gateway->shouldNotReceive('verify');
        $this->app->instance(PaymentGateway::class, $gateway);
        expect(fn () => app(SubscriptionPaymentRecoveryService::class)->recover(
            $fixture['payment']->reference,
            $this->terms($fixture),
            User::factory()->create(),
        ))->toThrow(RuntimeException::class, 'Another checkout recovery already claims');
    }
}
