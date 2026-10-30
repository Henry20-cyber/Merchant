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
            // Serialize branch invariants per business. Locking the parent row
            // also protects the first Head Office creation when no branch exists.
            $lockedBusiness = Business::query()
                ->whereKey($business->id)
                ->lockForUpdate()
                ->firstOrFail();

            $this->subscriptionLimitService->ensureBranchCapacity($lockedBusiness);

            $isHeadOffice = (bool) ($data['is_head_office'] ?? false);

            if ($isHeadOffice && $this->hasHeadOffice($lockedBusiness)) {
                throw ValidationException::withMessages([
                    'is_head_office' => [
                        'This business already has a Head Office.',
                    ],
                ]);
            }

            return $lockedBusiness->branches()->create([
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
        return DB::transaction(function () use ($business, $branch, $data): Branch {
            Business::query()
                ->whereKey($business->id)
                ->lockForUpdate()
                ->firstOrFail();

            $lockedBranch = Branch::query()
                ->whereKey($branch->id)
                ->where('business_id', $business->id)
                ->lockForUpdate()
                ->firstOrFail();

            $isHeadOffice = (bool) (
                $data['is_head_office'] ?? $lockedBranch->is_head_office
            );

            if (
                $lockedBranch->is_head_office &&
                ! $isHeadOffice &&
                ! $this->hasAnotherHeadOffice($business, $lockedBranch)
            ) {
                throw ValidationException::withMessages([
                    'is_head_office' => [
                        'The business must have a Head Office.',
                    ],
                ]);
            }

            if (
                ! $lockedBranch->is_head_office &&
                $isHeadOffice &&
                $this->hasHeadOffice($business)
            ) {
                throw ValidationException::withMessages([
                    'is_head_office' => [
                        'This business already has a Head Office.',
                    ],
                ]);
            }

            $lockedBranch->update([
                'name' => $data['name'] ?? $lockedBranch->name,
                'code' => $data['code'] ?? $lockedBranch->code,
                'phone' => $data['phone'] ?? $lockedBranch->phone,
                'email' => $data['email'] ?? $lockedBranch->email,
                'address' => $data['address'] ?? $lockedBranch->address,
                'city' => $data['city'] ?? $lockedBranch->city,
                'state' => $data['state'] ?? $lockedBranch->state,
                'country' => $data['country'] ?? $lockedBranch->country,
                'is_head_office' => $isHeadOffice,
            ]);

            return $lockedBranch->refresh();
        });
    }

    /**
     * Delete a branch belonging to a business.
     */
    public function delete(
        Business $business,
        Branch $branch
    ): void {
        DB::transaction(function () use ($business, $branch): void {
            Business::query()
                ->whereKey($business->id)
                ->lockForUpdate()
                ->firstOrFail();

            $lockedBranch = Branch::query()
                ->whereKey($branch->id)
                ->where('business_id', $business->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedBranch->is_head_office) {
                throw ValidationException::withMessages([
                    'branch' => [
                        'The Head Office cannot be deleted.',
                    ],
                ]);
            }

            $lockedBranch->delete();
        });
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

}
