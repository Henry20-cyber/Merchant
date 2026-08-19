<?php

namespace Tests\Feature\Identity;

use App\Domains\Identity\Services\RoleManagementService;
use App\Domains\Identity\Support\PermissionCatalog;
use App\Domains\Organization\Models\Business;
use App\Domains\Organization\Models\BusinessUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class RoleManagementServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_custom_role_is_created_for_the_correct_business(): void
    {
        $business = Business::factory()->create();

        $user = $this->createBusinessMember(
            $business
        );

        $this->createPermissions();

        $service = app(RoleManagementService::class);

        $role = $service->create(
            $user,
            $business->id,
            'Sales Assistant',
            [
                'business.view',
                'users.view',
            ]
        );

        expect($role->name)
            ->toBe('Sales Assistant');

        expect($role->team_id)
            ->toBe($business->id);

        expect($role->is_system)
            ->toBeFalse();

        expect($role->permissions->pluck('name')->sort()->values()->all())
            ->toBe([
                'business.view',
                'users.view',
            ]);
    }

    public function test_custom_role_rejects_unknown_permissions(): void
    {
        $business = Business::factory()->create();

        $user = $this->createBusinessMember(
            $business
        );

        $this->createPermissions();

        $service = app(RoleManagementService::class);

        expect(fn () => $service->create(
            $user,
            $business->id,
            'Malicious Role',
            [
                'business.view',
                'nuclear.launch',
            ]
        ))->toThrow(
            \Illuminate\Validation\ValidationException::class
        );

        expect(
            Role::where('name', 'Malicious Role')->exists()
        )->toBeFalse();
    }

    public function test_user_from_another_business_cannot_create_role(): void
    {
        $businessA = Business::factory()->create();
        $businessB = Business::factory()->create();

        $user = $this->createBusinessMember(
            $businessA
        );

        $this->createPermissions();

        $service = app(RoleManagementService::class);

        expect(fn () => $service->create(
            $user,
            $businessB->id,
            'Unauthorized Role',
            [
                'business.view',
            ]
        ))->toThrow(
            \Symfony\Component\HttpKernel\Exception\HttpException::class
        );

        expect(
            Role::where('team_id', $businessB->id)
                ->where('name', 'Unauthorized Role')
                ->exists()
        )->toBeFalse();
    }

    public function test_duplicate_permissions_are_stored_only_once(): void
    {
        $business = Business::factory()->create();

        $user = $this->createBusinessMember(
            $business
        );

        $this->createPermissions();

        $service = app(RoleManagementService::class);

        $role = $service->create(
            $user,
            $business->id,
            'Sales Assistant',
            [
                'business.view',
                'business.view',
                'users.view',
                'users.view',
            ]
        );

        expect(
            $role->permissions->pluck('name')->sort()->values()->all()
        )->toBe([
            'business.view',
            'users.view',
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