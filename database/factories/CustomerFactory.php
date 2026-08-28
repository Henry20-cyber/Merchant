<?php

namespace Database\Factories;

use App\Domains\Customer\Models\Customer;
use App\Domains\Organization\Models\Business;
use Illuminate\Database\Eloquent\Factories\Factory;

class CustomerFactory extends Factory
{
    protected $model = Customer::class;

    public function definition(): array
    {
        return [
            'business_id' => Business::factory(),

            'customer_number' => 'CUS-' .
                fake()->unique()->numberBetween(
                    1,
                    999999
                ),

            'name' => fake()->optional()->name(),

            'phone' => fake()->optional()->phoneNumber(),

            'status' => 'active',
        ];
    }
}
