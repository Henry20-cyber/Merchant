<?php

namespace Tests\Feature\Identity;

use App\Domains\Organization\Models\BusinessType;
use Database\Seeders\SubscriptionPlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\Rules\Password;
use Tests\TestCase;

class OwnerRegistrationPasswordPolicyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(SubscriptionPlanSeeder::class);
    }

    protected function tearDown(): void
    {
        Password::defaults(fn () => null);

        parent::tearDown();
    }

    private function validPayload(): array
    {
        $businessType = BusinessType::factory()->create();

        return [
            'owner' => [
                'name' => 'Taylor Owner',
                'email' => 'taylor@example.com',
                'password' => 'password123',
                'password_confirmation' => 'password123',
            ],
            'business' => [
                'business_type_id' => $businessType->id,
                'name' => 'Taylor Retail',
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

    private function useDeterministicProductionPasswordPolicy(): void
    {
        Password::defaults(
            fn () => Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
        );
    }

    public function test_owner_registration_rejects_password_without_symbol_under_production_policy(): void
    {
        $this->useDeterministicProductionPasswordPolicy();

        $payload = $this->validPayload();
        $payload['owner']['password'] = 'StrongPass2026';
        $payload['owner']['password_confirmation'] = 'StrongPass2026';

        $response = $this->postJson('/api/auth/register', $payload);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['owner.password'])
            ->assertJsonMissingValidationErrors(['password']);

        $this->assertDatabaseMissing('users', [
            'email' => 'taylor@example.com',
        ]);
    }

    public function test_owner_registration_accepts_password_meeting_production_complexity_requirements(): void
    {
        $this->useDeterministicProductionPasswordPolicy();

        $payload = $this->validPayload();
        $payload['owner']['password'] = 'StrongPass2026!';
        $payload['owner']['password_confirmation'] = 'StrongPass2026!';

        $response = $this->postJson('/api/auth/register', $payload);

        $response->assertCreated()
            ->assertJsonPath('success', true);

        $this->assertDatabaseHas('users', [
            'email' => 'taylor@example.com',
        ]);
    }
}
