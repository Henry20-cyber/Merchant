<?php

namespace Tests\Feature\Organization;

use App\Domains\Identity\Services\RoleService;
use App\Domains\Organization\Models\Business;
use App\Domains\Organization\Models\BusinessJoinRequest;
use App\Domains\Organization\Models\BusinessUser;
use App\Domains\Organization\Services\BusinessJoinRequestService;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;
use Tests\TestCase;
use Tests\Support\CreatesSubscriptionForBusiness;

class BusinessJoinRequestServiceTest extends TestCase
{
    use CreatesSubscriptionForBusiness;

    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed();
    }

    private function createUser(array $attributes = []): User
    {
        return User::factory()->create(array_merge([
            'password' => Hash::make('password'),
        ], $attributes));
    }

    private function createBusinessOwner(): array
    {
        $owner = $this->createUser();

        $business = $this->createBusinessWithSubscription();

        $business->update([
            'merchant_id' => 'MCH-' . strtoupper(str()->random(6)),
        ]);

        BusinessUser::create([
            'business_id' => $business->id,
            'user_id' => $owner->id,
            'status' => 'active',
            'joined_at' => now(),
        ]);

        app(RoleService::class)->assignOwner(
            $owner,
            $business->id
        );

        return [$business, $owner];
    }

    private function addMemberWithRole(
        Business $business,
        string $roleName,
    ): User {
        $user = $this->createUser();

        BusinessUser::create([
            'business_id' => $business->id,
            'user_id' => $user->id,
            'status' => 'active',
            'joined_at' => now(),
        ]);

        app(RoleService::class)->assignRole(
            $user,
            $roleName,
            $business->id
        );

        return $user;
    }


    public function test_user_can_submit_join_request_using_merchant_id(): void
    {
        [$business, $owner] = $this->createBusinessOwner();

        $employee = $this->createUser();

        $managerRole = Role::query()
            ->where('name', 'Manager')
            ->where('team_id', $business->id)
            ->firstOrFail();

        $service = app(BusinessJoinRequestService::class);

        $request = $service->submit(
            $employee,
            $business->merchant_id,
            $managerRole->id
        );

        $this->assertDatabaseHas('business_join_requests', [
            'id' => $request->id,
            'business_id' => $business->id,
            'user_id' => $employee->id,
            'requested_role_id' => $managerRole->id,
            'status' => 'pending',
        ]);

        $this->assertEquals(
            $business->id,
            $request->business_id
        );

        $this->assertEquals(
            $employee->id,
            $request->user_id
        );

        $this->assertEquals(
            $managerRole->id,
            $request->requested_role_id
        );
    }

    public function test_user_can_submit_join_request_without_requesting_a_role(): void
    {
        [$business, $owner] = $this->createBusinessOwner();

        $employee = $this->createUser();

        $service = app(BusinessJoinRequestService::class);

        $request = $service->submit(
            $employee,
            $business->merchant_id
        );

        $this->assertDatabaseHas('business_join_requests', [
            'id' => $request->id,
            'business_id' => $business->id,
            'user_id' => $employee->id,
            'requested_role_id' => null,
            'status' => 'pending',
        ]);
    }

    public function test_join_request_fails_when_business_does_not_exist(): void
    {
        $employee = $this->createUser();

        $service = app(BusinessJoinRequestService::class);

        $this->expectException(ValidationException::class);

        $service->submit(
            $employee,
            'MCH-NOTFOUND'
        );
    }

    public function test_existing_member_cannot_submit_join_request(): void
    {
        [$business, $owner] = $this->createBusinessOwner();

        $service = app(BusinessJoinRequestService::class);

        $this->expectException(ValidationException::class);

        $service->submit(
            $owner,
            $business->merchant_id
        );
    }

    public function test_duplicate_pending_join_request_is_rejected(): void
    {
        [$business, $owner] = $this->createBusinessOwner();

        $employee = $this->createUser();

        $service = app(BusinessJoinRequestService::class);

        $service->submit(
            $employee,
            $business->merchant_id
        );

        $this->expectException(ValidationException::class);

        $service->submit(
            $employee,
            $business->merchant_id
        );
    }

    public function test_employee_cannot_request_owner_role(): void
    {
        [$business, $owner] = $this->createBusinessOwner();

        $employee = $this->createUser();

        $ownerRole = Role::query()
            ->where('name', 'Owner')
            ->where('team_id', $business->id)
            ->firstOrFail();

        $service = app(BusinessJoinRequestService::class);

        $this->expectException(ValidationException::class);

        $service->submit(
            $employee,
            $business->merchant_id,
            $ownerRole->id
        );
    }

    public function test_employee_cannot_request_role_from_another_business(): void
    {
        [$business, $owner] = $this->createBusinessOwner();

        [$otherBusiness, $otherOwner] = $this->createBusinessOwner();

        $employee = $this->createUser();

        $otherManagerRole = Role::query()
            ->where('name', 'Manager')
            ->where('team_id', $otherBusiness->id)
            ->firstOrFail();

        $service = app(BusinessJoinRequestService::class);

        $this->expectException(ValidationException::class);

        $service->submit(
            $employee,
            $business->merchant_id,
            $otherManagerRole->id
        );
    }

    public function test_business_can_retrieve_pending_join_requests(): void
    {
        [$business, $owner] = $this->createBusinessOwner();

        $employeeOne = $this->createUser();
        $employeeTwo = $this->createUser();

        $service = app(BusinessJoinRequestService::class);

        $service->submit(
            $employeeOne,
            $business->merchant_id
        );

        $service->submit(
            $employeeTwo,
            $business->merchant_id
        );

        $pending = $service->pending($business);

        $this->assertCount(2, $pending);

        $this->assertTrue(
            $pending->contains(
                fn(BusinessJoinRequest $request) =>
                $request->user_id === $employeeOne->id
            )
        );

        $this->assertTrue(
            $pending->contains(
                fn(BusinessJoinRequest $request) =>
                $request->user_id === $employeeTwo->id
            )
        );
    }

    public function test_approval_is_blocked_when_user_limit_is_reached(): void
    {
        [$business, $owner] = $this->createBusinessOwner();

        $business->subscription->plan->update([
            'user_limit' => 1,
        ]);

        $employee = $this->createUser();

        $request = app(BusinessJoinRequestService::class)->submit(
            $employee,
            $business->merchant_id,
        );

        $managerRole = Role::query()
            ->where('name', 'Manager')
            ->where('team_id', $business->id)
            ->firstOrFail();

        $this->expectException(ValidationException::class);

        app(BusinessJoinRequestService::class)->approve(
            $request,
            $owner,
            $managerRole->id,
            $business,
        );

        $this->assertDatabaseMissing('business_user', [
            'business_id' => $business->id,
            'user_id' => $employee->id,
            'status' => 'active',
        ]);
    }

    public function test_approved_join_request_creates_active_membership_and_assigns_role(): void
    {
        [$business, $owner] = $this->createBusinessOwner();

        $employee = $this->createUser();

        $managerRole = Role::query()
            ->where('name', 'Manager')
            ->where('team_id', $business->id)
            ->firstOrFail();

        $service = app(BusinessJoinRequestService::class);

        $request = $service->submit(
            $employee,
            $business->merchant_id,
            $managerRole->id
        );

        $approved = $service->approve(
            $request,
            $owner,
            $managerRole->id,
            $business
        );

        $this->assertEquals(
            'approved',
            $approved->status
        );

        $this->assertEquals(
            $owner->id,
            $approved->reviewed_by
        );

        $this->assertNotNull(
            $approved->reviewed_at
        );

        $this->assertDatabaseHas('business_user', [
            'business_id' => $business->id,
            'user_id' => $employee->id,
            'status' => 'active',
        ]);

        $this->assertDatabaseHas('model_has_roles', [
            'role_id' => $managerRole->id,
            'model_id' => $employee->id,
            'team_id' => $business->id,
        ]);
    }

    public function test_approver_can_override_requested_role(): void
    {
        [$business, $owner] = $this->createBusinessOwner();

        $employee = $this->createUser();

        $managerRole = Role::query()
            ->where('name', 'Manager')
            ->where('team_id', $business->id)
            ->firstOrFail();

        $cashierRole = Role::query()
            ->where('name', 'Cashier')
            ->where('team_id', $business->id)
            ->firstOrFail();

        $service = app(BusinessJoinRequestService::class);

        $request = $service->submit(
            $employee,
            $business->merchant_id,
            $managerRole->id
        );

        $service->approve(
            $request,
            $owner,
            $cashierRole->id,
            $business
        );

        $this->assertDatabaseHas('model_has_roles', [
            'role_id' => $cashierRole->id,
            'model_id' => $employee->id,
            'team_id' => $business->id,
        ]);

        $this->assertDatabaseMissing('model_has_roles', [
            'role_id' => $managerRole->id,
            'model_id' => $employee->id,
            'team_id' => $business->id,
        ]);
    }

    public function test_owner_role_cannot_be_assigned_during_employee_approval(): void
    {
        [$business, $owner] = $this->createBusinessOwner();

        $employee = $this->createUser();

        $request = app(BusinessJoinRequestService::class)->submit(
            $employee,
            $business->merchant_id
        );

        $ownerRole = Role::query()
            ->where('name', 'Owner')
            ->where('team_id', $business->id)
            ->firstOrFail();

        $service = app(BusinessJoinRequestService::class);

        $this->expectException(ValidationException::class);

        $service->approve(
            $request,
            $owner,
            $ownerRole->id,
            $business
        );
    }

    public function test_rejected_join_request_is_not_active_membership(): void
    {
        [$business, $owner] = $this->createBusinessOwner();

        $employee = $this->createUser();

        $service = app(BusinessJoinRequestService::class);

        $request = $service->submit(
            $employee,
            $business->merchant_id
        );

        $rejected = $service->reject(
            $request,
            $owner,
            $business,
            'Position is no longer available.'
        );

        $this->assertEquals(
            'rejected',
            $rejected->status
        );

        $this->assertEquals(
            $owner->id,
            $rejected->reviewed_by
        );

        $this->assertEquals(
            'Position is no longer available.',
            $rejected->rejection_reason
        );

        $this->assertDatabaseMissing('business_user', [
            'business_id' => $business->id,
            'user_id' => $employee->id,
            'status' => 'active',
        ]);
    }

    public function test_non_member_cannot_approve_join_request(): void
    {
        [$business, $owner] = $this->createBusinessOwner();

        $employee = $this->createUser();
        $outsider = $this->createUser();

        $service = app(BusinessJoinRequestService::class);

        $request = $service->submit(
            $employee,
            $business->merchant_id
        );

        $cashierRole = Role::query()
            ->where('name', 'Cashier')
            ->where('team_id', $business->id)
            ->firstOrFail();

        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);

        $service->approve(
            $request,
            $outsider,
            $cashierRole->id,
            $business
        );
    }

    public function test_non_member_cannot_reject_join_request(): void
    {
        [$business, $owner] = $this->createBusinessOwner();

        $employee = $this->createUser();
        $outsider = $this->createUser();

        $service = app(BusinessJoinRequestService::class);

        $request = $service->submit(
            $employee,
            $business->merchant_id
        );

        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);

        $service->reject(
            $request,
            $outsider,
            $business,
            'Not authorized.'
        );
    }

    public function test_manager_can_approve_join_request(): void
    {
        [$business, $owner] = $this->createBusinessOwner();

        $manager = $this->addMemberWithRole($business, 'Manager');
        $employee = $this->createUser();

        $service = app(BusinessJoinRequestService::class);

        $request = $service->submit(
            $employee,
            $business->merchant_id
        );

        $cashierRole = Role::query()
            ->where('name', 'Cashier')
            ->where('team_id', $business->id)
            ->firstOrFail();

        $approved = $service->approve(
            $request,
            $manager,
            $cashierRole->id,
            $business
        );

        $this->assertEquals('approved', $approved->status);

        $this->assertDatabaseHas('business_user', [
            'business_id' => $business->id,
            'user_id' => $employee->id,
            'status' => 'active',
        ]);
    }

    public function test_manager_can_reject_join_request(): void
    {
        [$business, $owner] = $this->createBusinessOwner();

        $manager = $this->addMemberWithRole($business, 'Manager');
        $employee = $this->createUser();

        $service = app(BusinessJoinRequestService::class);

        $request = $service->submit(
            $employee,
            $business->merchant_id
        );

        $rejected = $service->reject(
            $request,
            $manager,
            $business,
            'Application rejected.'
        );

        $this->assertEquals('rejected', $rejected->status);

        $this->assertEquals(
            $manager->id,
            $rejected->reviewed_by
        );
    }

    public function test_cashier_cannot_approve_join_request(): void
    {
        [$business, $owner] = $this->createBusinessOwner();

        $cashier = $this->addMemberWithRole($business, 'Cashier');
        $employee = $this->createUser();

        $service = app(BusinessJoinRequestService::class);

        $request = $service->submit(
            $employee,
            $business->merchant_id
        );

        $cashierRole = Role::query()
            ->where('name', 'Cashier')
            ->where('team_id', $business->id)
            ->firstOrFail();

        $this->expectException(
            \Symfony\Component\HttpKernel\Exception\HttpException::class
        );

        $service->approve(
            $request,
            $cashier,
            $cashierRole->id,
            $business
        );
    }

    public function test_cashier_cannot_reject_join_request(): void
    {
        [$business, $owner] = $this->createBusinessOwner();

        $cashier = $this->addMemberWithRole($business, 'Cashier');
        $employee = $this->createUser();

        $service = app(BusinessJoinRequestService::class);

        $request = $service->submit(
            $employee,
            $business->merchant_id
        );

        $this->expectException(
            \Symfony\Component\HttpKernel\Exception\HttpException::class
        );

        $service->reject(
            $request,
            $cashier,
            $business,
            'Not authorized.'
        );
    }
}
