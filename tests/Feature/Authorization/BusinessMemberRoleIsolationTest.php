<?php

namespace Tests\Feature\Authorization;

use App\Domains\Identity\Services\RoleService;
use App\Domains\Organization\Models\Business;
use App\Domains\Organization\Models\BusinessUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class BusinessMemberRoleIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_member_role_is_resolved_from_current_business(): void
    {
        $businessA = Business::factory()->create();
        $businessB = Business::factory()->create();

        $user = User::factory()->create();

        $this->createPermissions();

        /*
         * The same user belongs to both businesses.
         */
        BusinessUser::create([
            'business_id' => $businessA->id,
            'user_id' => $user->id,
            'status' => 'active',
            'joined_at' => now(),
        ]);

        BusinessUser::create([
            'business_id' => $businessB->id,
            'user_id' => $user->id,
            'status' => 'active',
            'joined_at' => now(),
        ]);

        $roleService = app(RoleService::class);

        /*
         * Provision the standard roles independently
         * for both businesses.
         */
        $roleService->provisionBusinessRoles(
            $businessA->id
        );

        $roleService->provisionBusinessRoles(
            $businessB->id
        );

        /*
         * In Business A, the user is Owner.
         *
         * Owner is a protected role, so ownership must
         * be established through assignOwner().
         */
        $roleService->assignOwner(
            $user,
            $businessA->id
        );

        /*
         * In Business B, the same user is Manager.
         *
         * Manager is an ordinary employee role, so
         * assignRole() is correct here.
         */
        $roleService->assignRole(
            $user,
            'Manager',
            $businessB->id
        );

        $this->actingAs($user);

        /*
         * Switch the current business context to Business B.
         */
        app(\App\Domains\Organization\Services\BusinessContextService::class)
            ->set($user, $businessB);

        setPermissionsTeamId($businessB->id);

        $response = $this->getJson(
            '/api/businesses/current/members'
        );

        $response->assertOk();

        /*
         * The current business is Business B, therefore
         * the user's role must be Manager.
         */
        $response->assertJsonPath(
            'data.0.user.id',
            $user->id
        );

        $response->assertJsonPath(
            'data.0.role',
            'Manager'
        );

        /*
         * The Owner role from Business A must never leak
         * into the Business B context.
         */
        $response->assertJsonMissing([
            'role' => 'Owner',
        ]);
    }

    private function createPermissions(): void
    {
        $permissions = [
            'business.view',
            'business.update',

            'users.view',
            'users.invite',
            'users.update',

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

            'customers.view',
            'customers.create',
            'customers.update',
            'customers.delete',

            'sales.view',
            'sales.create',
            'sales.update',
            'sales.cancel',

            'receipts.view',
            'receipts.create',
            'receipts.print',

            'inventory.view',
            'inventory.receive',
            'inventory.adjust',
            'inventory.transfer',
        ];

        foreach ($permissions as $permission) {
            Permission::findOrCreate(
                $permission,
                'web'
            );
        }
    }
}