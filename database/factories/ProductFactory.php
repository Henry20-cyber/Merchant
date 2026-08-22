<?php

namespace Database\Factories;

use App\Domains\Organization\Models\Business;
use App\Domains\Product\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Product>
 */
class ProductFactory extends Factory
{
    protected $model = Product::class;

    public function definition(): array
    {
        return [
            'business_id' => Business::factory(),

            'name' => fake()->words(
                fake()->numberBetween(2, 4),
                true
            ),

            'sku' => 'SKU-' . fake()->unique()->bothify('####??'),

            'description' => fake()->optional()->sentence(),

            'status' => 'active',
        ];
    }
}