<?php

namespace App\Domains\Organization\Services;

use App\Domains\Organization\Models\Branch;
use App\Domains\Organization\Models\Business;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class BusinessService
{
    /**
     * Register a new business.
     */
    public function registerBusiness(array $data): Business
    {
        return DB::transaction(function () use ($data) {

            $business = $this->createBusiness($data);

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

            'slug' => Str::slug($data['name']),

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
}