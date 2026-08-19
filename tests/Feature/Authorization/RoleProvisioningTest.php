<?php

namespace Tests\Feature\Authorization;

use App\Domains\Organization\Models\Business;
use App\Domains\Identity\Services\RoleService;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class RoleProvisioningTest extends TestCase
{
    public function test_it_provisions_standard_roles_for_a_business(): void
    {
        $business = Business::factory()->create();

        $permissions = [
            'business.view',
            'business.update',

            'users.view',
            'users.invite',
            'users.update',

            'roles.view',
            'roles.assign',

            'branches.view',
            'branches.create',
            'branches.update',
        ];

        foreach ($permissions as $permission) {
            Permission::firstOrCreate([
                'name' => $permission,
                'guard_name' => 'web',
            ]);
        }

        $roleService = app(RoleService::class);

        $roleService->provisionBusinessRoles(
            $business->id
        );

        expect(
            Role::where('team_id', $business->id)
                ->pluck('name')
                ->sort()
                ->values()
                ->all()
        )->toBe([
            'Cashier',
            'Inventory Staff',
            'Manager',
            'Owner',
        ]);
    }

    public function test_owner_role_receives_owner_permissions(): void
    {
        $business = Business::factory()->create();

        $permissions = [
            'business.view',
            'business.update',

            'users.view',
            'users.invite',
            'users.update',

            'roles.view',
            'roles.assign',

            'branches.view',
            'branches.create',
            'branches.update',
        ];

        foreach ($permissions as $permission) {
            Permission::firstOrCreate([
                'name' => $permission,
                'guard_name' => 'web',
            ]);
        }

        $roleService = app(RoleService::class);

        $roleService->provisionBusinessRoles(
            $business->id
        );

        setPermissionsTeamId($business->id);

        $owner = Role::where('team_id', $business->id)
            ->where('name', 'Owner')
            ->firstOrFail();

        expect(
            $owner->permissions
                ->pluck('name')
                ->sort()
                ->values()
                ->all()
        )->toBe([
            'branches.create',
            'branches.update',
            'branches.view',
            'business.update',
            'business.view',
            'roles.assign',
            'roles.view',
            'users.invite',
            'users.update',
            'users.view',
        ]);
    }
}