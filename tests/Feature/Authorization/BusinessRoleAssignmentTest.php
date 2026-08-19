<?php

namespace Tests\Feature\Authorization;

use App\Domains\Organization\Models\Business;
use App\Domains\Organization\Models\BusinessUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class BusinessRoleAssignmentTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_with_roles_assign_permission_can_assign_a_role(): void
    {
        $business = Business::factory()->create();

        $admin = User::factory()->create();

        $targetUser = User::factory()->create([
            'name' => 'Cashier Employee',
        ]);

        BusinessUser::create([
            'business_id' => $business->id,
            'user_id' => $admin->id,
            'status' => 'active',
            'joined_at' => now(),
        ]);

        BusinessUser::create([
            'business_id' => $business->id,
            'user_id' => $targetUser->id,
            'status' => 'active',
            'joined_at' => now(),
        ]);

        $permission = Permission::firstOrCreate([
            'name' => 'roles.assign',
            'guard_name' => 'web',
        ]);

        $adminRole = Role::firstOrCreate([
            'name' => 'Owner',
            'guard_name' => 'web',
            'team_id' => $business->id,
        ]);

        $adminRole->syncPermissions([
            $permission,
        ]);

        Role::firstOrCreate([
            'name' => 'Cashier',
            'guard_name' => 'web',
            'team_id' => $business->id,
        ]);

        $this->actingAs($admin);

        setPermissionsTeamId($business->id);

        $admin->assignRole($adminRole);

        $response = $this->putJson(
            "/api/businesses/current/members/{$targetUser->id}/role",
            [
                'role' => 'Cashier',
            ]
        );

        $response->assertOk();

        $response->assertJson([
            'success' => true,
            'message' => 'Role assigned successfully.',
            'data' => [
                'user_id' => $targetUser->id,
                'role' => 'Cashier',
                'business_id' => $business->id,
            ],
        ]);

        setPermissionsTeamId($business->id);

        expect(
            $targetUser->hasRole('Cashier')
        )->toBeTrue();
    }

    public function test_user_without_roles_assign_permission_cannot_assign_a_role(): void
    {
        $business = Business::factory()->create();

        $user = User::factory()->create();

        $targetUser = User::factory()->create();

        BusinessUser::create([
            'business_id' => $business->id,
            'user_id' => $user->id,
            'status' => 'active',
            'joined_at' => now(),
        ]);

        BusinessUser::create([
            'business_id' => $business->id,
            'user_id' => $targetUser->id,
            'status' => 'active',
            'joined_at' => now(),
        ]);

        $permission = Permission::firstOrCreate([
            'name' => 'business.view',
            'guard_name' => 'web',
        ]);

        $staffRole = Role::firstOrCreate([
            'name' => 'Staff',
            'guard_name' => 'web',
            'team_id' => $business->id,
        ]);

        $staffRole->syncPermissions([
            $permission,
        ]);

        Role::firstOrCreate([
            'name' => 'Cashier',
            'guard_name' => 'web',
            'team_id' => $business->id,
        ]);

        $this->actingAs($user);

        setPermissionsTeamId($business->id);

        $user->assignRole($staffRole);

        $response = $this->putJson(
            "/api/businesses/current/members/{$targetUser->id}/role",
            [
                'role' => 'Cashier',
            ]
        );

        $response->assertForbidden();
    }

    public function test_role_cannot_be_assigned_to_a_member_of_another_business(): void
    {
        $businessA = Business::factory()->create();

        $businessB = Business::factory()->create();

        $admin = User::factory()->create();

        $targetUser = User::factory()->create();

        BusinessUser::create([
            'business_id' => $businessA->id,
            'user_id' => $admin->id,
            'status' => 'active',
            'joined_at' => now(),
        ]);

        BusinessUser::create([
            'business_id' => $businessB->id,
            'user_id' => $targetUser->id,
            'status' => 'active',
            'joined_at' => now(),
        ]);

        $permission = Permission::firstOrCreate([
            'name' => 'roles.assign',
            'guard_name' => 'web',
        ]);

        $ownerRole = Role::firstOrCreate([
            'name' => 'Owner',
            'guard_name' => 'web',
            'team_id' => $businessA->id,
        ]);

        $ownerRole->syncPermissions([
            $permission,
        ]);

        Role::firstOrCreate([
            'name' => 'Cashier',
            'guard_name' => 'web',
            'team_id' => $businessA->id,
        ]);

        $this->actingAs($admin);

        setPermissionsTeamId($businessA->id);

        $admin->assignRole($ownerRole);

        $response = $this->putJson(
            "/api/businesses/current/members/{$targetUser->id}/role",
            [
                'role' => 'Cashier',
            ]
        );

        $response->assertForbidden();

        $response->assertJson([
            'message' => 'User does not belong to this business.',
        ]);
    }
}
