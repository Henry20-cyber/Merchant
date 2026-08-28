<?php

namespace Tests\Feature\Customer;

use App\Domains\Customer\Models\Customer;
use App\Domains\Customer\Services\CustomerService;
use App\Domains\Organization\Models\Business;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CustomerServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_first_customer_number_is_generated(): void
    {
        $business = Business::factory()->create();

        $customer = app(CustomerService::class)->create(
            $business,
            []
        );

        $this->assertEquals(
            'CUS-000001',
            $customer->customer_number
        );
    }

    public function test_customer_numbers_increment_per_business(): void
    {
        $business = Business::factory()->create();

        $service = app(CustomerService::class);

        $customerOne = $service->create(
            $business,
            []
        );

        $customerTwo = $service->create(
            $business,
            []
        );

        $customerThree = $service->create(
            $business,
            []
        );

        $this->assertEquals(
            'CUS-000001',
            $customerOne->customer_number
        );

        $this->assertEquals(
            'CUS-000002',
            $customerTwo->customer_number
        );

        $this->assertEquals(
            'CUS-000003',
            $customerThree->customer_number
        );
    }

    public function test_customer_numbers_are_scoped_to_business(): void
    {
        $businessA = Business::factory()->create();
        $businessB = Business::factory()->create();

        $service = app(CustomerService::class);

        $customerA = $service->create(
            $businessA,
            []
        );

        $customerB = $service->create(
            $businessB,
            []
        );

        $this->assertEquals(
            'CUS-000001',
            $customerA->customer_number
        );

        $this->assertEquals(
            'CUS-000001',
            $customerB->customer_number
        );
    }

    public function test_customer_data_is_optional(): void
    {
        $business = Business::factory()->create();

        $customer = app(CustomerService::class)->create(
            $business,
            []
        );

        $this->assertNull(
            $customer->name
        );

        $this->assertNull(
            $customer->phone
        );

        $this->assertEquals(
            'active',
            $customer->status
        );
    }

    public function test_customer_data_can_be_provided(): void
    {
        $business = Business::factory()->create();

        $customer = app(CustomerService::class)->create(
            $business,
            [
                'name' => 'John Doe',
                'phone' => '08012345678',
            ]
        );

        $this->assertEquals(
            'John Doe',
            $customer->name
        );

        $this->assertEquals(
            '08012345678',
            $customer->phone
        );

        $this->assertEquals(
            'active',
            $customer->status
        );
    }

    public function test_customer_number_is_not_reused_after_deletion(): void
    {
        $business = Business::factory()->create();

        $service = app(CustomerService::class);

        $customerOne = $service->create(
            $business,
            []
        );

        $customerOne->delete();

        $customerTwo = $service->create(
            $business,
            []
        );

        $this->assertEquals(
            'CUS-000001',
            $customerOne->customer_number
        );

        $this->assertEquals(
            'CUS-000002',
            $customerTwo->customer_number
        );
    }

    public function test_customer_can_be_updated(): void
    {
        $business = Business::factory()->create();

        $service = app(CustomerService::class);

        $customer = $service->create(
            $business,
            [
                'name' => 'John Doe',
                'phone' => '08000000000',
            ]
        );

        $updated = $service->update(
            $business,
            $customer,
            [
                'name' => 'John Smith',
                'phone' => '08111111111',
            ]
        );

        $this->assertEquals(
            'John Smith',
            $updated->name
        );

        $this->assertEquals(
            '08111111111',
            $updated->phone
        );

        $this->assertEquals(
            'CUS-000001',
            $updated->customer_number
        );
    }

    public function test_customer_can_be_deactivated(): void
    {
        $business = Business::factory()->create();

        $service = app(CustomerService::class);

        $customer = $service->create(
            $business,
            []
        );

        $customer = $service->deactivate(
            $business,
            $customer
        );

        $this->assertEquals(
            'inactive',
            $customer->status
        );
    }

    public function test_customer_from_another_business_cannot_be_updated(): void
    {
        $businessA = Business::factory()->create();
        $businessB = Business::factory()->create();

        $service = app(CustomerService::class);

        $customer = $service->create(
            $businessA,
            []
        );

        $this->expectException(
            \Illuminate\Validation\ValidationException::class
        );

        $service->update(
            $businessB,
            $customer,
            [
                'name' => 'Hacked Customer',
            ]
        );
    }

    public function test_customer_from_another_business_cannot_be_deactivated(): void
    {
        $businessA = Business::factory()->create();
        $businessB = Business::factory()->create();

        $service = app(CustomerService::class);

        $customer = $service->create(
            $businessA,
            []
        );

        $this->expectException(
            \Illuminate\Validation\ValidationException::class
        );

        $service->deactivate(
            $businessB,
            $customer
        );
    }

    public function test_customer_can_be_found_for_business(): void
    {
        $business = Business::factory()->create();

        $service = app(CustomerService::class);

        $customer = $service->create(
            $business,
            [
                'name' => 'John Doe',
            ]
        );

        $found = $service->getForBusiness(
            $business,
            $customer->id
        );

        $this->assertTrue(
            $found->is($customer)
        );
    }

    public function test_customer_from_another_business_cannot_be_found(): void
    {
        $businessA = Business::factory()->create();
        $businessB = Business::factory()->create();

        $service = app(CustomerService::class);

        $customer = $service->create(
            $businessA,
            []
        );

        $this->expectException(
            \Illuminate\Database\Eloquent\ModelNotFoundException::class
        );

        $service->getForBusiness(
            $businessB,
            $customer->id
        );
    }
}
