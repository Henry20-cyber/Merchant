<?php

namespace Tests\Feature\Sales;

use App\Domains\Organization\Models\Business;
use App\Domains\Product\Models\Product;
use App\Domains\Product\Models\ProductUnit;
use App\Domains\Sales\Models\Sale;
use App\Domains\Sales\Models\SaleItem;
use App\Domains\Sales\Services\SalesAnalyticsService;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SalesAnalyticsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PermissionSeeder::class);
    }

    public function test_sales_analytics_calculates_revenue_and_gross_profit(): void
    {
        $business = Business::factory()->create();

        $user = User::factory()->create();

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

        $sale = Sale::create([
            'business_id' => $business->id,
            'cashier_id' => $user->id,
            'subtotal' => 1500,
            'discount' => 0,
            'tax' => 0,
            'total' => 1500,
        ]);

        SaleItem::create([
            'sale_id' => $sale->id,
            'business_id' => $business->id,
            'product_id' => $product->id,
            'product_unit_id' => $unit->id,
            'quantity' => 10,
            'unit_price' => 150,
            'unit_cost' => 100,
            'subtotal' => 1500,
            'total' => 1500,
        ]);

        $analytics = app(SalesAnalyticsService::class)
            ->overview($business);

        $this->assertEquals(
            1500,
            $analytics['gross_sales']
        );

        $this->assertEquals(
            1500,
            $analytics['revenue']
        );

        $this->assertEquals(
            1000,
            $analytics['cogs']
        );

        $this->assertEquals(
            500,
            $analytics['gross_profit']
        );

        $this->assertEquals(
            33.33,
            $analytics['gross_margin']
        );

        $this->assertEquals(
            10,
            $analytics['units_sold']
        );

        $this->assertEquals(
            1,
            $analytics['transactions']
        );
    }

    public function test_sales_analytics_handles_zero_discount_and_zero_tax(): void
    {
        $business = Business::factory()->create();

        $user = User::factory()->create();

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

        $sale = Sale::create([
            'business_id' => $business->id,
            'cashier_id' => $user->id,
            'subtotal' => 1500,
            'discount' => 0,
            'tax' => 0,
            'total' => 1500,
        ]);

        SaleItem::create([
            'sale_id' => $sale->id,
            'product_id' => $product->id,
            'product_unit_id' => $unit->id,
            'quantity' => 10,
            'unit_price' => 150,
            'unit_cost' => 100,
            'subtotal' => 1500,
            'total' => 1500,
        ]);

        $analytics = app(SalesAnalyticsService::class)
            ->overview($business);

        $this->assertEquals(1500, $analytics['gross_sales']);

        $this->assertEquals(0, $analytics['discount']);

        $this->assertEquals(0, $analytics['tax']);

        $this->assertEquals(1500, $analytics['revenue']);

        $this->assertEquals(1000, $analytics['cogs']);

        $this->assertEquals(500, $analytics['gross_profit']);

        $this->assertEquals(33.33, $analytics['gross_margin']);

        $this->assertEquals(1500, $analytics['total']);

        $this->assertEquals(10, $analytics['units_sold']);

        $this->assertEquals(1, $analytics['transactions']);
    }

    public function test_sales_analytics_calculates_discount_tax_and_net_revenue(): void
    {
        $business = Business::factory()->create();

        $user = User::factory()->create();

        $product = Product::factory()->create([
            'business_id' => $business->id,
        ]);

        $unit = ProductUnit::factory()->create([
            'business_id' => $business->id,
            'product_id' => $product->id,
            'quantity' => 1,
            'cost_price' => 600,
            'selling_price' => 1000,
            'is_base_unit' => true,
            'is_sellable' => true,
            'is_purchasable' => true,
        ]);

        $sale = Sale::create([
            'business_id' => $business->id,
            'cashier_id' => $user->id,
            'subtotal' => 10000,
            'discount' => 1000,
            'tax' => 500,
            'total' => 9500,
        ]);

        SaleItem::create([
            'sale_id' => $sale->id,
            'product_id' => $product->id,
            'product_unit_id' => $unit->id,
            'quantity' => 10,
            'unit_price' => 1000,
            'unit_cost' => 600,
            'subtotal' => 10000,
            'total' => 10000,
        ]);

        $analytics = app(SalesAnalyticsService::class)
            ->overview($business);

        $this->assertEquals(
            10000,
            $analytics['gross_sales']
        );

        $this->assertEquals(
            1000,
            $analytics['discount']
        );

        $this->assertEquals(
            500,
            $analytics['tax']
        );

        $this->assertEquals(
            9000,
            $analytics['revenue']
        );

        $this->assertEquals(
            6000,
            $analytics['cogs']
        );

        $this->assertEquals(
            3000,
            $analytics['gross_profit']
        );

        $this->assertEquals(
            33.33,
            $analytics['gross_margin']
        );

        $this->assertEquals(
            9500,
            $analytics['total']
        );

        $this->assertEquals(
            10,
            $analytics['units_sold']
        );

        $this->assertEquals(
            1,
            $analytics['transactions']
        );
    }

    public function test_sales_analytics_aggregates_multiple_sales(): void
    {
        $business = Business::factory()->create();

        $user = User::factory()->create();

        $product = Product::factory()->create([
            'business_id' => $business->id,
        ]);

        $unit = ProductUnit::factory()->create([
            'business_id' => $business->id,
            'product_id' => $product->id,
            'quantity' => 1,
            'cost_price' => 600,
            'selling_price' => 1000,
            'is_base_unit' => true,
            'is_sellable' => true,
            'is_purchasable' => true,
        ]);

        /*
     * Sale #1
     *
     * 10 × ₦1,000 = ₦10,000
     * COGS = 10 × ₦600 = ₦6,000
     */
        $saleOne = Sale::create([
            'business_id' => $business->id,
            'cashier_id' => $user->id,
            'subtotal' => 10000,
            'discount' => 0,
            'tax' => 0,
            'total' => 10000,
        ]);

        SaleItem::create([
            'sale_id' => $saleOne->id,
            'product_id' => $product->id,
            'product_unit_id' => $unit->id,
            'quantity' => 10,
            'unit_price' => 1000,
            'unit_cost' => 600,
            'subtotal' => 10000,
            'total' => 10000,
        ]);

        /*
     * Sale #2
     *
     * 5 × ₦1,000 = ₦5,000
     * COGS = 5 × ₦600 = ₦3,000
     */
        $saleTwo = Sale::create([
            'business_id' => $business->id,
            'cashier_id' => $user->id,
            'subtotal' => 5000,
            'discount' => 0,
            'tax' => 0,
            'total' => 5000,
        ]);

        SaleItem::create([
            'sale_id' => $saleTwo->id,
            'product_id' => $product->id,
            'product_unit_id' => $unit->id,
            'quantity' => 5,
            'unit_price' => 1000,
            'unit_cost' => 600,
            'subtotal' => 5000,
            'total' => 5000,
        ]);

        /*
     * Sale #3
     *
     * 20 × ₦1,000 = ₦20,000
     * COGS = 20 × ₦600 = ₦12,000
     */
        $saleThree = Sale::create([
            'business_id' => $business->id,
            'cashier_id' => $user->id,
            'subtotal' => 20000,
            'discount' => 0,
            'tax' => 0,
            'total' => 20000,
        ]);

        SaleItem::create([
            'sale_id' => $saleThree->id,
            'product_id' => $product->id,
            'product_unit_id' => $unit->id,
            'quantity' => 20,
            'unit_price' => 1000,
            'unit_cost' => 600,
            'subtotal' => 20000,
            'total' => 20000,
        ]);

        $analytics = app(SalesAnalyticsService::class)
            ->overview($business);

        $this->assertEquals(
            35000,
            $analytics['gross_sales']
        );

        $this->assertEquals(
            0,
            $analytics['discount']
        );

        $this->assertEquals(
            0,
            $analytics['tax']
        );

        $this->assertEquals(
            35000,
            $analytics['revenue']
        );

        $this->assertEquals(
            21000,
            $analytics['cogs']
        );

        $this->assertEquals(
            14000,
            $analytics['gross_profit']
        );

        $this->assertEquals(
            40.0,
            $analytics['gross_margin']
        );

        $this->assertEquals(
            35000,
            $analytics['total']
        );

        $this->assertEquals(
            35,
            $analytics['units_sold']
        );

        $this->assertEquals(
            3,
            $analytics['transactions']
        );
    }

    public function test_sales_analytics_respects_date_range(): void
    {
        $business = Business::factory()->create();

        $user = User::factory()->create();

        $product = Product::factory()->create([
            'business_id' => $business->id,
        ]);

        $unit = ProductUnit::factory()->create([
            'business_id' => $business->id,
            'product_id' => $product->id,
            'quantity' => 1,
            'cost_price' => 600,
            'selling_price' => 1000,
            'is_base_unit' => true,
            'is_sellable' => true,
            'is_purchasable' => true,
        ]);

        /*
     * Sale inside the requested range.
     */
        $saleOne = Sale::create([
            'business_id' => $business->id,
            'cashier_id' => $user->id,
            'subtotal' => 10000,
            'discount' => 0,
            'tax' => 0,
            'total' => 10000,
        ]);

        $saleOne->forceFill([
            'created_at' => '2026-08-01 12:00:00',
            'updated_at' => '2026-08-01 12:00:00',
        ])->saveQuietly();


        SaleItem::create([
            'sale_id' => $saleOne->id,
            'product_id' => $product->id,
            'product_unit_id' => $unit->id,
            'quantity' => 10,
            'unit_price' => 1000,
            'unit_cost' => 600,
            'discount' => 0,
            'total' => 10000,
        ]);
        /*
     * Second sale inside the requested range.
     */
        $saleTwo = Sale::create([
            'business_id' => $business->id,
            'cashier_id' => $user->id,
            'subtotal' => 20000,
            'discount' => 0,
            'tax' => 0,
            'total' => 20000,
        ]);

        $saleTwo->forceFill([
            'created_at' => '2026-08-10 12:00:00',
            'updated_at' => '2026-08-10 12:00:00',
        ])->saveQuietly();

        SaleItem::create([
            'sale_id' => $saleTwo->id,
            'product_id' => $product->id,
            'product_unit_id' => $unit->id,
            'quantity' => 20,
            'unit_price' => 1000,
            'unit_cost' => 600,
            'discount' => 0,
            'total' => 20000,
        ]);
        /*
     * Sale outside the requested range.
     */
        $saleThree = Sale::create([
            'business_id' => $business->id,
            'cashier_id' => $user->id,
            'subtotal' => 30000,
            'discount' => 0,
            'tax' => 0,
            'total' => 30000,
        ]);

        $saleThree->forceFill([
            'created_at' => '2026-08-20 12:00:00',
            'updated_at' => '2026-08-20 12:00:00',
        ])->saveQuietly();

        SaleItem::create([
            'sale_id' => $saleThree->id,
            'product_id' => $product->id,
            'product_unit_id' => $unit->id,
            'quantity' => 30,
            'unit_price' => 1000,
            'unit_cost' => 600,
            'discount' => 0,
            'total' => 30000,
        ]);


        $analytics = app(SalesAnalyticsService::class)
            ->overview(
                $business,
                '2026-08-01',
                '2026-08-15'
            );

        $this->assertEquals(
            30000,
            $analytics['gross_sales']
        );

        $this->assertEquals(
            30000,
            $analytics['revenue']
        );

        $this->assertEquals(
            18000,
            $analytics['cogs']
        );

        $this->assertEquals(
            12000,
            $analytics['gross_profit']
        );

        $this->assertEquals(
            40.0,
            $analytics['gross_margin']
        );

        $this->assertEquals(
            30,
            $analytics['units_sold']
        );

        $this->assertEquals(
            2,
            $analytics['transactions']
        );
    }

    public function test_sales_analytics_is_scoped_to_business(): void
{
    $businessA = Business::factory()->create();
    $businessB = Business::factory()->create();

    $userA = User::factory()->create();
    $userB = User::factory()->create();

    $productA = Product::factory()->create([
        'business_id' => $businessA->id,
    ]);

    $unitA = ProductUnit::factory()->create([
        'business_id' => $businessA->id,
        'product_id' => $productA->id,
        'quantity' => 1,
        'cost_price' => 600,
        'selling_price' => 1000,
        'is_base_unit' => true,
        'is_sellable' => true,
        'is_purchasable' => true,
    ]);

    $productB = Product::factory()->create([
        'business_id' => $businessB->id,
    ]);

    $unitB = ProductUnit::factory()->create([
        'business_id' => $businessB->id,
        'product_id' => $productB->id,
        'quantity' => 1,
        'cost_price' => 600,
        'selling_price' => 1000,
        'is_base_unit' => true,
        'is_sellable' => true,
        'is_purchasable' => true,
    ]);

    /*
     * Business A sale:
     *
     * 10 × ₦1,000 = ₦10,000
     */
    $saleA = Sale::create([
        'business_id' => $businessA->id,
        'cashier_id' => $userA->id,
        'subtotal' => 10000,
        'discount' => 0,
        'tax' => 0,
        'total' => 10000,
    ]);

    SaleItem::create([
        'sale_id' => $saleA->id,
        'product_id' => $productA->id,
        'product_unit_id' => $unitA->id,
        'quantity' => 10,
        'unit_price' => 1000,
        'unit_cost' => 600,
        'discount' => 0,
        'total' => 10000,
    ]);

    /*
     * Business B sale:
     *
     * 100 × ₦1,000 = ₦100,000
     */
    $saleB = Sale::create([
        'business_id' => $businessB->id,
        'cashier_id' => $userB->id,
        'subtotal' => 100000,
        'discount' => 0,
        'tax' => 0,
        'total' => 100000,
    ]);

    SaleItem::create([
        'sale_id' => $saleB->id,
        'product_id' => $productB->id,
        'product_unit_id' => $unitB->id,
        'quantity' => 100,
        'unit_price' => 1000,
        'unit_cost' => 600,
        'discount' => 0,
        'total' => 100000,
    ]);

    $analytics = app(SalesAnalyticsService::class)
        ->overview($businessA);

    /*
     * Business A must see only its own sale.
     */
    $this->assertEquals(
        10000,
        $analytics['gross_sales']
    );

    $this->assertEquals(
        10000,
        $analytics['revenue']
    );

    $this->assertEquals(
        6000,
        $analytics['cogs']
    );

    $this->assertEquals(
        4000,
        $analytics['gross_profit']
    );

    $this->assertEquals(
        10,
        $analytics['units_sold']
    );

    $this->assertEquals(
        1,
        $analytics['transactions']
    );

    /*
     * Business B's ₦100,000 sale must not leak
     * into Business A's analytics.
     */
    $this->assertNotEquals(
        110000,
        $analytics['gross_sales']
    );
}

public function test_sales_analytics_returns_top_selling_products(): void
{
    $business = Business::factory()->create();

    $user = User::factory()->create();

    $productA = Product::factory()->create([
        'business_id' => $business->id,
        'name' => 'Coca-Cola',
    ]);

    $unitA = ProductUnit::factory()->create([
        'business_id' => $business->id,
        'product_id' => $productA->id,
        'quantity' => 1,
        'cost_price' => 600,
        'selling_price' => 1000,
        'is_base_unit' => true,
        'is_sellable' => true,
        'is_purchasable' => true,
    ]);

    $productB = Product::factory()->create([
        'business_id' => $business->id,
        'name' => 'Peak Milk',
    ]);

    $unitB = ProductUnit::factory()->create([
        'business_id' => $business->id,
        'product_id' => $productB->id,
        'quantity' => 1,
        'cost_price' => 700,
        'selling_price' => 1200,
        'is_base_unit' => true,
        'is_sellable' => true,
        'is_purchasable' => true,
    ]);

    /*
     * Coca-Cola: 50 units sold
     */
    $saleOne = Sale::create([
        'business_id' => $business->id,
        'cashier_id' => $user->id,
        'subtotal' => 50000,
        'discount' => 0,
        'tax' => 0,
        'total' => 50000,
    ]);

    SaleItem::create([
        'sale_id' => $saleOne->id,
        'product_id' => $productA->id,
        'product_unit_id' => $unitA->id,
        'quantity' => 50,
        'unit_price' => 1000,
        'unit_cost' => 600,
        'discount' => 0,
        'total' => 50000,
    ]);

    /*
     * Peak Milk: 20 units sold
     */
    $saleTwo = Sale::create([
        'business_id' => $business->id,
        'cashier_id' => $user->id,
        'subtotal' => 24000,
        'discount' => 0,
        'tax' => 0,
        'total' => 24000,
    ]);

    SaleItem::create([
        'sale_id' => $saleTwo->id,
        'product_id' => $productB->id,
        'product_unit_id' => $unitB->id,
        'quantity' => 20,
        'unit_price' => 1200,
        'unit_cost' => 700,
        'discount' => 0,
        'total' => 24000,
    ]);

    /*
     * Another Coca-Cola sale: 30 units.
     *
     * Coca-Cola total = 80 units.
     */
    $saleThree = Sale::create([
        'business_id' => $business->id,
        'cashier_id' => $user->id,
        'subtotal' => 30000,
        'discount' => 0,
        'tax' => 0,
        'total' => 30000,
    ]);

    SaleItem::create([
        'sale_id' => $saleThree->id,
        'product_id' => $productA->id,
        'product_unit_id' => $unitA->id,
        'quantity' => 30,
        'unit_price' => 1000,
        'unit_cost' => 600,
        'discount' => 0,
        'total' => 30000,
    ]);

    $analytics = app(SalesAnalyticsService::class)
        ->topSellingProducts($business);

    $this->assertCount(
        2,
        $analytics
    );

    /*
     * Coca-Cola should be #1:
     *
     * 50 + 30 = 80 units
     */
    $this->assertEquals(
        $productA->id,
        $analytics[0]['product_id']
    );

    $this->assertEquals(
        'Coca-Cola',
        $analytics[0]['product_name']
    );

    $this->assertEquals(
        80,
        $analytics[0]['units_sold']
    );

    $this->assertEquals(
        80000,
        $analytics[0]['revenue']
    );

    /*
     * Peak Milk should be #2:
     *
     * 20 units
     */
    $this->assertEquals(
        $productB->id,
        $analytics[1]['product_id']
    );

    $this->assertEquals(
        'Peak Milk',
        $analytics[1]['product_name']
    );

    $this->assertEquals(
        20,
        $analytics[1]['units_sold']
    );

    $this->assertEquals(
        24000,
        $analytics[1]['revenue']
    );
}

public function test_top_selling_products_respects_date_range(): void
{
    $business = Business::factory()->create();

    $user = User::factory()->create();

    $productA = Product::factory()->create([
        'business_id' => $business->id,
        'name' => 'Coca-Cola',
    ]);

    $unitA = ProductUnit::factory()->create([
        'business_id' => $business->id,
        'product_id' => $productA->id,
        'quantity' => 1,
        'cost_price' => 600,
        'selling_price' => 1000,
        'is_base_unit' => true,
        'is_sellable' => true,
        'is_purchasable' => true,
    ]);

    $productB = Product::factory()->create([
        'business_id' => $business->id,
        'name' => 'Peak Milk',
    ]);

    $unitB = ProductUnit::factory()->create([
        'business_id' => $business->id,
        'product_id' => $productB->id,
        'quantity' => 1,
        'cost_price' => 700,
        'selling_price' => 1200,
        'is_base_unit' => true,
        'is_sellable' => true,
        'is_purchasable' => true,
    ]);

    $productC = Product::factory()->create([
        'business_id' => $business->id,
        'name' => 'Indomie',
    ]);

    $unitC = ProductUnit::factory()->create([
        'business_id' => $business->id,
        'product_id' => $productC->id,
        'quantity' => 1,
        'cost_price' => 500,
        'selling_price' => 800,
        'is_base_unit' => true,
        'is_sellable' => true,
        'is_purchasable' => true,
    ]);

    /*
     * Coca-Cola — inside range.
     */
    $saleA = Sale::create([
        'business_id' => $business->id,
        'cashier_id' => $user->id,
        'subtotal' => 50000,
        'discount' => 0,
        'tax' => 0,
        'total' => 50000,
    ]);

    $saleA->forceFill([
        'created_at' => '2026-08-05 12:00:00',
        'updated_at' => '2026-08-05 12:00:00',
    ])->saveQuietly();

    SaleItem::create([
        'sale_id' => $saleA->id,
        'product_id' => $productA->id,
        'product_unit_id' => $unitA->id,
        'quantity' => 50,
        'unit_price' => 1000,
        'unit_cost' => 600,
        'discount' => 0,
        'total' => 50000,
    ]);

    /*
     * Peak Milk — inside range.
     */
    $saleB = Sale::create([
        'business_id' => $business->id,
        'cashier_id' => $user->id,
        'subtotal' => 24000,
        'discount' => 0,
        'tax' => 0,
        'total' => 24000,
    ]);

    $saleB->forceFill([
        'created_at' => '2026-08-10 12:00:00',
        'updated_at' => '2026-08-10 12:00:00',
    ])->saveQuietly();

    SaleItem::create([
        'sale_id' => $saleB->id,
        'product_id' => $productB->id,
        'product_unit_id' => $unitB->id,
        'quantity' => 20,
        'unit_price' => 1200,
        'unit_cost' => 700,
        'discount' => 0,
        'total' => 24000,
    ]);

    /*
     * Indomie — outside range.
     *
     * Even though it sold more units,
     * it must NOT appear in the result.
     */
    $saleC = Sale::create([
        'business_id' => $business->id,
        'cashier_id' => $user->id,
        'subtotal' => 80000,
        'discount' => 0,
        'tax' => 0,
        'total' => 80000,
    ]);

    $saleC->forceFill([
        'created_at' => '2026-08-20 12:00:00',
        'updated_at' => '2026-08-20 12:00:00',
    ])->saveQuietly();

    SaleItem::create([
        'sale_id' => $saleC->id,
        'product_id' => $productC->id,
        'product_unit_id' => $unitC->id,
        'quantity' => 100,
        'unit_price' => 800,
        'unit_cost' => 500,
        'discount' => 0,
        'total' => 80000,
    ]);

    $analytics = app(SalesAnalyticsService::class)
        ->topSellingProducts(
            $business,
            10,
            '2026-08-01',
            '2026-08-15'
        );

    $this->assertCount(
        2,
        $analytics
    );

    $this->assertEquals(
        'Coca-Cola',
        $analytics[0]['product_name']
    );

    $this->assertEquals(
        50,
        $analytics[0]['units_sold']
    );

    $this->assertEquals(
        'Peak Milk',
        $analytics[1]['product_name']
    );

    $this->assertEquals(
        20,
        $analytics[1]['units_sold']
    );

    /*
     * Indomie sold 100 units, but its sale was
     * outside the requested date range.
     */
    $this->assertNotContains(
        'Indomie',
        array_column($analytics, 'product_name')
    );
}

public function test_sales_analytics_returns_product_profitability(): void
{
    $business = Business::factory()->create();

    $user = User::factory()->create();

    $productA = Product::factory()->create([
        'business_id' => $business->id,
        'name' => 'Coca-Cola',
    ]);

    $unitA = ProductUnit::factory()->create([
        'business_id' => $business->id,
        'product_id' => $productA->id,
        'quantity' => 1,
        'cost_price' => 600,
        'selling_price' => 1000,
        'is_base_unit' => true,
        'is_sellable' => true,
        'is_purchasable' => true,
    ]);

    $productB = Product::factory()->create([
        'business_id' => $business->id,
        'name' => 'Peak Milk',
    ]);

    $unitB = ProductUnit::factory()->create([
        'business_id' => $business->id,
        'product_id' => $productB->id,
        'quantity' => 1,
        'cost_price' => 700,
        'selling_price' => 1200,
        'is_base_unit' => true,
        'is_sellable' => true,
        'is_purchasable' => true,
    ]);

    /*
     * Coca-Cola:
     *
     * 50 × ₦1,000 = ₦50,000 revenue
     * 50 × ₦600   = ₦30,000 cost
     * Gross profit = ₦20,000
     */
    $saleA = Sale::create([
        'business_id' => $business->id,
        'cashier_id' => $user->id,
        'subtotal' => 50000,
        'discount' => 0,
        'tax' => 0,
        'total' => 50000,
    ]);

    SaleItem::create([
        'sale_id' => $saleA->id,
        'product_id' => $productA->id,
        'product_unit_id' => $unitA->id,
        'quantity' => 50,
        'unit_price' => 1000,
        'unit_cost' => 600,
        'discount' => 0,
        'total' => 50000,
    ]);

    /*
     * Peak Milk:
     *
     * 20 × ₦1,200 = ₦24,000 revenue
     * 20 × ₦700   = ₦14,000 cost
     * Gross profit = ₦10,000
     */
    $saleB = Sale::create([
        'business_id' => $business->id,
        'cashier_id' => $user->id,
        'subtotal' => 24000,
        'discount' => 0,
        'tax' => 0,
        'total' => 24000,
    ]);

    SaleItem::create([
        'sale_id' => $saleB->id,
        'product_id' => $productB->id,
        'product_unit_id' => $unitB->id,
        'quantity' => 20,
        'unit_price' => 1200,
        'unit_cost' => 700,
        'discount' => 0,
        'total' => 24000,
    ]);

    $analytics = app(SalesAnalyticsService::class)
        ->productProfitability($business);

    $this->assertCount(
        2,
        $analytics
    );

    /*
     * Coca-Cola should be #1 by gross profit.
     */
    $this->assertEquals(
        $productA->id,
        $analytics[0]['product_id']
    );

    $this->assertEquals(
        'Coca-Cola',
        $analytics[0]['product_name']
    );

    $this->assertEquals(
        50,
        $analytics[0]['units_sold']
    );

    $this->assertEquals(
        50000,
        $analytics[0]['revenue']
    );

    $this->assertEquals(
        30000,
        $analytics[0]['cogs']
    );

    $this->assertEquals(
        20000,
        $analytics[0]['gross_profit']
    );

    $this->assertEquals(
        40,
        $analytics[0]['gross_margin']
    );

    /*
     * Peak Milk should be #2.
     */
    $this->assertEquals(
        $productB->id,
        $analytics[1]['product_id']
    );

    $this->assertEquals(
        'Peak Milk',
        $analytics[1]['product_name']
    );

    $this->assertEquals(
        20,
        $analytics[1]['units_sold']
    );

    $this->assertEquals(
        24000,
        $analytics[1]['revenue']
    );

    $this->assertEquals(
        14000,
        $analytics[1]['cogs']
    );

    $this->assertEquals(
        10000,
        $analytics[1]['gross_profit']
    );

    $this->assertEquals(
        41.6667,
        $analytics[1]['gross_margin']
    );
}
}
