<?php

namespace App\Domains\Product\Services;

use App\Domains\Product\Models\ProductBarcode;
use App\Domains\Product\Models\ProductBarcodeBranchMapping;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ProductBarcodeBranchMappingService
{
    /**
     * Synchronize all eligible barcodes for a product to a branch.
     *
     * The product must already have positive stock at that branch.
     */
    public function syncProductBarcodesToBranch(
        string $businessId,
        string $productId,
        string $branchId
    ): int {
        return DB::transaction(function () use (
            $businessId,
            $productId,
            $branchId
        ) {
            $branchExists = DB::table('branches')
                ->where('id', $branchId)
                ->where('business_id', $businessId)
                ->whereNull('deleted_at')
                ->exists();

            if (! $branchExists) {
                throw ValidationException::withMessages([
                    'branch_id' => 'The selected branch does not belong to this business or is inactive.',
                ]);
            }

            $productExists = DB::table('products')
                ->where('id', $productId)
                ->where('business_id', $businessId)
                ->exists();

            if (! $productExists) {
                throw ValidationException::withMessages([
                    'product_id' => 'The selected product does not belong to this business.',
                ]);
            }

            $hasStock = DB::table('stocks')
                ->where('business_id', $businessId)
                ->where('branch_id', $branchId)
                ->where('product_id', $productId)
                ->where('quantity', '>', 0)
                ->exists();

            if (! $hasStock) {
                return 0;
            }

            $barcodes = ProductBarcode::query()
                ->where('business_id', $businessId)
                ->where('product_id', $productId)
                ->whereNotNull('product_unit_id')
                ->get();

            $synced = 0;

            foreach ($barcodes as $barcode) {
                $unitIsValid = DB::table('product_units')
                    ->where('id', $barcode->product_unit_id)
                    ->where('business_id', $businessId)
                    ->where('product_id', $productId)
                    ->where('is_sellable', true)
                    ->whereNull('deleted_at')
                    ->exists();

                if (! $unitIsValid) {
                    continue;
                }

                ProductBarcodeBranchMapping::firstOrCreate([
                    'business_id' => $businessId,
                    'branch_id' => $branchId,
                    'product_barcode_id' => $barcode->id,
                ]);

                $synced++;
            }

            return $synced;
        });
    }

    /**
     * Backfill existing barcodes for products that have positive
     * stock at a branch. Historical stock without a branch is ignored.
     */
    public function backfillExistingMappings(): int
    {
        $stockRows = DB::table('stocks')
            ->whereNotNull('branch_id')
            ->where('quantity', '>', 0)
            ->select('business_id', 'product_id', 'branch_id')
            ->distinct()
            ->get();

        $processed = 0;

        foreach ($stockRows as $stock) {
            $processed += $this->syncProductBarcodesToBranch(
                $stock->business_id,
                $stock->product_id,
                $stock->branch_id
            );
        }

        return $processed;
    }
}
