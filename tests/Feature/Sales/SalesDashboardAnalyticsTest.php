<?php

namespace Tests\Feature\Sales;

use App\Domains\Organization\Models\Business;
use App\Domains\Product\Models\Product;
use App\Domains\Product\Models\ProductUnit;
use App\Domains\Service\Models\Service;
use App\Domains\Sales\Models\Sale;
use App\Domains\Sales\Models\SaleItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Carbon\Carbon;

class SalesDashboardAnalyticsTest extends TestCase
{
    use RefreshDatabase;

    private function createProduct(
        Business $business,
        string $name,
        float $sellingPrice = 1000
    ): array {
        $product = Product::factory()->create([
            'business_id' => $business->id,
            'name' => $name,
        ]);

        $unit = ProductUnit::factory()->create([
            'business_id' => $business->id,
            'product_id' => $product->id,
            'quantity' => 1,
            'cost_price' => $sellingPrice * 0.6,
            'selling_price' => $sellingPrice,
            'is_base_unit' => true,
            'is_sellable' => true,
            'is_purchasable' => true,
        ]);

        return [$product, $unit];
    }

   private function createSale(
    Business $business,
    User $cashier,
    array $items,
    ?string $createdAt = null
): Sale {
    $subtotal = collect($items)->sum(
        fn ($item) => $item['quantity'] * $item['unit_price']
    );

    $sale = new Sale();

    $sale->business_id = $business->id;
    $sale->cashier_id = $cashier->id;
    $sale->subtotal = $subtotal;
    $sale->discount = 0;
    $sale->tax = 0;
    $sale->total = $subtotal;
    $sale->payment_method = 'cash';
    $sale->payment_status = 'paid';
    $sale->status = 'completed';

    /*
     * Set created_at explicitly because it is not
     * mass assignable on the Sale model.
     */
    if ($createdAt !== null) {
        $sale->created_at = $createdAt;
    }

    $sale->save();

    foreach ($items as $item) {
        SaleItem::create([
            'sale_id' => $sale->id,
            'product_id' => $item['product_id'] ?? null,
            'product_unit_id' => $item['product_unit_id'] ?? null,
            'service_id' => $item['service_id'] ?? null,
            'quantity' => $item['quantity'],
            'unit_price' => $item['unit_price'],
            'unit_cost' => $item['unit_cost'] ?? 0,
            'discount' => 0,
            'total' => $item['quantity'] * $item['unit_price'],
        ]);
    }

    return $sale;
}

    public function test_dashboard_calculates_daily_revenue(): void
    {
        $business = Business::factory()->create();
        $cashier = User::factory()->create();

        [$product, $unit] = $this->createProduct(
            $business,
            'Coca-Cola',
            1000
        );

        $this->createSale(
            $business,
            $cashier,
            [
                [
                    'product_id' => $product->id,
                    'product_unit_id' => $unit->id,
                    'quantity' => 5,
                    'unit_price' => 1000,
                ],
            ],
            now()->setTime(10, 0)
        );

        $this->createSale(
            $business,
            $cashier,
            [
                [
                    'product_id' => $product->id,
                    'product_unit_id' => $unit->id,
                    'quantity' => 3,
                    'unit_price' => 1000,
                ],
            ],
            now()->setTime(14, 0)
        );

        $analytics = app(
            \App\Domains\Sales\Services\SalesAnalyticsService::class
        )->dashboard($business, now());

        $this->assertEquals(
            8000,
            $analytics['daily']['revenue']
        );
    }

    public function test_dashboard_calculates_weekly_revenue(): void
    {
        $business = Business::factory()->create();
        $cashier = User::factory()->create();

        [$product, $unit] = $this->createProduct(
            $business,
            'Peak Milk',
            1200
        );

        $this->createSale(
            $business,
            $cashier,
            [
                [
                    'product_id' => $product->id,
                    'product_unit_id' => $unit->id,
                    'quantity' => 5,
                    'unit_price' => 1200,
                ],
            ],
            now()->startOfWeek()->addDay()
        );

        $this->createSale(
            $business,
            $cashier,
            [
                [
                    'product_id' => $product->id,
                    'product_unit_id' => $unit->id,
                    'quantity' => 2,
                    'unit_price' => 1200,
                ],
            ],
            now()->startOfWeek()->addDays(3)
        );

        $analytics = app(
            \App\Domains\Sales\Services\SalesAnalyticsService::class
        )->dashboard($business, now());

        $this->assertEquals(
            8400,
            $analytics['weekly']['revenue']
        );
    }

    public function test_dashboard_calculates_monthly_revenue(): void
    {
        $business = Business::factory()->create();
        $cashier = User::factory()->create();

        [$product, $unit] = $this->createProduct(
            $business,
            'Bread',
            1500
        );

        $this->createSale(
            $business,
            $cashier,
            [
                [
                    'product_id' => $product->id,
                    'product_unit_id' => $unit->id,
                    'quantity' => 10,
                    'unit_price' => 1500,
                ],
            ],
            now()->startOfMonth()->addDay()
        );

        $analytics = app(
            \App\Domains\Sales\Services\SalesAnalyticsService::class
        )->dashboard($business, now());

        $this->assertEquals(
            15000,
            $analytics['monthly']['revenue']
        );
    }

    public function test_dashboard_returns_top_three_items_by_revenue(): void
    {
        $business = Business::factory()->create();
        $cashier = User::factory()->create();

        [$productA, $unitA] = $this->createProduct(
            $business,
            'Coca-Cola',
            1000
        );

        [$productB, $unitB] = $this->createProduct(
            $business,
            'Peak Milk',
            2000
        );

        [$productC, $unitC] = $this->createProduct(
            $business,
            'Bread',
            1500
        );

        [$productD, $unitD] = $this->createProduct(
            $business,
            'Biscuits',
            500
        );

        $this->createSale($business, $cashier, [
            [
                'product_id' => $productA->id,
                'product_unit_id' => $unitA->id,
                'quantity' => 10,
                'unit_price' => 1000,
            ],
        ]);

        $this->createSale($business, $cashier, [
            [
                'product_id' => $productB->id,
                'product_unit_id' => $unitB->id,
                'quantity' => 10,
                'unit_price' => 2000,
            ],
        ]);

        $this->createSale($business, $cashier, [
            [
                'product_id' => $productC->id,
                'product_unit_id' => $unitC->id,
                'quantity' => 10,
                'unit_price' => 1500,
            ],
        ]);

        $this->createSale($business, $cashier, [
            [
                'product_id' => $productD->id,
                'product_unit_id' => $unitD->id,
                'quantity' => 100,
                'unit_price' => 500,
            ],
        ]);

        $analytics = app(
            \App\Domains\Sales\Services\SalesAnalyticsService::class
        )->dashboard($business, now());

        $top = $analytics['top_items'];

        $this->assertCount(3, $top);

        $this->assertEquals(
            'Biscuits',
            $top[0]['name']
        );

        $this->assertEquals(
            50000,
            $top[0]['revenue']
        );

        $this->assertEquals(
            'Peak Milk',
            $top[1]['name']
        );

        $this->assertEquals(
            20000,
            $top[1]['revenue']
        );

        $this->assertEquals(
            'Bread',
            $top[2]['name']
        );

        $this->assertEquals(
            15000,
            $top[2]['revenue']
        );
    }

   public function test_dashboard_returns_top_items_for_each_period(): void
{
    $business = Business::factory()->create();
    $cashier = User::factory()->create();

    [$dailyProduct, $dailyUnit] = $this->createProduct(
        $business,
        'Daily Best',
        2000
    );

    [$weeklyProduct, $weeklyUnit] = $this->createProduct(
        $business,
        'Weekly Best',
        3000
    );

    [$monthlyProduct, $monthlyUnit] = $this->createProduct(
        $business,
        'Monthly Best',
        4000
    );

    /*
     * Use a fixed date.
     *
     * June 17, 2026 is a Wednesday.
     *
     * This makes the test independent of the actual
     * date on which PHPUnit is executed.
     */
    $today = Carbon::create(
        2026,
        6,
        17,
        10,
        0,
        0,
        config('app.timezone')
    );

    /*
     * ---------------------------------------------------------
     * DAILY
     * ---------------------------------------------------------
     *
     * Wednesday, June 17.
     */
    $dailyDate = $today->copy();

    $this->createSale(
        $business,
        $cashier,
        [
            [
                'product_id' => $dailyProduct->id,
                'product_unit_id' => $dailyUnit->id,
                'quantity' => 10,
                'unit_price' => 2000,
            ],
        ],
        $dailyDate
    );

    /*
     * ---------------------------------------------------------
     * WEEKLY
     * ---------------------------------------------------------
     *
     * Monday, June 15.
     *
     * This belongs to the same week as June 17,
     * but is not part of the daily period.
     */
    $weeklyDate = Carbon::create(
        2026,
        6,
        15,
        12,
        0,
        0,
        config('app.timezone')
    );

    $this->createSale(
        $business,
        $cashier,
        [
            [
                'product_id' => $weeklyProduct->id,
                'product_unit_id' => $weeklyUnit->id,
                'quantity' => 10,
                'unit_price' => 3000,
            ],
        ],
        $weeklyDate
    );

    /*
     * ---------------------------------------------------------
     * MONTHLY
     * ---------------------------------------------------------
     *
     * Monday, June 1.
     *
     * This belongs to June but is outside the current
     * week of June 15-21.
     */
    $monthlyDate = Carbon::create(
        2026,
        6,
        1,
        12,
        0,
        0,
        config('app.timezone')
    );

    $this->createSale(
        $business,
        $cashier,
        [
            [
                'product_id' => $monthlyProduct->id,
                'product_unit_id' => $monthlyUnit->id,
                'quantity' => 10,
                'unit_price' => 4000,
            ],
        ],
        $monthlyDate
    );

    /*
     * ---------------------------------------------------------
     * RUN ANALYTICS
     * ---------------------------------------------------------
     */
    $analytics = app(
        \App\Domains\Sales\Services\SalesAnalyticsService::class
    )->dashboard(
        $business,
        $today
    );

    /*
     * ---------------------------------------------------------
     * DAILY ASSERTIONS
     * ---------------------------------------------------------
     */
    $this->assertNotEmpty(
        $analytics['daily']['top_items']
    );

    $this->assertEquals(
        'Daily Best',
        $analytics['daily']['top_items'][0]['name']
    );

    $this->assertEquals(
        'product',
        $analytics['daily']['top_items'][0]['item_type']
    );

    /*
     * ---------------------------------------------------------
     * WEEKLY ASSERTIONS
     * ---------------------------------------------------------
     */
    $this->assertNotEmpty(
        $analytics['weekly']['top_items']
    );

    $this->assertEquals(
        'Weekly Best',
        $analytics['weekly']['top_items'][0]['name']
    );

    $this->assertEquals(
        'product',
        $analytics['weekly']['top_items'][0]['item_type']
    );

    /*
     * ---------------------------------------------------------
     * MONTHLY ASSERTIONS
     * ---------------------------------------------------------
     */
    $this->assertNotEmpty(
        $analytics['monthly']['top_items']
    );

    $this->assertEquals(
        'Monthly Best',
        $analytics['monthly']['top_items'][0]['name']
    );

    $this->assertEquals(
        'product',
        $analytics['monthly']['top_items'][0]['item_type']
    );
}

    public function test_dashboard_is_scoped_to_business(): void
    {
        $businessA = Business::factory()->create();
        $businessB = Business::factory()->create();

        $cashierA = User::factory()->create();
        $cashierB = User::factory()->create();

        [$productA, $unitA] = $this->createProduct(
            $businessA,
            'Coca-Cola',
            1000
        );

        [$productB, $unitB] = $this->createProduct(
            $businessB,
            'Coca-Cola',
            1000
        );

        $this->createSale($businessA, $cashierA, [
            [
                'product_id' => $productA->id,
                'product_unit_id' => $unitA->id,
                'quantity' => 5,
                'unit_price' => 1000,
            ],
        ]);

        $this->createSale($businessB, $cashierB, [
            [
                'product_id' => $productB->id,
                'product_unit_id' => $unitB->id,
                'quantity' => 100,
                'unit_price' => 1000,
            ],
        ]);

        $analytics = app(
            \App\Domains\Sales\Services\SalesAnalyticsService::class
        )->dashboard($businessA, now());

        $this->assertEquals(
            5000,
            $analytics['daily']['revenue']
        );

        $this->assertEquals(
            'Coca-Cola',
            $analytics['top_items'][0]['name']
        );

        $this->assertEquals(
            5000,
            $analytics['top_items'][0]['revenue']
        );
    }
}
