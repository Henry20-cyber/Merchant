<?php

namespace App\Domains\Inventory\Services;

use App\Domains\Inventory\Models\Stock;
use App\Domains\Inventory\Models\StockMovement;
use App\Domains\Organization\Models\Branch;
use App\Domains\Organization\Models\Business;
use App\Domains\Product\Models\Product;
use App\Domains\Product\Models\ProductUnit;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class StockService
{
    public function __construct(
        private readonly InventoryQuantityConverter $quantityConverter
    ) {}

    public function createStock(
        Business $business,
        Branch $branch,
        Product $product
    ): Stock {
        $this->assertBranchOwnership($business, $branch);
        $this->assertProductOwnership($business, $product);

        return Stock::firstOrCreate(
            [
                'business_id' => $business->id,
                'branch_id' => $branch->id,
                'product_id' => $product->id,
            ],
            [
                'quantity' => 0,
                'reorder_level' => 0,
            ]
        );
    }

    public function receive(
        Business $business,
        Branch $branch,
        Product $product,
        ProductUnit $unit,
        float $quantity,
        ?string $note = null,
        ?User $user = null
    ): Stock {
        $this->assertPositiveQuantity($quantity);

        return DB::transaction(function () use ($business, $branch, $product, $unit, $quantity, $note, $user) {
            $this->assertOwnership($business, $branch, $product, $unit);

            $baseQuantity = $this->quantityConverter->toBaseUnits($quantity, $unit);
            $stock = $this->lockOrCreateStock($business, $branch, $product);

            $before = (float) $stock->quantity;
            $after = $before + $baseQuantity;

            $stock->update(['quantity' => $after]);

            $this->createMovement(
                $business, $branch, $product, $unit, $stock,
                'receive', $quantity, $baseQuantity,
                $before, $after, $note, $user
            );

            return $stock->fresh();
        });
    }

    public function issue(
        Business $business,
        Branch $branch,
        Product $product,
        ProductUnit $unit,
        float $quantity,
        ?string $note = null,
        ?User $user = null
    ): Stock {
        $this->assertPositiveQuantity($quantity);

        return DB::transaction(function () use ($business, $branch, $product, $unit, $quantity, $note, $user) {
            $this->assertOwnership($business, $branch, $product, $unit);

            $baseQuantity = $this->quantityConverter->toBaseUnits($quantity, $unit);
            $stock = $this->lockOrCreateStock($business, $branch, $product);

            $before = (float) $stock->quantity;
            $after = $before - $baseQuantity;

            if ($after < 0) {
                throw ValidationException::withMessages([
                    'quantity' => 'Insufficient stock.',
                ]);
            }

            $stock->update(['quantity' => $after]);

            $this->createMovement(
                $business, $branch, $product, $unit, $stock,
                'sale', $quantity, -$baseQuantity,
                $before, $after, $note, $user
            );

            return $stock->fresh();
        });
    }

    public function adjust(
        Business $business,
        Branch $branch,
        Product $product,
        ProductUnit $unit,
        float $quantity,
        ?string $note = null,
        ?User $user = null
    ): Stock {
        if ($quantity === 0.0) {
            throw ValidationException::withMessages([
                'quantity' => 'Adjustment quantity cannot be zero.',
            ]);
        }

        return DB::transaction(function () use ($business, $branch, $product, $unit, $quantity, $note, $user) {
            $this->assertOwnership($business, $branch, $product, $unit);

            $absoluteBaseQuantity = $this->quantityConverter->toBaseUnits(abs($quantity), $unit);
            $baseQuantity = $quantity > 0 ? $absoluteBaseQuantity : -$absoluteBaseQuantity;
            $stock = $this->lockOrCreateStock($business, $branch, $product);

            $before = (float) $stock->quantity;
            $after = $before + $baseQuantity;

            if ($after < 0) {
                throw ValidationException::withMessages([
                    'quantity' => 'Adjustment cannot make stock negative.',
                ]);
            }

            $stock->update(['quantity' => $after]);

            $this->createMovement(
                $business, $branch, $product, $unit, $stock,
                'adjustment', $quantity, $baseQuantity,
                $before, $after, $note, $user
            );

            return $stock->fresh();
        });
    }

    /**
     * Transfer stock from the current branch to another branch.
     *
     * The operation is atomic: both branch balances and both ledger
     * movements are committed together or neither is committed.
     */
    public function transfer(
        Business $business,
        Branch $fromBranch,
        Branch $toBranch,
        Product $product,
        ProductUnit $unit,
        float $quantity,
        ?string $note = null,
        ?User $user = null
    ): array {
        $this->assertPositiveQuantity($quantity);

        return DB::transaction(function () use (
            $business, $fromBranch, $toBranch, $product, $unit, $quantity, $note, $user
        ) {
            $this->assertBranchOwnership($business, $fromBranch);
            $this->assertBranchOwnership($business, $toBranch);

            if ($fromBranch->id === $toBranch->id) {
                throw ValidationException::withMessages([
                    'to_branch_id' => 'The destination branch must be different from the source branch.',
                ]);
            }

            $this->assertOwnership($business, $fromBranch, $product, $unit);
            $this->assertProductOwnership($business, $product);

            $baseQuantity = $this->quantityConverter->toBaseUnits($quantity, $unit);

            /*
             * Ensure both balances exist before locking. Then acquire
             * both row locks in deterministic branch-id order so two
             * simultaneous opposite-direction transfers cannot deadlock.
             */
            $this->ensureStock($business, $fromBranch, $product);
            $this->ensureStock($business, $toBranch, $product);

            $branches = [$fromBranch, $toBranch];

            usort(
                $branches,
                fn (Branch $a, Branch $b) => strcmp($a->id, $b->id)
            );

            $lockedStocks = [];

            foreach ($branches as $branch) {
                $lockedStocks[$branch->id] = Stock::query()
                    ->where('business_id', $business->id)
                    ->where('branch_id', $branch->id)
                    ->where('product_id', $product->id)
                    ->lockForUpdate()
                    ->firstOrFail();
            }

            $source = $lockedStocks[$fromBranch->id];
            $destination = $lockedStocks[$toBranch->id];

            $sourceBefore = (float) $source->quantity;
            $sourceAfter = $sourceBefore - $baseQuantity;

            if ($sourceAfter < 0) {
                throw ValidationException::withMessages([
                    'quantity' => 'Insufficient stock in the source branch.',
                ]);
            }

            $destinationBefore = (float) $destination->quantity;
            $destinationAfter = $destinationBefore + $baseQuantity;

            $source->update(['quantity' => $sourceAfter]);
            $destination->update(['quantity' => $destinationAfter]);

            $referenceId = (string) Str::uuid();

            $this->createMovement(
                $business, $fromBranch, $product, $unit, $source,
                'transfer_out', $quantity, -$baseQuantity,
                $sourceBefore, $sourceAfter, $note, $user,
                'stock_transfer', $referenceId
            );

            $this->createMovement(
                $business, $toBranch, $product, $unit, $destination,
                'transfer_in', $quantity, $baseQuantity,
                $destinationBefore, $destinationAfter, $note, $user,
                'stock_transfer', $referenceId
            );

            return [
                'source' => $source->fresh(),
                'destination' => $destination->fresh(),
                'reference_id' => $referenceId,
            ];
        });
    }

    public function getStock(
        Business $business,
        Branch $branch,
        Product $product,
        ProductUnit $unit
    ): Stock {
        $this->assertOwnership($business, $branch, $product, $unit);

        return $this->lockOrCreateStock($business, $branch, $product);
    }

    private function ensureStock(
        Business $business,
        Branch $branch,
        Product $product
    ): Stock {
        return Stock::firstOrCreate(
            [
                'business_id' => $business->id,
                'branch_id' => $branch->id,
                'product_id' => $product->id,
            ],
            [
                'quantity' => 0,
                'reorder_level' => 0,
            ]
        );
    }

    private function lockOrCreateStock(
        Business $business,
        Branch $branch,
        Product $product
    ): Stock {
        $this->ensureStock($business, $branch, $product);

        return Stock::query()
            ->where('business_id', $business->id)
            ->where('branch_id', $branch->id)
            ->where('product_id', $product->id)
            ->lockForUpdate()
            ->firstOrFail();
    }

    private function createMovement(
        Business $business,
        Branch $branch,
        Product $product,
        ProductUnit $unit,
        Stock $stock,
        string $type,
        float $quantity,
        float $baseQuantity,
        float $quantityBefore,
        float $quantityAfter,
        ?string $note,
        ?User $user,
        ?string $referenceType = null,
        ?string $referenceId = null
    ): StockMovement {
        return StockMovement::create([
            'business_id' => $business->id,
            'branch_id' => $branch->id,
            'product_id' => $product->id,
            'product_unit_id' => $unit->id,
            'stock_id' => $stock->id,
            'type' => $type,
            'quantity' => $quantity,
            'base_quantity' => $baseQuantity,
            'quantity_before' => $quantityBefore,
            'quantity_after' => $quantityAfter,
            'reference_type' => $referenceType,
            'reference_id' => $referenceId,
            'note' => $note,
            'created_by' => $user?->id,
        ]);
    }

    private function assertPositiveQuantity(float $quantity): void
    {
        if ($quantity <= 0) {
            throw ValidationException::withMessages([
                'quantity' => 'Quantity must be greater than zero.',
            ]);
        }
    }

    private function assertBranchOwnership(Business $business, Branch $branch): void
    {
        if ($branch->business_id !== $business->id) {
            abort(403, 'Branch does not belong to this business.');
        }
    }

    private function assertProductOwnership(Business $business, Product $product): void
    {
        if ($product->business_id !== $business->id) {
            abort(403, 'Product does not belong to this business.');
        }
    }

    private function assertOwnership(
        Business $business,
        Branch $branch,
        Product $product,
        ProductUnit $unit
    ): void {
        $this->assertBranchOwnership($business, $branch);
        $this->assertProductOwnership($business, $product);

        if ($unit->business_id !== $business->id) {
            abort(403, 'Product unit does not belong to this business.');
        }

        if ($unit->product_id !== $product->id) {
            abort(403, 'Product unit does not belong to this product.');
        }
    }
}
