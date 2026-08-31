<?php

namespace Tests\Feature\Receipt;

use App\Domains\Customer\Models\Customer;
use App\Domains\Organization\Models\Business;
use App\Domains\Organization\Models\BusinessUser;
use App\Domains\Product\Models\Product;
use App\Domains\Product\Models\ProductUnit;
use App\Domains\Receipt\Models\Receipt;
use App\Domains\Receipt\Services\ReceiptService;
use App\Domains\Sales\Models\Sale;
use App\Domains\Sales\Models\SaleItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ReceiptServiceTest extends TestCase
{
    use RefreshDatabase;

    /*
    |--------------------------------------------------------------------------
    | Test Helpers
    |--------------------------------------------------------------------------
    */

    /**
     * Create a business with an active cashier.
     *
     * This follows the same BusinessUser convention used
     * throughout the existing MerchantOS tests.
     */
    private function createBusinessWithCashier(): array
    {
        $business = Business::factory()->create();

        $cashier = User::factory()->create();

        BusinessUser::create([
            'business_id' => $business->id,
            'user_id' => $cashier->id,
            'status' => 'active',
            'joined_at' => now(),
        ]);

        return [
            $business,
            $cashier,
        ];
    }

    /**
     * Create a completed and paid sale.
     */
    private function createSale(
        Business $business,
        User $cashier,
        ?Customer $customer = null
    ): Sale {
        return Sale::factory()->create([
            'business_id' => $business->id,
            'cashier_id' => $cashier->id,
            'customer_id' => $customer?->id,

            'subtotal' => 10000,
            'discount' => 500,
            'tax' => 0,
            'total' => 9500,

            'payment_method' => 'cash',
            'payment_status' => 'paid',
            'status' => 'completed',
        ]);
    }

    /**
     * Create a product and attach a sale item to the sale.
     *
     * The product/unit are created explicitly so the test
     * controls the exact historical values stored on SaleItem.
     */
    private function createProductItem(
        Sale $sale,
        Business $business
    ): SaleItem {
        $product = Product::factory()->create([
            'business_id' => $business->id,
        ]);

        $unit = ProductUnit::factory()->create([
            'business_id' => $business->id,
            'product_id' => $product->id,
            'quantity' => 1,
            'cost_price' => 3000,
            'selling_price' => 5000,
            'is_base_unit' => true,
            'is_sellable' => true,
            'is_purchasable' => true,
        ]);

        return SaleItem::create([
            'sale_id' => $sale->id,

            'product_id' => $product->id,
            'product_unit_id' => $unit->id,

            /*
             * This is a product line, not a service line.
             */
            'service_id' => null,

            'quantity' => 2,
            'unit_price' => 5000,
            'unit_cost' => 3000,
            'discount' => 0,
            'total' => 10000,
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Receipt Issuance
    |--------------------------------------------------------------------------
    */

    public function test_receipt_can_be_issued_for_completed_paid_sale(): void
    {
        $business = Business::factory()->create([
            'name' => 'Test Business',
            'email' => 'test@example.com',
            'phone' => '08000000000',
            'currency' => 'NGN',
            'timezone' => 'Africa/Lagos',
            'default_country' => 'Nigeria',
        ]);

        $cashier = User::factory()->create();

        BusinessUser::create([
            'business_id' => $business->id,
            'user_id' => $cashier->id,
            'status' => 'active',
            'joined_at' => now(),
        ]);

        $sale = $this->createSale(
            $business,
            $cashier
        );

        $this->createProductItem(
            $sale,
            $business
        );

        $receipt = app(ReceiptService::class)->issue(
            $sale->fresh(),
            $cashier
        );

        $this->assertInstanceOf(
            Receipt::class,
            $receipt
        );

        $this->assertSame(
            $business->id,
            $receipt->business_id
        );

        $this->assertSame(
            $sale->id,
            $receipt->sale_id
        );

        $this->assertSame(
            $cashier->id,
            $receipt->issued_by
        );

        $this->assertSame(
            'RCPT-000001',
            $receipt->receipt_number
        );

        $this->assertSame(
            'issued',
            $receipt->status
        );

        $this->assertNotNull(
            $receipt->issued_at
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Historical Snapshot
    |--------------------------------------------------------------------------
    */

    public function test_receipt_contains_historical_snapshot(): void
    {
        $business = Business::factory()->create([
            'name' => 'Henry Stores',
            'email' => 'henry@example.com',
            'phone' => '08012345678',
            'currency' => 'NGN',
            'timezone' => 'Africa/Lagos',
            'default_country' => 'Nigeria',
        ]);

        $cashier = User::factory()->create([
            'name' => 'Henry Cashier',
        ]);

        BusinessUser::create([
            'business_id' => $business->id,
            'user_id' => $cashier->id,
            'status' => 'active',
            'joined_at' => now(),
        ]);

        $customer = Customer::factory()->create([
            'business_id' => $business->id,
            'name' => 'John Customer',
            'phone' => '08099999999',
        ]);

        $sale = $this->createSale(
            $business,
            $cashier,
            $customer
        );

        $this->createProductItem(
            $sale,
            $business
        );

        $receipt = app(ReceiptService::class)->issue(
            $sale->fresh(),
            $cashier
        );

        $snapshot = $receipt->snapshot;

        $this->assertIsArray(
            $snapshot
        );

        /*
         * Snapshot schema version.
         */
        $this->assertSame(
            1,
            $snapshot['version']
        );

        /*
         * Business snapshot.
         */
        $this->assertSame(
            'Henry Stores',
            $snapshot['business']['name']
        );

        $this->assertSame(
            'NGN',
            $snapshot['business']['currency']
        );

        $this->assertSame(
            '08012345678',
            $snapshot['business']['phone']
        );

        /*
         * Receipt information.
         */
        $this->assertSame(
            $receipt->receipt_number,
            $snapshot['receipt']['number']
        );

        $this->assertSame(
            'issued',
            $snapshot['receipt']['status']
        );

        /*
         * Sale snapshot.
         */
        $this->assertSame(
            $sale->id,
            $snapshot['sale']['id']
        );

        $this->assertSame(
            '10000.00',
            $snapshot['sale']['subtotal']
        );

        $this->assertSame(
            '500.00',
            $snapshot['sale']['discount']
        );

        $this->assertSame(
            '0.00',
            $snapshot['sale']['tax']
        );

        $this->assertSame(
            '9500.00',
            $snapshot['sale']['total']
        );

        $this->assertSame(
            'cash',
            $snapshot['sale']['payment_method']
        );

        $this->assertSame(
            'paid',
            $snapshot['sale']['payment_status']
        );

        $this->assertSame(
            'completed',
            $snapshot['sale']['status']
        );

        /*
         * Customer snapshot.
         */
        $this->assertNotNull(
            $snapshot['customer']
        );

        $this->assertSame(
            'John Customer',
            $snapshot['customer']['name']
        );

        $this->assertSame(
            '08099999999',
            $snapshot['customer']['phone']
        );

        $this->assertSame(
            $customer->customer_number,
            $snapshot['customer']['customer_number']
        );

        /*
         * Cashier snapshot.
         */
        $this->assertNotNull(
            $snapshot['cashier']
        );

        $this->assertSame(
            $cashier->id,
            $snapshot['cashier']['id']
        );

        $this->assertSame(
            'Henry Cashier',
            $snapshot['cashier']['name']
        );

        /*
         * Items.
         */
        $this->assertCount(
            1,
            $snapshot['items']
        );

        $item = $snapshot['items'][0];

        $this->assertSame(
            'product',
            $item['type']
        );

        $this->assertSame(
            '2.0000',
            $item['quantity']
        );

        $this->assertSame(
            '5000.00',
            $item['unit_price']
        );

        $this->assertSame(
            '0.00',
            $item['discount']
        );

        $this->assertSame(
            '10000.00',
            $item['total']
        );

        /*
         * SECURITY:
         *
         * Internal cost information must never be exposed
         * through the customer-facing receipt snapshot.
         */
        $this->assertArrayNotHasKey(
            'unit_cost',
            $item
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Duplicate Protection
    |--------------------------------------------------------------------------
    */

    public function test_receipt_cannot_be_issued_twice_for_same_sale(): void
    {
        [$business, $cashier] =
            $this->createBusinessWithCashier();

        $sale = $this->createSale(
            $business,
            $cashier
        );

        $this->createProductItem(
            $sale,
            $business
        );

        $service = app(
            ReceiptService::class
        );

        /*
         * First issuance succeeds.
         */
        $firstReceipt = $service->issue(
            $sale->fresh(),
            $cashier
        );

        $this->assertNotNull(
            $firstReceipt
        );

        /*
         * Second issuance must fail.
         */
        $this->expectException(
            ValidationException::class
        );

        $service->issue(
            $sale->fresh(),
            $cashier
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Sale Validation
    |--------------------------------------------------------------------------
    */

    public function test_receipt_cannot_be_issued_for_cancelled_sale(): void
    {
        [$business, $cashier] =
            $this->createBusinessWithCashier();

        $sale = $this->createSale(
            $business,
            $cashier
        );

        $sale->update([
            'status' => 'cancelled',
        ]);

        $this->createProductItem(
            $sale,
            $business
        );

        $this->expectException(
            ValidationException::class
        );

        app(ReceiptService::class)->issue(
            $sale->fresh(),
            $cashier
        );
    }

    public function test_receipt_cannot_be_issued_for_unpaid_sale(): void
    {
        [$business, $cashier] =
            $this->createBusinessWithCashier();

        $sale = $this->createSale(
            $business,
            $cashier
        );

        $sale->update([
            'payment_status' => 'pending',
        ]);

        $this->createProductItem(
            $sale,
            $business
        );

        $this->expectException(
            ValidationException::class
        );

        app(ReceiptService::class)->issue(
            $sale->fresh(),
            $cashier
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Business Integrity
    |--------------------------------------------------------------------------
    */

    public function test_receipt_belongs_to_same_business_as_sale(): void
    {
        [$business, $cashier] =
            $this->createBusinessWithCashier();

        $sale = $this->createSale(
            $business,
            $cashier
        );

        $this->createProductItem(
            $sale,
            $business
        );

        $receipt = app(ReceiptService::class)->issue(
            $sale->fresh(),
            $cashier
        );

        $this->assertSame(
            $sale->business_id,
            $receipt->business_id
        );
    }
}