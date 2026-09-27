<?php

namespace App\Domains\Inventory\Services;

use App\Domains\Inventory\Models\Stock;
use App\Domains\Inventory\Models\StockMovement;
use App\Domains\Organization\Models\Business;
use App\Domains\Product\Models\Product;
use App\Domains\Product\Models\ProductUnit;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class StockService
{
    public function __construct(
        private readonly InventoryQuantityConverter $quantityConverter
    ) {}

    /**
     * Create an empty stock record for a product.
     *
     * Inventory is stored in the product's canonical
     * base unit.
     *
     * This does not create a stock movement.
     */
    public function createStock(
        Business $business,
        Product $product
    ): Stock {
        $this->assertProductOwnership(
            $business,
            $product
        );

        return Stock::create([
            'business_id' => $business->id,
            'product_id' => $product->id,
            'quantity' => 0,
            'reorder_level' => 0,
        ]);
    }

    /**
     * Receive stock into inventory.
     *
     * The supplied quantity is expressed in the selected
     * transaction unit and converted to base units before
     * changing the stock balance.
     */
    public function receive(
        Business $business,
        Product $product,
        ProductUnit $unit,
        float $quantity,
        ?string $note = null,
        ?User $user = null
    ): Stock {
        $this->assertPositiveQuantity($quantity);

        return DB::transaction(function () use (
            $business,
            $product,
            $unit,
            $quantity,
            $note,
            $user
        ) {
            $this->assertOwnership(
                $business,
                $product,
                $unit
            );

            $baseQuantity = $this->quantityConverter->toBaseUnits(
                $quantity,
                $unit
            );

            $stock = $this->getOrCreateStock(
                $business,
                $product
            );

            $stock = Stock::query()
                ->whereKey($stock->id)
                ->lockForUpdate()
                ->firstOrFail();

            $before = (float) $stock->quantity;
            $after = $before + $baseQuantity;

            $stock->update([
                'quantity' => $after,
            ]);

            $this->createMovement(
                business: $business,
                product: $product,
                unit: $unit,
                stock: $stock,
                type: 'receive',
                quantity: $quantity,
                baseQuantity: $baseQuantity,
                quantityBefore: $before,
                quantityAfter: $after,
                note: $note,
                user: $user
            );

            return $stock->fresh();
        });
    }

    /**
     * Issue stock from inventory.
     *
     * The supplied quantity is expressed in the selected
     * transaction unit and converted to base units.
     */
    public function issue(
        Business $business,
        Product $product,
        ProductUnit $unit,
        float $quantity,
        ?string $note = null,
        ?User $user = null
    ): Stock {
        $this->assertPositiveQuantity($quantity);

        return DB::transaction(function () use (
            $business,
            $product,
            $unit,
            $quantity,
            $note,
            $user
        ) {
            $this->assertOwnership(
                $business,
                $product,
                $unit
            );

            $baseQuantity = $this->quantityConverter->toBaseUnits(
                $quantity,
                $unit
            );

            $stock = $this->getOrCreateStock(
                $business,
                $product
            );

            $stock = Stock::query()
                ->whereKey($stock->id)
                ->lockForUpdate()
                ->firstOrFail();

            $before = (float) $stock->quantity;
            $after = $before - $baseQuantity;

            if ($after < 0) {
                throw ValidationException::withMessages([
                    'quantity' => 'Insufficient stock.',
                ]);
            }

            $stock->update([
                'quantity' => $after,
            ]);

            $this->createMovement(
                business: $business,
                product: $product,
                unit: $unit,
                stock: $stock,
                type: 'sale',
                quantity: $quantity,
                baseQuantity: -$baseQuantity,
                quantityBefore: $before,
                quantityAfter: $after,
                note: $note,
                user: $user
            );

            return $stock->fresh();
        });
    }

    /**
     * Manually adjust stock.
     *
     * Positive quantity increases inventory.
     * Negative quantity decreases inventory.
     *
     * The supplied quantity is expressed in the selected
     * transaction unit.
     */
    public function adjust(
        Business $business,
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

        return DB::transaction(function () use (
            $business,
            $product,
            $unit,
            $quantity,
            $note,
            $user
        ) {
            $this->assertOwnership(
                $business,
                $product,
                $unit
            );

            $absoluteQuantity = abs($quantity);

            $absoluteBaseQuantity = $this->quantityConverter
                ->toBaseUnits(
                    $absoluteQuantity,
                    $unit
                );

            $baseQuantity = $quantity > 0
                ? $absoluteBaseQuantity
                : -$absoluteBaseQuantity;

            $stock = $this->getOrCreateStock(
                $business,
                $product
            );

            $stock = Stock::query()
                ->whereKey($stock->id)
                ->lockForUpdate()
                ->firstOrFail();

            $before = (float) $stock->quantity;
            $after = $before + $baseQuantity;

            if ($after < 0) {
                throw ValidationException::withMessages([
                    'quantity' => 'Adjustment cannot make stock negative.',
                ]);
            }

            $stock->update([
                'quantity' => $after,
            ]);

            $this->createMovement(
                business: $business,
                product: $product,
                unit: $unit,
                stock: $stock,
                type: 'adjustment',
                quantity: $quantity,
                baseQuantity: $baseQuantity,
                quantityBefore: $before,
                quantityAfter: $after,
                note: $note,
                user: $user
            );

            return $stock->fresh();
        });
    }

    /**
     * Get the stock record for a product.
     *
     * The unit is only used to validate that the caller
     * supplied a unit belonging to this product.
     */
    public function getStock(
        Business $business,
        Product $product,
        ProductUnit $unit
    ): Stock {
        $this->assertOwnership(
            $business,
            $product,
            $unit
        );

        return $this->getOrCreateStock(
            $business,
            $product
        );
    }

    /**
     * Get or create the canonical stock record for a product.
     *
     * There is exactly one stock balance per product per business.
     */
    private function getOrCreateStock(
        Business $business,
        Product $product
    ): Stock {
        return Stock::query()->firstOrCreate(
            [
                'business_id' => $business->id,
                'product_id' => $product->id,
            ],
            [
                'quantity' => 0,
                'reorder_level' => 0,
            ]
        );
    }

    /**
     * Create an immutable stock movement record.
     *
     * quantity:
     *     Quantity expressed in the transaction unit.
     *
     * base_quantity:
     *     Actual inventory effect in canonical base units.
     */
    private function createMovement(
        Business $business,
        Product $product,
        ProductUnit $unit,
        Stock $stock,
        string $type,
        float $quantity,
        float $baseQuantity,
        float $quantityBefore,
        float $quantityAfter,
        ?string $note,
        ?User $user
    ): StockMovement {
        return StockMovement::create([
            'business_id' => $business->id,
            'product_id' => $product->id,
            'product_unit_id' => $unit->id,
            'stock_id' => $stock->id,
            'type' => $type,
            'quantity' => $quantity,
            'base_quantity' => $baseQuantity,
            'quantity_before' => $quantityBefore,
            'quantity_after' => $quantityAfter,
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

    private function assertProductOwnership(
        Business $business,
        Product $product
    ): void {
        if ($product->business_id !== $business->id) {
            abort(
                403,
                'Product does not belong to this business.'
            );
        }
    }

    private function assertOwnership(
        Business $business,
        Product $product,
        ProductUnit $unit
    ): void {
        $this->assertProductOwnership(
            $business,
            $product
        );

        if ($unit->business_id !== $business->id) {
            abort(
                403,
                'Product unit does not belong to this business.'
            );
        }

        if ($unit->product_id !== $product->id) {
            abort(
                403,
                'Product unit does not belong to this product.'
            );
        }
    }
}