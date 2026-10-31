<?php

namespace Tests\Feature\Identity;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RateLimitingTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_ip_limit_applies_across_distinct_identifiers(): void
    {
        // Each identifier receives only one failed attempt, so account
        // lockout does not interfere with this IP-level throttle test.
        for ($attempt = 1; $attempt <= 20; $attempt++) {
            $this->postJson('/api/auth/login', [
                'identifier' => "rate-limit-user-{$attempt}@example.com",
                'password' => 'incorrect-password',
            ])->assertUnauthorized();
        }

        $this->postJson('/api/auth/login', [
            'identifier' => 'another-rate-limit-user@example.com',
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
