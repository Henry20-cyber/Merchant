<?php

namespace Tests\Feature\Authorization;

use App\Domains\Organization\Models\Business;
use App\Domains\Organization\Models\BusinessUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class BusinessAuthorizationTest extends TestCase
{
  use RefreshDatabase;

  public function test_prevents_a_staff_user_from_updating_a_business(): void
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

    $role->syncPermissions([
      $permission,
    ]);

    $this->actingAs($user);

    setPermissionsTeamId($business->id);

    $user->assignRole($role);

    $response = $this->putJson(
      "/api/businesses/{$business->id}",
      [
        'name' => 'Malicious Update',
      ]
    );

    $response->assertForbidden();
  }
}
