<?php

namespace Tests\Feature\Authorization;

use App\Domains\Identity\Services\RoleService;
use App\Domains\Identity\Support\PermissionCatalog;
use App\Domains\Organization\Models\Business;
use App\Domains\Organization\Models\BusinessUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class BusinessRoleUpdateAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_authorized_user_can_update_a_custom_role(): void
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

        $customRole = Role::create([
            'name' => 'Sales Assistant',
            'guard_name' => 'web',
            'team_id' => $business->id,
            'is_system' => false,
        ]);

        $customRole->syncPermissions([
            Permission::where('name', 'business.view')->first(),
        ]);

        $response = $this->putJson(
            "/api/businesses/current/roles/{$customRole->id}",
            [
                'name' => 'Senior Sales Assistant',
                'permissions' => [
                    'business.view',
                    'users.view',
                ],
            ]
        );

        $response->assertOk();

        $response->assertJsonPath(
            'data.name',
            'Senior Sales Assistant'
        );

        $this->assertDatabaseHas('roles', [
            'id' => $customRole->id,
            'team_id' => $business->id,
            'name' => 'Senior Sales Assistant',
            'is_system' => false,
        ]);

        expect(
            $customRole->refresh()
                ->permissions
                ->pluck('name')
                ->sort()
                ->values()
                ->all()
        )->toBe([
            'business.view',
            'users.view',
        ]);
    }

    public function test_user_without_roles_update_permission_cannot_update_role(): void
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

        $customRole = Role::create([
            'name' => 'Sales Assistant',
            'guard_name' => 'web',
            'team_id' => $business->id,
            'is_system' => false,
        ]);

        $response = $this->putJson(
            "/api/businesses/current/roles/{$customRole->id}",
            [
                'name' => 'Hacked Role',
                'permissions' => [
                    'business.view',
                ],
            ]
        );

        $response->assertForbidden();

        $this->assertDatabaseHas('roles', [
            'id' => $customRole->id,
            'name' => 'Sales Assistant',
        ]);
    }

    public function test_system_role_cannot_be_updated(): void
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

        $systemRole = Role::query()
            ->where('team_id', $business->id)
            ->where('name', 'Cashier')
            ->firstOrFail();

        $response = $this->putJson(
            "/api/businesses/current/roles/{$systemRole->id}",
            [
                'name' => 'Hacked Cashier',
                'permissions' => [
                    'business.view',
                    'users.update',
                ],
            ]
        );

        $response->assertForbidden();

        $this->assertDatabaseHas('roles', [
            'id' => $systemRole->id,
            'name' => 'Cashier',
            'is_system' => true,
        ]);
    }

    public function test_role_from_another_business_cannot_be_updated(): void
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
            'Owner',
            $businessA->id
        );

        $roleFromBusinessB = Role::query()
            ->where('team_id', $businessB->id)
            ->where('name', 'Cashier')
            ->firstOrFail();

        $response = $this->putJson(
            "/api/businesses/current/roles/{$roleFromBusinessB->id}",
            [
                'name' => 'Hacked Role',
                'permissions' => [
                    'business.view',
                ],
            ]
        );

        $response->assertNotFound();

        $this->assertDatabaseHas('roles', [
            'id' => $roleFromBusinessB->id,
            'team_id' => $businessB->id,
            'name' => 'Cashier',
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

        $customRole = Role::create([
            'name' => 'Sales Assistant',
            'guard_name' => 'web',
            'team_id' => $business->id,
            'is_system' => false,
        ]);

        $response = $this->putJson(
            "/api/businesses/current/roles/{$customRole->id}",
            [
                'name' => 'Invalid Role',
                'permissions' => [
                    'business.view',
                    'nuclear.launch',
                ],
            ]
        );

        $response->assertUnprocessable();

        $this->assertDatabaseHas('roles', [
            'id' => $customRole->id,
            'name' => 'Sales Assistant',
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
