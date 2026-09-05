<?php

namespace Database\Factories;

use App\Domains\Organization\Models\Business;
use App\Domains\Organization\Models\BusinessType;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Business>
 */
class BusinessFactory extends Factory
{
    protected $model = Business::class;

    public function definition(): array
    {
        return [
            'business_type_id' => BusinessType::factory(),

            'merchant_id' => 'MCH-' . strtoupper(Str::random(6)),

            'name' => fake()->company(),

            'slug' => fn (array $attributes) =>
                Str::slug($attributes['name']),

            'phone' => fake()->phoneNumber(),

            'email' => fake()->safeEmail(),

            'website' => null,

            'registration_number' => null,

            'tax_number' => null,

            'logo' => null,

            'currency' => 'NGN',

            'timezone' => 'Africa/Lagos',

            'default_country' => 'Nigeria',

            'status' => 'trial',
        ];
    }

    /**
     * Use an existing business type.
     */
    public function withBusinessType(
        BusinessType $businessType
    ): static {
        return $this->state([
            'business_type_id' => $businessType->id,
        ]);
    }
}