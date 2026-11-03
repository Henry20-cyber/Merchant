<?php

namespace Tests\Feature\Payment;

use App\Domains\Organization\Models\Business;
use App\Domains\Payment\Models\Payment;
use App\Domains\Payment\Services\SubscriptionRenewalService;
use App\Domains\Subscription\Models\Subscription;
use App\Domains\Subscription\Models\SubscriptionPlan;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Carbon\Carbon;
use Tests\TestCase;

class SubscriptionRenewalServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_successful_charge_preserves_disabled_auto_renewal(): void
    {
        $business = Business::factory()->create();
        $plan = $this->createPlan();
        $cancelledAt = now()->subHour();
        $periodEnd = now()->addDays(10);

        $subscription = $this->createSubscription(
            $business,
            $plan,
            [
                'status' => 'active',
                'auto_renew' => false,
                'cancelled_at' => $cancelledAt,
                'current_period_end' => $periodEnd,
            ],
        );

        $payment = app(SubscriptionRenewalService::class)->renew(
            $this->renewalPayload('PAYSTACK-RENEWAL-001')
        );

        $subscription->refresh();

        $this->assertSame('paid', $payment->status);
        $this->assertSame($subscription->id, $payment->subscription_id);
        $this->assertFalse($subscription->auto_renew);
        $this->assertEquals($cancelledAt->toDateTimeString(), $subscription->cancelled_at->toDateTimeString());
        $this->assertEquals($periodEnd->addMonth()->toDateTimeString(), $subscription->current_period_end->toDateTimeString());
    }

    public function test_malformed_provider_amount_is_rejected_without_creating_payment(): void
    {
        $business = Business::factory()->create();
        $plan = $this->createPlan();
        $subscription = $this->createSubscription($business, $plan);
        $periodEnd = $subscription->current_period_end;

        $payload = $this->renewalPayload('PAYSTACK-RENEWAL-BAD-AMOUNT');
        $payload['amount'] = '1000000x';

        try {
            app(SubscriptionRenewalService::class)->renew($payload);
            $this->fail('Malformed provider amount should be rejected.');
        } catch (ValidationException) {
            $this->assertDatabaseMissing('payments', [
                'reference' => 'PAYSTACK-RENEWAL-BAD-AMOUNT',
            ]);

            $this->assertEquals(
                $periodEnd->toDateTimeString(),
                $subscription->fresh()->current_period_end->toDateTimeString(),
            );
        }
    }

    public function test_replayed_recurring_reference_does_not_extend_period_twice(): void
    {
        $business = Business::factory()->create();
        $plan = $this->createPlan();
        $subscription = $this->createSubscription($business, $plan);

        $service = app(SubscriptionRenewalService::class);
        $payload = $this->renewalPayload('PAYSTACK-RENEWAL-IDEMPOTENT');

        $firstPayment = $service->renew($payload);
        $periodEndAfterFirst = $subscription->fresh()->current_period_end;
        $secondPayment = $service->renew($payload);

        $this->assertSame($firstPayment->id, $secondPayment->id);
        $this->assertEquals(
            $periodEndAfterFirst->toDateTimeString(),
            $subscription->fresh()->current_period_end->toDateTimeString(),
        );
        $this->assertSame(
            1,
            Payment::query()->where('reference', 'PAYSTACK-RENEWAL-IDEMPOTENT')->count(),
        );
    }

    public function test_delayed_charge_for_terminal_subscription_is_recorded_without_resurrection(): void
    {
        $business = Business::factory()->create();
        $plan = $this->createPlan();
        $subscription = $this->createSubscription(
            $business,
            $plan,
            [
                'status' => 'cancelled',
                'auto_renew' => false,
                'cancelled_at' => now()->subDay(),
                'ended_at' => now()->subHour(),
            ],
        );

        $payment = app(SubscriptionRenewalService::class)->renew(
            $this->renewalPayload('PAYSTACK-RENEWAL-TERMINAL')
        );

        $subscription->refresh();

        $this->assertSame('paid', $payment->status);
        $this->assertSame('cancelled', $subscription->status);
        $this->assertFalse($subscription->auto_renew);
        $this->assertNotNull($subscription->ended_at);
    }

    private function createPlan(): SubscriptionPlan
    {
        return SubscriptionPlan::factory()->create([
            'price' => '10000.00',
            'currency' => 'NGN',
            'billing_interval' => 'monthly',
            'is_active' => true,
        ]);
    }

    private function createSubscription(
        Business $business,
        SubscriptionPlan $plan,
        array $overrides = [],
    ): Subscription {
        return Subscription::factory()->create(array_merge([
            'business_id' => $business->id,
            'plan_id' => $plan->id,
            'status' => 'active',
            'provider' => 'paystack',
            'provider_customer_code' => 'CUS_RENEWAL_TEST',
            'provider_subscription_code' => 'SUB_RENEWAL_TEST',
            'auto_renew' => true,
            'starts_at' => now()->subDay(),
            'current_period_start' => now()->subDay(),
            'current_period_end' => now()->addMonth(),
        ], $overrides));
    }

    /** @return array<string, mixed> */
    private function renewalPayload(string $reference): array
    {
        return [
            'reference' => $reference,
            'customer_code' => 'CUS_RENEWAL_TEST',
            'subscription_code' => 'SUB_RENEWAL_TEST',
            'amount' => 1000000,
            'currency' => 'NGN',
            'paid_at' => now()->toISOString(),
        ];
    }

    public function test_delayed_renewal_preserves_original_billing_anniversary(): void
{
    Carbon::setTestNow(Carbon::parse('2026-04-05 12:00:00'));

    try {
        $business = Business::factory()->create();
        $plan = $this->createPlan();

        $subscription = $this->createSubscription(
            $business,
            $plan,
            [
                'current_period_start' => Carbon::parse('2026-02-28 12:00:00'),
                'current_period_end' => Carbon::parse('2026-03-28 12:00:00'),
            ],
        );

        app(SubscriptionRenewalService::class)->renew(
            $this->renewalPayload('PAYSTACK-RENEWAL-DELAYED')
        );

        $subscription->refresh();

        $this->assertEquals(
            '2026-03-28 12:00:00',
            $subscription->current_period_start->toDateTimeString(),
        );

        $this->assertEquals(
            '2026-04-28 12:00:00',
            $subscription->current_period_end->toDateTimeString(),
        );
    } finally {
        Carbon::setTestNow();
    }
}

    public function test_existing_reference_for_another_subscription_is_rejected_without_mutation(): void
    {
        $business = Business::factory()->create();
        $plan = $this->createPlan();
        $target = $this->createSubscription($business, $plan, ['provider_customer_code' => 'CUS_TARGET', 'provider_subscription_code' => 'SUB_TARGET']);
        $other = $this->createSubscription(Business::factory()->create(), $plan, ['provider_customer_code' => 'CUS_OTHER', 'provider_subscription_code' => 'SUB_OTHER']);
        $this->createRenewalPayment($other, $plan, 'REF-OTHER-SUBSCRIPTION');
        $periodEnd = $target->current_period_end->toDateTimeString();

        try {
            app(SubscriptionRenewalService::class)->renew($this->payloadFor('REF-OTHER-SUBSCRIPTION', 'CUS_TARGET', 'SUB_TARGET'));
            $this->fail('A payment for another subscription must not be accepted.');
        } catch (ValidationException) {
            $this->assertSame($periodEnd, $target->fresh()->current_period_end->toDateTimeString());
            $this->assertSame(1, Payment::where('reference', 'REF-OTHER-SUBSCRIPTION')->count());
        }
    }

    public function test_existing_reference_for_another_business_is_rejected(): void
    {
        $plan = $this->createPlan();
        $target = $this->createSubscription(Business::factory()->create(), $plan, ['provider_customer_code' => 'CUS_TARGET', 'provider_subscription_code' => 'SUB_TARGET']);
        $other = $this->createSubscription(Business::factory()->create(), $plan, ['provider_customer_code' => 'CUS_OTHER', 'provider_subscription_code' => 'SUB_OTHER']);
        $this->createRenewalPayment($other, $plan, 'REF-OTHER-BUSINESS');

        $this->expectException(ValidationException::class);
        app(SubscriptionRenewalService::class)->renew($this->payloadFor('REF-OTHER-BUSINESS', 'CUS_TARGET', 'SUB_TARGET'));
    }

    public function test_existing_pos_payment_reference_is_rejected(): void
    {
        $plan = $this->createPlan();
        $subscription = $this->createSubscription(Business::factory()->create(), $plan);
        Payment::factory()->create([
            'business_id' => $subscription->business_id,
            'subscription_id' => null,
            'method' => 'cash',
            'status' => 'paid',
            'reference' => 'REF-POS',
            'metadata' => ['type' => 'sale'],
        ]);

        $this->expectException(ValidationException::class);
        app(SubscriptionRenewalService::class)->renew($this->payloadFor('REF-POS', $subscription->provider_customer_code, $subscription->provider_subscription_code));
    }

    public function test_existing_non_paid_reference_is_rejected(): void
    {
        $plan = $this->createPlan();
        $subscription = $this->createSubscription(Business::factory()->create(), $plan);
        $this->createRenewalPayment($subscription, $plan, 'REF-NON-PAID', ['status' => 'pending', 'paid_at' => null]);

        $this->expectException(ValidationException::class);
        app(SubscriptionRenewalService::class)->renew($this->renewalPayload('REF-NON-PAID'));
    }

    public function test_existing_reference_with_mismatched_amount_is_rejected(): void
    {
        $plan = $this->createPlan();
        $subscription = $this->createSubscription(Business::factory()->create(), $plan);
        $this->createRenewalPayment($subscription, $plan, 'REF-WRONG-AMOUNT', ['amount' => '9000.00']);

        $this->expectException(ValidationException::class);
        app(SubscriptionRenewalService::class)->renew($this->renewalPayload('REF-WRONG-AMOUNT'));
    }

    public function test_existing_reference_with_mismatched_renewal_metadata_is_rejected(): void
    {
        $plan = $this->createPlan();
        $subscription = $this->createSubscription(Business::factory()->create(), $plan);
        $this->createRenewalPayment($subscription, $plan, 'REF-WRONG-METADATA', [
            'metadata' => ['type' => 'subscription_renewal', 'provider' => 'paystack', 'customer_code' => 'CUS_WRONG', 'subscription_code' => $subscription->provider_subscription_code, 'plan_id' => $plan->id, 'currency' => 'NGN'],
        ]);

        $this->expectException(ValidationException::class);
        app(SubscriptionRenewalService::class)->renew($this->renewalPayload('REF-WRONG-METADATA'));
    }

    public function test_reference_conflict_recovery_returns_a_matching_winner(): void
    {
        $plan = $this->createPlan();
        $subscription = $this->createSubscription(Business::factory()->create(), $plan);
        $winner = $this->createRenewalPayment($subscription, $plan, 'REF-RACE-SAME');
        $service = app(SubscriptionRenewalService::class);
        $method = new \ReflectionMethod($service, 'recoverPaymentAfterReferenceConflict');
        $recovered = $method->invoke($service, $this->renewalPayload('REF-RACE-SAME'), $this->referenceUniqueViolation());
        $this->assertSame($winner->id, $recovered->id);
    }

    public function test_reference_conflict_recovery_rejects_a_winner_for_another_transaction(): void
    {
        $plan = $this->createPlan();
        $target = $this->createSubscription(Business::factory()->create(), $plan, ['provider_customer_code' => 'CUS_TARGET', 'provider_subscription_code' => 'SUB_TARGET']);
        $other = $this->createSubscription(Business::factory()->create(), $plan, ['provider_customer_code' => 'CUS_OTHER', 'provider_subscription_code' => 'SUB_OTHER']);
        $this->createRenewalPayment($other, $plan, 'REF-RACE-CONFLICT');
        $service = app(SubscriptionRenewalService::class);
        $method = new \ReflectionMethod($service, 'recoverPaymentAfterReferenceConflict');

        $this->expectException(ValidationException::class);
        $method->invoke($service, $this->payloadFor('REF-RACE-CONFLICT', 'CUS_TARGET', 'SUB_TARGET'), $this->referenceUniqueViolation());
    }

    public function test_paid_at_provenance_distinguishes_provider_time_from_local_fallback(): void
    {
        $business = Business::factory()->create();
        $plan = $this->createPlan();
        $subscription = $this->createSubscription($business, $plan);
        $service = app(SubscriptionRenewalService::class);
        $providerTime = '2026-04-05T12:00:00.000Z';

        $providerPayment = $service->renew(array_merge(
            $this->renewalPayload('REF-PROVIDER-TIME'),
            ['paid_at' => $providerTime],
        ));
        $fallbackPayment = $service->renew(array_merge(
            $this->renewalPayload('REF-LOCAL-FALLBACK'),
            ['paid_at' => 'not-a-timestamp'],
        ));

        expect($providerPayment->metadata['paid_at_source'])->toBe('provider');
        expect($providerPayment->paid_at->toISOString())->toBe('2026-04-05T12:00:00.000000Z');
        expect($fallbackPayment->metadata['paid_at_source'])->toBe('local_fallback');
    }

    public function test_unrelated_postgres_unique_constraint_is_not_classified_as_reference_race(): void
    {
        $service = app(SubscriptionRenewalService::class);
        $method = new \ReflectionMethod($service, 'isPaymentReferenceConflict');
        $this->assertTrue($method->invoke($service, $this->queryExceptionFor('payments_reference_unique')));
        $this->assertFalse($method->invoke($service, $this->queryExceptionFor('subscriptions_provider_subscription_unique')));
        $this->assertFalse($method->invoke($service, $this->queryExceptionFor('payments_reference_unique', '23503')));
    }

    private function createRenewalPayment(Subscription $subscription, SubscriptionPlan $plan, string $reference, array $overrides = []): Payment
    {
        return Payment::factory()->create(array_merge([
            'business_id' => $subscription->business_id,
            'subscription_id' => $subscription->id,
            'sale_id' => null,
            'amount' => $plan->price,
            'method' => 'paystack',
            'status' => 'paid',
            'reference' => $reference,
            'metadata' => [
                'type' => 'subscription_renewal',
                'provider' => 'paystack',
                'customer_code' => $subscription->provider_customer_code,
                'subscription_code' => $subscription->provider_subscription_code,
                'plan_id' => $plan->id,
                'currency' => $plan->currency,
            ],
            'paid_at' => now(),
        ], $overrides));
    }

    private function payloadFor(string $reference, string $customerCode, string $subscriptionCode): array
    {
        return array_merge($this->renewalPayload($reference), [
            'customer_code' => $customerCode,
            'subscription_code' => $subscriptionCode,
        ]);
    }

    private function referenceUniqueViolation(): QueryException
    {
        return $this->queryExceptionFor('payments_reference_unique');
    }

    private function queryExceptionFor(string $constraint, string $sqlState = '23505'): QueryException
    {
        $previous = new \PDOException('SQLSTATE['.$sqlState.']: PostgreSQL constraint violation "'.$constraint.'"');
        $previous->errorInfo = [$sqlState, 7, 'duplicate key value violates unique constraint "'.$constraint.'"'];
        return new QueryException('pgsql', 'insert into payments ...', [], $previous);
    }
}
