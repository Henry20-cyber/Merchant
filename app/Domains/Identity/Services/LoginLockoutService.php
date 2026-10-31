<?php

namespace App\Domains\Identity\Services;

use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

class LoginLockoutService
{
    public const MAX_ATTEMPTS = 5;

    public const LOCKOUT_SECONDS = 900;

    /**
     * Return the remaining lockout time for a normalized login identifier.
     */
    public function availableIn(string $identifier): int
    {
        $key = $this->key($identifier);

        return RateLimiter::tooManyAttempts($key, self::MAX_ATTEMPTS)
            ? RateLimiter::availableIn($key)
            : 0;
    }

    /**
     * Record a failed credential attempt. The fifth consecutive failure
     * starts a 15-minute lockout, independent of the request IP.
     */
    public function recordFailure(string $identifier): int
    {
        $key = $this->key($identifier);

        RateLimiter::hit($key, self::LOCKOUT_SECONDS);

        return RateLimiter::attempts($key) >= self::MAX_ATTEMPTS
            ? RateLimiter::availableIn($key)
            : 0;
    }

    public function clear(string $identifier): void
    {
        RateLimiter::clear($this->key($identifier));
    }

    private function key(string $identifier): string
    {
        $identifier = trim($identifier);

        $normalized = filter_var($identifier, FILTER_VALIDATE_EMAIL)
            ? Str::lower($identifier)
            : Str::upper($identifier);

        return 'auth-account-lockout:' . hash('sha256', $normalized);
    }
}
