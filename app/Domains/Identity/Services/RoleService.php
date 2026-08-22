<?php

namespace App\Domains\Identity\Services;

use App\Domains\Identity\Support\PermissionCatalog;
use App\Domains\Organization\Models\BusinessUser;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class RoleService
{
    /**
     * Permissions assigned to MerchantOS system roles.
     */
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

            'products.view',
            'products.create',
            'products.update',
            'products.delete',
        ],

        'Manager' => [
            'business.view',

            'users.view',
            'users.invite',
            'users.update',

            'roles.view',

            'branches.view',

            'products.view',
            'products.create',
            'products.update',
        ],

        'Cashier' => [
            'business.view',

            'products.view',
        ],

        'Inventory Staff' => [
            'business.view',

            'branches.view',

            'products.view',
        ],
    ];
}

    /**
     * Provision the standard MerchantOS roles for a business.
     */
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

            /*
             * Existing roles may have been created before is_system
             * was introduced, so enforce the invariant.
             */
            if (! $role->is_system) {
                $role->forceFill([
                    'is_system' => true,
                ])->save();
            }

            $validPermissionNames = PermissionCatalog::filterValid(
                $permissionNames
            );

            $permissions = Permission::query()
                ->where('guard_name', 'web')
                ->whereIn('name', $validPermissionNames)
                ->get();

            $role->syncPermissions($permissions);
        }
    }

    /**
     * Assign a business-scoped role to an active member.
     */
    public function assignRole(
        User $user,
        string $roleName,
        string $businessId
    ): Role {
        /*
         * Tenant boundary:
         * the target user must be an active member of this business.
         */
        $isMember = BusinessUser::query()
            ->where('business_id', $businessId)
            ->where('user_id', $user->id)
            ->where('status', 'active')
            ->exists();

        if (! $isMember) {
            throw new HttpException(
                403,
                'User does not belong to this business.'
            );
        }

        $role = Role::query()
            ->where('name', $roleName)
            ->where('guard_name', 'web')
            ->where('team_id', $businessId)
            ->firstOrFail();

        /*
         * We intentionally write the team-scoped pivot explicitly.
         *
         * MerchantOSTeamResolver represents the authenticated user's
         * CURRENT business context. Role assignment, however, is an
         * administrative operation against an explicit business.
         *
         * Therefore it must not depend on Auth::user() merely to
         * populate model_has_roles.team_id.
         */
        DB::table(
            config('permission.table_names.model_has_roles')
        )->updateOrInsert(
            [
                config('permission.column_names.model_morph_key')
                    => $user->getKey(),

                'model_type'
                    => $user->getMorphClass(),

                'role_id'
                    => $role->getKey(),

                config('permission.column_names.team_foreign_key')
                    => $businessId,
            ],
            []
        );

        /*
         * HasRoles may already have loaded the roles relation.
         * Forget it so subsequent permission checks query fresh data.
         */
        $user->unsetRelation('roles');

        return $role;
    }

    /**
     * Provision and assign the Owner role.
     */
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