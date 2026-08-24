<?php

namespace Database\Factories\Domains\Service\Models;

use App\Domains\Organization\Models\Business;
use App\Domains\Service\Models\Service;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Service>
 */
class ServiceFactory extends Factory
{
    protected $model = Service::class;

    public function definition(): array
    {
        return [
            'business_id' => Business::factory(),
            'name' => fake()->words(2, true),
            'description' => fake()->optional()->sentence(),
            'price' => fake()->randomFloat(2, 1000, 50000),
            'is_active' => true,
        ];
    }
}