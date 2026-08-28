<?php

namespace Tests\Feature\Sales;

use App\Domains\Customer\Models\Customer;
use App\Domains\Inventory\Models\Stock;
use App\Domains\Organization\Models\Business;
use App\Domains\Product\Models\Product;
use App\Domains\Product\Models\ProductUnit;
use App\Domains\Subscription\Models\Subscription;
use App\Domains\Subscription\Models\SubscriptionPlan;
use App\Domains\Sales\Services\SaleService;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class SaleCustomerTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Create a product, unit and stock record
     * that can be used in a sale.
     */
    private function createProductWithStock(
        Business $business
    ): array {
        $product = Product::factory()->create([
            'business_id' => $business->id,
        ]);

        $unit = ProductUnit::factory()->create([
            'business_id' => $business->id,
            'product_id' => $product->id,
            'quantity' => 1,
            'cost_price' => 100,
            'selling_price' => 150,
            'is_base_unit' => true,
            'is_sellable' => true,
            'is_purchasable' => true,
        ]);

        Stock::create([
            'business_id' => $business->id,
            'product_id' => $product->id,
            'product_unit_id' => $unit->id,
            'quantity' => 100,
            'reorder_level' => 10,
        ]);

        return [
            $product,
            $unit,
        ];
    }

    /**
     * Build a simple product sale item.
     */
    private function saleItems(
        Product $product,
        ProductUnit $unit
    ): array {
        return [
            [
                'product_id' => $product->id,
                'product_unit_id' => $unit->id,
                'quantity' => 1,
            ],
        ];
    }

    /**
     * Walk-in sales do not require a customer.
     */
    public function test_walk_in_sale_can_be_created_without_customer(): void
    {
        $business = Business::factory()->create();

        $this->createSubscriptionFor($business);

        $cashier = User::factory()->create();

        [$product, $unit] =
            $this->createProductWithStock($business);

        $sale = app(SaleService::class)->create(
            $business,
            $cashier,
            $this->saleItems($product, $unit)
        );

        $this->assertNull(
            $sale->customer_id
        );

        $this->assertNull(
            $sale->customer
        );

        $this->assertDatabaseHas('sales', [
            'id' => $sale->id,
            'business_id' => $business->id,
            'customer_id' => null,
        ]);
    }

    /**
     * A sale can optionally belong to a customer.
     */
    public function test_sale_can_be_attached_to_customer(): void
    {
        $business = Business::factory()->create();

        $this->createSubscriptionFor($business);

        $cashier = User::factory()->create();

        $customer = Customer::factory()->create([
            'business_id' => $business->id,
            'status' => 'active',
        ]);

        [$product, $unit] =
            $this->createProductWithStock($business);

        $sale = app(SaleService::class)->create(
            $business,
            $cashier,
            $this->saleItems($product, $unit),
            [
                'customer_id' => $customer->id,
            ]
        );

        $this->assertEquals(
            $customer->id,
            $sale->customer_id
        );

        $this->assertTrue(
            $sale->customer->is($customer)
        );

        $this->assertDatabaseHas('sales', [
            'id' => $sale->id,
            'business_id' => $business->id,
            'customer_id' => $customer->id,
        ]);
    }

    /**
     * A customer belonging to another business
     * cannot be attached to this business's sale.
     */
    public function test_customer_from_another_business_cannot_be_used(): void
    {
        $businessA = Business::factory()->create();

        $this->createSubscriptionFor($businessA);

        $businessB = Business::factory()->create();

        $cashier = User::factory()->create();

        $customerB = Customer::factory()->create([
            'business_id' => $businessB->id,
            'status' => 'active',
        ]);

        [$product, $unit] =
            $this->createProductWithStock($businessA);

        try {
            app(SaleService::class)->create(
                $businessA,
                $cashier,
                $this->saleItems($product, $unit),
                [
                    'customer_id' => $customerB->id,
                ]
            );

            $this->fail(
                'Expected customer tenant validation to fail.'
            );
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey(
                'customer_id',
                $exception->errors()
            );
        }

        $this->assertDatabaseMissing('sales', [
            'business_id' => $businessA->id,
            'customer_id' => $customerB->id,
        ]);
    }

    /**
     * An inactive customer cannot be attached
     * to a new sale.
     */
    public function test_inactive_customer_cannot_be_used_for_new_sale(): void
    {
        $business = Business::factory()->create();

        $this->createSubscriptionFor($business);

        $cashier = User::factory()->create();

        $customer = Customer::factory()->create([
            'business_id' => $business->id,
            'status' => 'inactive',
        ]);

        [$product, $unit] =
            $this->createProductWithStock($business);

        try {
            app(SaleService::class)->create(
                $business,
                $cashier,
                $this->saleItems($product, $unit),
                [
                    'customer_id' => $customer->id,
                ]
            );

            $this->fail(
                'Expected inactive customer validation to fail.'
            );
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey(
                'customer_id',
                $exception->errors()
            );
        }

        $this->assertDatabaseMissing('sales', [
            'business_id' => $business->id,
            'customer_id' => $customer->id,
        ]);
    }

    private function createSubscriptionFor(
    Business $business
): Subscription {
    $plan = SubscriptionPlan::factory()->create([
        'transaction_daily_limit' => 1000,
        'transaction_monthly_limit' => 10000,
        'is_active' => true,
    ]);

    return Subscription::factory()->create([
        'business_id' => $business->id,
        'plan_id' => $plan->id,
        'status' => 'active',
        'starts_at' => now()->subDay(),
        'current_period_start' => now()->subDay(),
        'current_period_end' => now()->addMonth(),
        'grace_period_ends_at' => null,
        'cancelled_at' => null,
        'ended_at' => null,
    ]);
}
}
