<?php

namespace Tests\Feature\Authorization;

use App\Domains\Organization\Models\Business;
use App\Domains\Organization\Models\BusinessUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class BusinessMemberAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_with_users_view_permission_can_view_business_members(): void
    {
        $user = User::factory()->create();

        $member = User::factory()->create([
            'name' => 'Cashier User',
        ]);

        $business = Business::factory()->create();

        BusinessUser::create([
            'business_id' => $business->id,
            'user_id' => $user->id,
            'status' => 'active',
            'joined_at' => now(),
        ]);

        BusinessUser::create([
            'business_id' => $business->id,
            'user_id' => $member->id,
            'status' => 'active',
            'joined_at' => now(),
        ]);

        $permission = Permission::firstOrCreate([
            'name' => 'users.view',
            'guard_name' => 'web',
        ]);

        $role = Role::firstOrCreate([
            'name' => 'Manager',
            'guard_name' => 'web',
            'team_id' => $business->id,
        ]);

        $role->syncPermissions([$permission]);

        $this->actingAs($user);

        setPermissionsTeamId($business->id);

        $user->assignRole($role);

        $response = $this->getJson(
            '/api/businesses/current/members'
        );

        $response->assertOk();

        $response->assertJsonFragment([
            'name' => 'Cashier User',
        ]);
    }

    public function test_user_without_users_view_permission_cannot_view_members(): void
    {
        $user = User::factory()->create();

        $business = Business::factory()->create();

        BusinessUser::create([
            'business_id' => $business->id,
            'user_id' => $user->id,
            'status' => 'active',
            'joined_at' => now(),
        ]);

        $permission = Permission::firstOrCreate([
            'name' => 'business.view',
            'guard_name' => 'web',
        ]);

        $role = Role::firstOrCreate([
            'name' => 'Staff',
            'guard_name' => 'web',
            'team_id' => $business->id,
        ]);

        $role->syncPermissions([$permission]);

        $this->actingAs($user);

        setPermissionsTeamId($business->id);

        $user->assignRole($role);

        $response = $this->getJson(
            '/api/businesses/current/members'
        );

        $response->assertForbidden();
    }
}