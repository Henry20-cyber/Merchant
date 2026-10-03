<?php

namespace Tests\Feature\Authorization;

use App\Domains\Identity\Services\RoleService;
use App\Domains\Identity\Support\PermissionCatalog;
use App\Domains\Organization\Models\Business;
use App\Domains\Organization\Models\BusinessUser;
use App\Domains\Subscription\Models\Subscription;
use App\Domains\Subscription\Models\SubscriptionPlan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class BusinessRoleCreationAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_with_roles_create_permission_can_create_a_custom_role(): void
    {
        $business = Business::factory()->create();

        $this->createAdvancedRbacSubscription($business);

        $user = $this->createBusinessMember($business);

        $this->createPermissions();

        $this->actingAs($user);

        setPermissionsTeamId($business->id);

        $roleService = app(RoleService::class);

        $roleService->provisionBusinessRoles(
            $business->id
        );

        /*
         * The Owner role must be established through the dedicated
         * ownership method, not employee role management.
         */
        $roleService->assignOwner(
            $user,
            $business->id
        );

        $response = $this->postJson(
            '/api/businesses/current/roles',
            [
                'name' => 'Sales Assistant',
                'permissions' => [
                    'business.view',
                    'users.view',
                ],
            ]
        );

        $response->assertCreated();

        $response->assertJsonPath(
            'data.name',
            'Sales Assistant'
        );

        $response->assertJsonPath(
            'data.is_system',
            false
        );

        $this->assertDatabaseHas('roles', [
            'team_id' => $business->id,
            'name' => 'Sales Assistant',
            'is_system' => false,
        ]);
    }

    public function test_user_without_roles_create_permission_cannot_create_a_custom_role(): void
    {
        $business = Business::factory()->create();

        $this->createAdvancedRbacSubscription($business);

        $user = $this->createBusinessMember($business);

        $this->createPermissions();

        $this->actingAs($user);

        setPermissionsTeamId($business->id);

        $roleService = app(RoleService::class);

        $roleService->provisionBusinessRoles(
            $business->id
        );

        /*
         * Manager intentionally does not receive roles.create,
         * so this request should be forbidden.
         */
        $roleService->assignRole(
            $user,
            'Manager',
            $business->id
        );

        $response = $this->postJson(
            '/api/businesses/current/roles',
            [
                'name' => 'Unauthorized Role',
                'permissions' => [
                    'business.view',
                ],
            ]
        );

        $response->assertForbidden();

        $this->assertDatabaseMissing('roles', [
            'team_id' => $business->id,
            'name' => 'Unauthorized Role',
        ]);
    }

    public function test_user_cannot_create_role_in_another_business(): void
    {
        $businessA = Business::factory()->create();
        $businessB = Business::factory()->create();

        $this->createAdvancedRbacSubscription($businessA);
        $this->createAdvancedRbacSubscription($businessB);

        $user = $this->createBusinessMember($businessA);

        $this->createPermissions();

        $this->actingAs($user);

        setPermissionsTeamId($businessA->id);

        $roleService = app(RoleService::class);

        $roleService->provisionBusinessRoles(
            $businessA->id
        );

        $roleService->provisionBusinessRoles(
            $businessB->id
        );

        /*
         * User belongs to Business A, so ownership is established
         * through Business A only.
         */
        $roleService->assignOwner(
            $user,
            $businessA->id
        );

        $response = $this->postJson(
            '/api/businesses/current/roles',
            [
                'name' => 'Cross Business Role',
                'permissions' => [
                    'business.view',
                ],
            ]
        );

        /*
         * The role is created in the authenticated user's current
         * business (Business A), not Business B.
         */
        $response->assertCreated();

        $this->assertDatabaseHas('roles', [
            'team_id' => $businessA->id,
            'name' => 'Cross Business Role',
        ]);

        $this->assertDatabaseMissing('roles', [
            'team_id' => $businessB->id,
            'name' => 'Cross Business Role',
        ]);
    }

    public function test_invalid_permission_is_rejected(): void
    {
        $business = Business::factory()->create();

        $this->createAdvancedRbacSubscription($business);

        $user = $this->createBusinessMember($business);

        $this->createPermissions();

        $this->actingAs($user);

        setPermissionsTeamId($business->id);

        $roleService = app(RoleService::class);

        $roleService->provisionBusinessRoles(
            $business->id
        );

        $roleService->assignOwner(
            $user,
            $business->id
        );

        $response = $this->postJson(
            '/api/businesses/current/roles',
            [
                'name' => 'Invalid Role',
                'permissions' => [
                    'business.view',
                    'nuclear.launch',
                ],
            ]
        );

        $response->assertUnprocessable();

        $this->assertDatabaseMissing('roles', [
            'team_id' => $business->id,
            'name' => 'Invalid Role',
        ]);
    }

    private function createAdvancedRbacSubscription(
        Business $business
    ): void {
        $plan = SubscriptionPlan::factory()->create([
            'features' => [
                'advanced_rbac' => true,
            ],
            'is_active' => true,
        ]);

        Subscription::factory()->create([
            'business_id' => $business->id,
            'plan_id' => $plan->id,
            'status' => 'active',
            'starts_at' => now()->subDay(),
            'current_period_start' => now()->subDay(),
            'current_period_end' => now()->addMonth(),
        ]);
    }

    private function createBusinessMember(
        Business $business
    ): User {
        $user = User::factory()->create();

        BusinessUser::create([
            'business_id' => $business->id,
            'user_id' => $user->id,
            'status' => 'active',
            'joined_at' => now(),
        ]);

        return $user;
    }

    private function createPermissions(): void
    {
        foreach (PermissionCatalog::all() as $permission) {
            Permission::firstOrCreate([
                'name' => $permission,
                'guard_name' => 'web',
            ]);
        }
    }
}