<?php

namespace Tests\Feature\Product;

use App\Domains\Organization\Models\Business;
use App\Domains\Product\Models\Product;
use App\Domains\Product\Models\ProductUnit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductUnitTenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_product_unit_belongs_to_its_product_business(): void
    {
        $business = Business::factory()->create();

        $product = Product::factory()->create([
            'business_id' => $business->id,
        ]);

        $unit = ProductUnit::factory()->create([
            'business_id' => $business->id,
            'product_id' => $product->id,
        ]);

        $this->assertEquals(
            $business->id,
            $unit->business_id
        );

        $this->assertEquals(
            $business->id,
            $unit->product->business_id
        );
    }

    public function test_products_from_different_businesses_have_separate_units(): void
    {
        $businessA = Business::factory()->create();
        $businessB = Business::factory()->create();

        $productA = Product::factory()->create([
            'business_id' => $businessA->id,
            'name' => 'Gala',
        ]);

        $productB = Product::factory()->create([
            'business_id' => $businessB->id,
            'name' => 'Coke',
        ]);

        ProductUnit::factory()->create([
            'business_id' => $businessA->id,
            'product_id' => $productA->id,
            'name' => 'Piece',
        ]);

        ProductUnit::factory()->create([
            'business_id' => $businessA->id,
            'product_id' => $productA->id,
            'name' => 'Carton',
            'quantity' => 12,
            'is_base_unit' => false,
        ]);

        ProductUnit::factory()->create([
            'business_id' => $businessB->id,
            'product_id' => $productB->id,
            'name' => 'Bottle',
        ]);

        $businessAUnits = ProductUnit::query()
            ->where('business_id', $businessA->id)
            ->get();

        $this->assertCount(2, $businessAUnits);

        $this->assertTrue(
            $businessAUnits->every(
                fn (ProductUnit $unit) =>
                    $unit->product->business_id === $businessA->id
            )
        );
    }
}
