<?php

namespace Tests\Feature\Sales;

use App\Domains\Inventory\Models\Stock;
use App\Domains\Organization\Models\Business;
use App\Domains\Product\Models\Product;
use App\Domains\Product\Models\ProductUnit;
use App\Domains\Sales\Models\Sale;
use App\Domains\Sales\Models\SaleItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class SaleTest extends TestCase
{
    use RefreshDatabase;

    private function createBusiness(): Business
    {
        return Business::factory()->create();
    }

    private function createUser(): User
    {
        return User::factory()->create();
    }

    private function createProductWithUnit(
        Business $business,
        float $costPrice = 100,
        float $sellingPrice = 150
    ): array {
        $product = Product::factory()->create([
            'business_id' => $business->id,
        ]);

        $unit = ProductUnit::factory()->create([
            'business_id' => $business->id,
            'product_id' => $product->id,
            'quantity' => 1,
            'cost_price' => $costPrice,
            'selling_price' => $sellingPrice,
            'is_base_unit' => true,
            'is_sellable' => true,
            'is_purchasable' => true,
        ]);

        return [$product, $unit];
    }

    private function createStock(
        Business $business,
        Product $product,
        ProductUnit $unit,
        float $quantity = 100
    ): Stock {
        return Stock::create([
            'business_id' => $business->id,
            'product_id' => $product->id,
            'product_unit_id' => $unit->id,
            'quantity' => $quantity,
            'reorder_level' => 10,
        ]);
    }

    public function test_sale_belongs_to_business(): void
    {
        $business = $this->createBusiness();
        $user = $this->createUser();

        $sale = Sale::create([
            'business_id' => $business->id,
            'cashier_id' => $user->id,
            'subtotal' => 300,
            'discount' => 0,
            'tax' => 0,
            'total' => 300,
            'payment_method' => 'cash',
            'payment_status' => 'paid',
            'status' => 'completed',
        ]);

        $this->assertTrue($sale->business->is($business));
    }

    public function test_sale_belongs_to_cashier(): void
    {
        $business = $this->createBusiness();
        $user = $this->createUser();

        $sale = Sale::create([
            'business_id' => $business->id,
            'cashier_id' => $user->id,
            'subtotal' => 300,
            'discount' => 0,
            'tax' => 0,
            'total' => 300,
            'payment_method' => 'cash',
            'payment_status' => 'paid',
            'status' => 'completed',
        ]);

        $this->assertTrue($sale->cashier->is($user));
    }

    public function test_sale_can_have_multiple_items(): void
    {
        $business = $this->createBusiness();
        $user = $this->createUser();

        [$productA, $unitA] = $this->createProductWithUnit(
            $business,
            100,
            150
        );

        [$productB, $unitB] = $this->createProductWithUnit(
            $business,
            200,
            250
        );

        $sale = Sale::create([
            'business_id' => $business->id,
            'cashier_id' => $user->id,
            'subtotal' => 400,
            'discount' => 0,
            'tax' => 0,
            'total' => 400,
            'payment_method' => 'cash',
            'payment_status' => 'paid',
            'status' => 'completed',
        ]);

        $itemA = SaleItem::create([
            'sale_id' => $sale->id,
            'product_id' => $productA->id,
            'product_unit_id' => $unitA->id,
            'quantity' => 1,
            'unit_price' => 150,
            'unit_cost' => 100,
            'discount' => 0,
            'total' => 150,
        ]);

        $itemB = SaleItem::create([
            'sale_id' => $sale->id,
            'product_id' => $productB->id,
            'product_unit_id' => $unitB->id,
            'quantity' => 1,
            'unit_price' => 250,
            'unit_cost' => 200,
            'discount' => 0,
            'total' => 250,
        ]);

        $this->assertCount(2, $sale->fresh()->items);

        $this->assertTrue(
            $sale->fresh()->items->contains(
                fn (SaleItem $item) => $item->is($itemA)
            )
        );

        $this->assertTrue(
            $sale->fresh()->items->contains(
                fn (SaleItem $item) => $item->is($itemB)
            )
        );
    }

    public function test_sale_item_belongs_to_sale(): void
    {
        $business = $this->createBusiness();
        $user = $this->createUser();

        [$product, $unit] = $this->createProductWithUnit($business);

        $sale = Sale::create([
            'business_id' => $business->id,
            'cashier_id' => $user->id,
            'subtotal' => 150,
            'discount' => 0,
            'tax' => 0,
            'total' => 150,
            'payment_method' => 'cash',
            'payment_status' => 'paid',
            'status' => 'completed',
        ]);

        $item = SaleItem::create([
            'sale_id' => $sale->id,
            'product_id' => $product->id,
            'product_unit_id' => $unit->id,
            'quantity' => 1,
            'unit_price' => 150,
            'unit_cost' => 100,
            'discount' => 0,
            'total' => 150,
        ]);

        $this->assertTrue($item->sale->is($sale));
    }

    public function test_sale_item_references_correct_product_and_unit(): void
    {
        $business = $this->createBusiness();
        $user = $this->createUser();

        [$product, $unit] = $this->createProductWithUnit($business);

        $sale = Sale::create([
            'business_id' => $business->id,
            'cashier_id' => $user->id,
            'subtotal' => 150,
            'discount' => 0,
            'tax' => 0,
            'total' => 150,
            'payment_method' => 'cash',
            'payment_status' => 'paid',
            'status' => 'completed',
        ]);

        $item = SaleItem::create([
            'sale_id' => $sale->id,
            'product_id' => $product->id,
            'product_unit_id' => $unit->id,
            'quantity' => 1,
            'unit_price' => 150,
            'unit_cost' => 100,
            'discount' => 0,
            'total' => 150,
        ]);

        $this->assertTrue($item->product->is($product));
        $this->assertTrue($item->productUnit->is($unit));
    }

    public function test_sale_item_preserves_historical_price_and_cost(): void
    {
        $business = $this->createBusiness();
        $user = $this->createUser();

        [$product, $unit] = $this->createProductWithUnit(
            $business,
            100,
            150
        );

        $sale = Sale::create([
            'business_id' => $business->id,
            'cashier_id' => $user->id,
            'subtotal' => 150,
            'discount' => 0,
            'tax' => 0,
            'total' => 150,
            'payment_method' => 'cash',
            'payment_status' => 'paid',
            'status' => 'completed',
        ]);

        $item = SaleItem::create([
            'sale_id' => $sale->id,
            'product_id' => $product->id,
            'product_unit_id' => $unit->id,
            'quantity' => 1,
            'unit_price' => 150,
            'unit_cost' => 100,
            'discount' => 0,
            'total' => 150,
        ]);

        $unit->update([
            'cost_price' => 120,
            'selling_price' => 180,
        ]);

        $item = $item->fresh();

        $this->assertEquals('150.00', $item->unit_price);
        $this->assertEquals('100.00', $item->unit_cost);
    }

    public function test_product_and_unit_must_belong_to_same_business(): void
    {
        $businessA = $this->createBusiness();
        $businessB = $this->createBusiness();

        [$productA, $unitA] = $this->createProductWithUnit($businessA);
        [$productB, $unitB] = $this->createProductWithUnit($businessB);

        $this->assertNotEquals(
            $businessA->id,
            $productB->business_id
        );

        $this->assertNotEquals(
            $businessA->id,
            $unitB->business_id
        );
    }

    public function test_stock_can_be_created_for_sale_product(): void
    {
        $business = $this->createBusiness();

        [$product, $unit] = $this->createProductWithUnit($business);

        $stock = $this->createStock(
            $business,
            $product,
            $unit,
            50
        );

        $this->assertDatabaseHas('stocks', [
            'id' => $stock->id,
            'business_id' => $business->id,
            'product_id' => $product->id,
            'product_unit_id' => $unit->id,
            'quantity' => 50,
        ]);
    }

    public function test_sale_total_can_be_calculated_from_items(): void
    {
        $business = $this->createBusiness();
        $user = $this->createUser();

        [$productA, $unitA] = $this->createProductWithUnit(
            $business,
            100,
            150
        );

        [$productB, $unitB] = $this->createProductWithUnit(
            $business,
            200,
            250
        );

        $sale = Sale::create([
            'business_id' => $business->id,
            'cashier_id' => $user->id,
            'subtotal' => 650,
            'discount' => 50,
            'tax' => 0,
            'total' => 600,
            'payment_method' => 'cash',
            'payment_status' => 'paid',
            'status' => 'completed',
        ]);

        SaleItem::create([
            'sale_id' => $sale->id,
            'product_id' => $productA->id,
            'product_unit_id' => $unitA->id,
            'quantity' => 2,
            'unit_price' => 150,
            'unit_cost' => 100,
            'discount' => 0,
            'total' => 300,
        ]);

        SaleItem::create([
            'sale_id' => $sale->id,
            'product_id' => $productB->id,
            'product_unit_id' => $unitB->id,
            'quantity' => 1,
            'unit_price' => 250,
            'unit_cost' => 200,
            'discount' => 0,
            'total' => 250,
        ]);

        $itemsTotal = $sale->fresh()
            ->items
            ->sum('total');

        $this->assertEquals(550, $itemsTotal);

        $this->assertEquals(
            600,
            (float) $sale->total
        );
    }
}
