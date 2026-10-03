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
                'users.remove',
                'users.join_requests.review',

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

                'services.view',
                'services.create',
                'services.update',
                'services.delete',

                'categories.view',
                'categories.create',
                'categories.update',
                'categories.delete',

                /*
                 * Customers
                 */
                'customers.view',
                'customers.create',
                'customers.update',
                'customers.delete',

                /*
                 * Sales
                 */
                'sales.view',
                'sales.create',
                'sales.update',
                'sales.cancel',

                /*
                 * Receipts
                 */
                'receipts.view',
                'receipts.create',
                'receipts.print',

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
                'users.remove',
                'users.join_requests.review',

                'roles.view',

                'branches.view',

                'products.view',
                'products.create',
                'products.update',

                'services.view',
                'services.create',
                'services.update',
                'services.delete',

                'categories.view',
                'categories.create',
                'categories.update',

                /*
                 * Customers
                 */
                'customers.view',
                'customers.create',
                'customers.update',

                /*
                 * Sales
                 */
                'sales.view',
                'sales.create',

                /*
                 * Receipts
                 */
                'receipts.view',
                'receipts.create',
                'receipts.print',

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

                'services.view',

                'categories.view',

                /*
                 * Customers
                 */
                'customers.view',
                'customers.create',

                /*
                 * Sales
                 */
                'sales.view',
                'sales.create',

                /*
                 * Receipts
                 */
                'receipts.view',
                'receipts.create',
                'receipts.print',

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

                'categories.view',

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
         * Spatie Permission is configured with teams.
         *
         * Every permission/role operation must happen inside
         * the correct business permission context.
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
             * Business scope belongs to the role.
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
        app(
            \Spatie\Permission\PermissionRegistrar::class
        )->forgetCachedPermissions();

        /*
         * Re-establish business context after clearing cache.
         */
        setPermissionsTeamId($businessId);
    }

    /**
     * Assign a non-Owner business-scoped role to an active member.
     *
     * IMPORTANT:
     *
     * This method is the normal employee role-management operation.
     * It MUST NOT be used to assign or modify the Owner role.
     *
     * Owner assignment is handled exclusively by assignOwner().
     */
    public function assignRole(
        User $user,
        string $roleName,
        string $businessId
    ): Role {
        /*
         * Establish explicit business context.
         */
        setPermissionsTeamId($businessId);

        /*
         * Tenant boundary:
         *
         * Target user must be an active member
         * of this business.
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
         * Never allow the ordinary employee role endpoint
         * to assign Owner.
         */
        if ($roleName === 'Owner') {
            throw new HttpException(
                403,
                'The Owner role cannot be assigned through employee role management.'
            );
        }

        /*
         * Get the target user's current role in this business.
         */
        $currentRole = $this->getBusinessRole(
            $user,
            $businessId
        );

        /*
         * Owner is a protected business-level role.
         *
         * Once a user is the Owner, the ordinary employee
         * role-management endpoint cannot change that role.
         */
        if ($currentRole?->name === 'Owner') {
            throw new HttpException(
                403,
                'The business Owner role cannot be changed.'
            );
        }

        /*
         * Resolve requested role strictly inside this business.
         */
        $role = Role::query()
            ->where('name', $roleName)
            ->where('guard_name', 'web')
            ->where('team_id', $businessId)
            ->firstOrFail();

        /*
         * A defensive second check.
         *
         * Even though the request above rejects "Owner", we also
         * protect the domain service from assigning an Owner role
         * if this method is called directly from another code path.
         */
        if ($role->name === 'Owner') {
            throw new HttpException(
                403,
                'The Owner role cannot be assigned through employee role management.'
            );
        }

        /*
         * Replace the user's existing business role.
         */
        $this->replaceBusinessRole(
            $user,
            $role,
            $businessId
        );

        return $role;
    }

    /**
     * Replace a user's business-scoped role.
     *
     * This is the low-level persistence operation.
     *
     * Owner protection is enforced by assignRole() for normal
     * employee role management.
     *
     * assignOwner() intentionally calls this method directly
     * because Owner assignment is a separate trusted operation.
     */
    private function replaceBusinessRole(
        User $user,
        Role $role,
        string $businessId
    ): void {
        DB::transaction(function () use (
            $user,
            $role,
            $businessId
        ) {
            $roleTable = config(
                'permission.table_names.model_has_roles'
            );

            /*
             * Remove all existing roles for this user
             * inside this business.
             */
            DB::table($roleTable)
                ->where(
                    config(
                        'permission.column_names.model_morph_key'
                    ),
                    $user->getKey()
                )
                ->where(
                    'model_type',
                    $user->getMorphClass()
                )
                ->where(
                    config(
                        'permission.column_names.team_foreign_key'
                    ),
                    $businessId
                )
                ->delete();

            /*
             * Assign exactly one business role.
             */
            DB::table($roleTable)->insert([
                'role_id' => $role->getKey(),

                config(
                    'permission.column_names.model_morph_key'
                ) => $user->getKey(),

                'model_type' => $user->getMorphClass(),

                config(
                    'permission.column_names.team_foreign_key'
                ) => $businessId,
            ]);
        });

        /*
         * Forget stale Eloquent relations.
         */
        $user->unsetRelation('roles');
        $user->unsetRelation('permissions');

        /*
         * Clear Spatie's permission cache.
         */
        app(
            \Spatie\Permission\PermissionRegistrar::class
        )->forgetCachedPermissions();

        /*
         * Restore the correct team context.
         */
        setPermissionsTeamId($businessId);
    }

    /**
     * Get the user's role inside a specific business.
     */
    public function getBusinessRole(
        User $user,
        string $businessId
    ): ?Role {
        setPermissionsTeamId($businessId);

        $roleId = DB::table(
            config('permission.table_names.model_has_roles')
        )
            ->where(
                config(
                    'permission.column_names.model_morph_key'
                ),
                $user->getKey()
            )
            ->where(
                'model_type',
                $user->getMorphClass()
            )
            ->where(
                config(
                    'permission.column_names.team_foreign_key'
                ),
                $businessId
            )
            ->value('role_id');

        if (! $roleId) {
            return null;
        }

        return Role::query()
            ->whereKey($roleId)
            ->where('guard_name', 'web')
            ->where('team_id', $businessId)
            ->first();
    }

    /**
     * Get all effective permissions for a user
     * inside a specific business.
     */
    public function getEffectivePermissions(
        User $user,
        string $businessId
    ): array {
        setPermissionsTeamId($businessId);

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
         * Forget potentially stale Eloquent permission relations.
         */
        $user->unsetRelation('roles');
        $user->unsetRelation('permissions');

        return $user->getAllPermissions()
            ->pluck('name')
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Provision and assign the Owner role.
     *
     * This is the ONLY normal service operation that can
     * establish the Owner role.
     */
    public function assignOwner(
        User $user,
        string $businessId
    ): Role {
        /*
         * Establish tenant context.
         */
        setPermissionsTeamId($businessId);

        /*
         * Ensure standard roles exist and have their
         * current permissions.
         */
        $this->provisionBusinessRoles($businessId);

        /*
         * Resolve the Owner role explicitly.
         */
        $ownerRole = Role::query()
            ->where('name', 'Owner')
            ->where('guard_name', 'web')
            ->where('team_id', $businessId)
            ->firstOrFail();

        /*
         * Owner assignment is deliberately separate from
         * ordinary employee role management.
         */
        $this->replaceBusinessRole(
            $user,
            $ownerRole,
            $businessId
        );

        return $ownerRole;
    }
}