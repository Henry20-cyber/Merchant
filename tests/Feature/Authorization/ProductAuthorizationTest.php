<?php

namespace Tests\Feature\Authorization;

use App\Domains\Identity\Services\RoleService;
use App\Domains\Organization\Models\Business;
use App\Domains\Organization\Models\BusinessUser;
use App\Domains\Organization\Services\MerchantOSTeamResolver;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PermissionSeeder::class);
    }

    public function test_owner_receives_product_management_permissions(): void
    {
        $business = Business::factory()->create();
        $owner = User::factory()->create();

        BusinessUser::create([
            'business_id' => $business->id,
            'user_id' => $owner->id,
            'status' => 'active',
            'joined_at' => now(),
        ]);

        $this->actingAs($owner);

        app(MerchantOSTeamResolver::class)
            ->setPermissionsTeamId($business->id);

        app(RoleService::class)->assignOwner(
            $owner,
            $business->id
        );

        $this->assertTrue(
            $owner->hasPermissionTo('products.view')
        );

        $this->assertTrue(
            $owner->hasPermissionTo('products.create')
        );

        $this->assertTrue(
            $owner->hasPermissionTo('products.update')
        );

        $this->assertTrue(
            $owner->hasPermissionTo('products.delete')
        );
    }

    public function test_manager_can_manage_but_not_delete_products(): void
    {
        $business = Business::factory()->create();
        $manager = User::factory()->create();

        BusinessUser::create([
            'business_id' => $business->id,
            'user_id' => $manager->id,
            'status' => 'active',
            'joined_at' => now(),
        ]);

        $this->actingAs($manager);

        app(MerchantOSTeamResolver::class)
            ->setPermissionsTeamId($business->id);

        app(RoleService::class)->provisionBusinessRoles(
            $business->id
        );

        app(RoleService::class)->assignRole(
            $manager,
            'Manager',
            $business->id
        );

        $this->assertTrue(
            $manager->hasPermissionTo('products.view')
        );

        $this->assertTrue(
            $manager->hasPermissionTo('products.create')
        );

        $this->assertTrue(
            $manager->hasPermissionTo('products.update')
        );

        $this->assertFalse(
            $manager->hasPermissionTo('products.delete')
        );
    }

    public function test_cashier_can_view_but_not_modify_products(): void
    {
        $business = Business::factory()->create();
        $cashier = User::factory()->create();

        BusinessUser::create([
            'business_id' => $business->id,
            'user_id' => $cashier->id,
            'status' => 'active',
            'joined_at' => now(),
        ]);

        $this->actingAs($cashier);

        app(MerchantOSTeamResolver::class)
            ->setPermissionsTeamId($business->id);

        app(RoleService::class)->provisionBusinessRoles(
            $business->id
        );

        app(RoleService::class)->assignRole(
            $cashier,
            'Cashier',
            $business->id
        );

        $this->assertTrue(
            $cashier->hasPermissionTo('products.view')
        );

        $this->assertFalse(
            $cashier->hasPermissionTo('products.create')
        );

        $this->assertFalse(
            $cashier->hasPermissionTo('products.update')
        );

        $this->assertFalse(
            $cashier->hasPermissionTo('products.delete')
        );
    }
}