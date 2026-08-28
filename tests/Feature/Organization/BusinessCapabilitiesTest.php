<?php

namespace Tests\Feature\Organization;

use App\Domains\Organization\Models\Business;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BusinessCapabilitiesTest extends TestCase
{
    use RefreshDatabase;

    public function test_business_can_have_products_enabled(): void
    {
        $business = Business::factory()->create();

        $business->capabilities()->create([
            'products_enabled' => true,
            'services_enabled' => false,
        ]);

        $this->assertTrue(
            $business->fresh()
                ->capabilities
                ->products_enabled
        );

        $this->assertFalse(
            $business->fresh()
                ->capabilities
                ->services_enabled
        );
    }

    public function test_business_can_have_services_enabled(): void
    {
        $business = Business::factory()->create();

        $business->capabilities()->create([
            'products_enabled' => false,
            'services_enabled' => true,
        ]);

        $this->assertFalse(
            $business->fresh()
                ->capabilities
                ->products_enabled
        );

        $this->assertTrue(
            $business->fresh()
                ->capabilities
                ->services_enabled
        );
    }

    public function test_business_can_enable_both_products_and_services(): void
    {
        $business = Business::factory()->create();

        $business->capabilities()->create([
            'products_enabled' => true,
            'services_enabled' => true,
        ]);

        $capabilities = $business->fresh()->capabilities;

        $this->assertTrue($capabilities->products_enabled);
        $this->assertTrue($capabilities->services_enabled);
    }

    public function test_business_capabilities_belong_to_the_correct_business(): void
    {
        $businessA = Business::factory()->create();
        $businessB = Business::factory()->create();

        $businessA->capabilities()->create([
            'products_enabled' => true,
            'services_enabled' => false,
        ]);

        $businessB->capabilities()->create([
            'products_enabled' => false,
            'services_enabled' => true,
        ]);

        $this->assertTrue(
            $businessA->fresh()
                ->capabilities
                ->products_enabled
        );

        $this->assertFalse(
            $businessA->fresh()
                ->capabilities
                ->services_enabled
        );

        $this->assertFalse(
            $businessB->fresh()
                ->capabilities
                ->products_enabled
        );

        $this->assertTrue(
            $businessB->fresh()
                ->capabilities
                ->services_enabled
        );
    }
}
