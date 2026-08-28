<?php

namespace Database\Factories\Domains\Payment\Models;

use App\Domains\Organization\Models\Business;
use App\Domains\Payment\Models\Payment;
use App\Domains\Sales\Models\Sale;
use App\Domains\Subscription\Models\Subscription;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Payment>
 */
class PaymentFactory extends Factory
{
    protected $model = Payment::class;

    public function definition(): array
    {
        return [
            'business_id' => Business::factory(),
            'sale_id' => null,
            'subscription_id' => null,
            'amount' => '10000.00',
            'method' => 'cash',
            'status' => 'paid',
            'reference' => null,
            'metadata' => null,
            'paid_at' => now(),
        ];
    }

    /**
     * Create a payment belonging to a sale.
     */
    public function forSale(?Sale $sale = null): static
    {
        return $this->state(function () use ($sale) {
            $sale ??= Sale::factory()->create();

            return [
                'business_id' => $sale->business_id,
                'sale_id' => $sale->id,
                'subscription_id' => null,
            ];
        });
    }

    /**
     * Create a payment belonging to a subscription.
     */
    public function forSubscription(
        ?Subscription $subscription = null
    ): static {
        return $this->state(function () use ($subscription) {
            $subscription ??= Subscription::factory()->create();

            return [
                'business_id' => $subscription->business_id,
                'sale_id' => null,
                'subscription_id' => $subscription->id,
            ];
        });
    }

    /**
     * Create a pending payment.
     */
    public function pending(): static
    {
        return $this->state([
            'status' => 'pending',
            'paid_at' => null,
        ]);
    }

    /**
     * Create a paid payment.
     */
    public function paid(): static
    {
        return $this->state([
            'status' => 'paid',
            'paid_at' => now(),
        ]);
    }
}
