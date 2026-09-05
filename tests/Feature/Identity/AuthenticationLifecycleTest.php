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
}
