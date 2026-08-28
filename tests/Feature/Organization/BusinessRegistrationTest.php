<?php

namespace Tests\Feature\Organization;

use App\Domains\Organization\Models\Business;
use App\Domains\Organization\Models\BusinessType;
use App\Domains\Organization\Services\BusinessService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BusinessRegistrationTest extends TestCase
{
    use RefreshDatabase;

    private function validBusinessData(
        bool $productsEnabled,
        bool $servicesEnabled
    ): array {
        $businessType = BusinessType::factory()->create();

        return [
            'business_type_id' => $businessType->id,

            'name' => 'Henry Beauty Store',

            'phone' => '08012345678',

            'email' => 'business@example.com',

            'products_enabled' => $productsEnabled,

            'services_enabled' => $servicesEnabled,

            'address' => 'Main Street',

            'city' => 'Owerri',

            'state' => 'Imo',
        ];
    }

    public function test_products_only_business_can_be_registered(): void
    {
        $business = app(BusinessService::class)
            ->registerBusiness(
                $this->validBusinessData(true, false)
            );

        $capabilities = $business->fresh()->capabilities;

        $this->assertTrue(
            $capabilities->products_enabled
        );

        $this->assertFalse(
            $capabilities->services_enabled
        );
    }

    public function test_services_only_business_can_be_registered(): void
    {
        $business = app(BusinessService::class)
            ->registerBusiness(
                $this->validBusinessData(false, true)
            );

        $capabilities = $business->fresh()->capabilities;

        $this->assertFalse(
            $capabilities->products_enabled
        );

        $this->assertTrue(
            $capabilities->services_enabled
        );
    }

    public function test_hybrid_business_can_be_registered(): void
    {
        $business = app(BusinessService::class)
            ->registerBusiness(
                $this->validBusinessData(true, true)
            );

        $capabilities = $business->fresh()->capabilities;

        $this->assertTrue(
            $capabilities->products_enabled
        );

        $this->assertTrue(
            $capabilities->services_enabled
        );
    }

    public function test_business_with_neither_products_nor_services_is_invalid(): void
    {
        $data = $this->validBusinessData(false, false);

        $validator = validator(
            $data,
            app(
                \App\Domains\Organization\Requests\StoreBusinessRequest::class
            )->rules()
        );

        $validator->after(
            app(
                \App\Domains\Organization\Requests\StoreBusinessRequest::class
            )->after()[0]
        );

        $this->assertTrue(
            $validator->fails()
        );

        $this->assertArrayHasKey(
            'capabilities',
            $validator->errors()->toArray()
        );

        $this->assertDatabaseCount(
            'businesses',
            0
        );
    }
}
