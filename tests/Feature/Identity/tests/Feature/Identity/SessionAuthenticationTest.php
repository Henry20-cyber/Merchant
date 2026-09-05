<?php

namespace Tests\Feature\Identity;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class SessionAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_creates_a_session_authenticated_as_the_user(): void
    {
        $user = User::factory()->create([
            'password' => Hash::make('password'),
        ]);

        $response = $this->withSession([])->postJson('/api/auth/login', [
            'identifier' => $user->email,
            'password' => 'password',
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertAuthenticatedAs($user, 'web');

        $this->getJson('/api/debug/auth-context')
            ->assertOk()
            ->assertJson([
                'authenticated' => true,
                'user_id' => $user->id,
            ]);
    }
}