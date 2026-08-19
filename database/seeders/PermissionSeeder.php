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
        ];

        foreach ($permissions as $permission) {
            Permission::firstOrCreate([
                'name' => $permission,
                'guard_name' => 'web',
            ]);
        }
    }
}