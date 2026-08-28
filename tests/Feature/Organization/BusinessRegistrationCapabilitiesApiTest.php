<?php

namespace Tests\Feature\Organization;

use App\Domains\Organization\Models\Business;
use App\Domains\Organization\Models\BusinessType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BusinessRegistrationCapabilitiesApiTest extends TestCase
{
    use RefreshDatabase;

    private function validPayload(
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

            'default_country' => 'Nigeria',

            'currency' => 'NGN',

            'timezone' => 'Africa/Lagos',
        ];
    }

    public function test_business_can_register_with_products_only(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)
            ->postJson(
                '/api/businesses',
                $this->validPayload(true, false)
            );

        $response->assertCreated()
            ->assertJson([
                'success' => true,
            ]);

        $business = Business::query()
            ->where('name', 'Henry Beauty Store')
            ->firstOrFail();

        $this->assertDatabaseHas('business_capabilities', [
            'business_id' => $business->id,
            'products_enabled' => true,
            'services_enabled' => false,
        ]);
    }

    public function test_business_can_register_with_services_only(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)
            ->postJson(
                '/api/businesses',
                $this->validPayload(false, true)
            );

        $response->assertCreated()
            ->assertJson([
                'success' => true,
            ]);

        $business = Business::query()
            ->where('name', 'Henry Beauty Store')
            ->firstOrFail();

        $this->assertDatabaseHas('business_capabilities', [
            'business_id' => $business->id,
            'products_enabled' => false,
            'services_enabled' => true,
        ]);
    }

    public function test_business_can_register_with_both_products_and_services(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)
            ->postJson(
                '/api/businesses',
                $this->validPayload(true, true)
            );

        $response->assertCreated()
            ->assertJson([
                'success' => true,
            ]);

        $business = Business::query()
            ->where('name', 'Henry Beauty Store')
            ->firstOrFail();

        $this->assertDatabaseHas('business_capabilities', [
            'business_id' => $business->id,
            'products_enabled' => true,
            'services_enabled' => true,
        ]);
    }

    public function test_business_cannot_register_without_a_capability(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)
            ->postJson(
                '/api/businesses',
                $this->validPayload(false, false)
            );

        $response->assertUnprocessable();

        $response->assertJsonValidationErrors([
            'capabilities',
        ]);

        $this->assertDatabaseCount(
            'businesses',
            0
        );
    }

    public function test_products_enabled_must_be_boolean(): void
    {
        $user = User::factory()->create();

        $payload = $this->validPayload(true, false);

        $payload['products_enabled'] = 'yes';

        $response = $this->actingAs($user)
            ->postJson(
                '/api/businesses',
                $payload
            );

        $response->assertUnprocessable();

        $response->assertJsonValidationErrors([
            'products_enabled',
        ]);
    }

    public function test_services_enabled_must_be_boolean(): void
    {
        $user = User::factory()->create();

        $payload = $this->validPayload(true, false);

        $payload['services_enabled'] = 'yes';

        $response = $this->actingAs($user)
            ->postJson(
                '/api/businesses',
                $payload
            );

        $response->assertUnprocessable();

        $response->assertJsonValidationErrors([
            'services_enabled',
        ]);
    }
}
