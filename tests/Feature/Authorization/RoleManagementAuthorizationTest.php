<?php

namespace Tests\Feature\Authorization;

use App\Domains\Identity\Services\RoleService;
use App\Domains\Organization\Models\Business;
use App\Domains\Organization\Models\BusinessUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class RoleManagementAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Create the permissions required by these tests.
     */
    private function createRoleManagementPermissions(): void
    {
        $permissions = [
            'roles.view',
            'roles.create',
            'roles.update',
            'roles.delete',
            'roles.assign',
        ];

        foreach ($permissions as $permission) {
            Permission::firstOrCreate([
                'name' => $permission,
                'guard_name' => 'web',
            ]);
        }
    }

    public function test_owner_receives_role_management_permissions(): void
    {
        $this->createRoleManagementPermissions();

        $business = Business::factory()->create();

        $owner = User::factory()->create();

        BusinessUser::create([
            'business_id' => $business->id,
            'user_id' => $owner->id,
            'status' => 'active',
            'joined_at' => now(),
        ]);

        $this->actingAs($owner);

        setPermissionsTeamId($business->id);

        $roleService = app(RoleService::class);

        $roleService->assignOwner(
            $owner,
            $business->id
        );

        expect(
            $owner->hasPermissionTo('roles.view')
        )->toBeTrue();

        expect(
            $owner->hasPermissionTo('roles.create')
        )->toBeTrue();

        expect(
            $owner->hasPermissionTo('roles.update')
        )->toBeTrue();

        expect(
            $owner->hasPermissionTo('roles.delete')
        )->toBeTrue();

        expect(
            $owner->hasPermissionTo('roles.assign')
        )->toBeTrue();
    }

    public function test_manager_does_not_receive_role_management_permissions(): void
    {
        $this->createRoleManagementPermissions();

        $business = Business::factory()->create();

        $manager = User::factory()->create();

        BusinessUser::create([
            'business_id' => $business->id,
            'user_id' => $manager->id,
            'status' => 'active',
            'joined_at' => now(),
        ]);

        $roleService = app(RoleService::class);

        $roleService->provisionBusinessRoles(
            $business->id
        );

        $this->actingAs($manager);

        setPermissionsTeamId($business->id);

        $managerRole = Role::where('name', 'Manager')
            ->where('guard_name', 'web')
            ->where('team_id', $business->id)
            ->firstOrFail();

        $manager->assignRole($managerRole);

        expect(
            $manager->hasPermissionTo('roles.view')
        )->toBeTrue();

        expect(
            $manager->hasPermissionTo('roles.create')
        )->toBeFalse();

        expect(
            $manager->hasPermissionTo('roles.update')
        )->toBeFalse();

        expect(
            $manager->hasPermissionTo('roles.delete')
        )->toBeFalse();

        expect(
            $manager->hasPermissionTo('roles.assign')
        )->toBeFalse();
    }
}