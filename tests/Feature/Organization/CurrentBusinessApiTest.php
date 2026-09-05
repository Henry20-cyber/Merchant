<?php

namespace Tests\Feature\Organization;

use App\Domains\Organization\Models\Business;
use App\Domains\Organization\Models\BusinessUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CurrentBusinessApiTest extends TestCase
{
    use RefreshDatabase;

    private function attachBusiness(User $user): Business
    {
        $business = Business::factory()->create();

        BusinessUser::create([
            'business_id' => $business->id,
            'user_id' => $user->id,
            'status' => 'active',
        ]);

        return $business;
    }

    public function test_authenticated_user_can_get_current_business_from_session(): void
    {
        $user = User::factory()->create();

        $business = $this->attachBusiness($user);

        $this->withSession([
            'current_business_id' => $business->id,
        ]);

        $response = $this
            ->actingAs($user)
            ->getJson('/api/businesses/current');

        $response
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.id', $business->id);
    }

    public function test_authenticated_user_can_get_current_business_with_business_header(): void
    {
        $user = User::factory()->create();

        $business = $this->attachBusiness($user);

        $response = $this
            ->actingAs($user)
            ->withHeader('X-Business-ID', $business->id)
            ->getJson('/api/businesses/current');

        $response
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.id', $business->id);
    }

    public function test_authenticated_user_without_business_context_is_rejected(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->getJson('/api/businesses/current');

        $response->assertStatus(403);
    }

    public function test_unowned_business_header_is_rejected(): void
    {
        $user = User::factory()->create();

        $ownedBusiness = $this->attachBusiness($user);
        $otherBusiness = Business::factory()->create();

        $response = $this
            ->actingAs($user)
            ->withHeader('X-Business-ID', $otherBusiness->id)
            ->getJson('/api/businesses/current');

        $response->assertForbidden();
    }

    public function test_session_business_context_survives_auth_me_before_current_business(): void
    {
        $user = User::factory()->create();

        $business = $this->attachBusiness($user);

        $this->withSession([
            'current_business_id' => $business->id,
        ]);

        $authMeResponse = $this
            ->actingAs($user)
            ->getJson('/api/auth/me');

        $authMeResponse
            ->assertOk()
            ->assertJsonPath('business.id', $business->id);

        $currentBusinessResponse = $this
            ->actingAs($user)
            ->getJson('/api/businesses/current');

        $currentBusinessResponse
            ->assertOk()
            ->assertJsonPath('data.id', $business->id);
    }
}