<?php

namespace Tests\Feature\Inventory;

use App\Domains\Inventory\Services\StockService;
use App\Domains\Organization\Models\Business;
use App\Domains\Organization\Models\Branch;
use App\Domains\Product\Models\Product;
use App\Domains\Product\Models\ProductUnit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StockMovementTest extends TestCase
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

    public function test_stock_can_be_received(): void
    {
        $business = Business::factory()->create();

        [$product, $unit] = $this->createProductWithBaseUnit(
            $business
        );

        $stock = app(StockService::class)->receive(
            $business,
            $this->branchFor($business),
            $product,
            $unit,
            50
        );

        $this->assertEquals(50, (float) $stock->quantity);

        $this->assertDatabaseHas('stock_movements', [
            'business_id' => $business->id,
            'product_id' => $product->id,
            'product_unit_id' => $unit->id,
            'stock_id' => $stock->id,
            'type' => 'receive',
            'quantity' => 50,
            'quantity_before' => 0,
            'quantity_after' => 50,
        ]);
    }

    public function test_stock_can_be_issued(): void
    {
        $business = Business::factory()->create();

        [$product, $unit] = $this->createProductWithBaseUnit(
            $business
        );

        $service = app(StockService::class);

        $stock = $service->receive(
            $business,
            $this->branchFor($business),
            $product,
            $unit,
            50
        );

        $stock = $service->issue(
            $business,
            $this->branchFor($business),
            $product,
            $unit,
            10
        );

        $this->assertEquals(40, (float) $stock->quantity);

        $this->assertDatabaseHas('stock_movements', [
            'stock_id' => $stock->id,
            'type' => 'sale',
            'quantity' => 10,
            'quantity_before' => 50,
            'quantity_after' => 40,
        ]);
    }

    public function test_stock_cannot_become_negative(): void
    {
        $business = Business::factory()->create();

        [$product, $unit] = $this->createProductWithBaseUnit(
            $business
        );

        $service = app(StockService::class);

        $service->receive(
            $business,
            $this->branchFor($business),
            $product,
            $unit,
            5
        );

        $this->expectException(
            \Illuminate\Validation\ValidationException::class
        );

        $service->issue(
            $business,
            $this->branchFor($business),
            $product,
            $unit,
            10
        );
    }

    public function test_stock_can_be_adjusted(): void
    {
        $business = Business::factory()->create();

        [$product, $unit] = $this->createProductWithBaseUnit(
            $business
        );

        $service = app(StockService::class);

        $service->receive(
            $business,
            $this->branchFor($business),
            $product,
            $unit,
            20
        );

        $stock = $service->adjust(
            $business,
            $this->branchFor($business),
            $product,
            $unit,
            -3,
            'Damaged goods'
        );

        $this->assertEquals(17, (float) $stock->quantity);

        $this->assertDatabaseHas('stock_movements', [
            'stock_id' => $stock->id,
            'type' => 'adjustment',
            'quantity' => -3,
            'quantity_before' => 20,
            'quantity_after' => 17,
            'note' => 'Damaged goods',
        ]);
    }

    public function test_stock_is_separate_per_branch(): void
    {
        $business = Business::factory()->create();

        [$product, $unit] = $this->createProductWithBaseUnit($business);

        $branchA = $this->branchFor($business);
        $branchB = Branch::create([
            'business_id' => $business->id,
            'name' => 'Second Branch',
            'code' => 'SECOND-' . $business->id,
            'city' => 'Port Harcourt',
            'state' => 'Rivers',
            'country' => 'Nigeria',
            'is_head_office' => false,
        ]);

        $service = app(StockService::class);

        $service->receive(
            $business,
            $branchA,
            $product,
            $unit,
            50
        );

        $service->receive(
            $business,
            $branchB,
            $product,
            $unit,
            20
        );

        $this->assertEquals(
            50,
            (float) $service->getStock($business, $branchA, $product, $unit)->quantity
        );

        $this->assertEquals(
            20,
            (float) $service->getStock($business, $branchB, $product, $unit)->quantity
        );
    }

    public function test_stock_can_be_transferred_between_branches_atomically(): void
    {
        $business = Business::factory()->create();

        [$product, $unit] = $this->createProductWithBaseUnit($business);

        $source = $this->branchFor($business);

        $destination = Branch::create([
            'business_id' => $business->id,
            'name' => 'Second Branch',
            'code' => 'SECOND-' . $business->id,
            'city' => 'Port Harcourt',
            'state' => 'Rivers',
            'country' => 'Nigeria',
            'is_head_office' => false,
        ]);

        $service = app(StockService::class);

        $service->receive(
            $business,
            $source,
            $product,
            $unit,
            100
        );

        $result = $service->transfer(
            $business,
            $source,
            $destination,
            $product,
            $unit,
            30,
            'Replenishment'
        );

        $this->assertEquals(70, (float) $result['source']->quantity);
        $this->assertEquals(30, (float) $result['destination']->quantity);

        $this->assertDatabaseHas('stock_movements', [
            'branch_id' => $source->id,
            'type' => 'transfer_out',
            'base_quantity' => -30,
        ]);

        $this->assertDatabaseHas('stock_movements', [
            'branch_id' => $destination->id,
            'type' => 'transfer_in',
            'base_quantity' => 30,
        ]);
    }

    public function test_stock_movement_cannot_cross_business_boundary(): void
    {
        $businessA = Business::factory()->create();
        $businessB = Business::factory()->create();

        [$product, $unit] = $this->createProductWithBaseUnit(
            $businessA
        );

        $this->expectException(
            \Symfony\Component\HttpKernel\Exception\HttpException::class
        );

        app(StockService::class)->receive(
            $businessB,
            $this->branchFor($businessB),
            $product,
            $unit,
            10
        );
    }
}
