<?php

namespace Tests\Feature\Inventory;

use App\Domains\Inventory\Services\StockService;
use App\Domains\Organization\Models\Business;
use App\Domains\Product\Models\Product;
use App\Domains\Product\Models\ProductUnit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StockTest extends TestCase
{
    use RefreshDatabase;

    private function createProductWithBaseUnit(
        Business $business
    ): array {
        $product = Product::factory()->create([
            'business_id' => $business->id,
        ]);

        $unit = ProductUnit::factory()->create([
            'business_id' => $business->id,
            'product_id' => $product->id,
            'name' => 'Piece',
            'quantity' => 1,
            'is_base_unit' => true,
        ]);

        return [$product, $unit];
    }

    public function test_stock_can_be_created_for_a_product_unit(): void
    {
        $business = Business::factory()->create();

        [$product, $unit] = $this->createProductWithBaseUnit(
            $business
        );

        $stock = app(StockService::class)->createStock(
            $business,
            $product,
            $unit
        );

        $this->assertDatabaseHas('stocks', [
            'id' => $stock->id,
            'business_id' => $business->id,
            'product_id' => $product->id,
            'product_unit_id' => $unit->id,
            'quantity' => 0,
            'reorder_level' => 0,
        ]);
    }

    public function test_stock_belongs_to_the_correct_business(): void
    {
        $businessA = Business::factory()->create();
        $businessB = Business::factory()->create();

        [$product, $unit] = $this->createProductWithBaseUnit(
            $businessA
        );

        $stock = app(StockService::class)->createStock(
            $businessA,
            $product,
            $unit
        );

        $this->assertEquals(
            $businessA->id,
            $stock->business_id
        );

        $this->assertNotEquals(
            $businessB->id,
            $stock->business_id
        );
    }

    public function test_duplicate_stock_for_same_unit_is_rejected(): void
    {
        $business = Business::factory()->create();

        [$product, $unit] = $this->createProductWithBaseUnit(
            $business
        );

        $service = app(StockService::class);

        $service->createStock(
            $business,
            $product,
            $unit
        );

        $this->expectException(\Throwable::class);

        $service->createStock(
            $business,
            $product,
            $unit
        );
    }

    public function test_stock_cannot_be_created_for_unit_from_another_business(): void
    {
        $businessA = Business::factory()->create();
        $businessB = Business::factory()->create();

        [$product, $unit] = $this->createProductWithBaseUnit(
            $businessA
        );

        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);

        app(StockService::class)->createStock(
            $businessB,
            $product,
            $unit
        );
    }
}
