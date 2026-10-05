<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;

class PermissionSeeder extends Seeder
{
    /**
     * Seed MerchantOS permissions.
     */
    public function run(): void
    {
        $permissions = [
            // Business
            'business.view',
            'business.update',

            // Users
            'users.view',
            'users.invite',
            'users.update',
            'users.remove',
            'users.join_requests.review',

            // Roles
            'roles.view',
            'roles.create',
            'roles.update',
            'roles.delete',
            'roles.assign',

            // Branches
            'branches.view',
            'branches.create',
            'branches.update',

            // Categories
            'categories.view',
            'categories.create',
            'categories.update',
            'categories.delete',

            // Products
            'products.view',
            'products.create',
            'products.update',
            'products.delete',

            // Services
            'services.view',
            'services.create',
            'services.update',
            'services.delete',

            // Customers
            'customers.view',
            'customers.create',
            'customers.update',
            'customers.delete',

            // Sales
            'sales.view',
            'sales.create',
            'sales.update',
            'sales.cancel',

            // Orders
            'orders.view',
            'orders.create',
            'orders.update',
            'orders.cancel',

            // Payments
            'payments.view',
            'payments.create',
            'payments.refund',
            'payments.void',

            // Receipts
            'receipts.view',
            'receipts.create',
            'receipts.print',

            // Inventory
            'inventory.view',
            'inventory.receive',
            'inventory.adjust',
            'inventory.transfer',

            // Expenses
            'expenses.view',
            'expenses.create',
            'expenses.update',
            'expenses.delete',

            // Reports
            'reports.view',
            'reports.export',
        ];

        foreach ($permissions as $permission) {
            Permission::firstOrCreate([
                'name' => $permission,
                'guard_name' => 'web',
            ]);
        }
    }
}