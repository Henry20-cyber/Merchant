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

class BusinessRoleCreationAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_with_roles_create_permission_can_create_a_custom_role(): void
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
            'Owner',
            $business->id
        );

        $response = $this->postJson(
            '/api/businesses/current/roles',
            [
                'name' => 'Sales Assistant',
                'permissions' => [
                    'business.view',
                    'users.view',
                ],
            ]
        );

        $response->assertCreated();

        $response->assertJsonPath(
            'data.name',
            'Sales Assistant'
        );

        $response->assertJsonPath(
            'data.is_system',
            false
        );

        $this->assertDatabaseHas('roles', [
            'team_id' => $business->id,
            'name' => 'Sales Assistant',
            'is_system' => false,
        ]);
    }

    public function test_user_without_roles_create_permission_cannot_create_a_custom_role(): void
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

        $response = $this->postJson(
            '/api/businesses/current/roles',
            [
                'name' => 'Unauthorized Role',
                'permissions' => [
                    'business.view',
                ],
            ]
        );

        $response->assertForbidden();

        $this->assertDatabaseMissing('roles', [
            'team_id' => $business->id,
            'name' => 'Unauthorized Role',
        ]);
    }

    public function test_user_cannot_create_role_in_another_business(): void
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

        $roleService->assignRole(
            $user,
            'Owner',
            $businessA->id
        );

        $response = $this->postJson(
            "/api/businesses/current/roles",
            [
                'name' => 'Cross Business Role',
                'permissions' => [
                    'business.view',
                ],
            ]
        );

        $response->assertCreated();

        $this->assertDatabaseHas('roles', [
            'team_id' => $businessA->id,
            'name' => 'Cross Business Role',
        ]);

        $this->assertDatabaseMissing('roles', [
            'team_id' => $businessB->id,
            'name' => 'Cross Business Role',
        ]);
    }

    public function test_invalid_permission_is_rejected(): void
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
            'Owner',
            $business->id
        );

        $response = $this->postJson(
            '/api/businesses/current/roles',
            [
                'name' => 'Invalid Role',
                'permissions' => [
                    'business.view',
                    'nuclear.launch',
                ],
            ]
        );

        $response->assertUnprocessable();

        $this->assertDatabaseMissing('roles', [
            'team_id' => $business->id,
            'name' => 'Invalid Role',
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