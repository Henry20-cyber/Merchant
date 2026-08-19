<?php

namespace App\Domains\Identity\Services;

use App\Domains\Identity\Support\PermissionCatalog;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class RoleManagementService
{
    /**
     * Ensure that a role is a custom role
     * and therefore eligible for modification.
     */
    public function ensureCustomRole(Role $role): void
    {
        if ($role->is_system) {
            abort(403, 'System roles cannot be modified.');
        }
    }

    /**
     * Ensure that a role is a custom role
     * and therefore eligible for deletion.
     */
    public function ensureDeletableRole(Role $role): void
    {
        if ($role->is_system) {
            abort(403, 'System roles cannot be deleted.');
        }
    }

    /**
     * Create a custom role for a business.
     *
     * The caller is responsible for authorization
     * before calling this method.
     */
    public function create(
        User $user,
        string $businessId,
        string $name,
        array $permissionNames
    ): Role {
        $this->ensureUserBelongsToBusiness(
            $user,
            $businessId
        );

        $permissionNames = $this->validatePermissions(
            $permissionNames
        );

        return DB::transaction(function () use (
            $businessId,
            $name,
            $permissionNames
        ) {
            $role = Role::create([
                'name' => $name,
                'guard_name' => 'web',
                'team_id' => $businessId,
                'is_system' => false,
            ]);

            $permissions = Permission::query()
                ->where('guard_name', 'web')
                ->whereIn('name', $permissionNames)
                ->get();

            $role->syncPermissions($permissions);

            return $role->load('permissions');
        });
    }

    /**
     * Ensure the user belongs to the business.
     */
    private function ensureUserBelongsToBusiness(
        User $user,
        string $businessId
    ): void {
        $belongsToBusiness = $user->memberships()
            ->where('business_id', $businessId)
            ->where('status', 'active')
            ->exists();

        if (! $belongsToBusiness) {
            abort(
                403,
                'You do not belong to this business.'
            );
        }
    }

    /**
     * Validate requested permissions against
     * the MerchantOS permission catalog.
     */
    private function validatePermissions(
        array $permissionNames
    ): array {
        $invalidPermissions = array_values(
            array_diff(
                $permissionNames,
                PermissionCatalog::all()
            )
        );

        if ($invalidPermissions !== []) {
            throw ValidationException::withMessages([
                'permissions' => [
                    'The following permissions are invalid: '
                    . implode(', ', $invalidPermissions),
                ],
            ]);
        }

        return array_values(
            array_unique($permissionNames)
        );
    }

    /**
 * Get all roles belonging to a business.
 */
public function listForBusiness(string $businessId)
{
    return Role::query()
        ->where('team_id', $businessId)
        ->where('guard_name', 'web')
        ->with('permissions')
        ->orderBy('is_system', 'desc')
        ->orderBy('name')
        ->get();
}

/**
 * Update a custom role belonging to a business.
 */
public function update(
    User $user,
    string $businessId,
    string $roleId,
    string $name,
    array $permissionNames
): Role {
    $this->ensureUserBelongsToBusiness(
        $user,
        $businessId
    );

    $role = Role::query()
        ->where('id', $roleId)
        ->where('team_id', $businessId)
        ->where('guard_name', 'web')
        ->firstOrFail();

    $this->ensureCustomRole($role);

    $permissionNames = $this->validatePermissions(
        $permissionNames
    );

    return DB::transaction(function () use (
        $role,
        $name,
        $permissionNames
    ) {
        $role->update([
            'name' => $name,
        ]);

        $permissions = Permission::query()
            ->where('guard_name', 'web')
            ->whereIn('name', $permissionNames)
            ->get();

        $role->syncPermissions($permissions);

        return $role->refresh()->load('permissions');
    });
}
}