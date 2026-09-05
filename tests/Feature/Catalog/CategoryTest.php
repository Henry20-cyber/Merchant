<?php

namespace Tests\Feature\Catalog;

use App\Domains\Catalog\Models\Category;
use App\Domains\Organization\Models\Business;
use App\Domains\Product\Models\Product;
use App\Domains\Service\Models\Service;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CategoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_category_belongs_to_business(): void
    {
        $business = Business::factory()->create();

        $category = Category::create([
            'business_id' => $business->id,
            'name' => 'Drinks',
            'slug' => 'drinks',
            'status' => 'active',
        ]);

        $this->assertEquals(
            $business->id,
            $category->business_id
        );

        $this->assertTrue(
            $category->business->is($business)
        );
    }

    public function test_category_can_have_children(): void
    {
        $business = Business::factory()->create();

        $parent = Category::create([
            'business_id' => $business->id,
            'name' => 'Drinks',
            'slug' => 'drinks',
            'status' => 'active',
        ]);

        $child = Category::create([
            'business_id' => $business->id,
            'parent_id' => $parent->id,
            'name' => 'Soft Drinks',
            'slug' => 'soft-drinks',
            'status' => 'active',
        ]);

        $this->assertTrue(
            $child->parent->is($parent)
        );

        $this->assertTrue(
            $parent->children->contains(
                fn (Category $category) => $category->is($child)
            )
        );
    }

    public function test_product_can_belong_to_category(): void
    {
        $business = Business::factory()->create();

        $category = Category::create([
            'business_id' => $business->id,
            'name' => 'Drinks',
            'slug' => 'drinks',
            'status' => 'active',
        ]);

        $product = Product::factory()->create([
            'business_id' => $business->id,
            'category_id' => $category->id,
        ]);

        $this->assertTrue(
            $product->category->is($category)
        );

        $this->assertTrue(
            $category->products->contains(
                fn (Product $item) => $item->is($product)
            )
        );
    }

    public function test_service_can_belong_to_category(): void
    {
        $business = Business::factory()->create();

        $category = Category::create([
            'business_id' => $business->id,
            'name' => 'Hair Services',
            'slug' => 'hair-services',
            'status' => 'active',
        ]);

        $service = Service::factory()->create([
            'business_id' => $business->id,
            'category_id' => $category->id,
        ]);

        $this->assertTrue(
            $service->category->is($category)
        );

        $this->assertTrue(
            $category->services->contains(
                fn (Service $item) => $item->is($service)
            )
        );
    }

    public function test_category_can_be_unused(): void
    {
        $business = Business::factory()->create();

        $category = Category::create([
            'business_id' => $business->id,
            'name' => 'Unused',
            'slug' => 'unused',
            'status' => 'active',
        ]);

        $this->assertCount(0, $category->products);
        $this->assertCount(0, $category->services);
    }
}
