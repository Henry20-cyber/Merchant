<?php

namespace Tests\Feature\Identity;

use App\Domains\Organization\Models\Business;
use App\Domains\Organization\Models\BusinessType;
use App\Domains\Organization\Models\BusinessUser;
use App\Models\User;
use Database\Seeders\SubscriptionPlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

class LoginTest extends TestCase
{
  use RefreshDatabase;

  protected function setUp(): void
  {
    parent::setUp();

    $this->seed(SubscriptionPlanSeeder::class);
  }

  private function createBusinessForUser(
    User $user,
    string $name = 'Henry Beauty Store'
  ): Business {
    $businessType = BusinessType::factory()->create();

    $business = Business::factory()
      ->withBusinessType($businessType)
      ->create([
        'name' => $name,
      ]);

    BusinessUser::create([
      'business_id' => $business->id,
      'user_id' => $user->id,
      'status' => 'active',
      'joined_at' => now(),
    ]);

    return $business;
  }

  public function test_user_can_login_with_email(): void
  {
    $user = User::factory()->create([
      'email' => 'henry@example.com',
      'password' => 'password123',
    ]);

    $this->createBusinessForUser($user);

    $response = $this->postJson('/api/auth/login', [
      'identifier' => 'henry@example.com',
      'password' => 'password123',
    ]);

    $response
      ->assertOk()
      ->assertJsonPath('success', true)
      ->assertJsonPath(
        'data.user.email',
        'henry@example.com'
      );
  }

  public function test_user_can_login_with_merchant_id(): void
  {
    $user = User::factory()->create([
      'email' => 'henry@example.com',
      'password' => 'password123',
    ]);

    $business = $this->createBusinessForUser($user);

    $response = $this->postJson('/api/auth/login', [
      'identifier' => $business->merchant_id,
      'password' => 'password123',
    ]);

    $response
      ->assertOk()
      ->assertJsonPath('success', true)
      ->assertJsonPath(
        'data.user.email',
        'henry@example.com'
      )
      ->assertJsonPath(
        'data.business.id',
        $business->id
      )
      ->assertJsonPath(
        'data.business.merchant_id',
        $business->merchant_id
      );
  }

  public function test_login_fails_with_wrong_password(): void
  {
    $user = User::factory()->create([
      'email' => 'henry@example.com',
      'password' => 'password123',
    ]);

    $this->createBusinessForUser($user);

    $response = $this->postJson('/api/auth/login', [
      'identifier' => 'henry@example.com',
      'password' => 'wrong-password',
    ]);

    $response->assertUnauthorized();
  }

  public function test_login_fails_with_unknown_identifier(): void
  {
    $response = $this->postJson('/api/auth/login', [
      'identifier' => 'MCH-XXXXXX',
      'password' => 'password123',
    ]);

    $response->assertUnauthorized();
  }

  public function test_authenticated_user_can_access_me(): void
{
    $user = User::factory()->create([
        'email' => 'henry@example.com',
        'password' => 'password123',
    ]);

    $business = $this->createBusinessForUser($user);

    $loginResponse = $this->postJson('/api/auth/login', [
        'identifier' => 'henry@example.com',
        'password' => 'password123',
    ]);

    $loginResponse->assertOk();

    $response = $this->getJson('/api/auth/me');

    $response
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('user.id', $user->id)
        ->assertJsonPath('business.id', $business->id);
}

public function test_user_can_logout_and_session_is_invalidated(): void
{
    $user = User::factory()->create([
        'email' => 'henry@example.com',
        'password' => 'password123',
    ]);

    $business = $this->createBusinessForUser($user);

    $loginResponse = $this->postJson('/api/auth/login', [
        'identifier' => 'henry@example.com',
        'password' => 'password123',
    ]);

    $loginResponse->assertOk();

    // Confirm authentication and business context exist before logout.
    $this->getJson('/api/auth/me')
        ->assertOk()
        ->assertJsonPath('user.id', $user->id)
        ->assertJsonPath('business.id', $business->id);

   // Logout.
$logoutResponse = $this->postJson('/api/auth/logout');

$logoutResponse
    ->assertOk()
    ->assertJsonPath('success', true);

$this->assertGuest('web');

Auth::forgetGuards();

$this->getJson('/api/auth/me')
    ->assertUnauthorized();

$this->assertGuest('web');
} 
}
