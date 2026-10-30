<?php

namespace Tests\Feature\Identity;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RateLimitingTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_is_limited_by_identifier_and_ip(): void
    {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->postJson('/api/auth/login', [
                'identifier' => 'rate-limit-user@example.com',
                'password' => 'incorrect-password',
            ])->assertUnauthorized();
        }

        $this->postJson('/api/auth/login', [
            'identifier' => 'rate-limit-user@example.com',
            'password' => 'incorrect-password',
        ])->assertTooManyRequests();
    }

    public function test_owner_registration_is_limited_by_ip(): void
    {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->postJson('/api/auth/register', [])->assertUnprocessable();
        }

        $this->postJson('/api/auth/register', [])->assertTooManyRequests();
    }

    public function test_employee_registration_is_limited_by_ip(): void
    {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->postJson('/api/auth/register-employee', [])->assertUnprocessable();
        }

        $this->postJson('/api/auth/register-employee', [])->assertTooManyRequests();
    }
}
