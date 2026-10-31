<?php

namespace Tests\Feature\Payment;

use App\Domains\Organization\Models\Business;
use App\Domains\Payment\Models\Payment;
use App\Domains\Payment\Services\SubscriptionRenewalService;
use App\Domains\Subscription\Models\Subscription;
use App\Domains\Subscription\Models\SubscriptionPlan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
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
}
