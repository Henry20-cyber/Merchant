<?php

namespace App\Domains\Identity\Services;

use App\Domains\Organization\Models\Business;
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
     * Authenticate using email OR Merchant ID and password.
     */
    public function attempt(
        string $identifier,
        string $password
    ): ?User {
        /*
         * First determine whether the identifier is an email
         * or a MerchantOS business ID.
         */
        if (filter_var($identifier, FILTER_VALIDATE_EMAIL)) {
            $user = User::where('email', $identifier)->first();

            if (
                ! $user ||
                ! Hash::check($password, $user->password)
            ) {
                return null;
            }

            Auth::login($user);

            return $user;
        }

        /*
         * Merchant ID login.
         *
         * A Merchant ID identifies a business, not a user.
         * Therefore we find the business first, then find
         * an active user membership for that business.
         */
        $business = Business::query()
            ->where('merchant_id', strtoupper(trim($identifier)))
            ->first();

        if (! $business) {
            return null;
        }

        /*
         * Find an active membership belonging to the business.
         *
         * We select the user through the business membership
         * rather than treating merchant_id as a user field.
         */
        $membership = $business->memberships()
            ->where('status', 'active')
            ->with('user')
            ->first();

        if (! $membership) {
            return null;
        }

        $user = $membership->user;

        if (
            ! $user ||
            ! Hash::check($password, $user->password)
        ) {
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
    Auth::guard('web')->logout();
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