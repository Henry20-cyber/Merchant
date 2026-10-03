<?php

namespace Tests\Feature\Organization;

use App\Domains\Identity\Services\RoleService;
use App\Domains\Organization\Models\Business;
use App\Domains\Organization\Models\BusinessJoinRequest;
use App\Domains\Organization\Models\BusinessType;
use App\Domains\Organization\Models\BusinessUser;
use Database\Seeders\PermissionSeeder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class BusinessJoinRequestControllerTest extends TestCase
{
    use RefreshDatabase;

    protected Business $business;

    protected User $owner;

    protected RoleService $roleService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PermissionSeeder::class);

        $this->roleService = app(RoleService::class);

        $this->owner = User::factory()->create();

        $businessType = BusinessType::factory()->create();

        $this->business = Business::create([
            'id' => (string) Str::uuid(),
            'business_type_id' => $businessType->id,
            'name' => 'Test Electronics Ltd.',
            'slug' => 'test-electronics-' . Str::lower(Str::random(6)),
            'merchant_id' => 'MCH-' . Str::upper(Str::random(6)),
            'email' => 'business' . Str::random(6) . '@example.com',
            'phone' => '080' . random_int(10000000, 99999999),
            'country' => 'Nigeria',
            'currency' => 'NGN',
            'timezone' => 'Africa/Lagos',
            'status' => 'trial',
        ]);

        /*
         * Provision the standard business roles.
         */
        $this->roleService->provisionBusinessRoles(
            $this->business->id
        );

        /*
         * Create owner membership.
         */
        BusinessUser::create([
            'id' => (string) Str::uuid(),
            'business_id' => $this->business->id,
            'user_id' => $this->owner->id,
            'status' => 'active',
            'joined_at' => now(),
        ]);

        /*
         * Assign Owner role.
         *
         * Owner assignment is intentionally handled by
         * assignOwner(), not the ordinary employee
         * assignRole() method.
         */
        $this->roleService->assignOwner(
            $this->owner,
            $this->business->id
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers
    |--------------------------------------------------------------------------
    */

    protected function createUser(): User
    {
        return User::factory()->create();
    }

    protected function createMember(
        string $roleName = 'Manager'
    ): User {
        $user = $this->createUser();

        BusinessUser::create([
            'id' => (string) Str::uuid(),
            'business_id' => $this->business->id,
            'user_id' => $user->id,
            'status' => 'active',
            'joined_at' => now(),
        ]);

        $this->roleService->assignRole(
            $user,
            $roleName,
            $this->business->id
        );

        return $user;
    }

    protected function createPendingRequest(
        ?User $user = null,
        ?int $requestedRoleId = null
    ): BusinessJoinRequest {
        $user ??= $this->createUser();

        return BusinessJoinRequest::create([
            'business_id' => $this->business->id,
            'user_id' => $user->id,
            'requested_role_id' => $requestedRoleId,
            'status' => 'pending',
            'requested_at' => now(),
        ]);
    }

    protected function role(string $roleName): Role
    {
        return Role::query()
            ->where('name', $roleName)
            ->where('guard_name', 'web')
            ->where('team_id', $this->business->id)
            ->firstOrFail();
    }

    /*
    |--------------------------------------------------------------------------
    | Store - Employee Join Request
    |--------------------------------------------------------------------------
    */

    public function test_authenticated_user_can_submit_join_request(): void
    {
        $employee = $this->createUser();

        $response = $this
            ->actingAs($employee)
            ->postJson('/api/businesses/join-requests', [
                'merchant_id' => $this->business->merchant_id,
            ]);

        $response
            ->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath(
                'data.join_request.business.id',
                $this->business->id
            );

        $this->assertDatabaseHas('business_join_requests', [
            'business_id' => $this->business->id,
            'user_id' => $employee->id,
            'status' => 'pending',
        ]);
    }

    public function test_employee_can_submit_requested_role(): void
    {
        $employee = $this->createUser();

        $managerRole = $this->role('Manager');

        $response = $this
            ->actingAs($employee)
            ->postJson('/api/businesses/join-requests', [
                'merchant_id' => $this->business->merchant_id,
                'requested_role_id' => $managerRole->id,
            ]);

        $response
            ->assertCreated()
            ->assertJsonPath(
                'data.join_request.requested_role_id',
                $managerRole->id
            );

        $this->assertDatabaseHas('business_join_requests', [
            'business_id' => $this->business->id,
            'user_id' => $employee->id,
            'requested_role_id' => $managerRole->id,
            'status' => 'pending',
        ]);
    }

    public function test_join_request_requires_merchant_id(): void
    {
        $employee = $this->createUser();

        $response = $this
            ->actingAs($employee)
            ->postJson('/api/businesses/join-requests', []);

        $response->assertUnprocessable();
    }

    public function test_unauthenticated_user_cannot_submit_join_request(): void
    {
        $response = $this->postJson(
            '/api/businesses/join-requests',
            [
                'merchant_id' => $this->business->merchant_id,
            ]
        );

        $response->assertUnauthorized();
    }

    /*
    |--------------------------------------------------------------------------
    | Index - Pending Join Requests
    |--------------------------------------------------------------------------
    */

    public function test_owner_can_view_pending_join_requests(): void
    {
        $employee = $this->createUser();

        $request = $this->createPendingRequest($employee);

        $response = $this
            ->actingAs($this->owner)
            ->withHeaders([
                'X-Business-ID' => $this->business->id,
            ])
            ->getJson('/api/businesses/current/join-requests');

        $response
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath(
                'data.join_requests.0.id',
                $request->id
            );
    }

    public function test_manager_can_view_pending_join_requests(): void
    {
        $manager = $this->createMember('Manager');

        $employee = $this->createUser();

        $request = $this->createPendingRequest($employee);

        $response = $this
            ->actingAs($manager)
            ->withHeaders([
                'X-Business-ID' => $this->business->id,
            ])
            ->getJson('/api/businesses/current/join-requests');

        $response
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath(
                'data.join_requests.0.id',
                $request->id
            );
    }

    public function test_cashier_cannot_view_pending_join_requests(): void
    {
        $cashier = $this->createMember('Cashier');

        $employee = $this->createUser();

        $request = $this->createPendingRequest($employee);

        $response = $this
            ->actingAs($cashier)
            ->withHeaders([
                'X-Business-ID' => $this->business->id,
            ])
            ->getJson('/api/businesses/current/join-requests');

        $response->assertForbidden();
    }

    /*
    |--------------------------------------------------------------------------
    | Approve
    |--------------------------------------------------------------------------
    */

    public function test_owner_can_approve_join_request(): void
    {
        $employee = $this->createUser();

        $request = $this->createPendingRequest(
            $employee,
            $this->role('Cashier')->id
        );

        $response = $this
            ->actingAs($this->owner)
            ->withHeaders([
                'X-Business-ID' => $this->business->id,
            ])
            ->postJson(
                "/api/businesses/current/join-requests/{$request->id}/approve",
                [
                    'role_id' => $this->role('Cashier')->id,
                ]
            );

        $response
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertDatabaseHas('business_join_requests', [
            'id' => $request->id,
            'status' => 'approved',
            'reviewed_by' => $this->owner->id,
        ]);

        $this->assertDatabaseHas('business_user', [
            'business_id' => $this->business->id,
            'user_id' => $employee->id,
            'status' => 'active',
        ]);
    }

    public function test_manager_can_approve_join_request(): void
    {
        $manager = $this->createMember('Manager');

        $employee = $this->createUser();

        $request = $this->createPendingRequest($employee);

        $role = $this->role('Cashier');

        $response = $this
            ->actingAs($manager)
            ->withHeaders([
                'X-Business-ID' => $this->business->id,
            ])
            ->postJson(
                "/api/businesses/current/join-requests/{$request->id}/approve",
                [
                    'role_id' => $role->id,
                ]
            );

        $response
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertDatabaseHas('business_join_requests', [
            'id' => $request->id,
            'status' => 'approved',
            'reviewed_by' => $manager->id,
        ]);
    }

    public function test_cashier_cannot_approve_join_request(): void
    {
        $cashier = $this->createMember('Cashier');

        $employee = $this->createUser();

        $request = $this->createPendingRequest($employee);

        $response = $this
            ->actingAs($cashier)
            ->withHeaders([
                'X-Business-ID' => $this->business->id,
            ])
            ->postJson(
                "/api/businesses/current/join-requests/{$request->id}/approve",
                [
                    'role_id' => $this->role('Cashier')->id,
                ]
            );

        $response->assertForbidden();

        $this->assertDatabaseHas('business_join_requests', [
            'id' => $request->id,
            'status' => 'pending',
        ]);
    }

    public function test_approve_requires_role_id(): void
    {
        $employee = $this->createUser();

        $request = $this->createPendingRequest($employee);

        $response = $this
            ->actingAs($this->owner)
            ->withHeaders([
                'X-Business-ID' => $this->business->id,
            ])
            ->postJson(
                "/api/businesses/current/join-requests/{$request->id}/approve",
                []
            );

        $response->assertUnprocessable();
    }

    /*
    |--------------------------------------------------------------------------
    | Reject
    |--------------------------------------------------------------------------
    */

    public function test_owner_can_reject_join_request(): void
    {
        $employee = $this->createUser();

        $request = $this->createPendingRequest($employee);

        $response = $this
            ->actingAs($this->owner)
            ->withHeaders([
                'X-Business-ID' => $this->business->id,
            ])
            ->postJson(
                "/api/businesses/current/join-requests/{$request->id}/reject",
                [
                    'rejection_reason' => 'We do not have an opening currently.',
                ]
            );

        $response
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertDatabaseHas('business_join_requests', [
            'id' => $request->id,
            'status' => 'rejected',
            'reviewed_by' => $this->owner->id,
            'rejection_reason' => 'We do not have an opening currently.',
        ]);

        $this->assertDatabaseMissing('business_user', [
            'business_id' => $this->business->id,
            'user_id' => $employee->id,
        ]);
    }

    public function test_manager_can_reject_join_request(): void
    {
        $manager = $this->createMember('Manager');

        $employee = $this->createUser();

        $request = $this->createPendingRequest($employee);

        $response = $this
            ->actingAs($manager)
            ->withHeaders([
                'X-Business-ID' => $this->business->id,
            ])
            ->postJson(
                "/api/businesses/current/join-requests/{$request->id}/reject",
                [
                    'rejection_reason' => 'Application rejected.',
                ]
            );

        $response
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertDatabaseHas('business_join_requests', [
            'id' => $request->id,
            'status' => 'rejected',
            'reviewed_by' => $manager->id,
            'rejection_reason' => 'Application rejected.',
        ]);
    }

    public function test_cashier_cannot_reject_join_request(): void
    {
        $cashier = $this->createMember('Cashier');

        $employee = $this->createUser();

        $request = $this->createPendingRequest($employee);

        $response = $this
            ->actingAs($cashier)
            ->withHeaders([
                'X-Business-ID' => $this->business->id,
            ])
            ->postJson(
                "/api/businesses/current/join-requests/{$request->id}/reject",
                [
                    'rejection_reason' => 'Rejected.',
                ]
            );

        $response->assertForbidden();

        $this->assertDatabaseHas('business_join_requests', [
            'id' => $request->id,
            'status' => 'pending',
        ]);
    }
}