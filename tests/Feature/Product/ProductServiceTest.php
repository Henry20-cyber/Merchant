<?php

namespace Tests\Feature\Product;

use App\Domains\Organization\Models\Business;
use App\Domains\Product\Models\Product;
use App\Domains\Product\Services\ProductService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ProductServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_product_is_created_with_a_base_unit(): void
    {
        $business = Business::factory()->create();

        $service = app(ProductService::class);

        $product = $service->createProduct(
            $business,
            [
                'name' => 'Gala',
                'sku' => 'GALA-001',
                'description' => 'Gala sausage roll',
            ],
            [
                'name' => 'Piece',
                'quantity' => 1,
                'cost_price' => 300,
                'selling_price' => 500,
            ]
        );

        $this->assertDatabaseHas('products', [
            'id' => $product->id,
            'business_id' => $business->id,
            'name' => 'Gala',
        ]);

        $this->assertCount(
            1,
            $product->units
        );

        $this->assertTrue(
            $product->units->first()->is_base_unit
        );

        $this->assertEquals(
            1,
            $product->units->first()->quantity
        );
    }

    public function test_base_unit_cannot_have_quantity_other_than_one(): void
    {
        $business = Business::factory()->create();

        $product = Product::factory()->create([
            'business_id' => $business->id,
        ]);

        $service = app(ProductService::class);

        $this->expectException(
            ValidationException::class
        );

        $service->createBaseUnit(
            $product,
            $business,
            [
                'name' => 'Piece',
                'quantity' => 12,
                'cost_price' => 300,
                'selling_price' => 500,
            ]
        );
    }

    public function test_product_cannot_have_two_base_units(): void
    {
        $business = Business::factory()->create();

        $product = Product::factory()->create([
            'business_id' => $business->id,
        ]);

        $service = app(ProductService::class);

        $service->createBaseUnit(
            $product,
            $business,
            [
                'name' => 'Piece',
                'quantity' => 1,
                'cost_price' => 300,
                'selling_price' => 500,
            ]
        );

        $this->expectException(
            ValidationException::class
        );

        $service->createBaseUnit(
            $product,
            $business,
            [
                'name' => 'Bottle',
                'quantity' => 1,
                'cost_price' => 300,
                'selling_price' => 500,
            ]
        );
    }

    public function test_bulk_unit_can_be_added(): void
    {
        $business = Business::factory()->create();

        $product = Product::factory()->create([
            'business_id' => $business->id,
        ]);

        $service = app(ProductService::class);

        $service->createBaseUnit(
            $product,
            $business,
            [
                'name' => 'Piece',
                'quantity' => 1,
                'cost_price' => 300,
                'selling_price' => 500,
            ]
        );

        $carton = $service->addUnit(
            $product,
            $business,
            [
                'name' => 'Carton',
                'quantity' => 12,
                'cost_price' => 3000,
                'selling_price' => 5700,
            ]
        );

        $this->assertEquals(
            'Carton',
            $carton->name
        );

        $this->assertEquals(
            12,
            $carton->quantity
        );

        $this->assertFalse(
            $carton->is_base_unit
        );
    }

    public function test_non_base_unit_cannot_have_quantity_of_one(): void
    {
        $business = Business::factory()->create();

        $product = Product::factory()->create([
            'business_id' => $business->id,
        ]);

        $service = app(ProductService::class);

        $this->expectException(
            ValidationException::class
        );

        $service->addUnit(
            $product,
            $business,
            [
                'name' => 'Carton',
                'quantity' => 1,
                'cost_price' => 3000,
                'selling_price' => 5700,
            ]
        );
    }

    public function test_duplicate_unit_names_are_rejected(): void
    {
        $business = Business::factory()->create();

        $product = Product::factory()->create([
            'business_id' => $business->id,
        ]);

        $service = app(ProductService::class);

        $service->createBaseUnit(
            $product,
            $business,
            [
                'name' => 'Piece',
                'quantity' => 1,
                'cost_price' => 300,
                'selling_price' => 500,
            ]
        );

        $this->expectException(
            ValidationException::class
        );

        $service->addUnit(
            $product,
            $business,
            [
                'name' => 'Piece',
                'quantity' => 12,
                'cost_price' => 3000,
                'selling_price' => 5700,
            ]
        );
    }

    public function test_product_from_another_business_cannot_be_modified(): void
    {
        $businessA = Business::factory()->create();
        $businessB = Business::factory()->create();

        $product = Product::factory()->create([
            'business_id' => $businessA->id,
        ]);

        $service = app(ProductService::class);

        $this->expectException(
            ValidationException::class
        );

        $service->createBaseUnit(
            $product,
            $businessB,
            [
                'name' => 'Piece',
                'quantity' => 1,
                'cost_price' => 300,
                'selling_price' => 500,
            ]
        );
    }

    public function test_bulk_unit_cannot_be_added_to_product_from_another_business(): void
    {
        $businessA = Business::factory()->create();
        $businessB = Business::factory()->create();

        $product = Product::factory()->create([
            'business_id' => $businessA->id,
        ]);

        $service = app(ProductService::class);

        $this->expectException(
            ValidationException::class
        );

        $service->addUnit(
            $product,
            $businessB,
            [
                'name' => 'Carton',
                'quantity' => 12,
                'cost_price' => 3000,
                'selling_price' => 5700,
            ]
        );
    }

    public function test_base_unit_cannot_be_deleted(): void
{
    $business = Business::factory()->create();

    $product = Product::factory()->create([
        'business_id' => $business->id,
    ]);

    $service = app(ProductService::class);

    $baseUnit = $service->createBaseUnit(
        $product,
        $business,
        [
            'name' => 'Piece',
            'quantity' => 1,
            'cost_price' => 300,
            'selling_price' => 500,
        ]
    );

    $this->expectException(
        ValidationException::class
    );

    $service->removeUnit(
        $baseUnit,
        $business
    );
}

public function test_bulk_unit_can_be_deleted(): void
{
    $business = Business::factory()->create();

    $product = Product::factory()->create([
        'business_id' => $business->id,
    ]);

    $service = app(ProductService::class);

    $service->createBaseUnit(
        $product,
        $business,
        [
            'name' => 'Piece',
            'quantity' => 1,
            'cost_price' => 300,
            'selling_price' => 500,
        ]
    );

    $carton = $service->addUnit(
        $product,
        $business,
        [
            'name' => 'Carton',
            'quantity' => 12,
            'cost_price' => 3000,
            'selling_price' => 5700,
        ]
    );

    $service->removeUnit(
        $carton,
        $business
    );

    $this->assertSoftDeleted(
        'product_units',
        [
            'id' => $carton->id,
        ]
    );

    $this->assertDatabaseHas(
        'product_units',
        [
            'id' => $carton->id,
            'is_base_unit' => false,
        ]
    );
}

public function test_base_unit_quantity_cannot_be_changed(): void
{
    $business = Business::factory()->create();

    $product = Product::factory()->create([
        'business_id' => $business->id,
    ]);

    $service = app(ProductService::class);

    $baseUnit = $service->createBaseUnit(
        $product,
        $business,
        [
            'name' => 'Piece',
            'quantity' => 1,
            'cost_price' => 300,
            'selling_price' => 500,
        ]
    );

    $this->expectException(
        ValidationException::class
    );

    $service->updateUnit(
        $baseUnit,
        $business,
        [
            'quantity' => 12,
        ]
    );
}

public function test_bulk_unit_can_be_updated(): void
{
    $business = Business::factory()->create();

    $product = Product::factory()->create([
        'business_id' => $business->id,
    ]);

    $service = app(ProductService::class);

    $service->createBaseUnit(
        $product,
        $business,
        [
            'name' => 'Piece',
            'quantity' => 1,
            'cost_price' => 300,
            'selling_price' => 500,
        ]
    );

    $carton = $service->addUnit(
        $product,
        $business,
        [
            'name' => 'Carton',
            'quantity' => 12,
            'cost_price' => 3000,
            'selling_price' => 5700,
        ]
    );

    $updated = $service->updateUnit(
        $carton,
        $business,
        [
            'quantity' => 24,
            'selling_price' => 10000,
        ]
    );

    $this->assertEquals(
        24,
        $updated->quantity
    );

    $this->assertEquals(
        10000,
        $updated->selling_price
    );
}

public function test_unit_from_another_business_cannot_be_updated(): void
{
    $businessA = Business::factory()->create();
    $businessB = Business::factory()->create();

    $product = Product::factory()->create([
        'business_id' => $businessA->id,
    ]);

    $service = app(ProductService::class);

    $unit = $service->createBaseUnit(
        $product,
        $businessA,
        [
            'name' => 'Piece',
            'quantity' => 1,
            'cost_price' => 300,
            'selling_price' => 500,
        ]
    );

    $this->expectException(
        ValidationException::class
    );

    $service->updateUnit(
        $unit,
        $businessB,
        [
            'selling_price' => 1000,
        ]
    );
}

public function test_unit_from_another_business_cannot_be_deleted(): void
{
    $businessA = Business::factory()->create();
    $businessB = Business::factory()->create();

    $product = Product::factory()->create([
        'business_id' => $businessA->id,
    ]);

    $service = app(ProductService::class);

    $service->createBaseUnit(
        $product,
        $businessA,
        [
            'name' => 'Piece',
            'quantity' => 1,
            'cost_price' => 300,
            'selling_price' => 500,
        ]
    );

    $carton = $service->addUnit(
        $product,
        $businessA,
        [
            'name' => 'Carton',
            'quantity' => 12,
            'cost_price' => 3000,
            'selling_price' => 5700,
        ]
    );

    $this->expectException(
        ValidationException::class
    );

    $service->removeUnit(
        $carton,
        $businessB
    );
}
}
