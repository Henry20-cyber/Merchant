<?php

namespace Tests\Feature\Customer;

use App\Domains\Customer\Models\Customer;
use App\Domains\Customer\Services\CustomerService;
use App\Domains\Identity\Services\RoleService;
use App\Domains\Organization\Models\Business;
use App\Domains\Organization\Models\BusinessUser;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CustomerApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PermissionSeeder::class);
    }

    /**
     * Create an authenticated owner with a business.
     */
    private function ownerWithBusiness(): array
    {
        $business = Business::factory()->create();

        $owner = User::factory()->create();

        BusinessUser::create([
            'business_id' => $business->id,
            'user_id' => $owner->id,
            'status' => 'active',
            'joined_at' => now(),
        ]);

        $this->actingAs($owner);

        app(RoleService::class)->assignOwner(
            $owner,
            $business->id
        );

        return [$owner, $business];
    }

    /**
     * Add the current business to the request.
     */
    private function withBusiness(Business $business)
    {
        return $this->withHeader(
            'X-Business-ID',
            $business->id
        );
    }

    /**
     * Create a customer directly through the domain service.
     */
    private function createCustomer(
        Business $business,
        array $data = []
    ): Customer {
        return app(CustomerService::class)->create(
            $business,
            $data
        );
    }

    /**
     * Create a business user with the specified standard role.
     */
    private function userWithRole(
        Business $business,
        string $role
    ): User {
        $user = User::factory()->create();

        BusinessUser::create([
            'business_id' => $business->id,
            'user_id' => $user->id,
            'status' => 'active',
            'joined_at' => now(),
        ]);

        $this->actingAs($user);

        app(
            \App\Domains\Organization\Services\MerchantOSTeamResolver::class
        )->setPermissionsTeamId($business->id);

        app(RoleService::class)->provisionBusinessRoles(
            $business->id
        );

        app(RoleService::class)->assignRole(
            $user,
            $role,
            $business->id
        );

        return $user;
    }

    /*
    |--------------------------------------------------------------------------
    | Create
    |--------------------------------------------------------------------------
    */

    public function test_owner_can_create_customer(): void
    {
        [$owner, $business] = $this->ownerWithBusiness();

        $response = $this->withBusiness($business)
            ->postJson(
                '/api/businesses/current/customers',
                [
                    'name' => 'John Doe',
                    'phone' => '08012345678',
                ]
            );

        $response
            ->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath(
                'customer.customer_number',
                'CUS-000001'
            )
            ->assertJsonPath(
                'customer.name',
                'John Doe'
            )
            ->assertJsonPath(
                'customer.phone',
                '08012345678'
            )
            ->assertJsonPath(
                'customer.status',
                'active'
            );

        $this->assertDatabaseHas('customers', [
            'business_id' => $business->id,
            'customer_number' => 'CUS-000001',
            'name' => 'John Doe',
            'phone' => '08012345678',
        ]);
    }

    public function test_owner_can_create_customer_without_name_or_phone(): void
    {
        [$owner, $business] = $this->ownerWithBusiness();

        $response = $this->withBusiness($business)
            ->postJson(
                '/api/businesses/current/customers',
                []
            );

        $response
            ->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath(
                'customer.customer_number',
                'CUS-000001'
            )
            ->assertJsonPath(
                'customer.name',
                null
            )
            ->assertJsonPath(
                'customer.phone',
                null
            );

        $this->assertDatabaseHas('customers', [
            'business_id' => $business->id,
            'customer_number' => 'CUS-000001',
            'name' => null,
            'phone' => null,
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | List
    |--------------------------------------------------------------------------
    */

    public function test_owner_can_list_business_customers(): void
    {
        [$owner, $business] = $this->ownerWithBusiness();

        $this->createCustomer(
            $business,
            [
                'name' => 'John Doe',
                'phone' => '08011111111',
            ]
        );

        $this->createCustomer(
            $business,
            [
                'name' => 'Jane Doe',
                'phone' => '08022222222',
            ]
        );

        $response = $this->withBusiness($business)
            ->getJson(
                '/api/businesses/current/customers'
            );

        $response
            ->assertOk()
            ->assertJsonPath('success', true);

        $response->assertJsonFragment([
            'customer_number' => 'CUS-000001',
            'name' => 'John Doe',
        ]);

        $response->assertJsonFragment([
            'customer_number' => 'CUS-000002',
            'name' => 'Jane Doe',
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Show
    |--------------------------------------------------------------------------
    */

    public function test_owner_can_view_customer(): void
    {
        [$owner, $business] = $this->ownerWithBusiness();

        $customer = $this->createCustomer(
            $business,
            [
                'name' => 'John Doe',
            ]
        );

        $response = $this->withBusiness($business)
            ->getJson(
                "/api/businesses/current/customers/{$customer->id}"
            );

        $response
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath(
                'customer.id',
                $customer->id
            )
            ->assertJsonPath(
                'customer.customer_number',
                'CUS-000001'
            )
            ->assertJsonPath(
                'customer.name',
                'John Doe'
            );
    }

    /*
    |--------------------------------------------------------------------------
    | Update
    |--------------------------------------------------------------------------
    */

    public function test_owner_can_update_customer(): void
    {
        [$owner, $business] = $this->ownerWithBusiness();

        $customer = $this->createCustomer(
            $business,
            [
                'name' => 'John Doe',
                'phone' => '08011111111',
            ]
        );

        $response = $this->withBusiness($business)
            ->putJson(
                "/api/businesses/current/customers/{$customer->id}",
                [
                    'name' => 'John Smith',
                    'phone' => '08099999999',
                ]
            );

        $response
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath(
                'customer.name',
                'John Smith'
            )
            ->assertJsonPath(
                'customer.phone',
                '08099999999'
            )
            ->assertJsonPath(
                'customer.customer_number',
                'CUS-000001'
            );

        $this->assertDatabaseHas('customers', [
            'id' => $customer->id,
            'name' => 'John Smith',
            'phone' => '08099999999',
        ]);
    }

    public function test_customer_number_cannot_be_changed_through_api(): void
    {
        [$owner, $business] = $this->ownerWithBusiness();

        $customer = $this->createCustomer(
            $business,
            [
                'name' => 'John Doe',
            ]
        );

        $response = $this->withBusiness($business)
            ->putJson(
                "/api/businesses/current/customers/{$customer->id}",
                [
                    'customer_number' => 'CUS-999999',
                    'name' => 'John Smith',
                ]
            );

        $response
            ->assertOk()
            ->assertJsonPath(
                'customer.customer_number',
                'CUS-000001'
            )
            ->assertJsonPath(
                'customer.name',
                'John Smith'
            );
    }

    /*
    |--------------------------------------------------------------------------
    | Deactivate
    |--------------------------------------------------------------------------
    */

    public function test_owner_can_deactivate_customer(): void
    {
        [$owner, $business] = $this->ownerWithBusiness();

        $customer = $this->createCustomer(
            $business,
            [
                'name' => 'John Doe',
            ]
        );

        $response = $this->withBusiness($business)
            ->deleteJson(
                "/api/businesses/current/customers/{$customer->id}"
            );

        $response
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath(
                'customer.status',
                'inactive'
            );

        $this->assertDatabaseHas('customers', [
            'id' => $customer->id,
            'status' => 'inactive',
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Permissions
    |--------------------------------------------------------------------------
    */

    public function test_user_without_customer_view_permission_cannot_list_customers(): void
    {
        [$owner, $business] = $this->ownerWithBusiness();

        /*
         * Inventory Staff does not have customer permissions.
         */
        $inventoryStaff = $this->userWithRole(
            $business,
            'Inventory Staff'
        );

        $response = $this->withBusiness($business)
            ->getJson(
                '/api/businesses/current/customers'
            );

        $response->assertForbidden();
    }

    public function test_user_without_customer_create_permission_cannot_create_customer(): void
    {
        [$owner, $business] = $this->ownerWithBusiness();

        /*
         * Inventory Staff does not have customers.create.
         */
        $inventoryStaff = $this->userWithRole(
            $business,
            'Inventory Staff'
        );

        $response = $this->withBusiness($business)
            ->postJson(
                '/api/businesses/current/customers',
                [
                    'name' => 'Unauthorized Customer',
                ]
            );

        $response->assertForbidden();

        $this->assertDatabaseMissing('customers', [
            'business_id' => $business->id,
            'name' => 'Unauthorized Customer',
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Business Isolation
    |--------------------------------------------------------------------------
    */

    public function test_customer_from_another_business_cannot_be_viewed(): void
    {
        [$ownerA, $businessA] = $this->ownerWithBusiness();

        $businessB = Business::factory()->create();

        $customerB = $this->createCustomer(
            $businessB,
            [
                'name' => 'Business B Customer',
            ]
        );

        $response = $this->withBusiness($businessA)
            ->getJson(
                "/api/businesses/current/customers/{$customerB->id}"
            );

        $response->assertNotFound();
    }

    public function test_customer_from_another_business_cannot_be_updated(): void
    {
        [$ownerA, $businessA] = $this->ownerWithBusiness();

        $businessB = Business::factory()->create();

        $customerB = $this->createCustomer(
            $businessB,
            [
                'name' => 'Business B Customer',
            ]
        );

        $response = $this->withBusiness($businessA)
            ->putJson(
                "/api/businesses/current/customers/{$customerB->id}",
                [
                    'name' => 'Hacked Customer',
                ]
            );

        $response->assertNotFound();

        $this->assertDatabaseHas('customers', [
            'id' => $customerB->id,
            'business_id' => $businessB->id,
            'name' => 'Business B Customer',
        ]);
    }

    public function test_customer_from_another_business_cannot_be_deactivated(): void
    {
        [$ownerA, $businessA] = $this->ownerWithBusiness();

        $businessB = Business::factory()->create();

        $customerB = $this->createCustomer(
            $businessB,
            [
                'name' => 'Business B Customer',
            ]
        );

        $response = $this->withBusiness($businessA)
            ->deleteJson(
                "/api/businesses/current/customers/{$customerB->id}"
            );

        $response->assertNotFound();

        $this->assertDatabaseHas('customers', [
            'id' => $customerB->id,
            'business_id' => $businessB->id,
            'status' => 'active',
        ]);
    }

    public function test_customer_list_is_scoped_to_current_business(): void
    {
        [$owner, $businessA] = $this->ownerWithBusiness();

        $businessB = Business::factory()->create();

        $this->createCustomer(
            $businessA,
            [
                'name' => 'Business A Customer',
            ]
        );

        $this->createCustomer(
            $businessB,
            [
                'name' => 'Business B Customer',
            ]
        );

        $response = $this->withBusiness($businessA)
            ->getJson(
                '/api/businesses/current/customers'
            );

        $response
            ->assertOk()
            ->assertJsonFragment([
                'name' => 'Business A Customer',
            ]);

        $response->assertJsonMissing([
            'name' => 'Business B Customer',
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Authentication
    |--------------------------------------------------------------------------
    */

    public function test_unauthenticated_user_cannot_access_customers(): void
    {
        $business = Business::factory()->create();

        $response = $this->withBusiness($business)
            ->getJson(
                '/api/businesses/current/customers'
            );

        $response->assertUnauthorized();
    }

    /*
    |--------------------------------------------------------------------------
    | Validation
    |--------------------------------------------------------------------------
    */

    public function test_customer_validation_rejects_invalid_name(): void
    {
        [$owner, $business] = $this->ownerWithBusiness();

        $response = $this->withBusiness($business)
            ->postJson(
                '/api/businesses/current/customers',
                [
                    'name' => str_repeat('A', 256),
                ]
            );

        $response->assertUnprocessable();
    }

    public function test_customer_validation_rejects_invalid_phone(): void
    {
        [$owner, $business] = $this->ownerWithBusiness();

        $response = $this->withBusiness($business)
            ->postJson(
                '/api/businesses/current/customers',
                [
                    'phone' => str_repeat('1', 51),
                ]
            );

        $response->assertUnprocessable();
    }
}