<?php

namespace App\Domains\Organization\Services;

use App\Domains\Organization\Models\Branch;
use App\Domains\Organization\Models\Business;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\DB;
use App\Domains\Subscription\Services\SubscriptionLimitService;

class BranchService
{
    public function __construct(
        private SubscriptionLimitService $subscriptionLimitService,
    ) {}

    /**
     * Get all branches belonging to a business.
     */
    public function listForBusiness(
        Business $business
    ): Collection {
        return $business->branches()
            ->orderByDesc('is_head_office')
            ->orderBy('name')
            ->get();
    }

    /**
     * Create a branch for a business.
     */
    public function create(
        Business $business,
        array $data
    ): Branch {
        return DB::transaction(function () use ($business, $data): Branch {
            $this->subscriptionLimitService->ensureBranchCapacity($business);

            $isHeadOffice = (bool) (
                $data['is_head_office'] ?? false
            );

            if ($isHeadOffice && $this->hasHeadOffice($business)) {
                throw ValidationException::withMessages([
                    'is_head_office' => [
                        'This business already has a Head Office.',
                    ],
                ]);
            }

            return $business->branches()->create([
                'name' => $data['name'],
                'code' => $data['code'],
                'phone' => $data['phone'] ?? null,
                'email' => $data['email'] ?? null,
                'address' => $data['address'] ?? null,
                'city' => $data['city'],
                'state' => $data['state'],
                'country' => $data['country'] ?? 'Nigeria',
                'is_head_office' => $isHeadOffice,
            ]);
        });
    }

    /**
     * Update a branch belonging to a business.
     */
    public function update(
        Business $business,
        Branch $branch,
        array $data
    ): Branch {
        $this->ensureBelongsToBusiness(
            $branch,
            $business
        );

        $isHeadOffice = (bool) (
            $data['is_head_office']
            ?? $branch->is_head_office
        );

        /*
         * A Head Office cannot be demoted while it
         * remains the business's only Head Office.
         */
        if (
            $branch->is_head_office &&
            ! $isHeadOffice &&
            ! $this->hasAnotherHeadOffice(
                $business,
                $branch
            )
        ) {
            throw ValidationException::withMessages([
                'is_head_office' => [
                    'The business must have a Head Office.',
                ],
            ]);
        }

        /*
         * A normal branch cannot become Head Office
         * when another Head Office already exists.
         */
        if (
            ! $branch->is_head_office &&
            $isHeadOffice &&
            $this->hasHeadOffice($business)
        ) {
            throw ValidationException::withMessages([
                'is_head_office' => [
                    'This business already has a Head Office.',
                ],
            ]);
        }

        $branch->update([
            'name' => $data['name'] ?? $branch->name,
            'code' => $data['code'] ?? $branch->code,
            'phone' => $data['phone'] ?? $branch->phone,
            'email' => $data['email'] ?? $branch->email,
            'address' => $data['address'] ?? $branch->address,
            'city' => $data['city'] ?? $branch->city,
            'state' => $data['state'] ?? $branch->state,
            'country' => $data['country'] ?? $branch->country,
            'is_head_office' => $isHeadOffice,
        ]);

        return $branch->refresh();
    }

    /**
     * Delete a branch belonging to a business.
     */
    public function delete(
        Business $business,
        Branch $branch
    ): void {
        $this->ensureBelongsToBusiness(
            $branch,
            $business
        );

        if ($branch->is_head_office) {
            throw ValidationException::withMessages([
                'branch' => [
                    'The Head Office cannot be deleted.',
                ],
            ]);
        }

        $branch->delete();
    }

    /**
     * Determine whether the business has a Head Office.
     */
    private function hasHeadOffice(
        Business $business
    ): bool {
        return $business->branches()
            ->where('is_head_office', true)
            ->exists();
    }

    /**
     * Determine whether another Head Office exists.
     */
    private function hasAnotherHeadOffice(
        Business $business,
        Branch $branch
    ): bool {
        return $business->branches()
            ->where('is_head_office', true)
            ->whereKeyNot($branch->getKey())
            ->exists();
    }

    /**
     * Ensure the branch belongs to the current business.
     */
    private function ensureBelongsToBusiness(
        Branch $branch,
        Business $business
    ): void {
        if ($branch->business_id !== $business->id) {
            abort(
                404,
                'Branch not found.'
            );
        }
    }
}
