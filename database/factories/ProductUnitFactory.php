<?php

namespace Database\Factories;

use App\Domains\Organization\Models\Business;
use App\Domains\Product\Models\Product;
use App\Domains\Product\Models\ProductUnit;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProductUnit>
 */
class ProductUnitFactory extends Factory
{
    protected $model = ProductUnit::class;

    public function definition(): array
    {
        return [
            'business_id' => Business::factory(),

            'product_id' => Product::factory(),

            'name' => 'Piece',

            'quantity' => 1,

            'cost_price' => fake()->randomFloat(
                2,
                100,
                50000
            ),

            'selling_price' => fake()->randomFloat(
                2,
                100,
                75000
            ),

            'currency' => 'NGN',

            'is_base_unit' => true,

            'is_sellable' => true,

            'is_purchasable' => true,
        ];
    }

    /**
     * Configure this unit as a bulk unit.
     */
    public function bulk(
        string $name = 'Carton',
        int|float $quantity = 12
    ): static {
        return $this->state([
            'name' => $name,
            'quantity' => $quantity,
            'is_base_unit' => false,
        ]);
    }

    /**
     * Configure this unit as the base unit.
     */
    public function base(
        string $name = 'Piece'
    ): static {
        return $this->state([
            'name' => $name,
            'quantity' => 1,
            'is_base_unit' => true,
        ]);
    }
}