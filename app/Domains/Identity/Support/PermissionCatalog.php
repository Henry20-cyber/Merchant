<?php

namespace App\Domains\Identity\Support;

class PermissionCatalog
{
    /**
     * All permissions available in MerchantOS.
     *
     * This is the single source of truth for permission names.
     */
    public static function all(): array
    {
        return [
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
    }

    /**
     * Determine whether a permission exists in MerchantOS.
     */
    public static function contains(string $permission): bool
    {
        return in_array(
            $permission,
            self::all(),
            true
        );
    }

    /**
     * Validate a list of permission names.
     *
     * Returns only valid MerchantOS permissions.
     */
    public static function filterValid(array $permissions): array
    {
        return array_values(
            array_filter(
                $permissions,
                fn (string $permission) =>
                    self::contains($permission)
            )
        );
    }
}
