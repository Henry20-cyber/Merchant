<?php

namespace Tests\Feature\Services;

use App\Domains\Catalog\Models\Category;
use App\Domains\Identity\Services\RoleService;
use App\Domains\Organization\Models\Business;
use App\Domains\Organization\Models\BusinessUser;
use App\Domains\Service\Models\Service;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Tests\Support\CreatesSubscriptionForBusiness;

class ServiceApiTest extends TestCase
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

    public function test_owner_can_create_service(): void
    {
        [, $business] = $this->ownerWithBusiness();

        $response = $this->withBusiness($business)
            ->postJson(
                '/api/businesses/current/services',
                [
                    'name' => 'Hair Braiding',
                    'description' => 'Professional hair braiding',
                    'price' => 15000,
                ]
            );

        $response
            ->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath(
                'service.name',
                'Hair Braiding'
            );

        $this->assertDatabaseHas('services', [
            'business_id' => $business->id,
            'name' => 'Hair Braiding',
        ]);
    }

    public function test_owner_can_create_service_with_category(): void
    {
        [, $business] = $this->ownerWithBusiness();

        $category = Category::create([
            'business_id' => $business->id,
            'name' => 'Hair Services',
            'slug' => 'hair-services',
            'status' => 'active',
        ]);

        $response = $this->withBusiness($business)
            ->postJson(
                '/api/businesses/current/services',
                [
                    'name' => 'Hair Braiding',
                    'price' => 15000,
                    'category_id' => $category->id,
                ]
            );

        $response
            ->assertCreated()
            ->assertJsonPath(
                'service.category.id',
                $category->id
            );

        $this->assertDatabaseHas('services', [
            'business_id' => $business->id,
            'category_id' => $category->id,
            'name' => 'Hair Braiding',
        ]);
    }

    public function test_service_cannot_use_category_from_another_business(): void
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
            ->postJson(
                '/api/businesses/current/services',
                [
                    'name' => 'Hair Braiding',
                    'price' => 15000,
                    'category_id' => $category->id,
                ]
            );

        $response
            ->assertStatus(422)
            ->assertJsonValidationErrors('category_id');
    }

    public function test_owner_can_list_services(): void
    {
        [, $business] = $this->ownerWithBusiness();

        Service::factory()->create([
            'business_id' => $business->id,
            'name' => 'Hair Braiding',
        ]);

        $response = $this->withBusiness($business)
            ->getJson(
                '/api/businesses/current/services'
            );

        $response
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonFragment([
                'name' => 'Hair Braiding',
            ]);
    }

    public function test_service_from_another_business_cannot_be_viewed(): void
    {
        [, $business] = $this->ownerWithBusiness();

        $otherBusiness = $this->createBusinessWithSubscription();

        $service = Service::factory()->create([
            'business_id' => $otherBusiness->id,
            'name' => 'Private Service',
        ]);

        $response = $this->withBusiness($business)
            ->getJson(
                "/api/businesses/current/services/{$service->id}"
            );

        $response->assertNotFound();
    }

    public function test_owner_can_update_service_category(): void
    {
        [, $business] = $this->ownerWithBusiness();

        $categoryA = Category::create([
            'business_id' => $business->id,
            'name' => 'Hair',
            'slug' => 'hair',
            'status' => 'active',
        ]);

        $categoryB = Category::create([
            'business_id' => $business->id,
            'name' => 'Beauty',
            'slug' => 'beauty',
            'status' => 'active',
        ]);

        $service = Service::factory()->create([
            'business_id' => $business->id,
            'category_id' => $categoryA->id,
        ]);

        $response = $this->withBusiness($business)
            ->putJson(
                "/api/businesses/current/services/{$service->id}",
                [
                    'category_id' => $categoryB->id,
                ]
            );

        $response
            ->assertOk()
            ->assertJsonPath(
                'service.category.id',
                $categoryB->id
            );
    }

    public function test_cashier_cannot_create_service(): void
    {
        [, $business] = $this->ownerWithBusiness();

        $cashier = User::factory()->create();

        BusinessUser::create([
            'business_id' => $business->id,
            'user_id' => $cashier->id,
            'status' => 'active',
            'joined_at' => now(),
        ]);

        $this->actingAs($cashier);

        app(
            \App\Domains\Organization\Services\MerchantOSTeamResolver::class
        )->setPermissionsTeamId($business->id);

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
                '/api/businesses/current/services',
                [
                    'name' => 'Private Service',
                    'price' => 10000,
                ]
            );

        $response->assertForbidden();
    }

    public function test_owner_can_delete_service(): void
    {
        [, $business] = $this->ownerWithBusiness();

        $service = Service::factory()->create([
            'business_id' => $business->id,
        ]);

        $response = $this->withBusiness($business)
            ->deleteJson(
                "/api/businesses/current/services/{$service->id}"
            );

        $response
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertSoftDeleted('services', [
            'id' => $service->id,
        ]);
    }

    
}
