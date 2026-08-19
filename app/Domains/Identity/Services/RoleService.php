<?php

namespace App\Domains\Identity\Services;

use App\Domains\Identity\Support\PermissionCatalog;
use App\Models\User;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class RoleService
{
    private function rolePermissions(): array
    {
        return [
            'Owner' => [
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
            ],

            'Manager' => [
                'business.view',

                'users.view',
                'users.invite',
                'users.update',

                'roles.view',

                'branches.view',
            ],

            'Cashier' => [
                'business.view',
            ],

            'Inventory Staff' => [
                'business.view',
                'branches.view',
            ],
        ];
    }

    public function provisionBusinessRoles(string $businessId): void
    {
        foreach ($this->rolePermissions() as $roleName => $permissionNames) {
            $role = Role::firstOrCreate(
                [
                    'name' => $roleName,
                    'guard_name' => 'web',
                    'team_id' => $businessId,
                ],
                [
                    'is_system' => true,
                ]
            );

            $permissions = Permission::whereIn(
                'name',
                $permissionNames
            )
                ->where('guard_name', 'web')
                ->get();

            $role->syncPermissions($permissions);
        }
    }

    public function assignRole(
        User $user,
        string $roleName,
        string $businessId
    ): Role {
        $role = Role::where('name', $roleName)
            ->where('guard_name', 'web')
            ->where('team_id', $businessId)
            ->firstOrFail();

        $user->assignRole($role);

        return $role;
    }

    public function assignOwner(
        User $user,
        string $businessId
    ): Role {
        $this->provisionBusinessRoles($businessId);

        return $this->assignRole(
            $user,
            'Owner',
            $businessId
        );
    }
}