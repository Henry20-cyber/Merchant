<?php

namespace Tests\Unit\Identity;

use App\Domains\Identity\Support\PermissionCatalog;
use Tests\TestCase;

class PermissionCatalogTest extends TestCase
{
    public function test_catalog_contains_expected_permissions(): void
    {
        expect(
            PermissionCatalog::contains('business.view')
        )->toBeTrue();

        expect(
            PermissionCatalog::contains('roles.create')
        )->toBeTrue();

        expect(
            PermissionCatalog::contains('roles.assign')
        )->toBeTrue();
    }

    public function test_catalog_rejects_unknown_permissions(): void
    {
        expect(
            PermissionCatalog::contains('system.destroy')
        )->toBeFalse();

        expect(
            PermissionCatalog::contains('nuclear.launch')
        )->toBeFalse();
    }

    public function test_filter_valid_returns_only_known_permissions(): void
    {
        $permissions = [
            'business.view',
            'users.view',
            'nuclear.launch',
            'roles.assign',
        ];

        expect(
            PermissionCatalog::filterValid($permissions)
        )->toBe([
            'business.view',
            'users.view',
            'roles.assign',
        ]);
    }
}
