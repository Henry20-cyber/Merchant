<?php

namespace Tests\Feature\Catalog;

use App\Domains\Identity\Services\RoleService;
use App\Domains\Organization\Models\Business;
use App\Domains\Organization\Models\BusinessUser;
use App\Domains\Catalog\Models\Category;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Tests\Support\CreatesSubscriptionForBusiness;

class CategoryApiTest extends TestCase
{
    use CreatesSubscriptionForBusiness;

    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PermissionSeeder::class);
    }

    private function ownerWithBusiness(): array
    {
        $business = $this->createBusinessWithSubscription();

        $owner = User::factory()->create();

        BusinessUser::create([
            'business_id' => $business->id,
            'user_id' => $owner->id,
            'status' => 'active',
            'joined_at' => now(),
        ]);

        $this->actingAs($owner);

        app(RoleService::class)->assignOwner(
            $owner,
            $business->id
        );

        return [$owner, $business];
    }

    private function withBusiness(Business $business)
    {
        return $this->withHeader(
            'X-Business-ID',
            $business->id
        );
    }

    public function test_owner_can_create_category(): void
    {
        [, $business] = $this->ownerWithBusiness();

        $response = $this->withBusiness($business)
            ->postJson(
                '/api/businesses/current/catalog/categories',
                [
                    'name' => 'Drinks',
                    'description' => 'All drinks',
                ]
            );

        $response
            ->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath(
                'category.name',
                'Drinks'
            )
            ->assertJsonPath(
                'category.slug',
                'drinks'
            );

        $this->assertDatabaseHas('categories', [
            'business_id' => $business->id,
            'name' => 'Drinks',
            'slug' => 'drinks',
        ]);
    }

    public function test_owner_can_create_nested_category(): void
    {
        [, $business] = $this->ownerWithBusiness();

        $parent = Category::create([
            'business_id' => $business->id,
            'name' => 'Drinks',
            'slug' => 'drinks',
            'status' => 'active',
        ]);

        $response = $this->withBusiness($business)
            ->postJson(
                '/api/businesses/current/catalog/categories',
                [
                    'name' => 'Soft Drinks',
                    'parent_id' => $parent->id,
                ]
            );

        $response
            ->assertCreated()
            ->assertJsonPath(
                'category.parent.id',
                $parent->id
            );
    }

    public function test_category_parent_must_belong_to_current_business(): void
    {
        [, $business] = $this->ownerWithBusiness();

        $otherBusiness = $this->createBusinessWithSubscription();

        $otherParent = Category::create([
            'business_id' => $otherBusiness->id,
            'name' => 'Food',
            'slug' => 'food',
            'status' => 'active',
        ]);

        $response = $this->withBusiness($business)
            ->postJson(
                '/api/businesses/current/catalog/categories',
                [
                    'name' => 'Drinks',
                    'parent_id' => $otherParent->id,
                ]
            );

        $response
            ->assertStatus(422)
            ->assertJsonValidationErrors('parent_id');
    }

    public function test_owner_can_list_only_current_business_categories(): void
    {
        [, $business] = $this->ownerWithBusiness();

        $otherBusiness = $this->createBusinessWithSubscription();

        Category::create([
            'business_id' => $business->id,
            'name' => 'Drinks',
            'slug' => 'drinks',
            'status' => 'active',
        ]);

        Category::create([
            'business_id' => $otherBusiness->id,
            'name' => 'Food',
            'slug' => 'food',
            'status' => 'active',
        ]);

        $response = $this->withBusiness($business)
            ->getJson(
                '/api/businesses/current/catalog/categories'
            );

        $response
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonCount(
                1,
                'categories'
            )
            ->assertJsonFragment([
                'name' => 'Drinks',
            ])
            ->assertJsonMissing([
                'name' => 'Food',
            ]);
    }

    public function test_business_cannot_access_another_business_category(): void
    {
        [, $business] = $this->ownerWithBusiness();

        $otherBusiness = $this->createBusinessWithSubscription();

        $category = Category::create([
            'business_id' => $otherBusiness->id,
            'name' => 'Private',
            'slug' => 'private',
            'status' => 'active',
        ]);

        $response = $this->withBusiness($business)
            ->getJson(
                "/api/businesses/current/catalog/categories/{$category->id}"
            );

        $response->assertNotFound();
    }

    public function test_owner_can_update_category(): void
    {
        [, $business] = $this->ownerWithBusiness();

        $category = Category::create([
            'business_id' => $business->id,
            'name' => 'Drinks',
            'slug' => 'drinks',
            'status' => 'active',
        ]);

        $response = $this->withBusiness($business)
            ->putJson(
                "/api/businesses/current/catalog/categories/{$category->id}",
                [
                    'name' => 'Beverages',
                ]
            );

        $response
            ->assertOk()
            ->assertJsonPath(
                'category.name',
                'Beverages'
            )
            ->assertJsonPath(
                'category.slug',
                'beverages'
            );
    }

    public function test_owner_cannot_delete_category_with_children(): void
    {
        [, $business] = $this->ownerWithBusiness();

        $parent = Category::create([
            'business_id' => $business->id,
            'name' => 'Drinks',
            'slug' => 'drinks',
            'status' => 'active',
        ]);

        Category::create([
            'business_id' => $business->id,
            'parent_id' => $parent->id,
            'name' => 'Soft Drinks',
            'slug' => 'soft-drinks',
            'status' => 'active',
        ]);

        $response = $this->withBusiness($business)
            ->deleteJson(
                "/api/businesses/current/catalog/categories/{$parent->id}"
            );

        $response
            ->assertStatus(422)
            ->assertJsonValidationErrors('category');
    }

    public function test_owner_can_delete_leaf_category(): void
    {
        [, $business] = $this->ownerWithBusiness();

        $category = Category::create([
            'business_id' => $business->id,
            'name' => 'Drinks',
            'slug' => 'drinks',
            'status' => 'active',
        ]);

        $response = $this->withBusiness($business)
            ->deleteJson(
                "/api/businesses/current/catalog/categories/{$category->id}"
            );

        $response
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertSoftDeleted('categories', [
            'id' => $category->id,
        ]);
    }

    public function test_cashier_cannot_create_category(): void
{
    [$owner, $business] = $this->ownerWithBusiness();

    $cashier = User::factory()->create();

    BusinessUser::create([
        'business_id' => $business->id,
        'user_id' => $cashier->id,
        'status' => 'active',
        'joined_at' => now(),
    ]);

    $this->actingAs($cashier);

    app(\App\Domains\Organization\Services\MerchantOSTeamResolver::class)
        ->setPermissionsTeamId($business->id);

    app(RoleService::class)->provisionBusinessRoles(
        $business->id
    );

    app(RoleService::class)->assignRole(
        $cashier,
        'Cashier',
        $business->id
    );

    $response = $this->withBusiness($business)
        ->postJson(
            '/api/businesses/current/catalog/categories',
            [
                'name' => 'Drinks',
            ]
        );

    $response->assertForbidden();

    $this->assertDatabaseMissing('categories', [
        'business_id' => $business->id,
        'name' => 'Drinks',
    ]);
}
}

