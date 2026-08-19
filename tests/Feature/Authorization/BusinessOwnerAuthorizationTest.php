<?php

namespace Tests\Feature\Authorization;

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
    $user = User::factory()->create();

    $business = Business::factory()->create();

    BusinessUser::create([
      'business_id' => $business->id,
      'user_id' => $user->id,
      'status' => 'active',
      'joined_at' => now(),
    ]);

    $permission = Permission::firstOrCreate([
      'name' => 'business.update',
      'guard_name' => 'web',
    ]);

    $role = Role::firstOrCreate([
      'name' => 'Owner',
      'guard_name' => 'web',
      'team_id' => $business->id,
    ]);

    $role->syncPermissions([
      $permission,
    ]);

    $this->actingAs($user);

    setPermissionsTeamId($business->id);

    $user->assignRole($role);

    $response = $this->putJson(
      "/api/businesses/{$business->id}",
      [
        'name' => 'Updated Business Name',
      ]
    );

    $response->assertOk();
    
    $this->assertDatabaseHas('businesses', [
      'id' => $business->id,
      'name' => 'Updated Business Name',
    ]);
  }
}
