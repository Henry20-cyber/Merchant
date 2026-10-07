<?php

namespace Tests\Feature\Sales;

use App\Domains\Inventory\Models\Stock;
use App\Domains\Inventory\Models\StockMovement;
use App\Domains\Organization\Models\Business;
use App\Domains\Organization\Models\Branch;
use App\Domains\Product\Models\Product;
use App\Domains\Product\Models\ProductUnit;
use App\Domains\Sales\Models\Sale;
use App\Domains\Sales\Services\SaleService;
use App\Domains\Service\Models\Service;
use App\Domains\Subscription\Models\Subscription;
use App\Domains\Subscription\Models\SubscriptionPlan;
use App\Domains\Organization\Models\BusinessUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class SaleServiceTest extends TestCase
{
    use RefreshDatabase;

    private function createProductWithUnit(
        Business $business,
        float $costPrice = 100,
        float $sellingPrice = 150
    ): array {
        $product = Product::factory()->create([
            'business_id' => $business->id,
        ]);

        $unit = ProductUnit::factory()->create([
            'business_id' => $business->id,
            'product_id' => $product->id,
            'quantity' => 1,
            'cost_price' => $costPrice,
            'selling_price' => $sellingPrice,
            'is_base_unit' => true,
            'is_sellable' => true,
            'is_purchasable' => true,
        ]);

        return [$product, $unit];
    }

    public function test_product_sale_reduces_inventory_only_in_the_sale_branch(): void
    {
        $business = Business::factory()->create();

        $this->createSubscriptionFor($business);

        $branchA = $this->createBranchFor($business);
        $branchB = Branch::create([
            'business_id' => $business->id,
            'name' => 'Second Branch',
            'code' => 'SECOND-' . $business->id,
            'city' => 'Port Harcourt',
            'state' => 'Rivers',
            'country' => 'Nigeria',
            'is_head_office' => false,
        ]);

        $cashier = $this->createCashierFor($business);

        [$product, $unit] = $this->createProductWithUnit(
            $business,
            100,
            150
        );

        Stock::create([
            'business_id' => $business->id,
            'branch_id' => $branchA->id,
            'product_id' => $product->id,
            'quantity' => 50,
            'reorder_level' => 10,
        ]);

        Stock::create([
            'business_id' => $business->id,
            'branch_id' => $branchB->id,
            'product_id' => $product->id,
            'quantity' => 80,
            'reorder_level' => 10,
        ]);

        app(SaleService::class)->create(
            $business,
            $branchA,
            $cashier,
            [
                [
                    'product_id' => $product->id,
                    'product_unit_id' => $unit->id,
                    'quantity' => 5,
                ],
            ]
        );

        $this->assertDatabaseHas('stocks', [
            'branch_id' => $branchA->id,
            'product_id' => $product->id,
            'quantity' => 45,
        ]);

        $this->assertDatabaseHas('stocks', [
            'branch_id' => $branchB->id,
            'product_id' => $product->id,
            'quantity' => 80,
        ]);
    }

    public function test_service_can_be_sold(): void
    {
        $business = Business::factory()->create();

        $this->createSubscriptionFor($business);

     $branch = $this->createBranchFor($business);

        $cashier = $this->createCashierFor($business);

        $service = Service::factory()->create([
            'business_id' => $business->id,
            'name' => 'Braiding',
            'price' => 15000,
            'is_active' => true,
        ]);

        $sale = app(SaleService::class)->create(
            $business,
            $branch,
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

        $this->assertEquals(
            $branch->id,
            $sale->branch_id
        );

        $this->assertDatabaseHas('sales', [
            'id' => $sale->id,
            'business_id' => $business->id,
            'branch_id' => $branch->id,
        ]);

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

       $branch = $this->createBranchFor($business);

        $cashier = $this->createCashierFor($business);

        $service = Service::factory()->create([
            'business_id' => $business->id,
            'name' => 'Wig Installation',
            'price' => 10000,
            'is_active' => true,
        ]);

        $sale = app(SaleService::class)->create(
            $business,
            $branch,
            $cashier,
            [
                [
                    'service_id' => $service->id,
                    'quantity' => 1,
                ],
            ]
        );

        $service->update([
            'price' => 15000,
        ]);

        $item = $sale->items()
            ->where('service_id', $service->id)
            ->first();

        $this->assertNotNull($item);

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

       $branch = $this->createBranchFor($business);

        $cashier = $this->createCashierFor($business);

        $service = Service::factory()->create([
            'business_id' => $business->id,
            'price' => 15000,
            'is_active' => true,
        ]);

        $sale = app(SaleService::class)->create(
            $business,
            $branch,
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

      $branch = $this->createBranchFor($businessA);

        $businessB = Business::factory()->create();

        $cashier = $this->createCashierFor($businessA);

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
            $branch,
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

       $branch = $this->createBranchFor($business);

        $cashier = $this->createCashierFor($business);

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
            $branch,
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

       $branch = $this->createBranchFor($business);

        $cashier = $this->createCashierFor($business);

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

        /*
         * Stock is now stored once per product.
         *
         * No product_unit_id column.
         */
        $stock = Stock::create([
            'business_id' => $business->id,
            'branch_id' => $branch->id,
            'product_id' => $product->id,
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
            $branch,
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

        $this->assertEquals(
            $branch->id,
            $sale->branch_id
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

    public function test_product_sale_uses_catalog_cost_even_when_client_supplies_unit_cost(): void
    {
        $business = Business::factory()->create();

        $this->createSubscriptionFor($business);

       $branch = $this->createBranchFor($business);

        $cashier = $this->createCashierFor($business);

        $product = Product::factory()->create([
            'business_id' => $business->id,
        ]);

        /*
         * This is the base unit.
         *
         * quantity = 1 means:
         *
         * 1 unit = 1 base unit.
         */
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

        /*
         * Stock is stored in canonical base units.
         */
        Stock::create([
            'business_id' => $business->id,
            'branch_id' => $branch->id,
            'product_id' => $product->id,
            'quantity' => 10,
            'reorder_level' => 2,
        ]);

        $sale = app(SaleService::class)->create(
            $business,
            $branch,
            $cashier,
            [
                [
                    'product_id' => $product->id,
                    'product_unit_id' => $unit->id,
                    'quantity' => 2,

                    // Client attempts to manipulate price/cost.
                    'unit_price' => 7500,
                    'unit_cost' => 1,
                ],
            ]
        );

        $item = $sale->items()
            ->where('product_id', $product->id)
            ->first();

        $this->assertNotNull($item);

        /*
         * Transaction price may be overridden.
         */
        $this->assertEquals(
            7500,
            (float) $item->unit_price
        );

        /*
         * Cost must always come from the catalog.
         */
        $this->assertEquals(
            5000,
            (float) $item->unit_cost
        );

        $this->assertEquals(
            15000,
            (float) $item->total
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

    private function createCashierFor(
        Business $business
    ): User {
        $cashier = User::factory()->create();

        BusinessUser::create([
            'business_id' => $business->id,
            'user_id' => $cashier->id,
            'status' => 'active',
            'joined_at' => now(),
        ]);

        return $cashier;
    }

    private function createBranchFor(
    Business $business
): Branch {
    return Branch::firstOrCreate(
        [
            'business_id' => $business->id,
            'code' => 'TEST-' . $business->id,
        ],
        [
        'business_id' => $business->id,
        'name' => 'Test Branch',
        'code' => 'TEST-' . $business->id,
        'city' => 'Owerri',
        'state' => 'Imo',
        'country' => 'Nigeria',
        'is_head_office' => true,
    ]);
}

}