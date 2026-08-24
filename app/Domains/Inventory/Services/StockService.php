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
    /**
     * Create an empty stock record for a product unit.
     *
     * This does not create a stock movement.
     */
    public function createStock(
        Business $business,
        Product $product,
        ProductUnit $unit
    ): Stock {
        $this->assertOwnership(
            $business,
            $product,
            $unit
        );

        return Stock::create([
            'business_id' => $business->id,
            'product_id' => $product->id,
            'product_unit_id' => $unit->id,
            'quantity' => 0,
            'reorder_level' => 0,
        ]);
    }

    /**
     * Receive stock into inventory.
     *
     * Example:
     *
     * 0 -> 50
     *
     * Creates a "receive" stock movement.
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

            $stock = $this->getOrCreateStock(
                $business,
                $product,
                $unit
            );

            /*
             * Lock the stock row so concurrent inventory
             * operations cannot overwrite each other's values.
             */
            $stock = Stock::query()
                ->whereKey($stock->id)
                ->lockForUpdate()
                ->firstOrFail();

            $before = (float) $stock->quantity;
            $after = $before + $quantity;

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
     * Example:
     *
     * 50 -> 47
     *
     * The movement is recorded as "sale".
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

            $stock = $this->getOrCreateStock(
                $business,
                $product,
                $unit
            );

            /*
             * Lock the stock row before calculating the new
             * quantity.
             */
            $stock = Stock::query()
                ->whereKey($stock->id)
                ->lockForUpdate()
                ->firstOrFail();

            $before = (float) $stock->quantity;
            $after = $before - $quantity;

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
     * Positive quantity increases stock.
     * Negative quantity decreases stock.
     *
     * Examples:
     *
     * +10:
     * 50 -> 60
     *
     * -3:
     * 50 -> 47
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

            $stock = $this->getOrCreateStock(
                $business,
                $product,
                $unit
            );

            /*
             * Lock the stock row before calculating the
             * adjustment.
             */
            $stock = Stock::query()
                ->whereKey($stock->id)
                ->lockForUpdate()
                ->firstOrFail();

            $before = (float) $stock->quantity;
            $after = $before + $quantity;

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
                quantityBefore: $before,
                quantityAfter: $after,
                note: $note,
                user: $user
            );

            return $stock->fresh();
        });
    }

    /**
     * Get the stock record for a product unit.
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
            $product,
            $unit
        );
    }

    /**
     * Get or create the stock record for a product unit.
     *
     * This method intentionally does not create a movement.
     */
    private function getOrCreateStock(
        Business $business,
        Product $product,
        ProductUnit $unit
    ): Stock {
        return Stock::query()->firstOrCreate(
            [
                'business_id' => $business->id,
                'product_id' => $product->id,
                'product_unit_id' => $unit->id,
            ],
            [
                'quantity' => 0,
                'reorder_level' => 0,
            ]
        );
    }

    /**
     * Create an immutable stock movement record.
     */
    private function createMovement(
        Business $business,
        Product $product,
        ProductUnit $unit,
        Stock $stock,
        string $type,
        float $quantity,
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
            'quantity_before' => $quantityBefore,
            'quantity_after' => $quantityAfter,
            'note' => $note,
            'created_by' => $user?->id,
        ]);
    }

    /**
     * Make sure a quantity used for receiving or issuing
     * stock is greater than zero.
     */
    private function assertPositiveQuantity(float $quantity): void
    {
        if ($quantity <= 0) {
            throw ValidationException::withMessages([
                'quantity' => 'Quantity must be greater than zero.',
            ]);
        }
    }

    /**
     * Enforce tenant and product/unit ownership.
     *
     * A product, product unit and stock operation must all
     * belong to the same business.
     */
    private function assertOwnership(
        Business $business,
        Product $product,
        ProductUnit $unit
    ): void {
        if ($product->business_id !== $business->id) {
            abort(
                403,
                'Product does not belong to this business.'
            );
        }

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