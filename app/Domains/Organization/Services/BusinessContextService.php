<?php

namespace App\Domains\Organization\Services;

use App\Domains\Organization\Models\Business;
use App\Domains\Organization\Models\BusinessUser;
use App\Models\User;
use Illuminate\Validation\ValidationException;

class BusinessContextService
{
    /**
     * Set the current business for the authenticated user.
     */
    public function set(User $user, Business $business): void
    {
        $membership = BusinessUser::query()
            ->where('user_id', $user->id)
            ->where('business_id', $business->id)
            ->where('status', 'active')
            ->first();

        if (! $membership) {
            throw ValidationException::withMessages([
                'business' => 'You do not belong to this business.',
            ]);
        }

        session([
            'current_business_id' => $business->id,
        ]);
    }

    /**
     * Get the current business for the authenticated user.
     */
    public function current(User $user): ?Business
    {
        $businessId = session('current_business_id');

        if (! $businessId) {
            return null;
        }

        return Business::query()
            ->where('id', $businessId)
            ->whereHas('memberships', function ($query) use ($user) {
                $query
                    ->where('user_id', $user->id)
                    ->where('status', 'active');
            })
            ->first();
    }

    /**
     * Clear the current business.
     */
    public function clear(): void
    {
        session()->forget('current_business_id');
    }
}