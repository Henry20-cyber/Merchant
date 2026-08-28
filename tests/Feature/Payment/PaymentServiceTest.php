<?php

namespace Tests\Feature\Payment;

use App\Domains\Organization\Models\Business;
use App\Domains\Organization\Models\BusinessUser;
use App\Domains\Payment\Models\Payment;
use App\Domains\Sales\Models\Sale;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class PaymentServiceTest extends TestCase
{
    use RefreshDatabase;

    private function createBusiness(): array
    {
        $business = Business::factory()->create();

        $user = User::factory()->create();

        BusinessUser::create([
            'business_id' => $business->id,
            'user_id' => $user->id,
            'status' => 'active',
            'joined_at' => now(),
        ]);

        return [$business, $user];
    }

    private function createSale(
        Business $business,
        User $cashier,
        float $total = 10000
    ): Sale {
        return Sale::create([
            'business_id' => $business->id,
            'cashier_id' => $cashier->id,
            'customer_id' => null,
            'subtotal' => $total,
            'discount' => 0,
            'tax' => 0,
            'total' => $total,
            'payment_method' => 'cash',
            'payment_status' => 'paid',
            'status' => 'completed',
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Creation
    |--------------------------------------------------------------------------
    */

    public function test_payment_can_be_created_for_sale(): void
    {
        [$business, $user] = $this->createBusiness();

        $sale = $this->createSale(
            $business,
            $user,
            10000
        );

        $service = app(
            \App\Domains\Payment\Services\PaymentService::class
        );

        $payment = $service->create(
            $business,
            $sale,
            [
                'amount' => 10000,
                'method' => 'cash',
            ]
        );

        expect($payment)
            ->toBeInstanceOf(Payment::class);

        expect($payment->business_id)
            ->toBe($business->id);

        expect($payment->sale_id)
            ->toBe($sale->id);

        expect($payment->status)
            ->toBe('paid');
    }

    public function test_payment_can_be_created_as_pending(): void
    {
        [$business, $user] = $this->createBusiness();

        $sale = $this->createSale(
            $business,
            $user,
            10000
        );

        $service = app(
            \App\Domains\Payment\Services\PaymentService::class
        );

        $payment = $service->create(
            $business,
            $sale,
            [
                'amount' => 10000,
                'method' => 'bank_transfer',
                'status' => 'pending',
                'reference' => 'TRX-001',
            ]
        );

        expect($payment->status)
            ->toBe('pending');

        expect($payment->paid_at)
            ->toBeNull();
    }

    /*
    |--------------------------------------------------------------------------
    | Amount validation
    |--------------------------------------------------------------------------
    */

    public function test_zero_payment_is_rejected(): void
    {
        [$business, $user] = $this->createBusiness();

        $sale = $this->createSale(
            $business,
            $user
        );

        $service = app(
            \App\Domains\Payment\Services\PaymentService::class
        );

        expect(fn () => $service->create(
            $business,
            $sale,
            [
                'amount' => 0,
                'method' => 'cash',
            ]
        ))->toThrow(ValidationException::class);
    }

    public function test_negative_payment_is_rejected(): void
    {
        [$business, $user] = $this->createBusiness();

        $sale = $this->createSale(
            $business,
            $user
        );

        $service = app(
            \App\Domains\Payment\Services\PaymentService::class
        );

        expect(fn () => $service->create(
            $business,
            $sale,
            [
                'amount' => -100,
                'method' => 'cash',
            ]
        ))->toThrow(ValidationException::class);
    }

    /*
    |--------------------------------------------------------------------------
    | Business isolation
    |--------------------------------------------------------------------------
    */

    public function test_payment_cannot_be_created_for_sale_from_another_business(): void
    {
        [$businessA, $userA] = $this->createBusiness();

        [$businessB, $userB] = $this->createBusiness();

        $saleB = $this->createSale(
            $businessB,
            $userB
        );

        $service = app(
            \App\Domains\Payment\Services\PaymentService::class
        );

        expect(fn () => $service->create(
            $businessA,
            $saleB,
            [
                'amount' => 10000,
                'method' => 'cash',
            ]
        ))->toThrow(ValidationException::class);

        expect(Payment::count())
            ->toBe(0);
    }

    /*
    |--------------------------------------------------------------------------
    | Payment methods
    |--------------------------------------------------------------------------
    */

    public function test_supported_payment_methods_are_accepted(): void
    {
        [$business, $user] = $this->createBusiness();

        $methods = [
            'cash',
            'bank_transfer',
            'card',
            'mobile_money',
            'other',
        ];

        foreach ($methods as $method) {
            $sale = $this->createSale(
                $business,
                $user,
                10000
            );

            $service = app(
                \App\Domains\Payment\Services\PaymentService::class
            );

            $payment = $service->create(
                $business,
                $sale,
                [
                    'amount' => 100,
                    'method' => $method,
                ]
            );

            expect($payment->method)
                ->toBe($method);
        }
    }

    public function test_unsupported_payment_method_is_rejected(): void
    {
        [$business, $user] = $this->createBusiness();

        $sale = $this->createSale(
            $business,
            $user
        );

        $service = app(
            \App\Domains\Payment\Services\PaymentService::class
        );

        expect(fn () => $service->create(
            $business,
            $sale,
            [
                'amount' => 1000,
                'method' => 'bitcoin',
            ]
        ))->toThrow(ValidationException::class);
    }

    /*
    |--------------------------------------------------------------------------
    | Paid amount / balance
    |--------------------------------------------------------------------------
    */

    public function test_paid_amount_counts_only_paid_payments(): void
    {
        [$business, $user] = $this->createBusiness();

        $sale = $this->createSale(
            $business,
            $user,
            10000
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
            'amount' => 3000,
            'method' => 'bank_transfer',
            'status' => 'pending',
            'reference' => 'TRX-PENDING',
        ]);

        $service = app(
            \App\Domains\Payment\Services\PaymentService::class
        );

        expect($service->paidAmount($sale))
            ->toBe('4000.00');
    }

    public function test_remaining_balance_is_calculated_correctly(): void
    {
        [$business, $user] = $this->createBusiness();

        $sale = $this->createSale(
            $business,
            $user,
            10000
        );

        Payment::create([
            'business_id' => $business->id,
            'sale_id' => $sale->id,
            'amount' => 4000,
            'method' => 'cash',
            'status' => 'paid',
            'paid_at' => now(),
        ]);

        $service = app(
            \App\Domains\Payment\Services\PaymentService::class
        );

        expect($service->remainingBalance($sale))
            ->toBe('6000.00');
    }

    /*
    |--------------------------------------------------------------------------
    | Overpayment
    |--------------------------------------------------------------------------
    */

    public function test_payment_cannot_exceed_remaining_balance(): void
    {
        [$business, $user] = $this->createBusiness();

        $sale = $this->createSale(
            $business,
            $user,
            10000
        );

        Payment::create([
            'business_id' => $business->id,
            'sale_id' => $sale->id,
            'amount' => 7000,
            'method' => 'cash',
            'status' => 'paid',
            'paid_at' => now(),
        ]);

        $service = app(
            \App\Domains\Payment\Services\PaymentService::class
        );

        expect(fn () => $service->create(
            $business,
            $sale,
            [
                'amount' => 4000,
                'method' => 'cash',
            ]
        ))->toThrow(ValidationException::class);

        expect(
            Payment::where('sale_id', $sale->id)->count()
        )->toBe(1);
    }

    /*
    |--------------------------------------------------------------------------
    | Metadata / references
    |--------------------------------------------------------------------------
    */

    public function test_reference_and_metadata_are_preserved(): void
    {
        [$business, $user] = $this->createBusiness();

        $sale = $this->createSale(
            $business,
            $user
        );

        $service = app(
            \App\Domains\Payment\Services\PaymentService::class
        );

        $payment = $service->create(
            $business,
            $sale,
            [
                'amount' => 5000,
                'method' => 'bank_transfer',
                'reference' => 'BANK-12345',
                'metadata' => [
                    'provider' => 'test-provider',
                    'channel' => 'bank_transfer',
                ],
            ]
        );

        expect($payment->reference)
            ->toBe('BANK-12345');

        expect($payment->metadata)
            ->toBe([
                'provider' => 'test-provider',
                'channel' => 'bank_transfer',
            ]);
    }
}
