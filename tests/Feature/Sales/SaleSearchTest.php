<?php

namespace Tests\Feature\Sales;

use App\Domains\Organization\Models\Business;
use App\Domains\Product\Models\Product;
use App\Domains\Product\Models\ProductUnit;
use App\Domains\Sales\Models\Sale;
use App\Domains\Sales\Models\SaleItem;
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
            'Coca-Cola',
            $results[0]['product_name']
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
}