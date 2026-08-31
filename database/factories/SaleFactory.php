<?php

namespace Database\Factories;

use App\Domains\Organization\Models\Business;
use App\Domains\Sales\Models\Sale;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Sale>
 */
class SaleFactory extends Factory
{
    protected $model = Sale::class;

    public function definition(): array
    {
        return [
            'business_id' => Business::factory(),
            'cashier_id' => User::factory(),

            'customer_id' => null,

            'subtotal' => 10000,
            'discount' => 0,
            'tax' => 0,
            'total' => 10000,

            'payment_method' => 'cash',
            'payment_status' => 'paid',
            'status' => 'completed',
        ];
    }
}