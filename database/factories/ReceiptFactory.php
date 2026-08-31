<?php

namespace Database\Factories;

use App\Domains\Organization\Models\Business;
use App\Domains\Receipt\Models\Receipt;
use App\Domains\Sales\Models\Sale;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Receipt>
 */
class ReceiptFactory extends Factory
{
    protected $model = Receipt::class;

    public function definition(): array
    {
        return [
            'business_id' => Business::factory(),

            'sale_id' => Sale::factory(),

            'receipt_number' =>
                'RCPT-' .
                fake()->unique()->numerify('######'),

            'status' => 'issued',

            'issued_by' => User::factory(),

            'issued_at' => now(),

            'snapshot' => [
                'version' => 1,

                'receipt' => [
                    'number' => 'RCPT-000001',
                    'status' => 'issued',
                ],

                'business' => [
                    'name' => 'Test Business',
                    'currency' => 'NGN',
                ],

                'customer' => null,

                'cashier' => null,

                'items' => [],
            ],
        ];
    }
}