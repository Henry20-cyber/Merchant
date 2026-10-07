<?php

namespace App\Domains\Organization\Services;

use App\Domains\Organization\Models\Branch;
use App\Domains\Organization\Models\Business;
use App\Domains\Organization\Models\BusinessUser;
use App\Models\User;
use Illuminate\Validation\ValidationException;

class BranchContextService
{
    /**
     * Set the current branch for an authenticated user.
     */
    public function set(
        User $user,
        Business $business,
        Branch $branch
    ): void {
        $this->ensureMembership($user, $business);

        if ($branch->business_id !== $business->id) {
            throw ValidationException::withMessages([
                'branch' => 'This branch does not belong to the current business.',
            ]);
        }

        if ($branch->trashed()) {
            throw ValidationException::withMessages([
                'branch' => 'This branch is no longer active.',
            ]);
        }

        session([
            'current_branch_id' => $branch->id,
        ]);
    }

    /**
     * Get the current branch for the authenticated user.
     */
    public function current(
        User $user,
        Business $business
    ): ?Branch {
        $branchId = session('current_branch_id');

        if (! $branchId) {
            return null;
        }

        return Branch::query()
            ->whereKey($branchId)
            ->where('business_id', $business->id)
            ->whereHas('business.memberships', function ($query) use ($user, $business) {
                $query
                    ->where('user_id', $user->id)
                    ->where('business_id', $business->id)
                    ->where('status', 'active');
            })
            ->first();
    }

    /**
     * Clear the current branch.
     */
    public function clear(): void
    {
        session()->forget('current_branch_id');
    }

    /**
     * Ensure the user belongs to the business.
     */
    private function ensureMembership(
        User $user,
        Business $business
    ): void {
        $exists = BusinessUser::query()
            ->where('user_id', $user->id)
            ->where('business_id', $business->id)
            ->where('status', 'active')
            ->exists();

        if (! $exists) {
            throw ValidationException::withMessages([
                'business' => 'You do not belong to this business.',
            ]);
        }
    }
}
