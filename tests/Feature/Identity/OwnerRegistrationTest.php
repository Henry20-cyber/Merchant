<?php

namespace Tests\Feature\Identity;

use App\Domains\Organization\Models\BusinessType;
use App\Models\User;
use Database\Seeders\SubscriptionPlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OwnerRegistrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(SubscriptionPlanSeeder::class);
    }

    private function validPayload(): array
    {
        $businessType = BusinessType::factory()->create();

        return [
            'owner' => [
                'name' => 'Henry Onuoha',
                'email' => 'henry@example.com',
                'password' => 'password123',
                'password_confirmation' => 'password123',
            ],

            'business' => [
                'business_type_id' => $businessType->id,
                'name' => 'Henry Beauty Store',
                'phone' => '08012345678',
                'email' => 'business@example.com',
                'address' => 'Main Street',
                'city' => 'Owerri',
                'state' => 'Imo',
                'products_enabled' => true,
                'services_enabled' => false,
            ],
        ];
    }

    public function test_owner_registration_returns_merchant_id(): void
    {
        $response = $this->postJson(
            '/api/auth/register',
            $this->validPayload()
        );

        $response->assertCreated()
            ->assertJsonPath(
                'success',
                true
            )
            ->assertJsonPath(
                'data.business.name',
                'Henry Beauty Store'
            );

        $merchantId = $response->json(
            'data.business.merchant_id'
        );

        $this->assertNotNull($merchantId);

        $this->assertMatchesRegularExpression(
            '/^MCH-[A-Z0-9]{6}$/',
            $merchantId
        );

        $this->assertDatabaseHas('businesses', [
            'name' => 'Henry Beauty Store',
            'merchant_id' => $merchantId,
        ]);

        $this->assertDatabaseHas('users', [
            'email' => 'henry@example.com',
        ]);
    }

    public function test_owner_can_register_with_other_business_type_and_custom_type(): void
{
    $otherType = BusinessType::factory()->create([
        'name' => 'Other',
    ]);

    $payload = $this->validPayload();

    $payload['business']['business_type_id'] = $otherType->id;
    $payload['business']['custom_business_type'] = 'Auto Parts';

    $response = $this->postJson(
        '/api/auth/register',
        $payload
    );

    $response->assertCreated();

    $this->assertDatabaseHas('businesses', [
        'name' => 'Henry Beauty Store',
        'business_type_id' => $otherType->id,
        'custom_business_type' => 'Auto Parts',
    ]);
}

public function test_owner_cannot_register_other_business_type_without_custom_type(): void
{
    $otherType = BusinessType::factory()->create([
        'name' => 'Other',
    ]);

    $payload = $this->validPayload();

    $payload['business']['business_type_id'] = $otherType->id;
    unset($payload['business']['custom_business_type']);

    $response = $this->postJson(
        '/api/auth/register',
        $payload
    );

    $response->assertUnprocessable()
        ->assertJsonValidationErrors([
            'business.custom_business_type',
        ]);

    $this->assertDatabaseMissing('businesses', [
        'name' => 'Henry Beauty Store',
    ]);
}

public function test_owner_cannot_provide_custom_type_for_standard_business_type(): void
{
    $standardType = BusinessType::factory()->create([
        'name' => 'Supermarket',
    ]);

    $payload = $this->validPayload();

    $payload['business']['business_type_id'] = $standardType->id;
    $payload['business']['custom_business_type'] = 'Auto Parts';

    $response = $this->postJson(
        '/api/auth/register',
        $payload
    );

    $response->assertUnprocessable()
        ->assertJsonValidationErrors([
            'business.custom_business_type',
        ]);

    $this->assertDatabaseMissing('businesses', [
        'name' => 'Henry Beauty Store',
    ]);
}
}