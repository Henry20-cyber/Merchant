<?php

namespace Tests\Feature\Organization;

use App\Domains\Organization\Models\Business;
use Database\Seeders\SubscriptionPlanSeeder;
use App\Domains\Organization\Services\BusinessService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BusinessRegistrationCapabilitiesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
{
    parent::setUp();

    $this->seed(SubscriptionPlanSeeder::class);
}

    private function businessData(
        bool $productsEnabled,
        bool $servicesEnabled
    ): array {
        return [
            'business_type_id' => \App\Domains\Organization\Models\BusinessType::factory()->create()->id,

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

    public function test_business_registration_creates_product_only_capabilities(): void
    {
        $business = app(BusinessService::class)
            ->registerBusiness(
                $this->businessData(true, false)
            );

        $capabilities = $business->fresh()->capabilities;

        $this->assertNotNull($capabilities);

        $this->assertTrue(
            $capabilities->products_enabled
        );

        $this->assertFalse(
            $capabilities->services_enabled
        );
    }

    public function test_business_registration_creates_service_only_capabilities(): void
    {
        $business = app(BusinessService::class)
            ->registerBusiness(
                $this->businessData(false, true)
            );

        $capabilities = $business->fresh()->capabilities;

        $this->assertNotNull($capabilities);

        $this->assertFalse(
            $capabilities->products_enabled
        );

        $this->assertTrue(
            $capabilities->services_enabled
        );
    }

    public function test_business_registration_can_enable_both(): void
    {
        $business = app(BusinessService::class)
            ->registerBusiness(
                $this->businessData(true, true)
            );

        $capabilities = $business->fresh()->capabilities;

        $this->assertNotNull($capabilities);

        $this->assertTrue(
            $capabilities->products_enabled
        );

        $this->assertTrue(
            $capabilities->services_enabled
        );
    }

    public function test_business_registration_creates_exactly_one_capability_record(): void
    {
        $business = app(BusinessService::class)
            ->registerBusiness(
                $this->businessData(true, true)
            );

        $this->assertDatabaseCount(
            'business_capabilities',
            1
        );

        $this->assertDatabaseHas(
            'business_capabilities',
            [
                'business_id' => $business->id,
                'products_enabled' => true,
                'services_enabled' => true,
            ]
        );
    }
}
