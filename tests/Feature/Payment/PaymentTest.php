<?php

namespace Tests\Feature\Payment;

use App\Domains\Customer\Models\Customer;
use App\Domains\Organization\Models\Business;
use App\Domains\Payment\Models\Payment;
use App\Domains\Sales\Models\Sale;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Domains\Subscription\Models\Subscription;
use Tests\TestCase;

class PaymentTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Create a business with an owner.
     */
    private function createBusiness(): array
    {
        $business = Business::factory()->create();

        $user = User::factory()->create();

        return [$business, $user];
    }

    /**
     * Create a sale belonging to a business.
     */
    private function createSale(
        Business $business,
        User $cashier
    ): Sale {
        return Sale::create([
            'business_id' => $business->id,
            'cashier_id' => $cashier->id,
            'customer_id' => null,
            'subtotal' => 10000,
            'discount' => 0,
            'tax' => 0,
            'total' => 10000,
            'payment_method' => 'cash',
            'payment_status' => 'paid',
            'status' => 'completed',
        ]);
    }

    public function test_payment_belongs_to_business(): void
    {
        [$business, $user] = $this->createBusiness();

        $sale = $this->createSale(
            $business,
            $user
        );

        $payment = Payment::create([
            'business_id' => $business->id,
            'sale_id' => $sale->id,
            'amount' => 10000,
            'method' => 'cash',
            'status' => 'paid',
            'reference' => null,
            'metadata' => null,
            'paid_at' => now(),
        ]);

        expect($payment->business)
            ->toBeInstanceOf(Business::class)
            ->id->toBe($business->id);
    }

    public function test_payment_belongs_to_sale(): void
    {
        [$business, $user] = $this->createBusiness();

        $sale = $this->createSale(
            $business,
            $user
        );

        $payment = Payment::create([
            'business_id' => $business->id,
            'sale_id' => $sale->id,
            'amount' => 10000,
            'method' => 'cash',
            'status' => 'paid',
            'reference' => null,
            'metadata' => null,
            'paid_at' => now(),
        ]);

        expect($payment->sale)
            ->toBeInstanceOf(Sale::class)
            ->id->toBe($sale->id);
    }

    public function test_sale_has_many_payments(): void
    {
        [$business, $user] = $this->createBusiness();

        $sale = $this->createSale(
            $business,
            $user
        );

        Payment::create([
            'business_id' => $business->id,
            'sale_id' => $sale->id,
            'amount' => 4000,
            'method' => 'cash',
            'status' => 'paid',
            'paid_at' => now(),
        ]);

        Payment::create([
            'business_id' => $business->id,
            'sale_id' => $sale->id,
            'amount' => 6000,
            'method' => 'bank_transfer',
            'status' => 'paid',
            'reference' => 'TRX-001',
            'paid_at' => now(),
        ]);

        expect($sale->payments)
            ->toHaveCount(2);
    }

    public function test_business_has_many_payments(): void
    {
        [$business, $user] = $this->createBusiness();

        $sale = $this->createSale(
            $business,
            $user
        );

        Payment::create([
            'business_id' => $business->id,
            'sale_id' => $sale->id,
            'amount' => 10000,
            'method' => 'cash',
            'status' => 'paid',
            'paid_at' => now(),
        ]);

        expect($business->payments)
            ->toHaveCount(1);
    }

    public function test_payment_amount_supports_decimal_values(): void
    {
        [$business, $user] = $this->createBusiness();

        $sale = $this->createSale(
            $business,
            $user
        );

        $payment = Payment::create([
            'business_id' => $business->id,
            'sale_id' => $sale->id,
            'amount' => 1250.50,
            'method' => 'cash',
            'status' => 'paid',
            'paid_at' => now(),
        ]);

        expect($payment->amount)
            ->toBe('1250.50');
    }

    public function test_payment_metadata_is_cast_to_array(): void
    {
        [$business, $user] = $this->createBusiness();

        $sale = $this->createSale(
            $business,
            $user
        );

        $metadata = [
            'provider' => 'example',
            'channel' => 'bank_transfer',
            'provider_reference' => 'ABC123',
        ];

        $payment = Payment::create([
            'business_id' => $business->id,
            'sale_id' => $sale->id,
            'amount' => 5000,
            'method' => 'bank_transfer',
            'status' => 'paid',
            'reference' => 'ABC123',
            'metadata' => $metadata,
            'paid_at' => now(),
        ]);

        expect($payment->metadata)
            ->toBe($metadata);
    }

    public function test_payment_reference_can_be_null(): void
    {
        [$business, $user] = $this->createBusiness();

        $sale = $this->createSale(
            $business,
            $user
        );

        $payment = Payment::create([
            'business_id' => $business->id,
            'sale_id' => $sale->id,
            'amount' => 5000,
            'method' => 'cash',
            'status' => 'paid',
            'reference' => null,
            'paid_at' => now(),
        ]);

        expect($payment->reference)
            ->toBeNull();
    }

    public function test_payment_paid_at_can_be_null_for_pending_payment(): void
    {
        [$business, $user] = $this->createBusiness();

        $sale = $this->createSale(
            $business,
            $user
        );

        $payment = Payment::create([
            'business_id' => $business->id,
            'sale_id' => $sale->id,
            'amount' => 5000,
            'method' => 'bank_transfer',
            'status' => 'pending',
            'reference' => 'TRX-002',
            'paid_at' => null,
        ]);

        expect($payment->paid_at)
            ->toBeNull();
    }

    public function test_multiple_payments_can_be_attached_to_one_sale(): void
    {
        [$business, $user] = $this->createBusiness();

        $sale = $this->createSale(
            $business,
            $user
        );

        Payment::create([
            'business_id' => $business->id,
            'sale_id' => $sale->id,
            'amount' => 3000,
            'method' => 'cash',
            'status' => 'paid',
            'paid_at' => now(),
        ]);

        Payment::create([
            'business_id' => $business->id,
            'sale_id' => $sale->id,
            'amount' => 7000,
            'method' => 'bank_transfer',
            'reference' => 'TRX-003',
            'status' => 'paid',
            'paid_at' => now(),
        ]);

        $totalPaid = $sale->payments()
            ->where('status', 'paid')
            ->sum('amount');

        expect((float) $totalPaid)
            ->toBe(10000.0);
    }

    public function test_payment_is_scoped_to_its_business(): void
    {
        [$businessA, $userA] = $this->createBusiness();
        [$businessB, $userB] = $this->createBusiness();

        $saleA = $this->createSale(
            $businessA,
            $userA
        );

        $payment = Payment::create([
            'business_id' => $businessA->id,
            'sale_id' => $saleA->id,
            'amount' => 10000,
            'method' => 'cash',
            'status' => 'paid',
            'paid_at' => now(),
        ]);

        expect($payment->business_id)
            ->toBe($businessA->id)
            ->not->toBe($businessB->id);
    }

    public function test_payment_belongs_to_subscription(): void
{
    $subscription = Subscription::factory()->create();

    $payment = Payment::factory()->create([
        'business_id' => $subscription->business_id,
        'subscription_id' => $subscription->id,
    ]);

    expect($payment->subscription->is($subscription))
        ->toBeTrue();
}
}
