<?php

namespace App\Domains\Organization\Services;

use App\Domains\Organization\Models\Branch;
use App\Domains\Organization\Models\Business;
use App\Domains\Organization\Models\BusinessCapabilities;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use App\Models\User;

class BusinessService
{
    /**
     * Register a new business.
     */
    public function registerBusiness(array $data): Business
    {
        return DB::transaction(function () use ($data) {
            $business = $this->createBusiness($data);

            $this->createCapabilities($business, $data);

            $this->createHeadOffice($business, $data);

            return $business;
        });
    }

    /**
     * Create the business.
     */
     private function createBusiness(array $data): Business
    {
        return Business::create([
            'business_type_id' => $data['business_type_id'],

            'name' => $data['name'],

            'slug' => $this->generateUniqueSlug($data['name']),

            'phone' => $data['phone'],

            'email' => $data['email'] ?? null,

            'website' => $data['website'] ?? null,

            'registration_number' => $data['registration_number'] ?? null,

            'tax_number' => $data['tax_number'] ?? null,

            'default_country' => $data['default_country'] ?? 'Nigeria',

            'currency' => $data['currency'] ?? 'NGN',

            'timezone' => $data['timezone'] ?? 'Africa/Lagos',

            'status' => 'trial',
        ]);
    }

      /**
     * Create the initial business capabilities.
     */
    private function createCapabilities(
        Business $business,
        array $data
    ): BusinessCapabilities {
        return $business->capabilities()->create([
            'products_enabled' => (bool) (
                $data['products_enabled'] ?? false
            ),

            'services_enabled' => (bool) (
                $data['services_enabled'] ?? false
            ),
        ]);
    }

    /**
     * Generate a unique business slug.
     */
    private function generateUniqueSlug(string $name): string
    {
        $baseSlug = Str::slug($name);

        $slug = $baseSlug;

        $counter = 2;

        while (
            Business::withTrashed()
                ->where('slug', $slug)
                ->exists()
        ) {
            $slug = "{$baseSlug}-{$counter}";

            $counter++;
        }

        return $slug;
    }

    /**
     * Automatically create the Head Office branch.
     */
    private function createHeadOffice(
        Business $business,
        array $data
    ): Branch {
        return Branch::create([
            'business_id' => $business->id,

            'name' => 'Head Office',

            'code' => 'HO-' . strtoupper(Str::random(6)),

            'phone' => $business->phone,

            'email' => $business->email,

            'address' => $data['address'] ?? null,

            'city' => $data['city'],

            'state' => $data['state'],

            'country' => $data['default_country'] ?? 'Nigeria',

            'is_head_office' => true,
        ]);
    }

    /**
 * Get a business belonging to the authenticated user.
 */
public function getForUser(User $user, string $businessId): Business
{
    return Business::query()
        ->where('id', $businessId)
        ->whereHas('memberships', function ($query) use ($user) {
            $query
                ->where('user_id', $user->id)
                ->where('status', 'active');
        })
        ->firstOrFail();
}

/**
 * Update a business belonging to the authenticated user.
 */
public function updateForUser(
    User $user,
    string $businessId,
    array $data
): Business {
    $business = Business::query()
        ->where('id', $businessId)
        ->whereHas('memberships', function ($query) use ($user) {
            $query
                ->where('user_id', $user->id)
                ->where('status', 'active');
        })
        ->firstOrFail();

    $business->update($data);

    return $business->refresh();
}
}