<?php

namespace Tests\Feature\Authorization;

use App\Domains\Identity\Services\RoleService;
use App\Domains\Organization\Models\Business;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class CustomerRoleProvisioningTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        /*
         * Customer permissions must exist before
         * the standard roles are provisioned.
         */
        foreach ([
            'customers.view',
            'customers.create',
            'customers.update',
            'customers.delete',
        ] as $permission) {
            Permission::firstOrCreate([
                'name' => $permission,
                'guard_name' => 'web',
            ]);
        }
    }

    private function provisionRoles(): array
    {
        $business = Business::factory()->create();

        app(RoleService::class)->provisionBusinessRoles(
            $business->id
        );

        setPermissionsTeamId($business->id);

        return [
            $business,
            Role::where('name', 'Owner')
                ->where('team_id', $business->id)
                ->firstOrFail(),

            Role::where('name', 'Manager')
                ->where('team_id', $business->id)
                ->firstOrFail(),

            Role::where('name', 'Cashier')
                ->where('team_id', $business->id)
                ->firstOrFail(),

            Role::where('name', 'Inventory Staff')
                ->where('team_id', $business->id)
                ->firstOrFail(),
        ];
    }

    public function test_owner_has_all_customer_permissions(): void
    {
        [$business, $owner] = $this->provisionRoles();

        expect($owner->hasPermissionTo('customers.view'))->toBeTrue();
        expect($owner->hasPermissionTo('customers.create'))->toBeTrue();
        expect($owner->hasPermissionTo('customers.update'))->toBeTrue();
        expect($owner->hasPermissionTo('customers.delete'))->toBeTrue();
    }

    public function test_manager_has_customer_view_create_and_update_permissions(): void
    {
        [$business, $owner, $manager] = $this->provisionRoles();

        expect($manager->hasPermissionTo('customers.view'))->toBeTrue();
        expect($manager->hasPermissionTo('customers.create'))->toBeTrue();
        expect($manager->hasPermissionTo('customers.update'))->toBeTrue();

        expect($manager->hasPermissionTo('customers.delete'))->toBeFalse();
    }

    public function test_cashier_can_view_and_create_customers(): void
    {
        [$business, $owner, $manager, $cashier] =
            $this->provisionRoles();

        expect($cashier->hasPermissionTo('customers.view'))->toBeTrue();
        expect($cashier->hasPermissionTo('customers.create'))->toBeTrue();

        expect($cashier->hasPermissionTo('customers.update'))->toBeFalse();
        expect($cashier->hasPermissionTo('customers.delete'))->toBeFalse();
    }

    public function test_inventory_staff_has_no_customer_permissions(): void
    {
        [
            $business,
            $owner,
            $manager,
            $cashier,
            $inventoryStaff
        ] = $this->provisionRoles();

        expect(
            $inventoryStaff->hasPermissionTo('customers.view')
        )->toBeFalse();

        expect(
            $inventoryStaff->hasPermissionTo('customers.create')
        )->toBeFalse();

        expect(
            $inventoryStaff->hasPermissionTo('customers.update')
        )->toBeFalse();

        expect(
            $inventoryStaff->hasPermissionTo('customers.delete')
        )->toBeFalse();
    }

    public function test_customer_permissions_are_scoped_to_business(): void
    {
        $businessA = Business::factory()->create();
        $businessB = Business::factory()->create();

        $roleService = app(RoleService::class);

        $roleService->provisionBusinessRoles($businessA->id);
        $roleService->provisionBusinessRoles($businessB->id);

        setPermissionsTeamId($businessA->id);

        $ownerA = Role::where('name', 'Owner')
            ->where('team_id', $businessA->id)
            ->firstOrFail();

        setPermissionsTeamId($businessB->id);

        $ownerB = Role::where('name', 'Owner')
            ->where('team_id', $businessB->id)
            ->firstOrFail();

        expect(
            $ownerA->hasPermissionTo('customers.create')
        )->toBeTrue();

        expect(
            $ownerB->hasPermissionTo('customers.create')
        )->toBeTrue();

        expect($ownerA->team_id)
            ->not->toBe($ownerB->team_id);
    }
}
