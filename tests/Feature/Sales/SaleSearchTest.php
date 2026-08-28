<?php

namespace Tests\Feature\Sales;

use App\Domains\Organization\Models\Business;
use App\Domains\Product\Models\Product;
use App\Domains\Product\Models\ProductUnit;
use App\Domains\Sales\Models\Sale;
use App\Domains\Sales\Models\SaleItem;
use App\Domains\Service\Models\Service;
use App\Domains\Sales\Services\SaleSearchService;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SaleSearchTest extends TestCase
{
    use RefreshDatabase;

    private function createSale(
        Business $business,
        User $cashier,
        Product $product,
        ProductUnit $unit,
        float $quantity,
        float $unitPrice
    ): Sale {
        $total = $quantity * $unitPrice;

        $sale = Sale::create([
            'business_id' => $business->id,
            'cashier_id' => $cashier->id,
            'subtotal' => $total,
            'discount' => 0,
            'tax' => 0,
            'total' => $total,
        ]);

        SaleItem::create([
            'sale_id' => $sale->id,
            'product_id' => $product->id,
            'product_unit_id' => $unit->id,
            'quantity' => $quantity,
            'unit_price' => $unitPrice,
            'unit_cost' => $unitPrice * 0.6,
            'discount' => 0,
            'total' => $total,
        ]);

        return $sale;
    }

    public function test_search_finds_sales_by_product_name(): void
    {
        $business = Business::factory()->create();

        $cashier = User::factory()->create([
            'name' => 'John Cashier',
        ]);

        $cocaCola = Product::factory()->create([
            'business_id' => $business->id,
            'name' => 'Coca-Cola',
        ]);

        $cocaUnit = ProductUnit::factory()->create([
            'business_id' => $business->id,
            'product_id' => $cocaCola->id,
            'quantity' => 1,
            'cost_price' => 600,
            'selling_price' => 1000,
            'is_base_unit' => true,
            'is_sellable' => true,
            'is_purchasable' => true,
        ]);

        $peakMilk = Product::factory()->create([
            'business_id' => $business->id,
            'name' => 'Peak Milk',
        ]);

        $peakUnit = ProductUnit::factory()->create([
            'business_id' => $business->id,
            'product_id' => $peakMilk->id,
            'quantity' => 1,
            'cost_price' => 700,
            'selling_price' => 1200,
            'is_base_unit' => true,
            'is_sellable' => true,
            'is_purchasable' => true,
        ]);

        $cocaSale = $this->createSale(
            $business,
            $cashier,
            $cocaCola,
            $cocaUnit,
            5,
            1000
        );

        $this->createSale(
            $business,
            $cashier,
            $peakMilk,
            $peakUnit,
            3,
            1200
        );

        $results = app(SaleSearchService::class)
            ->search($business, 'Coca-Cola');

        $this->assertCount(1, $results);

        $this->assertEquals(
            $cocaSale->id,
            $results[0]['sale_id']
        );

        $this->assertEquals(
            'product',
            $results[0]['item_type']
        );

        $this->assertEquals(
            'Coca-Cola',
            $results[0]['item_name']
        );

        $this->assertEquals(
            'John Cashier',
            $results[0]['cashier_name']
        );

        $this->assertEquals(
            5000,
            $results[0]['total']
        );
    }

    public function test_search_is_scoped_to_business(): void
    {
        $businessA = Business::factory()->create();
        $businessB = Business::factory()->create();

        $cashierA = User::factory()->create();
        $cashierB = User::factory()->create();

        $productA = Product::factory()->create([
            'business_id' => $businessA->id,
            'name' => 'Coca-Cola',
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
            'name' => 'Coca-Cola',
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

        $saleA = $this->createSale(
            $businessA,
            $cashierA,
            $productA,
            $unitA,
            5,
            1000
        );

        $this->createSale(
            $businessB,
            $cashierB,
            $productB,
            $unitB,
            100,
            1000
        );

        $results = app(SaleSearchService::class)
            ->search($businessA, 'Coca-Cola');

        $this->assertCount(1, $results);

        $this->assertEquals(
            $saleA->id,
            $results[0]['sale_id']
        );

        $this->assertEquals(
            5000,
            $results[0]['total']
        );
    }

    public function test_search_finds_sales_by_service_name(): void
{
    $business = Business::factory()->create();

    $cashier = User::factory()->create([
        'name' => 'Jane Cashier',
    ]);

    $service = Service::factory()->create([
        'business_id' => $business->id,
        'name' => 'Wig Installation',
        'price' => 10000,
        'is_active' => true,
    ]);

    $sale = Sale::create([
        'business_id' => $business->id,
        'cashier_id' => $cashier->id,
        'subtotal' => 10000,
        'discount' => 0,
        'tax' => 0,
        'total' => 10000,
    ]);

    SaleItem::create([
        'sale_id' => $sale->id,
        'service_id' => $service->id,
        'product_id' => null,
        'product_unit_id' => null,
        'quantity' => 1,
        'unit_price' => 10000,
        'unit_cost' => 0,
        'discount' => 0,
        'total' => 10000,
    ]);

    $results = app(SaleSearchService::class)
        ->search($business, 'Wig');

    $this->assertCount(1, $results);

    $this->assertEquals(
        $sale->id,
        $results[0]['sale_id']
    );

    $this->assertEquals(
        'service',
        $results[0]['item_type']
    );

    $this->assertEquals(
        'Wig Installation',
        $results[0]['item_name']
    );

    $this->assertEquals(
        'Jane Cashier',
        $results[0]['cashier_name']
    );

    $this->assertEquals(
        10000,
        $results[0]['total']
    );
}

public function test_search_finds_product_or_service_in_mixed_sale(): void
{
    $business = Business::factory()->create();

    $cashier = User::factory()->create();

    $product = Product::factory()->create([
        'business_id' => $business->id,
        'name' => 'Coca-Cola',
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

    $service = Service::factory()->create([
        'business_id' => $business->id,
        'name' => 'Wig Installation',
        'price' => 10000,
        'is_active' => true,
    ]);

    $sale = Sale::create([
        'business_id' => $business->id,
        'cashier_id' => $cashier->id,
        'subtotal' => 11000,
        'discount' => 0,
        'tax' => 0,
        'total' => 11000,
    ]);

    SaleItem::create([
        'sale_id' => $sale->id,
        'product_id' => $product->id,
        'product_unit_id' => $unit->id,
        'service_id' => null,
        'quantity' => 1,
        'unit_price' => 1000,
        'unit_cost' => 600,
        'discount' => 0,
        'total' => 1000,
    ]);

    SaleItem::create([
        'sale_id' => $sale->id,
        'product_id' => null,
        'product_unit_id' => null,
        'service_id' => $service->id,
        'quantity' => 1,
        'unit_price' => 10000,
        'unit_cost' => 0,
        'discount' => 0,
        'total' => 10000,
    ]);

    $productResults = app(SaleSearchService::class)
        ->search($business, 'Coca');

    $this->assertCount(1, $productResults);

    $this->assertEquals(
        'product',
        $productResults[0]['item_type']
    );

    $this->assertEquals(
        'Coca-Cola',
        $productResults[0]['item_name']
    );

    $serviceResults = app(SaleSearchService::class)
        ->search($business, 'Wig');

    $this->assertCount(1, $serviceResults);

    $this->assertEquals(
        'service',
        $serviceResults[0]['item_type']
    );

    $this->assertEquals(
        'Wig Installation',
        $serviceResults[0]['item_name']
    );
}
}


