<?php

namespace Tests\Feature\Authorization;

use App\Domains\Identity\Services\RoleService;
use App\Domains\Organization\Models\Business;
use App\Domains\Organization\Models\BusinessUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class RoleAssignmentAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_role_can_be_assigned_to_a_member_of_the_business(): void
    {
        $business = Business::factory()->create();

        $user = User::factory()->create();

        BusinessUser::create([
            'business_id' => $business->id,
            'user_id' => $user->id,
            'status' => 'active',
            'joined_at' => now(),
        ]);

        Permission::firstOrCreate([
            'name' => 'business.view',
            'guard_name' => 'web',
        ]);

        $role = Role::firstOrCreate([
            'name' => 'Cashier',
            'guard_name' => 'web',
            'team_id' => $business->id,
        ]);

        $role->syncPermissions([
            Permission::where('name', 'business.view')
                ->where('guard_name', 'web')
                ->first(),
        ]);

        // Authenticate the user so MerchantOSTeamResolver
        // can determine the current business.
        $this->actingAs($user);

        // Set the current business/team.
        setPermissionsTeamId($business->id);

        $roleService = app(RoleService::class);

        $assignedRole = $roleService->assignRole(
            $user,
            'Cashier',
            $business->id
        );

        expect($assignedRole->name)->toBe('Cashier');

        expect(
            $user->hasRole('Cashier')
        )->toBeTrue();
    }

    public function test_role_cannot_be_assigned_to_a_user_from_another_business(): void
    {
        $businessA = Business::factory()->create();

        $businessB = Business::factory()->create();

        // This is the authenticated administrator/member of Business A.
        $admin = User::factory()->create();

        // This user belongs only to Business B.
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

        Role::firstOrCreate([
            'name' => 'Cashier',
            'guard_name' => 'web',
            'team_id' => $businessA->id,
        ]);

        // The authenticated user legitimately belongs to Business A.
        $this->actingAs($admin);

        setPermissionsTeamId($businessA->id);

        $roleService = app(RoleService::class);

        expect(fn () => $roleService->assignRole(
            $targetUser,
            'Cashier',
            $businessA->id
        ))->toThrow(
            \Symfony\Component\HttpKernel\Exception\HttpException::class
        );
    }
}