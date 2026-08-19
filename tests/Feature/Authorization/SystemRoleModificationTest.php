<?php

namespace Tests\Feature\Authorization;

use App\Domains\Identity\Services\RoleManagementService;
use App\Domains\Identity\Services\RoleService;
use App\Domains\Organization\Models\Business;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class SystemRoleModificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_system_role_cannot_be_modified(): void
    {
        $business = Business::factory()->create();

        $this->createPermissions();

        $roleService = app(RoleService::class);

        $roleService->provisionBusinessRoles(
            $business->id
        );

        $role = Role::where('team_id', $business->id)
            ->where('name', 'Cashier')
            ->firstOrFail();

        expect($role->is_system)->toBeTrue();

        $roleManagementService = app(RoleManagementService::class);

        expect(fn () =>
            $roleManagementService->ensureCustomRole($role)
        )->toThrow(
            \Symfony\Component\HttpKernel\Exception\HttpException::class
        );
    }

    public function test_custom_role_can_be_modified(): void
    {
        $business = Business::factory()->create();

        $role = Role::create([
            'name' => 'Sales Assistant',
            'guard_name' => 'web',
            'team_id' => $business->id,
            'is_system' => false,
        ]);

        $roleManagementService = app(RoleManagementService::class);

        $result = $roleManagementService->ensureCustomRole(
            $role
        );

        expect($result)->toBeNull();
    }

    public function test_system_role_cannot_be_deleted(): void
    {
        $business = Business::factory()->create();

        $this->createPermissions();

        $roleService = app(RoleService::class);

        $roleService->provisionBusinessRoles(
            $business->id
        );

        $role = Role::where('team_id', $business->id)
            ->where('name', 'Cashier')
            ->firstOrFail();

        $roleManagementService = app(RoleManagementService::class);

        expect(fn () =>
            $roleManagementService->ensureDeletableRole($role)
        )->toThrow(
            \Symfony\Component\HttpKernel\Exception\HttpException::class
        );
    }

    public function test_custom_role_can_be_deleted(): void
    {
        $business = Business::factory()->create();

        $role = Role::create([
            'name' => 'Temporary Staff',
            'guard_name' => 'web',
            'team_id' => $business->id,
            'is_system' => false,
        ]);

        $roleManagementService = app(RoleManagementService::class);

        $result = $roleManagementService->ensureDeletableRole(
            $role
        );

        expect($result)->toBeNull();
    }

    private function createPermissions(): void
    {
        $permissions = [
            'business.view',
            'business.update',
            'users.view',
            'users.invite',
            'users.update',
            'roles.view',
            'roles.create',
            'roles.update',
            'roles.delete',
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
    }
}
