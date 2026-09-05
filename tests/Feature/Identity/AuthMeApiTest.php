<?php

namespace Tests\Feature\Identity;

use App\Domains\Organization\Models\Business;
use App\Domains\Organization\Models\BusinessUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthMeApiTest extends TestCase
{
    use RefreshDatabase;

    private function createBusinessFor(User $user): Business
    {
        $business = Business::factory()->create();

        BusinessUser::create([
            'business_id' => $business->id,
            'user_id' => $user->id,
            'status' => 'active',
            'joined_at' => now(),
        ]);

        session([
            'current_business_id' => $business->id,
        ]);

        return $business;
    }

    public function test_authenticated_user_can_access_auth_me(): void
    {
        $user = User::factory()->create([
            'name' => 'Henry',
            'email' => 'henry@example.com',
        ]);

        $business = $this->createBusinessFor($user);

        $response = $this
            ->actingAs($user)
            ->getJson('/api/auth/me');

        $response
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('user.id', $user->id)
            ->assertJsonPath('user.email', $user->email)
            ->assertJsonPath('business.id', $business->id);
    }

    public function test_auth_me_remains_authenticated_with_business_header(): void
    {
        $user = User::factory()->create();

        $business = $this->createBusinessFor($user);

        $response = $this
            ->actingAs($user)
            ->withHeader('X-Business-ID', $business->id)
            ->getJson('/api/auth/me');

        $response
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('user.id', $user->id)
            ->assertJsonPath('business.id', $business->id);
    }

    public function test_auth_me_rejects_unowned_business_header(): void
    {
        $user = User::factory()->create();

        $businessA = $this->createBusinessFor($user);
        $businessB = Business::factory()->create();

        $response = $this
            ->actingAs($user)
            ->withHeader('X-Business-ID', $businessB->id)
            ->getJson('/api/auth/me');

        $response->assertForbidden();
    }

    public function test_auth_me_returns_null_business_without_context(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->getJson('/api/auth/me');

        $response
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('user.id', $user->id)
            ->assertJsonPath('business', null);
    }
}