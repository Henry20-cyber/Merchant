<?php

namespace Tests\Feature\Customer;

use App\Domains\Customer\Models\Customer;
use App\Domains\Organization\Models\Business;
use App\Domains\Sales\Models\Sale;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CustomerTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_belongs_to_business(): void
    {
        $business = Business::factory()->create();

        $customer = Customer::factory()->create([
            'business_id' => $business->id,
        ]);

        $this->assertTrue(
            $customer->business->is($business)
        );
    }

    public function test_business_has_many_customers(): void
    {
        $business = Business::factory()->create();

        Customer::factory()->count(3)->create([
            'business_id' => $business->id,
        ]);

        $this->assertCount(
            3,
            $business->customers
        );
    }

    public function test_customer_has_many_sales(): void
{
    $business = Business::factory()->create();

    $customer = Customer::factory()->create([
        'business_id' => $business->id,
    ]);

    $cashier = User::factory()->create();

    $saleOne = Sale::create([
        'business_id' => $business->id,
        'cashier_id' => $cashier->id,
        'customer_id' => $customer->id,
        'subtotal' => 1000,
        'discount' => 0,
        'tax' => 0,
        'total' => 1000,
        'payment_method' => 'cash',
        'payment_status' => 'paid',
        'status' => 'completed',
    ]);

    $saleTwo = Sale::create([
        'business_id' => $business->id,
        'cashier_id' => $cashier->id,
        'customer_id' => $customer->id,
        'subtotal' => 2000,
        'discount' => 0,
        'tax' => 0,
        'total' => 2000,
        'payment_method' => 'cash',
        'payment_status' => 'paid',
        'status' => 'completed',
    ]);

    $this->assertCount(
        2,
        $customer->sales
    );

    $this->assertTrue(
        $customer->sales
            ->pluck('id')
            ->contains($saleOne->id)
    );

    $this->assertTrue(
        $customer->sales
            ->pluck('id')
            ->contains($saleTwo->id)
    );
}

    public function test_customer_can_be_soft_deleted(): void
    {
        $customer = Customer::factory()->create();

        $customer->delete();

        $this->assertSoftDeleted(
            'customers',
            [
                'id' => $customer->id,
            ]
        );

        $this->assertNull(
            Customer::find($customer->id)
        );

        $this->assertNotNull(
            Customer::withTrashed()->find(
                $customer->id
            )
        );
    }

    public function test_customer_number_is_unique_per_business(): void
    {
        $business = Business::factory()->create();

        Customer::factory()->create([
            'business_id' => $business->id,
            'customer_number' => 'CUS-000001',
        ]);

        $this->expectException(
            \Illuminate\Database\QueryException::class
        );

        Customer::factory()->create([
            'business_id' => $business->id,
            'customer_number' => 'CUS-000001',
        ]);
    }

    public function test_same_customer_number_can_exist_in_different_businesses(): void
    {
        $businessA = Business::factory()->create();
        $businessB = Business::factory()->create();

        $customerA = Customer::factory()->create([
            'business_id' => $businessA->id,
            'customer_number' => 'CUS-000001',
        ]);

        $customerB = Customer::factory()->create([
            'business_id' => $businessB->id,
            'customer_number' => 'CUS-000001',
        ]);

        $this->assertNotEquals(
            $customerA->id,
            $customerB->id
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

    public function test_name_and_phone_are_optional(): void
    {
        $business = Business::factory()->create();

        $customer = Customer::factory()->create([
            'business_id' => $business->id,
            'name' => null,
            'phone' => null,
        ]);

        $this->assertDatabaseHas(
            'customers',
            [
                'id' => $customer->id,
                'business_id' => $business->id,
                'name' => null,
                'phone' => null,
            ]
        );
    }

    public function test_customers_are_scoped_to_business(): void
    {
        $businessA = Business::factory()->create();
        $businessB = Business::factory()->create();

        $customerA = Customer::factory()->create([
            'business_id' => $businessA->id,
        ]);

        Customer::factory()->create([
            'business_id' => $businessB->id,
        ]);

        $customers = $businessA
            ->customers()
            ->get();

        $this->assertCount(
            1,
            $customers
        );

        $this->assertTrue(
            $customers->first()->is($customerA)
        );
    }
}
