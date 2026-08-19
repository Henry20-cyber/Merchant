<?php

namespace App\Domains\Identity\Services;

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

class AuthenticationService
{
    /**
     * Authenticate a user using the Laravel session.
     */
    public function login(User $user): void
    {
        Auth::login($user);
    }

    /**
     * Authenticate using email and password.
     */
    public function attempt(string $email, string $password): ?User
    {
        $user = User::where('email', $email)->first();

        if (! $user || ! Hash::check($password, $user->password)) {
            return null;
        }

        Auth::login($user);

        return $user;
    }

    /**
     * Log out the current user.
     */
    public function logout(): void
    {
        Auth::logout();
    }

    /**
     * Get the currently authenticated user.
     */
    public function user(): ?User
    {
        return Auth::user();
    }

    /**
     * Determine whether a user is authenticated.
     */
    public function check(): bool
    {
        return Auth::check();
    }
}