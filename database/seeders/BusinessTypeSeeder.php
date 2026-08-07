<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use App\Domains\Organization\Models\BusinessType;

class BusinessTypeSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
       {
        $businessTypes = [

            'Supermarket',
            'Pharmacy',
            'Restaurant',
            'Bakery',
            'Hotel',
            'Boutique',
            'Electronics',
            'Bookshop',
            'Salon',
            'Barbershop',
            'Cyber Cafe',
            'Laundry',
            'Hospital',
            'Cosmetics',
            'Other'

        ];

        foreach ($businessTypes as $type) {

            BusinessType::firstOrCreate([
                'name' => $type
            ]);

        }
    }
    }
}
