<?php

namespace Tests\Feature\Inventory;

use App\Domains\Inventory\Models\Stock;
use App\Domains\Inventory\Models\StockMovement;
use App\Domains\Inventory\Services\InventoryAnalyticsService;
use App\Domains\Organization\Models\Business;
use App\Domains\Product\Models\Product;
use App\Domains\Product\Models\ProductUnit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class InventoryAnalyticsTest extends TestCase
{
    use RefreshDatabase;

    private function createProductWithBaseUnit(
        Business $business,
        string $name = 'Test Product',
        string $sku = 'TEST-001'
    ): array {
        $product = Product::factory()->create([
            'business_id' => $business->id,
            'name' => $name,
            'sku' => $sku,
        ]);

        $unit = ProductUnit::factory()->create([
            'business_id' => $business->id,
            'product_id' => $product->id,
            'name' => 'Piece',
            'quantity' => 1,
            'cost_price' => 100,
            'selling_price' => 150,
            'is_base_unit' => true,
            'is_sellable' => true,
            'is_purchasable' => true,
        ]);

        return [$product, $unit];
    }

    private function createStock(
        Business $business,
        Product $product,
        ProductUnit $unit,
        float $quantity = 0,
        float $reorderLevel = 0
    ): Stock {
        return Stock::create([
            'business_id' => $business->id,
            'product_id' => $product->id,
            'product_unit_id' => $unit->id,
            'quantity' => $quantity,
            'reorder_level' => $reorderLevel,
        ]);
    }

    /**
     * Create a stock movement without relying on a factory.
     *
     * StockMovement does not use HasFactory, so all movement
     * records are created explicitly.
     */
   private function createMovement(
    Business $business,
    Product $product,
    ProductUnit $unit,
    Stock $stock,
    string $type,
    float $quantity,
    float $quantityBefore,
    float $quantityAfter,
    ?Carbon $createdAt = null
): StockMovement {
    $createdAt ??= now();

    $movement = StockMovement::create([
        'business_id' => $business->id,
        'product_id' => $product->id,
        'product_unit_id' => $unit->id,
        'stock_id' => $stock->id,
        'type' => $type,
        'quantity' => $quantity,
        'quantity_before' => $quantityBefore,
        'quantity_after' => $quantityAfter,
        'reference_type' => null,
        'reference_id' => null,
        'note' => null,
        'created_by' => null,
    ]);

    /*
     * created_at / updated_at are not in StockMovement::$fillable,
     * so they must be assigned after creation.
     */
    $movement->created_at = $createdAt;
    $movement->updated_at = $createdAt;
    $movement->save();

    return $movement->fresh();
}

    public function test_overview_returns_current_inventory_metrics(): void
    {
        $business = Business::factory()->create();

        [$productA, $unitA] = $this->createProductWithBaseUnit(
            $business,
            'Product A',
            'ANALYTICS-001'
        );

        [$productB, $unitB] = $this->createProductWithBaseUnit(
            $business,
            'Product B',
            'ANALYTICS-002'
        );

        $this->createStock(
            $business,
            $productA,
            $unitA,
            50,
            10
        );

        $this->createStock(
            $business,
            $productB,
            $unitB,
            20,
            5
        );

        $analytics = app(InventoryAnalyticsService::class)
            ->overviewMetrics($business);

        $this->assertSame(2, $analytics['products']);
        $this->assertSame(2, $analytics['product_units']);

        $this->assertEquals(
            70,
            $analytics['units_in_stock']
        );

        $this->assertSame(
            0,
            $analytics['low_stock_products']
        );

        $this->assertSame(
            0,
            $analytics['out_of_stock_products']
        );
    }

    public function test_low_stock_products_are_detected(): void
    {
        $business = Business::factory()->create();

        [$product, $unit] = $this->createProductWithBaseUnit(
            $business,
            'Low Stock Product',
            'LOW-001'
        );

        $this->createStock(
            $business,
            $product,
            $unit,
            5,
            10
        );

        $analytics = app(InventoryAnalyticsService::class)
            ->lowStockProducts($business);

        $this->assertCount(1, $analytics);

        $this->assertSame(
            $product->id,
            $analytics->first()['product_id']
        );

        $this->assertEquals(
            5,
            $analytics->first()['quantity']
        );

        $this->assertEquals(
            10,
            $analytics->first()['reorder_level']
        );
    }

    public function test_stock_at_zero_is_out_of_stock_not_low_stock(): void
    {
        $business = Business::factory()->create();

        [$product, $unit] = $this->createProductWithBaseUnit(
            $business,
            'Out Of Stock Product',
            'OUT-001'
        );

        $this->createStock(
            $business,
            $product,
            $unit,
            0,
            10
        );

        $lowStock = app(InventoryAnalyticsService::class)
            ->lowStockProducts($business);

        $outOfStock = app(InventoryAnalyticsService::class)
            ->outOfStockProducts($business);

        $this->assertCount(0, $lowStock);

        $this->assertCount(1, $outOfStock);

        $this->assertSame(
            $product->id,
            $outOfStock->first()['product_id']
        );
    }

    public function test_zero_reorder_level_does_not_create_low_stock_alert(): void
    {
        $business = Business::factory()->create();

        [$product, $unit] = $this->createProductWithBaseUnit(
            $business,
            'No Threshold Product',
            'THRESHOLD-001'
        );

        $this->createStock(
            $business,
            $product,
            $unit,
            2,
            0
        );

        $analytics = app(InventoryAnalyticsService::class)
            ->lowStockProducts($business);

        $this->assertCount(0, $analytics);
    }

    public function test_movement_summary_calculates_received_sales_and_adjustments(): void
    {
        $business = Business::factory()->create();

        [$product, $unit] = $this->createProductWithBaseUnit(
            $business,
            'Movement Product',
            'MOVE-001'
        );

        $stock = $this->createStock(
            $business,
            $product,
            $unit,
            0
        );

        $this->createMovement(
            $business,
            $product,
            $unit,
            $stock,
            'receive',
            100,
            0,
            100
        );

        $this->createMovement(
            $business,
            $product,
            $unit,
            $stock,
            'sale',
            30,
            100,
            70
        );

        $this->createMovement(
            $business,
            $product,
            $unit,
            $stock,
            'adjustment',
            -5,
            70,
            65
        );

        $summary = app(InventoryAnalyticsService::class)
            ->movementSummary($business);

        $this->assertEquals(
            100,
            $summary['received']
        );

        $this->assertEquals(
            30,
            $summary['sold']
        );

        $this->assertEquals(
            -5,
            $summary['adjusted']
        );
    }

    public function test_top_selling_products_are_ordered_by_units_sold(): void
    {
        $business = Business::factory()->create();

        [$productA, $unitA] = $this->createProductWithBaseUnit(
            $business,
            'Best Seller',
            'BEST-001'
        );

        [$productB, $unitB] = $this->createProductWithBaseUnit(
            $business,
            'Second Best',
            'BEST-002'
        );

        $stockA = $this->createStock(
            $business,
            $productA,
            $unitA,
            100
        );

        $stockB = $this->createStock(
            $business,
            $productB,
            $unitB,
            100
        );

        $this->createMovement(
            $business,
            $productA,
            $unitA,
            $stockA,
            'sale',
            80,
            100,
            20
        );

        $this->createMovement(
            $business,
            $productB,
            $unitB,
            $stockB,
            'sale',
            30,
            100,
            70
        );

        $products = app(InventoryAnalyticsService::class)
            ->topSellingProducts($business);

        $this->assertCount(2, $products);

        $this->assertSame(
            $productA->id,
            $products->first()['product_id']
        );

        $this->assertEquals(
            80,
            $products->first()['units_sold']
        );

        $this->assertSame(
            $productB->id,
            $products->last()['product_id']
        );
    }

    public function test_slow_moving_products_include_products_with_zero_sales(): void
    {
        $business = Business::factory()->create();

        [$productA, $unitA] = $this->createProductWithBaseUnit(
            $business,
            'Never Sold',
            'SLOW-001'
        );

        [$productB, $unitB] = $this->createProductWithBaseUnit(
            $business,
            'Sold Product',
            'SLOW-002'
        );

        $stockA = $this->createStock(
            $business,
            $productA,
            $unitA,
            50
        );

        $stockB = $this->createStock(
            $business,
            $productB,
            $unitB,
            20
        );

        $this->createMovement(
            $business,
            $productB,
            $unitB,
            $stockB,
            'sale',
            10,
            30,
            20
        );

        $products = app(InventoryAnalyticsService::class)
            ->slowMovingProducts($business);

        $this->assertNotEmpty($products);

        $this->assertSame(
            $productA->id,
            $products->first()['product_id']
        );

        $this->assertEquals(
            0,
            $products->first()['units_sold']
        );

        $this->assertEquals(
            50,
            $products->first()['current_stock']
        );
    }

    public function test_analytics_are_scoped_to_the_business(): void
    {
        $businessA = Business::factory()->create();
        $businessB = Business::factory()->create();

        [$productA, $unitA] = $this->createProductWithBaseUnit(
            $businessA,
            'Business A Product',
            'A-001'
        );

        [$productB, $unitB] = $this->createProductWithBaseUnit(
            $businessB,
            'Business B Product',
            'B-001'
        );

        $this->createStock(
            $businessA,
            $productA,
            $unitA,
            50
        );

        $stockB = $this->createStock(
            $businessB,
            $productB,
            $unitB,
            500
        );

        $this->createMovement(
            $businessB,
            $productB,
            $unitB,
            $stockB,
            'sale',
            100,
            600,
            500
        );

        $analytics = app(InventoryAnalyticsService::class)
            ->overview($businessA);

        $this->assertSame(
            1,
            $analytics['overview']['products']
        );

        $this->assertEquals(
            50,
            $analytics['overview']['units_in_stock']
        );

        $this->assertEquals(
            0,
            $analytics['movement_summary']['sold']
        );

        $this->assertCount(
            0,
            $analytics['top_products']
        );
    }

   public function test_movement_summary_respects_date_range(): void
{
    $business = Business::factory()->create();

    [$product, $unit] = $this->createProductWithBaseUnit(
        $business,
        'Date Filter Product',
        'DATE-001'
    );

    $stock = $this->createStock(
        $business,
        $product,
        $unit,
        100
    );

    // Outside the requested range.
    $oldDate = Carbon::create(
        2026,
        8,
        1,
        12,
        0,
        0,
        'Africa/Lagos'
    );

    // Inside the requested range.
    $newDate = Carbon::create(
        2026,
        8,
        20,
        12,
        0,
        0,
        'Africa/Lagos'
    );

    $this->createMovement(
        $business,
        $product,
        $unit,
        $stock,
        'sale',
        50,
        150,
        100,
        $oldDate
    );

    $this->createMovement(
        $business,
        $product,
        $unit,
        $stock,
        'sale',
        20,
        120,
        100,
        $newDate
    );

    $summary = app(InventoryAnalyticsService::class)
        ->movementSummary(
            $business,
            Carbon::create(
                2026,
                8,
                15,
                0,
                0,
                0,
                'Africa/Lagos'
            ),
            Carbon::create(
                2026,
                8,
                23,
                23,
                59,
                59,
                'Africa/Lagos'
            )
        );

    $this->assertEquals(
        20,
        $summary['sold']
    );
}

    public function test_empty_business_returns_zero_metrics(): void
    {
        $business = Business::factory()->create();

        $analytics = app(InventoryAnalyticsService::class)
            ->overview($business);

        $this->assertSame(
            0,
            $analytics['overview']['products']
        );

        $this->assertSame(
            0,
            $analytics['overview']['product_units']
        );

        $this->assertEquals(
            0,
            $analytics['overview']['units_in_stock']
        );

        $this->assertSame(
            0,
            $analytics['overview']['low_stock_products']
        );

        $this->assertSame(
            0,
            $analytics['overview']['out_of_stock_products']
        );

        $this->assertEquals(
            0,
            $analytics['movement_summary']['received']
        );

        $this->assertEquals(
            0,
            $analytics['movement_summary']['sold']
        );

        $this->assertEquals(
            0,
            $analytics['movement_summary']['adjusted']
        );
    }
}
