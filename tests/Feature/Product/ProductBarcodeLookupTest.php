<?php

namespace Tests\Feature\Product;

use App\Domains\Organization\Models\Branch;
use App\Domains\Organization\Models\Business;
use App\Domains\Product\Controllers\ProductController;
use App\Domains\Product\Models\Product;
use App\Domains\Product\Models\ProductBarcode;
use App\Domains\Product\Models\ProductBarcodeBranchMapping;
use App\Domains\Product\Models\ProductUnit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class ProductBarcodeLookupTest extends TestCase
{
    use RefreshDatabase;

    private function createBranch(Business $business, string $code): Branch
    {
        return Branch::create([
            'business_id' => $business->id,
            'name' => "Branch {$code}",
            'code' => $code,
            'city' => 'Owerri',
            'state' => 'Imo',
            'country' => 'Nigeria',
            'is_head_office' => $code === 'MAIN',
        ]);
    }

    private function createProductAndUnit(Business $business): array
    {
        $product = Product::factory()->create([
            'business_id' => $business->id,
            'name' => 'Test Product',
        ]);

        $unit = ProductUnit::factory()->create([
            'business_id' => $business->id,
            'product_id' => $product->id,
            'quantity' => 1,
            'cost_price' => 100,
            'selling_price' => 150,
            'is_base_unit' => true,
            'is_sellable' => true,
            'is_purchasable' => true,
        ]);

        return [$product, $unit];
    }

    private function createBarcodeMapping(
        Business $business,
        Branch $branch,
        Product $product,
        ProductUnit $unit,
        string $value
    ): ProductBarcode {
        $barcode = ProductBarcode::create([
            'business_id' => $business->id,
            'product_id' => $product->id,
            'product_unit_id' => $unit->id,
            'barcode' => $value,
            'type' => 'manufacturer',
            'is_primary' => true,
        ]);

        ProductBarcodeBranchMapping::create([
            'business_id' => $business->id,
            'branch_id' => $branch->id,
            'product_barcode_id' => $barcode->id,
        ]);

        return $barcode;
    }

    private function lookup(
        Business $business,
        Branch $branch,
        string $value
    ) {
        $request = Request::create(
            '/api/businesses/current/products/barcodes/lookup',
            'POST',
            ['barcode' => $value]
        );

        $request->attributes->set('current_business', $business);
        $request->attributes->set('current_branch', $branch);

        return app(ProductController::class)->lookupBarcode($request);
    }

    public function test_lookup_returns_product_and_exact_unit(): void
    {
        $business = Business::factory()->create([
            'barcode_enabled' => true,
        ]);

        $branch = $this->createBranch($business, 'MAIN');

        [$product, $unit] = $this->createProductAndUnit($business);

        $this->createBarcodeMapping(
            $business,
            $branch,
            $product,
            $unit,
            '1234567890123'
        );

        $response = $this->lookup($business, $branch, '1234567890123');
        $data = $response->getData(true);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertTrue($data['success']);
        $this->assertSame($product->id, $data['product']['id']);
        $this->assertSame($unit->id, $data['unit']['id']);
    }

    public function test_unknown_barcode_returns_not_found_message(): void
    {
        $business = Business::factory()->create([
            'barcode_enabled' => true,
        ]);

        $branch = $this->createBranch($business, 'MAIN');

        $response = $this->lookup($business, $branch, 'UNKNOWN-123');
        $data = $response->getData(true);

        $this->assertSame(404, $response->getStatusCode());
        $this->assertSame(
            'Barcode not found at this branch.',
            $data['message']
        );
    }

    public function test_barcode_mapped_to_another_branch_is_not_found(): void
    {
        $business = Business::factory()->create([
            'barcode_enabled' => true,
        ]);

        $activeBranch = $this->createBranch($business, 'MAIN');
        $otherBranch = $this->createBranch($business, 'OTHER');

        [$product, $unit] = $this->createProductAndUnit($business);

        $this->createBarcodeMapping(
            $business,
            $otherBranch,
            $product,
            $unit,
            '9876543210123'
        );

        $response = $this->lookup(
            $business,
            $activeBranch,
            '9876543210123'
        );

        $this->assertSame(404, $response->getStatusCode());
        $this->assertSame(
            'Barcode not found at this branch.',
            $response->getData(true)['message']
        );
    }

    public function test_lookup_is_rejected_when_barcode_feature_is_disabled(): void
    {
        $business = Business::factory()->create([
            'barcode_enabled' => false,
        ]);

        $branch = $this->createBranch($business, 'MAIN');

        try {
            $this->lookup($business, $branch, '1234567890123');
            $this->fail('Expected barcode lookup to be rejected.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
            $this->assertSame(
                'Barcode functionality is not enabled for this business.',
                $exception->getMessage()
            );
        }
    }
}
