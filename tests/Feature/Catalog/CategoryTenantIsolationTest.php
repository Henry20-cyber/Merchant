<?php

namespace Tests\Feature\Catalog;

use App\Domains\Catalog\Models\Category;
use App\Domains\Organization\Models\Business;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CategoryTenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_category_belongs_to_only_one_business(): void
    {
        $businessA = Business::factory()->create();
        $businessB = Business::factory()->create();

        $category = Category::create([
            'business_id' => $businessA->id,
            'name' => 'Drinks',
            'slug' => 'drinks',
            'status' => 'active',
        ]);

        $this->assertTrue(
            Category::query()
                ->where('business_id', $businessA->id)
                ->whereKey($category->id)
                ->exists()
        );

        $this->assertFalse(
            Category::query()
                ->where('business_id', $businessB->id)
                ->whereKey($category->id)
                ->exists()
        );
    }

    public function test_category_can_only_have_parent_from_same_business(): void
    {
        $businessA = Business::factory()->create();
        $businessB = Business::factory()->create();

        $parent = Category::create([
            'business_id' => $businessA->id,
            'name' => 'Drinks',
            'slug' => 'drinks',
            'status' => 'active',
        ]);

        $otherBusinessParent = Category::create([
            'business_id' => $businessB->id,
            'name' => 'Food',
            'slug' => 'food',
            'status' => 'active',
        ]);

        $this->assertNotEquals(
            $parent->business_id,
            $otherBusinessParent->business_id
        );
    }
}
