<?php

namespace Tests\Feature\Inventory;

use App\Domains\Inventory\Services\StockService;
use App\Domains\Organization\Models\Business;
use App\Domains\Organization\Models\Branch;
use App\Domains\Product\Models\Product;
use App\Domains\Product\Models\ProductUnit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StockTest extends TestCase
{
    use RefreshDatabase;

    private function branchFor(Business $business): Branch
    {
        return Branch::firstOrCreate(
            ['business_id' => $business->id, 'code' => 'MAIN-' . $business->id],
            ['name' => 'Main Branch', 'city' => 'Owerri', 'state' => 'Imo', 'country' => 'Nigeria', 'is_head_office' => true]
        );
    }

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

    public function test_stock_can_be_created_for_a_product(): void
    {
        $business = Business::factory()->create();

        [$product, $unit] = $this->createProductWithBaseUnit($business);

        $stock = app(StockService::class)->createStock(
            $business,
            $this->branchFor($business),
            $product
        );

        $this->assertDatabaseHas('stocks', [
            'id' => $stock->id,
            'business_id' => $business->id,
            'product_id' => $product->id,
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
            $this->branchFor($businessA),
            $product
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

    public function test_stock_creation_is_idempotent_for_same_branch_and_product(): void
    {
        $business = Business::factory()->create();

        [$product, $unit] = $this->createProductWithBaseUnit(
            $business
        );

        $service = app(StockService::class);
        $branch = $this->branchFor($business);

        $first = $service->createStock(
            $business,
            $branch,
            $product
        );

        $second = $service->createStock(
            $business,
            $branch,
            $product
        );

        $this->assertSame(
            $first->id,
            $second->id
        );

        $this->assertDatabaseCount('stocks', 1);
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
            $this->branchFor($businessB),
            $product
        );
    }
}
