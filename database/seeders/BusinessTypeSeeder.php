<?php

namespace Database\Seeders;

use App\Domains\Organization\Models\BusinessType;
use Illuminate\Database\Seeder;

class BusinessTypeSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $businessTypes = [
            [
                'name' => 'Supermarket',
                'description' => 'Retail businesses selling groceries and household goods.',
            ],
            [
                'name' => 'Pharmacy',
                'description' => 'Businesses selling medicines and health-related products.',
            ],
            [
                'name' => 'Restaurant',
                'description' => 'Businesses focused on food and beverage services.',
            ],
            [
                'name' => 'Bakery',
                'description' => 'Businesses producing and selling baked goods.',
            ],
            [
                'name' => 'Hotel',
                'description' => 'Businesses providing accommodation and hospitality services.',
            ],
            [
                'name' => 'Boutique',
                'description' => 'Retail businesses selling fashion and clothing products.',
            ],
            [
                'name' => 'Electronics',
                'description' => 'Businesses selling electronic devices and accessories.',
            ],
            [
                'name' => 'Bookshop',
                'description' => 'Businesses selling books, stationery, and related products.',
            ],
            [
                'name' => 'Salon',
                'description' => 'Businesses providing hair, beauty, and personal care services.',
            ],
            [
                'name' => 'Barbershop',
                'description' => 'Businesses providing barbering and grooming services.',
            ],
            [
                'name' => 'Cyber Cafe',
                'description' => 'Businesses providing internet, computer, and related services.',
            ],
            [
                'name' => 'Laundry',
                'description' => 'Businesses providing laundry and garment-care services.',
            ],
            [
                'name' => 'Hospital',
                'description' => 'Healthcare organizations providing medical services.',
            ],
            [
                'name' => 'Cosmetics',
                'description' => 'Businesses selling cosmetics and personal-care products.',
            ],
            [
                'name' => 'Other',
                'description' => 'Businesses that do not fit into the available categories.',
            ],
        ];

        foreach ($businessTypes as $type) {
            BusinessType::updateOrCreate(
                ['name' => $type['name']],
                [
                    'description' => $type['description'],
                    'is_active' => true,
                ]
            );
        }
    }
}