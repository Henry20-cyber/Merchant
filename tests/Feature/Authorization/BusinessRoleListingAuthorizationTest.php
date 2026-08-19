<?php

namespace Tests\Feature\Authorization;

use App\Domains\Identity\Services\RoleService;
use App\Domains\Identity\Support\PermissionCatalog;
use App\Domains\Organization\Models\Business;
use App\Domains\Organization\Models\BusinessUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class BusinessRoleListingAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_with_roles_view_permission_can_view_business_roles(): void
    {
        $business = Business::factory()->create();

        $user = $this->createBusinessMember($business);

        $this->createPermissions();

        $this->actingAs($user);

        setPermissionsTeamId($business->id);

        $roleService = app(RoleService::class);

        $roleService->provisionBusinessRoles(
            $business->id
        );

        $roleService->assignRole(
            $user,
            'Manager',
            $business->id
        );

        $response = $this->getJson(
            '/api/businesses/current/roles'
        );

        $response->assertOk();

        $response->assertJsonPath(
            'success',
            true
        );

        $response->assertJsonCount(
            4,
            'data'
        );
    }

    public function test_user_without_roles_view_permission_cannot_view_business_roles(): void
    {
        $business = Business::factory()->create();

        $user = $this->createBusinessMember($business);

        $this->createPermissions();

        $this->actingAs($user);

        setPermissionsTeamId($business->id);

        $response = $this->getJson(
            '/api/businesses/current/roles'
        );

        $response->assertForbidden();
    }

    public function test_roles_are_scoped_to_current_business(): void
    {
        $businessA = Business::factory()->create();
        $businessB = Business::factory()->create();

        $user = $this->createBusinessMember($businessA);

        $this->createPermissions();

        $this->actingAs($user);

        setPermissionsTeamId($businessA->id);

        $roleService = app(RoleService::class);

        $roleService->provisionBusinessRoles(
            $businessA->id
        );

        $roleService->provisionBusinessRoles(
            $businessB->id
        );

        $roleService->assignRole(
            $user,
            'Manager',
            $businessA->id
        );

        $response = $this->getJson(
            '/api/businesses/current/roles'
        );

        $response->assertOk();

        $response->assertJsonCount(
            4,
            'data'
        );

        $response->assertJsonMissing([
            'business_id' => $businessB->id,
        ]);
    }

    private function createBusinessMember(
        Business $business
    ): User {
        $user = User::factory()->create();

        BusinessUser::create([
            'business_id' => $business->id,
            'user_id' => $user->id,
            'status' => 'active',
            'joined_at' => now(),
        ]);

        return $user;
    }

    private function createPermissions(): void
    {
        foreach (PermissionCatalog::all() as $permission) {
            Permission::firstOrCreate([
                'name' => $permission,
                'guard_name' => 'web',
            ]);
        }
    }
}
