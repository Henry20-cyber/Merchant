<?php

namespace Tests\Feature\Product;

use App\Domains\Organization\Models\Business;
use App\Domains\Product\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductTenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_product_belongs_to_the_correct_business(): void
    {
        $business = Business::factory()->create();

        $product = Product::factory()->create([
            'business_id' => $business->id,
            'name' => 'Coca-Cola 50cl',
        ]);

        $this->assertEquals(
            $business->id,
            $product->business_id
        );

        $this->assertTrue(
            $product->business->is($business)
        );
    }

    public function test_business_only_retrieves_its_own_products(): void
    {
        $businessA = Business::factory()->create();
        $businessB = Business::factory()->create();

        Product::factory()->create([
            'business_id' => $businessA->id,
            'name' => 'Coca-Cola 50cl',
        ]);

        Product::factory()->create([
            'business_id' => $businessB->id,
            'name' => 'Peak Milk',
        ]);

        $products = Product::query()
            ->where('business_id', $businessA->id)
            ->get();

        $this->assertCount(1, $products);

        $this->assertEquals(
            'Coca-Cola 50cl',
            $products->first()->name
        );

        $this->assertFalse(
            $products->contains(
                fn (Product $product) =>
                    $product->name === 'Peak Milk'
            )
        );
    }
}
