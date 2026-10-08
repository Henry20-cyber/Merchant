<?php

namespace Tests\Feature\Organization;

use App\Domains\Organization\Models\Business;
use App\Domains\Organization\Models\BusinessUser;
use App\Domains\Organization\Services\BusinessContextService;
use App\Domains\Organization\Services\BusinessService;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class BusinessVatSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_business_owner_can_toggle_vat_from_business_settings(): void
    {
        $business = Business::factory()->create([
            'vat_enabled' => false,
        ]);

        $user = User::factory()->create();

        BusinessUser::create([
            'business_id' => $business->id,
            'user_id' => $user->id,
            'status' => 'active',
            'joined_at' => now(),
        ]);

        setPermissionsTeamId($business->id);

        $permission = Permission::firstOrCreate([
            'name' => 'business.update',
            'guard_name' => 'web',
        ]);

        $role = Role::firstOrCreate([
            'name' => 'Owner',
            'guard_name' => 'web',
            'team_id' => $business->id,
        ]);

        $role->syncPermissions([$permission]);

        $user->assignRole($role);

        app(BusinessContextService::class)->set(
            $user,
            $business
        );

        $this->actingAs($user);

        $response = $this->putJson(
            "/api/businesses/{$business->id}",
            [
                'vat_enabled' => true,
            ]
        );

        $response->assertOk();

        $response->assertJsonPath(
            'data.vat_enabled',
            true
        );

        $this->assertDatabaseHas('businesses', [
            'id' => $business->id,
            'vat_enabled' => true,
        ]);
    }
}
