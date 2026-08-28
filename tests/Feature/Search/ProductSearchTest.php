<?php

namespace Tests\Feature\Search;

use App\Domains\Organization\Models\Business;
use App\Domains\Product\Models\Product;
use App\Domains\Product\Services\ProductSearchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductSearchTest extends TestCase
{
    use RefreshDatabase;

    public function test_search_finds_product_by_name(): void
    {
        $business = Business::factory()->create();

        Product::factory()->create([
            'business_id' => $business->id,
            'name' => 'Coca-Cola',
            'sku' => 'COCA-001',
        ]);

        Product::factory()->create([
            'business_id' => $business->id,
            'name' => 'Peak Milk',
            'sku' => 'PEAK-001',
        ]);

        $results = app(ProductSearchService::class)
            ->search($business, 'Coca');

        $this->assertCount(1, $results);

        $this->assertEquals(
            'product',
            $results[0]['type']
        );

        $this->assertEquals(
            'Coca-Cola',
            $results[0]['title']
        );

        $this->assertEquals(
            'COCA-001',
            $results[0]['subtitle']
        );
    }

    public function test_search_finds_product_by_sku(): void
    {
        $business = Business::factory()->create();

        Product::factory()->create([
            'business_id' => $business->id,
            'name' => 'Coca-Cola',
            'sku' => 'COCA-001',
        ]);

        $results = app(ProductSearchService::class)
            ->search($business, 'COCA-001');

        $this->assertCount(1, $results);

        $this->assertEquals(
            'Coca-Cola',
            $results[0]['title']
        );
    }

    public function test_search_finds_product_by_description(): void
    {
        $business = Business::factory()->create();

        Product::factory()->create([
            'business_id' => $business->id,
            'name' => 'Premium Wig',
            'description' => 'Brazilian human hair wig',
        ]);

        $results = app(ProductSearchService::class)
            ->search($business, 'Brazilian');

        $this->assertCount(1, $results);

        $this->assertEquals(
            'Premium Wig',
            $results[0]['title']
        );
    }

    public function test_search_is_scoped_to_business(): void
    {
        $businessA = Business::factory()->create();
        $businessB = Business::factory()->create();

        Product::factory()->create([
            'business_id' => $businessA->id,
            'name' => 'Coca-Cola',
        ]);

        Product::factory()->create([
            'business_id' => $businessB->id,
            'name' => 'Coca-Cola',
        ]);

        $results = app(ProductSearchService::class)
            ->search($businessA, 'Coca-Cola');

        $this->assertCount(1, $results);

        $this->assertDatabaseHas('products', [
            'business_id' => $businessA->id,
            'name' => 'Coca-Cola',
        ]);
    }

    public function test_empty_search_returns_no_results(): void
    {
        $business = Business::factory()->create();

        Product::factory()->create([
            'business_id' => $business->id,
            'name' => 'Coca-Cola',
        ]);

        $results = app(ProductSearchService::class)
            ->search($business, '   ');

        $this->assertSame([], $results);
    }
}
