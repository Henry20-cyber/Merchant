<?php

namespace Tests\Feature\Authorization;

use App\Domains\Identity\Support\PermissionCatalog;
use App\Domains\Organization\Models\Business;
use App\Domains\Organization\Models\BusinessUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class BusinessOwnerAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_authenticated_owner_with_business_update_permission_can_successfully_update_their_business(): void
    {
        $business = Business::factory()->create();

        $user = User::factory()->create();

        BusinessUser::create([
            'business_id' => $business->id,
            'user_id' => $user->id,
            'status' => 'active',
            'joined_at' => now(),
        ]);

        $this->createPermissions();

        /*
         * Set the Spatie permission team to the current business.
         */
        setPermissionsTeamId($business->id);

        /*
         * Create the Owner role for this business.
         */
        $role = Role::firstOrCreate([
            'name' => 'Owner',
            'guard_name' => 'web',
            'team_id' => $business->id,
        ]);

        /*
         * The Owner needs the business.update permission
         * for the route middleware to authorize the request.
         */
        $role->syncPermissions([
            Permission::where(
                'name',
                'business.update'
            )->firstOrFail(),
        ]);

        /*
         * Assign the Owner role directly through Spatie.
         *
         * This test predates the protected-owner service flow
         * and is specifically testing business authorization.
         */
        $user->assignRole($role);

        /*
         * Establish the current business context.
         */
        app(\App\Domains\Organization\Services\BusinessContextService::class)
            ->set($user, $business);

        /*
         * Authenticate the owner.
         */
        $this->actingAs($user);

        /*
         * Update the business.
         */
        $response = $this->putJson(
            "/api/businesses/{$business->id}",
            [
                'name' => 'Updated Business Name',
            ]
        );

        $response->assertOk();

        $response->assertJsonPath(
            'success',
            true
        );

        $this->assertDatabaseHas('businesses', [
            'id' => $business->id,
            'name' => 'Updated Business Name',
        ]);
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