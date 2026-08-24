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

            /*
            |--------------------------------------------------------------------------
            | Owner
            |--------------------------------------------------------------------------
            */

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

                 /*
                  * Sales
                  */
                'sales.view',
                'sales.create',
                'sales.update',
                'sales.cancel',

                /*
     * Inventory
     */
                'inventory.view',
                'inventory.receive',
                'inventory.adjust',
                'inventory.transfer',

               
            ],

            /*
            |--------------------------------------------------------------------------
            | Manager
            |--------------------------------------------------------------------------
            */

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
                
                /*
                 * Sales
                 */
                'sales.view',
                'sales.create',

                /*
                 * Inventory
                 */
                'inventory.view',
                'inventory.receive',
                'inventory.adjust',
                'inventory.transfer',

            ],

            /*
            |--------------------------------------------------------------------------
            | Cashier
            |--------------------------------------------------------------------------
            */

            'Cashier' => [
                'business.view',

                'products.view',

                 /*
                 * Sales
                 */
                'sales.view',
                'sales.create',

                /*
                 * Cashiers can view inventory,
                 * but cannot modify it.
                 */
                'inventory.view',

               
            ],

            /*
            |--------------------------------------------------------------------------
            | Inventory Staff
            |--------------------------------------------------------------------------
            */

            'Inventory Staff' => [
                'business.view',

                'branches.view',

                'products.view',

                'inventory.view',
                'inventory.receive',
                'inventory.adjust',
                'inventory.transfer',
            ],
        ];
    }

    /**
     * Provision the standard MerchantOS roles for a business.
     */
    public function provisionBusinessRoles(string $businessId): void
    {
        /*
         * IMPORTANT:
         *
         * Spatie Permission is configured with teams.
         *
         * Any permission/role operation performed here must happen
         * inside the business's permission context.
         */
        setPermissionsTeamId($businessId);

        foreach ($this->rolePermissions() as $roleName => $permissionNames) {

            /*
             * Resolve/create the role explicitly for this business.
             */
            $role = Role::query()->firstOrCreate(
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
             * Existing roles may have been created before
             * is_system was introduced.
             */
            if (! $role->is_system) {
                $role->forceFill([
                    'is_system' => true,
                ])->save();
            }

            /*
             * Only permissions in the MerchantOS catalog
             * can be assigned.
             */
            $validPermissionNames = PermissionCatalog::filterValid(
                $permissionNames
            );

            /*
             * Permissions themselves are global.
             *
             * The business scope belongs to the role.
             */
            $permissions = Permission::query()
                ->where('guard_name', 'web')
                ->whereIn('name', $validPermissionNames)
                ->get();

            /*
             * Replace the role's permissions with the
             * current standard definition.
             */
            $role->syncPermissions($permissions);
        }

        /*
         * Clear cached permission information after provisioning.
         */
        app(\Spatie\Permission\PermissionRegistrar::class)
            ->forgetCachedPermissions();

        /*
         * Re-establish the business context because cache clearing
         * must not leave the registrar in an undefined state.
         */
        setPermissionsTeamId($businessId);
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
         * Establish the explicit business context.
         *
         * Do not depend on the HTTP/session business context here.
         */
        setPermissionsTeamId($businessId);

        /*
         * Tenant boundary:
         *
         * The target user must be an active member
         * of the requested business.
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

        /*
         * Resolve the role strictly inside the requested business.
         */
        $role = Role::query()
            ->where('name', $roleName)
            ->where('guard_name', 'web')
            ->where('team_id', $businessId)
            ->firstOrFail();

        /*
         * Explicitly assign the role with the correct team ID.
         *
         * We deliberately do not call $user->assignRole() here because
         * the explicit business ID is the authoritative tenant boundary.
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
         * Forget stale Eloquent relations.
         */
        $user->unsetRelation('roles');
        $user->unsetRelation('permissions');

        /*
         * Clear Spatie's permission cache.
         */
        app(\Spatie\Permission\PermissionRegistrar::class)
            ->forgetCachedPermissions();

        /*
         * IMPORTANT:
         *
         * Restore the correct team context after clearing cache.
         */
        setPermissionsTeamId($businessId);

        return $role;
    }

    /**
     * Provision and assign the Owner role.
     */
    public function assignOwner(
        User $user,
        string $businessId
    ): Role {
        /*
         * Explicitly establish tenant context before both
         * provisioning and assignment.
         */
        setPermissionsTeamId($businessId);

        $this->provisionBusinessRoles($businessId);

        return $this->assignRole(
            $user,
            'Owner',
            $businessId
        );
    }
}
