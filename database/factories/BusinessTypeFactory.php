<?php

namespace Database\Factories;

use App\Domains\Organization\Models\BusinessType;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<BusinessType>
 */
class BusinessTypeFactory extends Factory
{
    protected $model = BusinessType::class;

    public function definition(): array
    {
        return [
            'name' => 'Test Business Type ' . Str::uuid(),

            'icon' => null,

            'description' => fake()->sentence(),

            'is_active' => true,
        ];
    }
}