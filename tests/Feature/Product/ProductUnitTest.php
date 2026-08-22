<?php

namespace Tests\Feature\Product;

use App\Domains\Organization\Models\Business;
use App\Domains\Product\Models\Product;
use App\Domains\Product\Models\ProductUnit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductUnitTest extends TestCase
{
    use RefreshDatabase;

    public function test_product_can_have_multiple_units(): void
    {
        $business = Business::factory()->create();

        $product = Product::factory()->create([
            'business_id' => $business->id,
        ]);

        $piece = ProductUnit::factory()
            ->base('Piece')
            ->create([
                'business_id' => $business->id,
                'product_id' => $product->id,
            ]);

        $carton = ProductUnit::factory()
            ->bulk('Carton', 12)
            ->create([
                'business_id' => $business->id,
                'product_id' => $product->id,
            ]);

        $this->assertCount(2, $product->units);

        $this->assertEquals(
            1,
            $piece->quantity
        );

        $this->assertEquals(
            12,
            $carton->quantity
        );
    }

    public function test_base_unit_has_quantity_of_one(): void
    {
        $business = Business::factory()->create();

        $product = Product::factory()->create([
            'business_id' => $business->id,
        ]);

        $unit = ProductUnit::factory()
            ->base()
            ->create([
                'business_id' => $business->id,
                'product_id' => $product->id,
            ]);

        $this->assertTrue(
            $unit->is_base_unit
        );

        $this->assertEquals(
            1,
            $unit->quantity
        );
    }

    public function test_bulk_unit_is_not_base_unit(): void
    {
        $business = Business::factory()->create();

        $product = Product::factory()->create([
            'business_id' => $business->id,
        ]);

        $unit = ProductUnit::factory()
            ->bulk('Carton', 12)
            ->create([
                'business_id' => $business->id,
                'product_id' => $product->id,
            ]);

        $this->assertFalse(
            $unit->is_base_unit
        );

        $this->assertEquals(
            12,
            $unit->quantity
        );
    }

    public function test_product_unit_belongs_to_the_same_business_as_product(): void
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
            $product->business_id,
            $unit->business_id
        );

        $this->assertTrue(
            $unit->product->is($product)
        );

        $this->assertTrue(
            $unit->business->is($business)
        );
    }
}
