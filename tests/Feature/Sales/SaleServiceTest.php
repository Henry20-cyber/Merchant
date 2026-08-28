<?php

namespace Tests\Feature\Sales;

use App\Domains\Inventory\Models\StockMovement;
use App\Domains\Organization\Models\Business;
use App\Domains\Product\Models\Product;
use App\Domains\Product\Models\ProductUnit;
use App\Domains\Sales\Models\Sale;
use App\Domains\Sales\Models\SaleItem;
use App\Domains\Subscription\Models\Subscription;
use App\Domains\Subscription\Models\SubscriptionPlan;
use App\Domains\Sales\Services\SaleService;
use App\Domains\Service\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class SaleServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_service_can_be_sold(): void
    {
        $business = Business::factory()->create();

        $this->createSubscriptionFor($business);

        $cashier = User::factory()->create();

        $service = Service::factory()->create([
            'business_id' => $business->id,
            'name' => 'Braiding',
            'price' => 15000,
            'is_active' => true,
        ]);

        $sale = app(SaleService::class)->create(
            $business,
            $cashier,
            [
                [
                    'service_id' => $service->id,
                    'quantity' => 1,
                ],
            ]
        );

        $this->assertInstanceOf(
            Sale::class,
            $sale
        );

        $this->assertDatabaseHas('sale_items', [
            'sale_id' => $sale->id,
            'service_id' => $service->id,
            'product_id' => null,
            'product_unit_id' => null,
            'unit_price' => 15000,
            'quantity' => 1,
            'total' => 15000,
        ]);
    }

    public function test_service_sale_preserves_historical_price(): void
    {
        $business = Business::factory()->create();

        $this->createSubscriptionFor($business);

        $cashier = User::factory()->create();

        $service = Service::factory()->create([
            'business_id' => $business->id,
            'name' => 'Wig Installation',
            'price' => 10000,
            'is_active' => true,
        ]);

        $sale = app(SaleService::class)->create(
            $business,
            $cashier,
            [
                [
                    'service_id' => $service->id,
                    'quantity' => 1,
                ],
            ]
        );

        /*
         * Change the current service price after the sale.
         */
        $service->update([
            'price' => 15000,
        ]);

        $item = $sale->items()
            ->where('service_id', $service->id)
            ->first();

        $this->assertNotNull($item);

        /*
         * Historical sale must remain at ₦10,000.
         */
        $this->assertEquals(
            10000,
            (float) $item->unit_price
        );

        $this->assertEquals(
            10000,
            (float) $item->total
        );
    }

    public function test_service_sale_does_not_create_stock_movement(): void
    {
        $business = Business::factory()->create();

        $this->createSubscriptionFor($business);

        $cashier = User::factory()->create();

        $service = Service::factory()->create([
            'business_id' => $business->id,
            'price' => 15000,
            'is_active' => true,
        ]);

        $sale = app(SaleService::class)->create(
            $business,
            $cashier,
            [
                [
                    'service_id' => $service->id,
                    'quantity' => 1,
                ],
            ]
        );

        $this->assertDatabaseCount(
            'stock_movements',
            0
        );

        $this->assertDatabaseHas('sale_items', [
            'sale_id' => $sale->id,
            'service_id' => $service->id,
        ]);
    }

    public function test_service_from_another_business_is_rejected(): void
    {
        $businessA = Business::factory()->create();

        $this->createSubscriptionFor($businessA);

        $businessB = Business::factory()->create();

        $cashier = User::factory()->create();

        $service = Service::factory()->create([
            'business_id' => $businessB->id,
            'price' => 15000,
            'is_active' => true,
        ]);

        $this->expectException(
            ValidationException::class
        );

        app(SaleService::class)->create(
            $businessA,
            $cashier,
            [
                [
                    'service_id' => $service->id,
                    'quantity' => 1,
                ],
            ]
        );
    }

    public function test_inactive_service_cannot_be_sold(): void
    {
        $business = Business::factory()->create();

        $this->createSubscriptionFor($business);

        $cashier = User::factory()->create();

        $service = Service::factory()->create([
            'business_id' => $business->id,
            'price' => 15000,
            'is_active' => false,
        ]);

        $this->expectException(
            ValidationException::class
        );

        app(SaleService::class)->create(
            $business,
            $cashier,
            [
                [
                    'service_id' => $service->id,
                    'quantity' => 1,
                ],
            ]
        );
    }

    public function test_sale_can_contain_product_and_service(): void
    {
        $business = Business::factory()->create();

        $this->createSubscriptionFor($business);

        $cashier = User::factory()->create();

        /*
         * Physical product.
         */
        $product = Product::factory()->create([
            'business_id' => $business->id,
        ]);

        $unit = ProductUnit::factory()->create([
            'business_id' => $business->id,
            'product_id' => $product->id,
            'quantity' => 1,
            'cost_price' => 5000,
            'selling_price' => 8000,
            'is_base_unit' => true,
            'is_sellable' => true,
            'is_purchasable' => true,
        ]);

        $stock = \App\Domains\Inventory\Models\Stock::create([
            'business_id' => $business->id,
            'product_id' => $product->id,
            'product_unit_id' => $unit->id,
            'quantity' => 10,
            'reorder_level' => 2,
        ]);

        /*
         * Service.
         */
        $service = Service::factory()->create([
            'business_id' => $business->id,
            'name' => 'Hair Treatment',
            'price' => 12000,
            'is_active' => true,
        ]);

        $sale = app(SaleService::class)->create(
            $business,
            $cashier,
            [
                [
                    'product_id' => $product->id,
                    'product_unit_id' => $unit->id,
                    'quantity' => 2,
                ],
                [
                    'service_id' => $service->id,
                    'quantity' => 1,
                ],
            ]
        );

        $this->assertEquals(
            2,
            $sale->items()->count()
        );

        $this->assertDatabaseHas('sale_items', [
            'sale_id' => $sale->id,
            'product_id' => $product->id,
            'service_id' => null,
        ]);

        $this->assertDatabaseHas('sale_items', [
            'sale_id' => $sale->id,
            'service_id' => $service->id,
            'product_id' => null,
        ]);

        /*
         * Only the physical product consumed inventory.
         */
        $this->assertEquals(
            8,
            (float) $stock->fresh()->quantity
        );

        $this->assertDatabaseCount(
            'stock_movements',
            1
        );
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