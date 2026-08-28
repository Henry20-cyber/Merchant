<?php

namespace App\Domains\Identity\Support;

class PermissionCatalog
{
    /**
     * MerchantOS permission catalog.
     *
     * Each domain contains the capabilities available
     * within that part of the system.
     */
    private const PERMISSIONS = [
        'business' => [
            'business.view',
            'business.update',
        ],

        'users' => [
            'users.view',
            'users.invite',
            'users.update',
        ],

        'roles' => [
            'roles.view',
            'roles.create',
            'roles.update',
            'roles.delete',
            'roles.assign',
        ],

        'branches' => [
            'branches.view',
            'branches.create',
            'branches.update',
        ],

        'products' => [
            'products.view',
            'products.create',
            'products.update',
            'products.delete',
        ],

        'customers' => [
            'customers.view',
            'customers.create',
            'customers.update',
            'customers.delete',
        ],

        'sales' => [
            'sales.view',
            'sales.create',
            'sales.update',
            'sales.cancel',
        ],

        'orders' => [
            'orders.view',
            'orders.create',
            'orders.update',
            'orders.cancel',
        ],

        'payments' => [
            'payments.view',
            'payments.create',
            'payments.refund',
            'payments.void',
        ],

        'receipts' => [
            'receipts.view',
            'receipts.create',
            'receipts.print',
        ],

        'inventory' => [
            'inventory.view',
            'inventory.receive',
            'inventory.adjust',
            'inventory.transfer',
        ],

        'reports' => [
            'reports.view',
            'reports.export',
        ],
    ];

    /**
     * Return all permissions available in MerchantOS.
     */
    public static function all(): array
    {
        return array_values(
            array_merge(...array_values(self::PERMISSIONS))
        );
    }

    /**
     * Return permissions belonging to a specific domain.
     */
    public static function forDomain(string $domain): array
    {
        return self::PERMISSIONS[$domain] ?? [];
    }

    /**
     * Determine whether a permission exists.
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
     * Validate a list of permissions.
     */
    public static function filterValid(array $permissions): array
    {
        return array_values(
            array_filter(
                $permissions,
                fn(string $permission) =>
                self::contains($permission)
            )
        );
    }
}
