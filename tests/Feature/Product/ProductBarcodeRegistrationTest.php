<?php

namespace Tests\Feature\Product;

use App\Domains\Organization\Models\Branch;
use App\Domains\Organization\Models\Business;
use App\Domains\Product\Models\Product;
use App\Domains\Product\Models\ProductBarcode;
use App\Domains\Product\Models\ProductUnit;
use App\Domains\Product\Services\ProductBarcodeRegistrationService;
use App\Domains\Inventory\Services\StockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ProductBarcodeRegistrationTest extends TestCase
{
    use RefreshDatabase;

    private function branchFor(
        Business $business,
        string $suffix = 'MAIN'
    ): Branch {
        return Branch::create([
            'business_id' => $business->id,
            'name' => $suffix . ' Branch',
            'code' => $suffix . '-' . $business->id,
            'city' => 'Owerri',
            'state' => 'Imo',
            'country' => 'Nigeria',
            'is_head_office' => $suffix === 'MAIN',
        ]);
    }

    private function productAndUnit(Business $business): array
    {
        $product = Product::factory()->create([
            'business_id' => $business->id,
        ]);

        $unit = ProductUnit::factory()->create([
            'business_id' => $business->id,
            'product_id' => $product->id,
            'name' => 'Piece',
            'quantity' => 1,
            'is_base_unit' => true,
            'is_sellable' => true,
        ]);

        return [$product, $unit];
    }

    private function enableBarcodes(Business $business): void
    {
        $business->forceFill(['barcode_enabled' => true])->save();
    }

    public function test_registration_maps_barcode_to_existing_stock_branches(): void
    {
        $business = Business::factory()->create();
        $this->enableBarcodes($business);

        [$product, $unit] = $this->productAndUnit($business);

        $branchA = $this->branchFor($business, 'MAIN');
        $branchB = $this->branchFor($business, 'SECOND');

        $stockService = app(StockService::class);

        $stockService->receive($business, $branchA, $product, $unit, 10);
        $stockService->receive($business, $branchB, $product, $unit, 5);

        $barcode = app(ProductBarcodeRegistrationService::class)->register(
            $business,
            $product,
            $unit,
            'REG-' . $product->id,
        );

        $this->assertDatabaseHas('product_barcodes', [
            'id' => $barcode->id,
            'business_id' => $business->id,
            'product_id' => $product->id,
            'product_unit_id' => $unit->id,
            'barcode' => 'REG-' . $product->id,
        ]);

        foreach ([$branchA, $branchB] as $branch) {
            $this->assertDatabaseHas('product_barcode_branch_mappings', [
                'business_id' => $business->id,
                'branch_id' => $branch->id,
                'product_barcode_id' => $barcode->id,
            ]);
        }

        $this->assertDatabaseCount('product_barcode_branch_mappings', 2);
    }

    public function test_registration_rejects_duplicate_barcode_within_business(): void
    {
        $business = Business::factory()->create();
        $this->enableBarcodes($business);

        [$product, $unit] = $this->productAndUnit($business);

        $service = app(ProductBarcodeRegistrationService::class);

        $service->register(
            $business,
            $product,
            $unit,
            'DUPLICATE-123',
        );

        try {
            $service->register(
                $business,
                $product,
                $unit,
                'DUPLICATE-123',
            );

            $this->fail('Expected duplicate barcode registration to be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey(
                'barcode',
                $exception->errors()
            );
        }

        $this->assertDatabaseCount('product_barcodes', 1);
    }

    public function test_registration_is_rejected_when_barcode_feature_is_disabled(): void
    {
        $business = Business::factory()->create([
            'barcode_enabled' => false,
        ]);

        [$product, $unit] = $this->productAndUnit($business);

        try {
            app(ProductBarcodeRegistrationService::class)->register(
                $business,
                $product,
                $unit,
                'DISABLED-' . $product->id,
            );

            $this->fail('Expected barcode registration to be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey(
                'barcode',
                $exception->errors()
            );
        }

        $this->assertDatabaseCount('product_barcodes', 0);
    }

    public function test_registration_rejects_unit_from_another_product(): void
    {
        $business = Business::factory()->create();
        $this->enableBarcodes($business);

        [$productA, $unitA] = $this->productAndUnit($business);
        [$productB, $unitB] = $this->productAndUnit($business);

        try {
            app(ProductBarcodeRegistrationService::class)->register(
                $business,
                $productA,
                $unitB,
                'WRONG-UNIT-' . $productA->id,
            );

            $this->fail('Expected mismatched product unit to be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey(
                'product_unit_id',
                $exception->errors()
            );
        }

        $this->assertDatabaseCount('product_barcodes', 0);
    }

    public function test_registration_rejects_unit_from_another_business(): void
    {
        $businessA = Business::factory()->create();
        $businessB = Business::factory()->create();

        $this->enableBarcodes($businessA);

        [$productA, $unitA] = $this->productAndUnit($businessA);
        [$productB, $unitB] = $this->productAndUnit($businessB);

        try {
            app(ProductBarcodeRegistrationService::class)->register(
                $businessA,
                $productA,
                $unitB,
                'CROSS-BUSINESS-' . $productA->id,
            );

            $this->fail('Expected cross-business unit to be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey(
                'product_unit_id',
                $exception->errors()
            );
        }

        $this->assertDatabaseCount('product_barcodes', 0);
    }
}
