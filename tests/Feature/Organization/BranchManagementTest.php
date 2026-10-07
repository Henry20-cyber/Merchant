<?php

namespace Tests\Feature\Organization;

use App\Models\User;
use App\Domains\Organization\Models\Branch;
use App\Domains\Organization\Models\Business;
use App\Domains\Organization\Models\BusinessUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;
use Tests\Support\CreatesSubscriptionForBusiness;
use Illuminate\Validation\ValidationException;

class BranchManagementTest extends TestCase
{
    use CreatesSubscriptionForBusiness;

    use RefreshDatabase;

    private Business $business;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->business = $this->createBusinessWithSubscription();

        $this->owner = User::factory()->create();

        BusinessUser::create([
            'business_id' => $this->business->id,
            'user_id' => $this->owner->id,
            'status' => 'active',
            'joined_at' => now(),
        ]);

        $this->createRoleWithPermissions(
            'Owner',
            [
                'business.view',
                'business.update',
                'branches.view',
                'branches.create',
                'branches.update',
            ]
        );

        setPermissionsTeamId($this->business->id);

        $this->owner->assignRole(
            Role::where('name', 'Owner')
                ->where('team_id', $this->business->id)
                ->firstOrFail()
        );
    }

    public function test_owner_can_list_business_branches(): void
    {
        $this->createBranch([
            'name' => 'Head Office',
            'code' => 'HO-TEST01',
            'city' => 'Owerri',
            'state' => 'Imo',
            'is_head_office' => true,
        ]);

        $this->createBranch([
            'name' => 'Owerri Branch',
            'code' => 'OW-TEST01',
            'city' => 'Owerri',
            'state' => 'Imo',
            'is_head_office' => false,
        ]);

        $response = $this
            ->actingAs($this->owner)
            ->withSession([
                'current_business_id' => $this->business->id,
            ])
            ->getJson('/api/businesses/current/branches');

        $response
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath(
                'data.0.name',
                'Head Office'
            );
    }

    public function test_branch_creation_is_blocked_at_plan_limit(): void
    {
        $business = $this->createBusinessWithSubscription([
            'branch_limit' => 1,
        ]);

        $service = app(\App\Domains\Organization\Services\BranchService::class);

        $service->create($business, [
            'name' => 'First Branch',
            'code' => 'BR-001',
            'city' => 'Owerri',
            'state' => 'Imo',
            'country' => 'Nigeria',
            'is_head_office' => false,
        ]);

        $this->expectException(ValidationException::class);

        $service->create($business, [
            'name' => 'Second Branch',
            'code' => 'BR-002',
            'city' => 'Owerri',
            'state' => 'Imo',
            'country' => 'Nigeria',
            'is_head_office' => false,
        ]);
    }

    public function test_owner_can_create_branch(): void
    {
        $response = $this
            ->actingAs($this->owner)
            ->withSession([
                'current_business_id' => $this->business->id,
            ])
            ->postJson('/api/businesses/current/branches', [
                'name' => 'Owerri Branch',
                'code' => 'OW-001',
                'phone' => '08012345678',
                'email' => 'owerri@example.com',
                'address' => 'Douglas Road',
                'city' => 'Owerri',
                'state' => 'Imo',
                'country' => 'Nigeria',
                'is_head_office' => false,
            ]);

        $response
            ->assertCreated()
            ->assertJsonPath(
                'data.name',
                'Owerri Branch'
            )
            ->assertJsonPath(
                'data.code',
                'OW-001'
            )
            ->assertJsonPath(
                'data.business_id',
                $this->business->id
            );

        $this->assertDatabaseHas('branches', [
            'business_id' => $this->business->id,
            'name' => 'Owerri Branch',
            'code' => 'OW-001',
            'is_head_office' => false,
        ]);
    }

    public function test_owner_can_create_head_office_when_none_exists(): void
    {
        $response = $this
            ->actingAs($this->owner)
            ->withSession([
                'current_business_id' => $this->business->id,
            ])
            ->postJson('/api/businesses/current/branches', [
                'name' => 'Head Office',
                'code' => 'HO-001',
                'address' => 'Douglas Road',
                'city' => 'Owerri',
                'state' => 'Imo',
                'country' => 'Nigeria',
                'is_head_office' => true,
            ]);

        $response
            ->assertCreated()
            ->assertJsonPath(
                'data.is_head_office',
                true
            );

        $this->assertDatabaseHas('branches', [
            'business_id' => $this->business->id,
            'code' => 'HO-001',
            'is_head_office' => true,
        ]);
    }

    public function test_business_cannot_create_second_head_office(): void
    {
        $this->createBranch([
            'name' => 'Existing Head Office',
            'code' => 'HO-EXISTING',
            'city' => 'Owerri',
            'state' => 'Imo',
            'is_head_office' => true,
        ]);

        $response = $this
            ->actingAs($this->owner)
            ->withSession([
                'current_business_id' => $this->business->id,
            ])
            ->postJson('/api/businesses/current/branches', [
                'name' => 'Second Head Office',
                'code' => 'HO-SECOND',
                'city' => 'Port Harcourt',
                'state' => 'Rivers',
                'country' => 'Nigeria',
                'is_head_office' => true,
            ]);

        $response
            ->assertStatus(422)
            ->assertJsonValidationErrors([
                'is_head_office',
            ]);

        $this->assertDatabaseMissing('branches', [
            'code' => 'HO-SECOND',
        ]);
    }

    public function test_owner_can_update_branch(): void
    {
        $branch = $this->createBranch([
            'name' => 'Old Branch Name',
            'code' => 'OLD-001',
            'city' => 'Owerri',
            'state' => 'Imo',
            'is_head_office' => false,
        ]);

        $response = $this
            ->actingAs($this->owner)
            ->withSession([
                'current_business_id' => $this->business->id,
            ])
            ->putJson(
                "/api/businesses/current/branches/{$branch->id}",
                [
                    'name' => 'Updated Branch',
                    'code' => 'UPDATED-001',
                    'city' => 'Port Harcourt',
                    'state' => 'Rivers',
                ]
            );

        $response
            ->assertOk()
            ->assertJsonPath(
                'data.name',
                'Updated Branch'
            )
            ->assertJsonPath(
                'data.code',
                'UPDATED-001'
            );

        $this->assertDatabaseHas('branches', [
            'id' => $branch->id,
            'name' => 'Updated Branch',
            'code' => 'UPDATED-001',
        ]);
    }

    public function test_existing_head_office_cannot_be_demoted(): void
    {
        $headOffice = $this->createBranch([
            'name' => 'Head Office',
            'code' => 'HO-001',
            'city' => 'Owerri',
            'state' => 'Imo',
            'is_head_office' => true,
        ]);

        $response = $this
            ->actingAs($this->owner)
            ->withSession([
                'current_business_id' => $this->business->id,
            ])
            ->putJson(
                "/api/businesses/current/branches/{$headOffice->id}",
                [
                    'is_head_office' => false,
                ]
            );

        $response
            ->assertStatus(422)
            ->assertJsonValidationErrors([
                'is_head_office',
            ]);

        $this->assertDatabaseHas('branches', [
            'id' => $headOffice->id,
            'is_head_office' => true,
        ]);
    }

    public function test_normal_branch_cannot_be_promoted_when_head_office_exists(): void
    {
        $this->createBranch([
            'name' => 'Head Office',
            'code' => 'HO-001',
            'city' => 'Owerri',
            'state' => 'Imo',
            'is_head_office' => true,
        ]);

        $branch = $this->createBranch([
            'name' => 'Owerri Branch',
            'code' => 'OW-001',
            'city' => 'Owerri',
            'state' => 'Imo',
            'is_head_office' => false,
        ]);

        $response = $this
            ->actingAs($this->owner)
            ->withSession([
                'current_business_id' => $this->business->id,
            ])
            ->putJson(
                "/api/businesses/current/branches/{$branch->id}",
                [
                    'is_head_office' => true,
                ]
            );

        $response
            ->assertStatus(422)
            ->assertJsonValidationErrors([
                'is_head_office',
            ]);

        $this->assertDatabaseHas('branches', [
            'id' => $branch->id,
            'is_head_office' => false,
        ]);
    }

    public function test_branch_from_another_business_is_not_accessible(): void
    {
        $otherBusiness = $this->createBusinessWithSubscription();

        $otherBranch = Branch::create([
            'business_id' => $otherBusiness->id,
            'name' => 'Other Business Branch',
            'code' => 'OTHER-001',
            'city' => 'Lagos',
            'state' => 'Lagos',
            'country' => 'Nigeria',
            'is_head_office' => false,
        ]);

        $response = $this
            ->actingAs($this->owner)
            ->withSession([
                'current_business_id' => $this->business->id,
            ])
            ->putJson(
                "/api/businesses/current/branches/{$otherBranch->id}",
                [
                    'name' => 'Hijacked Branch',
                ]
            );

        $response->assertNotFound();

        $this->assertDatabaseHas('branches', [
            'id' => $otherBranch->id,
            'name' => 'Other Business Branch',
        ]);
    }

    private function createBranch(
        array $attributes = []
    ): Branch {
        return Branch::create(array_merge([
            'business_id' => $this->business->id,
            'name' => 'Test Branch',
            'code' => 'BR-' . uniqid(),
            'phone' => null,
            'email' => null,
            'address' => null,
            'city' => 'Owerri',
            'state' => 'Imo',
            'country' => 'Nigeria',
            'is_head_office' => false,
        ], $attributes));
    }

    private function createRoleWithPermissions(
        string $roleName,
        array $permissions
    ): void {
        setPermissionsTeamId($this->business->id);

        foreach ($permissions as $permissionName) {
            Permission::findOrCreate(
                $permissionName,
                'web'
            );
        }

        $role = Role::findOrCreate(
            $roleName,
            'web'
        );

        $role->syncPermissions($permissions);
    }

    public function test_cashier_cannot_view_branches(): void
{
    $cashier = User::factory()->create();

    BusinessUser::create([
        'business_id' => $this->business->id,
        'user_id' => $cashier->id,
        'status' => 'active',
        'joined_at' => now(),
    ]);

    $this->createRoleWithPermissions(
        'Cashier',
        [
            'business.view',
        ]
    );

    setPermissionsTeamId($this->business->id);

    $cashier->assignRole(
        Role::where('name', 'Cashier')
            ->where('team_id', $this->business->id)
            ->firstOrFail()
    );

    $response = $this
        ->actingAs($cashier)
        ->withSession([
            'current_business_id' => $this->business->id,
        ])
        ->getJson('/api/businesses/current/branches');

    $response->assertForbidden();
}

public function test_inventory_staff_can_view_branches(): void
{
    $inventoryStaff = User::factory()->create();

    BusinessUser::create([
        'business_id' => $this->business->id,
        'user_id' => $inventoryStaff->id,
        'status' => 'active',
        'joined_at' => now(),
    ]);

    $this->createRoleWithPermissions(
        'Inventory Staff',
        [
            'business.view',
            'branches.view',
        ]
    );

    setPermissionsTeamId($this->business->id);

    $inventoryStaff->assignRole(
        Role::where('name', 'Inventory Staff')
            ->where('team_id', $this->business->id)
            ->firstOrFail()
    );

    $response = $this
        ->actingAs($inventoryStaff)
        ->withSession([
            'current_business_id' => $this->business->id,
        ])
        ->getJson('/api/businesses/current/branches');

    $response->assertOk();
}

public function test_inventory_staff_cannot_create_branch(): void
{
    $inventoryStaff = User::factory()->create();

    BusinessUser::create([
        'business_id' => $this->business->id,
        'user_id' => $inventoryStaff->id,
        'status' => 'active',
        'joined_at' => now(),
    ]);

    $this->createRoleWithPermissions(
        'Inventory Staff',
        [
            'business.view',
            'branches.view',
        ]
    );

    setPermissionsTeamId($this->business->id);

    $inventoryStaff->assignRole(
        Role::where('name', 'Inventory Staff')
            ->where('team_id', $this->business->id)
            ->firstOrFail()
    );

    $response = $this
        ->actingAs($inventoryStaff)
        ->withSession([
            'current_business_id' => $this->business->id,
        ])
        ->postJson(
            '/api/businesses/current/branches',
            [
                'name' => 'Unauthorized Branch',
                'code' => 'UNAUTH-001',
                'city' => 'Owerri',
                'state' => 'Imo',
                'country' => 'Nigeria',
                'is_head_office' => false,
            ]
        );

    $response->assertForbidden();
}

public function test_cashier_cannot_update_branch(): void
{
    $branch = $this->createBranch();

    $cashier = User::factory()->create();

    BusinessUser::create([
        'business_id' => $this->business->id,
        'user_id' => $cashier->id,
        'status' => 'active',
        'joined_at' => now(),
    ]);

    $this->createRoleWithPermissions(
        'Cashier',
        [
            'business.view',
        ]
    );

    setPermissionsTeamId($this->business->id);

    $cashier->assignRole(
        Role::where('name', 'Cashier')
            ->where('team_id', $this->business->id)
            ->firstOrFail()
    );

    $response = $this
        ->actingAs($cashier)
        ->withSession([
            'current_business_id' => $this->business->id,
        ])
        ->putJson(
            "/api/businesses/current/branches/{$branch->id}",
            [
                'name' => 'Unauthorized Update',
            ]
        );

    $response->assertForbidden();

    $this->assertDatabaseHas('branches', [
        'id' => $branch->id,
        'name' => 'Test Branch',
    ]);
}

public function test_owner_can_switch_current_branch(): void
{
    $headOffice = $this->createBranch([
        'name' => 'Head Office',
        'code' => 'HO-SWITCH',
        'city' => 'Owerri',
        'state' => 'Imo',
        'is_head_office' => true,
    ]);

    $branch = $this->createBranch([
        'name' => 'Port Harcourt Branch',
        'code' => 'PH-SWITCH',
        'city' => 'Port Harcourt',
        'state' => 'Rivers',
        'is_head_office' => false,
    ]);

    $response = $this
        ->actingAs($this->owner)
        ->withSession([
            'current_business_id' => $this->business->id,
        ])
        ->postJson(
            "/api/businesses/current/branches/{$branch->id}/switch"
        );

    $response
        ->assertOk()
        ->assertJsonPath(
            'success',
            true
        )
        ->assertJsonPath(
            'message',
            'Location switched successfully.'
        )
        ->assertJsonPath(
            'data.id',
            $branch->id
        )
        ->assertJsonPath(
            'data.name',
            'Port Harcourt Branch'
        );

    $this->assertEquals(
        $branch->id,
        session('current_branch_id')
    );
}

public function test_user_cannot_switch_to_branch_from_another_business(): void
{
    $otherBusiness = $this->createBusinessWithSubscription();

    $otherBranch = Branch::create([
        'business_id' => $otherBusiness->id,
        'name' => 'Other Business Branch',
        'code' => 'OTHER-SWITCH',
        'city' => 'Lagos',
        'state' => 'Lagos',
        'country' => 'Nigeria',
        'is_head_office' => false,
    ]);

    $response = $this
        ->actingAs($this->owner)
        ->withSession([
            'current_business_id' => $this->business->id,
        ])
        ->postJson(
            "/api/businesses/current/branches/{$otherBranch->id}/switch"
        );

    $response->assertNotFound();

    $this->assertNull(
        session('current_branch_id')
    );
}

public function test_user_without_branch_view_permission_cannot_switch_branch(): void
{
    $branch = $this->createBranch([
        'name' => 'Port Harcourt Branch',
        'code' => 'PH-NOVIEW',
        'city' => 'Port Harcourt',
        'state' => 'Rivers',
        'is_head_office' => false,
    ]);

    $cashier = User::factory()->create();

    BusinessUser::create([
        'business_id' => $this->business->id,
        'user_id' => $cashier->id,
        'status' => 'active',
        'joined_at' => now(),
    ]);

    $this->createRoleWithPermissions(
        'Branch Switch Cashier',
        [
            'business.view',
        ]
    );

    setPermissionsTeamId($this->business->id);

    $cashier->assignRole(
        Role::where('name', 'Branch Switch Cashier')
            ->where('team_id', $this->business->id)
            ->firstOrFail()
    );

    $response = $this
        ->actingAs($cashier)
        ->withSession([
            'current_business_id' => $this->business->id,
        ])
        ->postJson(
            "/api/businesses/current/branches/{$branch->id}/switch"
        );

    $response->assertForbidden();

    $this->assertNull(
        session('current_branch_id')
    );
}
}