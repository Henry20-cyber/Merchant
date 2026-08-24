<?php

namespace Tests\Feature\Services;

use App\Domains\Organization\Models\Business;
use App\Domains\Service\Models\Service;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_service_belongs_to_business(): void
    {
        $business = Business::factory()->create();

        $service = Service::factory()->create([
            'business_id' => $business->id,
        ]);

        $this->assertEquals(
            $business->id,
            $service->business_id
        );

        $this->assertTrue(
            $service->business->is($business)
        );
    }

    public function test_service_has_price(): void
    {
        $business = Business::factory()->create();

        $service = Service::factory()->create([
            'business_id' => $business->id,
            'price' => 15000,
        ]);

        $this->assertEquals(
            15000,
            (float) $service->price
        );
    }

    public function test_service_can_be_active_or_inactive(): void
    {
        $business = Business::factory()->create();

        $activeService = Service::factory()->create([
            'business_id' => $business->id,
            'is_active' => true,
        ]);

        $inactiveService = Service::factory()->create([
            'business_id' => $business->id,
            'is_active' => false,
        ]);

        $this->assertTrue($activeService->is_active);
        $this->assertFalse($inactiveService->is_active);
    }

    public function test_service_cannot_cross_business_boundary(): void
    {
        $businessA = Business::factory()->create();
        $businessB = Business::factory()->create();

        $service = Service::factory()->create([
            'business_id' => $businessA->id,
        ]);

        $this->assertTrue(
            Service::query()
                ->where('business_id', $businessA->id)
                ->whereKey($service->id)
                ->exists()
        );

        $this->assertFalse(
            Service::query()
                ->where('business_id', $businessB->id)
                ->whereKey($service->id)
                ->exists()
        );
    }
}