<?php

namespace Tests\Feature\Identity;

use App\Domains\Identity\Services\RoleService;
use App\Domains\Organization\Models\Business;
use App\Domains\Organization\Models\BusinessType;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class EmployeeRegistrationControllerTest extends TestCase
{
    use RefreshDatabase;

    protected Business $business;

    protected function setUp(): void
    {
        parent::setUp();

        /*
         * PermissionSeeder is required because RoleService
         * provisions roles using the permissions already
         * present in the database.
         */
        $this->seed(PermissionSeeder::class);

        $businessType = BusinessType::factory()->create();

        $this->business = Business::factory()->create([
            'business_type_id' => $businessType->id,
            'merchant_id' => 'MCH-TEST01',
        ]);

        /*
         * Provision the business roles so that the employee
         * can request a legitimate business-scoped role.
         */
        app(RoleService::class)
            ->provisionBusinessRoles($this->business->id);
    }

    public function test_employee_can_register_and_submit_join_request(): void
    {
        $response = $this->postJson('/api/auth/register-employee', [
            'name' => 'John Employee',
            'email' => 'john@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'merchant_id' => $this->business->merchant_id,
        ]);

        $response
            ->assertCreated()
            ->assertJson([
                'success' => true,
            ])
            ->assertJsonPath(
                'data.user.email',
                'john@example.com'
            )
            ->assertJsonPath(
                'data.business.id',
                $this->business->id
            )
            ->assertJsonPath(
                'data.business.merchant_id',
                $this->business->merchant_id
            )
            ->assertJsonPath(
                'data.join_request.status',
                'pending'
            );

        $user = User::query()
            ->where('email', 'john@example.com')
            ->first();

        $this->assertNotNull($user);

        $this->assertTrue(
            Hash::check('password123', $user->password)
        );

        $this->assertDatabaseHas('business_join_requests', [
            'business_id' => $this->business->id,
            'user_id' => $user->id,
            'status' => 'pending',
        ]);

        /*
         * Registration must NOT automatically create
         * business membership.
         */
        $this->assertDatabaseMissing('business_user', [
            'business_id' => $this->business->id,
            'user_id' => $user->id,
        ]);
    }

    public function test_employee_can_request_a_business_role(): void
    {
        $role = \Spatie\Permission\Models\Role::query()
            ->where('team_id', $this->business->id)
            ->where('name', 'Cashier')
            ->firstOrFail();

        $response = $this->postJson('/api/auth/register-employee', [
            'name' => 'Jane Cashier',
            'email' => 'jane@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'merchant_id' => $this->business->merchant_id,
            'requested_role_id' => $role->id,
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath(
                'data.join_request.requested_role_id',
                $role->id
            );

        $user = User::query()
            ->where('email', 'jane@example.com')
            ->firstOrFail();

        $this->assertDatabaseHas('business_join_requests', [
            'business_id' => $this->business->id,
            'user_id' => $user->id,
            'requested_role_id' => $role->id,
            'status' => 'pending',
        ]);
    }

    public function test_employee_cannot_request_owner_role(): void
    {
        $ownerRole = \Spatie\Permission\Models\Role::query()
            ->where('team_id', $this->business->id)
            ->where('name', 'Owner')
            ->firstOrFail();

        $response = $this->postJson('/api/auth/register-employee', [
            'name' => 'Attempted Owner',
            'email' => 'attempted-owner@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'merchant_id' => $this->business->merchant_id,
            'requested_role_id' => $ownerRole->id,
        ]);

        $response->assertStatus(422);

        $this->assertDatabaseMissing('users', [
            'email' => 'attempted-owner@example.com',
        ]);
    }

    public function test_invalid_merchant_id_is_rejected(): void
    {
        $response = $this->postJson('/api/auth/register-employee', [
            'name' => 'Unknown Employee',
            'email' => 'unknown@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'merchant_id' => 'MCH-NOTFOUND',
        ]);

        $response->assertStatus(422);

        $this->assertDatabaseMissing('users', [
            'email' => 'unknown@example.com',
        ]);
    }

    public function test_duplicate_email_is_rejected(): void
    {
        User::factory()->create([
            'email' => 'existing@example.com',
        ]);

        $response = $this->postJson('/api/auth/register-employee', [
            'name' => 'Duplicate User',
            'email' => 'existing@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'merchant_id' => $this->business->merchant_id,
        ]);

        $response->assertStatus(422);
    }

    public function test_required_registration_fields_are_validated(): void
    {
        $response = $this->postJson('/api/auth/register-employee', []);

        $response
            ->assertStatus(422)
            ->assertJsonValidationErrors([
                'name',
                'email',
                'password',
                'password_confirmation',
                'merchant_id',
            ]);
    }

    public function test_password_confirmation_is_required(): void
    {
        $response = $this->postJson('/api/auth/register-employee', [
            'name' => 'Password Test',
            'email' => 'password@example.com',
            'password' => 'password123',
            'merchant_id' => $this->business->merchant_id,
        ]);

        $response
            ->assertStatus(422)
            ->assertJsonValidationErrors([
                'password_confirmation',
            ]);
    }

    public function test_employee_registration_does_not_assign_any_role(): void
    {
        $response = $this->postJson('/api/auth/register-employee', [
            'name' => 'No Role Employee',
            'email' => 'norole@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'merchant_id' => $this->business->merchant_id,
        ]);

        $response->assertCreated();

        $user = User::query()
            ->where('email', 'norole@example.com')
            ->firstOrFail();

        $this->assertDatabaseMissing('model_has_roles', [
            'model_id' => $user->id,
            'model_type' => $user->getMorphClass(),
            'team_id' => $this->business->id,
        ]);
    }

    public function test_registration_is_not_authenticated_as_an_employee(): void
    {
        $response = $this->postJson('/api/auth/register-employee', [
            'name' => 'Unauthenticated Employee',
            'email' => 'unauthenticated@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'merchant_id' => $this->business->merchant_id,
        ]);

        $response->assertCreated();

        /*
         * Creating the account does not automatically log
         * the employee into a business context.
         */
        $this->assertGuest('web');
    }
}
