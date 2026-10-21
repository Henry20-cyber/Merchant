<?php

namespace App\Domains\Product\Services;

use App\Domains\Organization\Models\Business;
use App\Domains\Product\Models\Product;
use App\Domains\Product\Models\ProductBarcode;
use App\Domains\Product\Models\ProductUnit;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ProductBarcodeRegistrationService
{
    public function __construct(
        private readonly ProductBarcodeBranchMappingService $mappingService,
    ) {}

    /**
     * Register a barcode for a specific sellable product unit.
     *
     * After registration, map it to branches where the product
     * already has positive stock.
     */
    public function register(
        Business $business,
        Product $product,
        ProductUnit $unit,
        string $barcode,
        string $type = 'manufacturer',
        bool $isPrimary = false,
    ): ProductBarcode {
        return DB::transaction(function () use (
            $business,
            $product,
            $unit,
            $barcode,
            $type,
            $isPrimary
        ) {
            if (! $business->barcode_enabled) {
                throw ValidationException::withMessages([
                    'barcode' => 'Barcode functionality is not enabled for this business.',
                ]);
            }

            if ($product->business_id !== $business->id) {
                throw ValidationException::withMessages([
                    'product_id' => 'The product does not belong to this business.',
                ]);
            }

            if (
                $unit->business_id !== $business->id ||
                $unit->product_id !== $product->id
            ) {
                throw ValidationException::withMessages([
                    'product_unit_id' => 'The selected unit does not belong to this product and business.',
                ]);
            }

            if (! $unit->is_sellable || $unit->trashed()) {
                throw ValidationException::withMessages([
                    'product_unit_id' => 'A barcode can only be registered for an active, sellable product unit.',
                ]);
            }

            $barcode = trim($barcode);

            if ($barcode === '') {
                throw ValidationException::withMessages([
                    'barcode' => 'A barcode value is required.',
                ]);
            }

            $alreadyExists = ProductBarcode::query()
                ->where('business_id', $business->id)
                ->where('barcode', $barcode)
                ->exists();

            if ($alreadyExists) {
                throw ValidationException::withMessages([
                    'barcode' => 'This barcode is already registered for this business.',
                ]);
            }

            $registeredBarcode = ProductBarcode::create([
                'business_id' => $business->id,
                'product_id' => $product->id,
                'product_unit_id' => $unit->id,
                'barcode' => $barcode,
                'type' => $type,
                'is_primary' => $isPrimary,
            ]);

            /*
             * Find branches where this product currently has stock.
             * The mapping service independently validates branch,
             * product and unit ownership before creating mappings.
             */
            $branchIds = DB::table('stocks')
                ->where('business_id', $business->id)
                ->where('product_id', $product->id)
                ->whereNotNull('branch_id')
                ->where('quantity', '>', 0)
                ->distinct()
                ->pluck('branch_id');

            foreach ($branchIds as $branchId) {
                $this->mappingService->syncProductBarcodesToBranch(
                    (string) $business->id,
                    (string) $product->id,
                    (string) $branchId,
                );
            }

            return $registeredBarcode;
        });
    }
}
