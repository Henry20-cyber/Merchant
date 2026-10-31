<?php

namespace Tests\Feature\Identity;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AuthenticationLifecycleTest extends TestCase
{
    use RefreshDatabase;

    public function test_complete_session_authentication_lifecycle(): void
    {
        $user = User::factory()->create([
            'password' => Hash::make('password'),
        ]);

        /*
         * 1. Unauthenticated request.
         */
        $this->getJson('/api/auth/me')
            ->assertUnauthorized();

        /*
         * 2. Login.
         */
        $login = $this->postJson('/api/auth/login', [
            'identifier' => $user->email,
            'password' => 'password',
        ]);

        $login
            ->assertOk()
            ->assertJsonPath('success', true);

        /*
         * 3. Verify Laravel session authentication.
         */
        $this->assertAuthenticatedAs($user, 'web');

        /*
         * 4. Immediately authenticate through Sanctum.
         */
        $this->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('user.id', $user->id);

        /*
         * 5. Repeat authentication.
         *
         * This catches session instability.
         */
        $this->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('user.id', $user->id);

        $this->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('user.id', $user->id);
    }

    public function test_fifth_failed_login_attempt_locks_identifier_for_fifteen_minutes(): void
    {
        $user = User::factory()->create([
            'password' => Hash::make('correct-password'),
        ]);

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $response = $this->postJson('/api/auth/login', [
                'identifier' => $user->email,
                'password' => 'incorrect-password',
            ]);

            if ($attempt < 5) {
                $response->assertUnauthorized();
            } else {
                $response->assertStatus(429)
                    ->assertJsonPath('success', false)
                    ->assertJsonPath('message', 'Too many failed login attempts. Please try again later.')
                    ->assertJsonStructure(['retry_after']);
            }
        }

        $this->postJson('/api/auth/login', [
            'identifier' => $user->email,
            'password' => 'correct-password',
        ])->assertStatus(429);

        $this->travel(15)->minutes();

        $this->postJson('/api/auth/login', [
            'identifier' => $user->email,
            'password' => 'correct-password',
        ])->assertOk();

        $this->assertAuthenticatedAs($user, 'web');
    }

    public function test_successful_login_clears_previous_failed_attempts(): void
    {
        $user = User::factory()->create([
            'password' => Hash::make('correct-password'),
        ]);

        $this->postJson('/api/auth/login', [
            'identifier' => $user->email,
            'password' => 'incorrect-password',
        ])->assertUnauthorized();

        $this->postJson('/api/auth/login', [
            'identifier' => $user->email,
            'password' => 'correct-password',
        ])->assertOk();

        auth()->logout();

        for ($attempt = 1; $attempt <= 4; $attempt++) {
            $this->postJson('/api/auth/login', [
                'identifier' => $user->email,
                'password' => 'incorrect-password',
            ])->assertUnauthorized();
        }
    }
}
