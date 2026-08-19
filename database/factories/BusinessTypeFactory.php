<?php

namespace Database\Factories;

use App\Domains\Organization\Models\BusinessType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BusinessType>
 */
class BusinessTypeFactory extends Factory
{
    protected $model = BusinessType::class;

    public function definition(): array
    {
        return [
            'name' => fake()->unique()->words(2, true),
            'icon' => null,
            'description' => fake()->sentence(),
            'is_active' => true,
        ];
    }
}