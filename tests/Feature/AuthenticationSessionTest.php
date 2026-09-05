<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

class AuthenticationSessionTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_session_survives_into_next_request(): void
    {
        $user = User::factory()->create();

        // Simulate login.
        $this->post('/api/auth/login', [
            'identifier' => $user->email,
            'password' => 'password',
        ]);

        // The same client now makes another request.
        $response = $this->get('/api/auth/me');

        $response
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('user.id', $user->id);
    }
}