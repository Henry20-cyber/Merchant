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

class BusinessRoleDeletionAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_authorized_user_can_delete_unused_custom_role(): void
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
            'name' => 'Temporary Role',
            'guard_name' => 'web',
            'team_id' => $business->id,
            'is_system' => false,
        ]);

        $response = $this->deleteJson(
            "/api/businesses/current/roles/{$customRole->id}"
        );

        $response->assertOk();

        $response->assertJson([
            'success' => true,
        ]);

        $this->assertDatabaseMissing('roles', [
            'id' => $customRole->id,
        ]);
    }

    public function test_user_without_roles_delete_permission_cannot_delete_role(): void
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
            'name' => 'Temporary Role',
            'guard_name' => 'web',
            'team_id' => $business->id,
            'is_system' => false,
        ]);

        $response = $this->deleteJson(
            "/api/businesses/current/roles/{$customRole->id}"
        );

        $response->assertForbidden();

        $this->assertDatabaseHas('roles', [
            'id' => $customRole->id,
        ]);
    }

    public function test_system_role_cannot_be_deleted(): void
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

        $response = $this->deleteJson(
            "/api/businesses/current/roles/{$systemRole->id}"
        );

        $response->assertForbidden();

        $this->assertDatabaseHas('roles', [
            'id' => $systemRole->id,
            'name' => 'Cashier',
            'is_system' => true,
        ]);
    }

    public function test_role_from_another_business_cannot_be_deleted(): void
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

        $response = $this->deleteJson(
            "/api/businesses/current/roles/{$roleFromBusinessB->id}"
        );

        $response->assertNotFound();

        $this->assertDatabaseHas('roles', [
            'id' => $roleFromBusinessB->id,
            'team_id' => $businessB->id,
            'name' => 'Cashier',
        ]);
    }

    public function test_role_with_assigned_users_cannot_be_deleted(): void
    {
        $business = Business::factory()->create();

        $owner = $this->createBusinessMember($business);
        $assignedUser = $this->createBusinessMember($business);

        $this->createPermissions();

        $this->actingAs($owner);

        setPermissionsTeamId($business->id);

        $roleService = app(RoleService::class);

        $roleService->provisionBusinessRoles(
            $business->id
        );

        $roleService->assignRole(
            $owner,
            'Owner',
            $business->id
        );

        $customRole = Role::create([
            'name' => 'Sales Assistant',
            'guard_name' => 'web',
            'team_id' => $business->id,
            'is_system' => false,
        ]);

        $roleService->assignRole(
            $assignedUser,
            'Sales Assistant',
            $business->id
        );

        $response = $this->deleteJson(
            "/api/businesses/current/roles/{$customRole->id}"
        );

        $response->assertUnprocessable();

        $response->assertJsonValidationErrors([
            'role',
        ]);

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