<?php

namespace Tests\Feature\Authorization;

use App\Domains\Organization\Models\Business;
use App\Domains\Organization\Models\BusinessUser;
use App\Models\User;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class BusinessTenantIsolationTest extends TestCase
{
    public function test_owner_cannot_update_another_business(): void
    {
        // User A
        $userA = User::factory()->create();

        // Business A
        $businessA = Business::factory()->create([
            'name' => 'Business A',
        ]);

        // Business B
        $businessB = Business::factory()->create([
            'name' => 'Business B',
        ]);

        // User A belongs ONLY to Business A
        BusinessUser::create([
            'business_id' => $businessA->id,
            'user_id' => $userA->id,
            'status' => 'active',
            'joined_at' => now(),
        ]);

        // Permission
        $permission = Permission::firstOrCreate([
            'name' => 'business.update',
            'guard_name' => 'web',
        ]);

        // Owner role for Business A
        $role = Role::firstOrCreate([
            'name' => 'Owner',
            'guard_name' => 'web',
            'team_id' => $businessA->id,
        ]);

        $role->syncPermissions([
            $permission,
        ]);

        // Authenticate User A
        $this->actingAs($userA);

        // Establish Business A context
        setPermissionsTeamId($businessA->id);

        // Give User A Owner role for Business A
        $userA->assignRole($role);

        // Attempt to update Business B
        $response = $this->putJson(
            "/api/businesses/{$businessB->id}",
            [
                'name' => 'Malicious Cross-Tenant Update',
            ]
        );

        // User A must not be able to access Business B
        $response->assertNotFound();

        // Business B must remain unchanged
        $this->assertDatabaseHas('businesses', [
            'id' => $businessB->id,
            'name' => 'Business B',
        ]);
    }
}