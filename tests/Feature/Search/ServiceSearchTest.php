<?php

namespace Tests\Feature\Search;

use App\Domains\Organization\Models\Business;
use App\Domains\Service\Models\Service;
use App\Domains\Service\Services\ServiceSearchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ServiceSearchTest extends TestCase
{
    use RefreshDatabase;

    public function test_search_finds_service_by_name(): void
    {
        $business = Business::factory()->create();

        Service::factory()->create([
            'business_id' => $business->id,
            'name' => 'Wig Installation',
            'price' => 10000,
            'is_active' => true,
        ]);

        Service::factory()->create([
            'business_id' => $business->id,
            'name' => 'Hair Braiding',
            'price' => 15000,
            'is_active' => true,
        ]);

        $results = app(ServiceSearchService::class)
            ->search($business, 'Wig');

        $this->assertCount(1, $results);

        $this->assertEquals(
            'service',
            $results[0]['type']
        );

        $this->assertEquals(
            'Wig Installation',
            $results[0]['title']
        );

        $this->assertEquals(
            10000,
            $results[0]['metadata']['price']
        );
    }

    public function test_search_finds_service_by_description(): void
    {
        $business = Business::factory()->create();

        Service::factory()->create([
            'business_id' => $business->id,
            'name' => 'Premium Hair Styling',
            'description' => 'Professional styling for natural hair',
            'price' => 12000,
            'is_active' => true,
        ]);

        $results = app(ServiceSearchService::class)
            ->search($business, 'natural hair');

        $this->assertCount(1, $results);

        $this->assertEquals(
            'Premium Hair Styling',
            $results[0]['title']
        );
    }

    public function test_search_is_scoped_to_business(): void
    {
        $businessA = Business::factory()->create();
        $businessB = Business::factory()->create();

        Service::factory()->create([
            'business_id' => $businessA->id,
            'name' => 'Wig Installation',
            'price' => 10000,
        ]);

        Service::factory()->create([
            'business_id' => $businessB->id,
            'name' => 'Wig Installation',
            'price' => 20000,
        ]);

        $results = app(ServiceSearchService::class)
            ->search($businessA, 'Wig Installation');

        $this->assertCount(1, $results);

        $this->assertEquals(
            10000,
            $results[0]['metadata']['price']
        );
    }

    public function test_inactive_services_can_still_be_found(): void
    {
        $business = Business::factory()->create();

        Service::factory()->create([
            'business_id' => $business->id,
            'name' => 'Old Hair Service',
            'price' => 8000,
            'is_active' => false,
        ]);

        $results = app(ServiceSearchService::class)
            ->search($business, 'Old Hair');

        $this->assertCount(1, $results);

        $this->assertFalse(
            $results[0]['metadata']['is_active']
        );
    }

    public function test_empty_search_returns_no_results(): void
    {
        $business = Business::factory()->create();

        Service::factory()->create([
            'business_id' => $business->id,
            'name' => 'Wig Installation',
        ]);

        $results = app(ServiceSearchService::class)
            ->search($business, '   ');

        $this->assertSame([], $results);
    }
}
