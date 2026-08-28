<?php

namespace Tests\Feature\Sales;

use App\Domains\Organization\Models\Business;
use App\Domains\Product\Models\Product;
use App\Domains\Product\Models\ProductUnit;
use App\Domains\Sales\Models\Sale;
use App\Domains\Sales\Models\SaleItem;
use App\Domains\Sales\Services\SalesAnalyticsService;
use App\Domains\Service\Models\Service;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SalesDashboardMetricsTest extends TestCase
{
    use RefreshDatabase;

    private function createProduct(
        Business $business,
        string $name,
        float $sellingPrice
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

    private function createService(
        Business $business,
        string $name,
        float $price
    ): Service {
        return Service::factory()->create([
            'business_id' => $business->id,
            'name' => $name,
            'price' => $price,
            'is_active' => true,
        ]);
    }

    private function createSale(
        Business $business,
        User $cashier,
        array $items,
        Carbon $createdAt
    ): Sale {
        $subtotal = collect($items)->sum(
            fn ($item) =>
                $item['quantity'] * $item['unit_price']
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
        $sale->created_at = $createdAt;

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
                'total' =>
                    $item['quantity'] * $item['unit_price'],
            ]);
        }

        return $sale;
    }

    public function test_dashboard_calculates_transaction_counts(): void
    {
        $business = Business::factory()->create();
        $cashier = User::factory()->create();

        [$product, $unit] = $this->createProduct(
            $business,
            'Coca-Cola',
            1000
        );

        $today = Carbon::create(
            2026,
            6,
            17,
            10,
            0,
            0,
            'UTC'
        );

        $this->createSale(
            $business,
            $cashier,
            [[
                'product_id' => $product->id,
                'product_unit_id' => $unit->id,
                'quantity' => 5,
                'unit_price' => 1000,
            ]],
            $today
        );

        $this->createSale(
            $business,
            $cashier,
            [[
                'product_id' => $product->id,
                'product_unit_id' => $unit->id,
                'quantity' => 3,
                'unit_price' => 1000,
            ]],
            $today->copy()->addHour()
        );

        $analytics = app(
            SalesAnalyticsService::class
        )->dashboard($business, $today);

        $this->assertEquals(
            2,
            $analytics['daily']['transactions']
        );

        $this->assertEquals(
            2,
            $analytics['weekly']['transactions']
        );

        $this->assertEquals(
            2,
            $analytics['monthly']['transactions']
        );
    }

    public function test_dashboard_calculates_product_and_service_revenue_breakdown(): void
    {
        $business = Business::factory()->create();
        $cashier = User::factory()->create();

        [$product, $unit] = $this->createProduct(
            $business,
            'Laptop',
            100000
        );

        $service = $this->createService(
            $business,
            'Laptop Repair',
            50000
        );

        $today = Carbon::create(
            2026,
            6,
            17,
            10,
            0,
            0,
            'UTC'
        );

        $this->createSale(
            $business,
            $cashier,
            [[
                'product_id' => $product->id,
                'product_unit_id' => $unit->id,
                'quantity' => 2,
                'unit_price' => 100000,
            ]],
            $today
        );

        $this->createSale(
            $business,
            $cashier,
            [[
                'service_id' => $service->id,
                'quantity' => 1,
                'unit_price' => 50000,
            ]],
            $today
        );

        $analytics = app(
            SalesAnalyticsService::class
        )->dashboard($business, $today);

        $this->assertEquals(
            200000,
            $analytics['revenue_breakdown']['products']['revenue']
        );

        $this->assertEquals(
            50000,
            $analytics['revenue_breakdown']['services']['revenue']
        );

        $this->assertEquals(
            80.0,
            $analytics['revenue_breakdown']['products']['percentage']
        );

        $this->assertEquals(
            20.0,
            $analytics['revenue_breakdown']['services']['percentage']
        );
    }

    public function test_product_only_business_has_zero_service_revenue(): void
    {
        $business = Business::factory()->create();
        $cashier = User::factory()->create();

        [$product, $unit] = $this->createProduct(
            $business,
            'Rice',
            5000
        );

        $today = Carbon::create(
            2026,
            6,
            17,
            10,
            0,
            0,
            'UTC'
        );

        $this->createSale(
            $business,
            $cashier,
            [[
                'product_id' => $product->id,
                'product_unit_id' => $unit->id,
                'quantity' => 2,
                'unit_price' => 5000,
            ]],
            $today
        );

        $analytics = app(
            SalesAnalyticsService::class
        )->dashboard($business, $today);

        $this->assertEquals(
            10000,
            $analytics['revenue_breakdown']['products']['revenue']
        );

        $this->assertEquals(
            0,
            $analytics['revenue_breakdown']['services']['revenue']
        );

        $this->assertEquals(
            100.0,
            $analytics['revenue_breakdown']['products']['percentage']
        );

        $this->assertEquals(
            0.0,
            $analytics['revenue_breakdown']['services']['percentage']
        );
    }

    public function test_service_only_business_has_zero_product_revenue(): void
    {
        $business = Business::factory()->create();
        $cashier = User::factory()->create();

        $service = $this->createService(
            $business,
            'Hair Braiding',
            15000
        );

        $today = Carbon::create(
            2026,
            6,
            17,
            10,
            0,
            0,
            'UTC'
        );

        $this->createSale(
            $business,
            $cashier,
            [[
                'service_id' => $service->id,
                'quantity' => 2,
                'unit_price' => 15000,
            ]],
            $today
        );

        $analytics = app(
            SalesAnalyticsService::class
        )->dashboard($business, $today);

        $this->assertEquals(
            0,
            $analytics['revenue_breakdown']['products']['revenue']
        );

        $this->assertEquals(
            30000,
            $analytics['revenue_breakdown']['services']['revenue']
        );

        $this->assertEquals(
            0.0,
            $analytics['revenue_breakdown']['products']['percentage']
        );

        $this->assertEquals(
            100.0,
            $analytics['revenue_breakdown']['services']['percentage']
        );
    }

    public function test_transaction_counts_are_scoped_to_business(): void
    {
        $businessA = Business::factory()->create();
        $businessB = Business::factory()->create();

        $cashierA = User::factory()->create();
        $cashierB = User::factory()->create();

        [$productA, $unitA] = $this->createProduct(
            $businessA,
            'Product A',
            1000
        );

        [$productB, $unitB] = $this->createProduct(
            $businessB,
            'Product B',
            1000
        );

        $today = Carbon::create(
            2026,
            6,
            17,
            10,
            0,
            0,
            'UTC'
        );

        $this->createSale(
            $businessA,
            $cashierA,
            [[
                'product_id' => $productA->id,
                'product_unit_id' => $unitA->id,
                'quantity' => 1,
                'unit_price' => 1000,
            ]],
            $today
        );

        $this->createSale(
            $businessB,
            $cashierB,
            [[
                'product_id' => $productB->id,
                'product_unit_id' => $unitB->id,
                'quantity' => 10,
                'unit_price' => 1000,
            ]],
            $today
        );

        $analytics = app(
            SalesAnalyticsService::class
        )->dashboard($businessA, $today);

        $this->assertEquals(
            1,
            $analytics['daily']['transactions']
        );

        $this->assertEquals(
            1000,
            $analytics['daily']['revenue']
        );
    }
}

