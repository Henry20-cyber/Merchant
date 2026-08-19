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

class SystemRoleProtectionTest extends TestCase
{
    use RefreshDatabase;

    public function test_standard_roles_are_marked_as_system_roles(): void
    {
        $business = Business::factory()->create();

        $this->createRequiredPermissions();

        $roleService = app(RoleService::class);

        $roleService->provisionBusinessRoles(
            $business->id
        );

        $systemRoles = Role::where(
            'team_id',
            $business->id
        )->get();

        expect($systemRoles)->toHaveCount(4);

        foreach ($systemRoles as $role) {
            expect($role->is_system)->toBeTrue();
        }
    }

    public function test_custom_role_is_not_a_system_role(): void
    {
        $business = Business::factory()->create();

        $role = Role::create([
            'name' => 'Sales Assistant',
            'guard_name' => 'web',
            'team_id' => $business->id,
            'is_system' => false,
        ]);

        expect($role->is_system)->toBeFalse();
    }

    private function createRequiredPermissions(): void
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