<?php

namespace App\Domains\Sales\Services;

use App\Domains\Inventory\Models\Stock;
use App\Domains\Inventory\Models\StockMovement;
use App\Domains\Organization\Models\Business;
use App\Domains\Product\Models\Product;
use App\Domains\Product\Models\ProductUnit;
use App\Domains\Service\Models\Service;
use App\Domains\Sales\Models\Sale;
use App\Domains\Sales\Models\SaleItem;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SaleService
{
    /**
     * Create a completed sale and process all items atomically.
     *
     * A sale item may be either:
     *
     * - a physical product
     * - a service
     *
     * Product sales deduct inventory.
     * Service sales do not affect inventory.
     *
     * @param Business $business
     * @param User $cashier
     * @param array<int, array<string, mixed>> $items
     * @param array<string, mixed> $saleData
     */
    public function create(
        Business $business,
        User $cashier,
        array $items,
        array $saleData = []
    ): Sale {
        if ($items === []) {
            throw ValidationException::withMessages([
                'items' => 'A sale must contain at least one item.',
            ]);
        }

        return DB::transaction(function () use (
            $business,
            $cashier,
            $items,
            $saleData
        ): Sale {
            $preparedItems = [];
            $subtotal = 0.0;

            /*
             * Validate and prepare every item before
             * changing inventory or creating the sale.
             */
            foreach ($items as $index => $item) {
                $prepared = $this->prepareItem(
                    $business,
                    $item,
                    $index
                );

                $preparedItems[] = $prepared;

                $subtotal += $prepared['total'];
            }

            $discount = $this->money(
                $saleData['discount'] ?? 0
            );

            $tax = $this->money(
                $saleData['tax'] ?? 0
            );

            if ($discount < 0) {
                throw ValidationException::withMessages([
                    'discount' => 'Discount cannot be negative.',
                ]);
            }

            if ($tax < 0) {
                throw ValidationException::withMessages([
                    'tax' => 'Tax cannot be negative.',
                ]);
            }

            if ($discount > $subtotal) {
                throw ValidationException::withMessages([
                    'discount' => 'Discount cannot exceed the subtotal.',
                ]);
            }

            $total = $subtotal - $discount + $tax;

            $sale = Sale::create([
                'business_id' => $business->id,
                'cashier_id' => $cashier->id,
                'customer_id' => $saleData['customer_id'] ?? null,
                'subtotal' => $subtotal,
                'discount' => $discount,
                'tax' => $tax,
                'total' => $total,
                'payment_method' => $saleData['payment_method'] ?? 'cash',
                'payment_status' => $saleData['payment_status'] ?? 'paid',
                'status' => $saleData['status'] ?? 'completed',
            ]);

            foreach ($preparedItems as $prepared) {
                $this->createItemAndMovement(
                    $sale,
                    $cashier,
                    $prepared
                );
            }

            return $sale->fresh([
                'items.product',
                'items.productUnit',
                'items.service',
                'cashier',
            ]);
        });
    }

    /**
     * Validate and prepare one sale item.
     *
     * A sale item must contain exactly one of:
     *
     * - product_id
     * - service_id
     */
    private function prepareItem(
        Business $business,
        array $item,
        int $index
    ): array {
        $productId = $item['product_id'] ?? null;
        $serviceId = $item['service_id'] ?? null;

        /*
         * A sale item cannot represent both a product
         * and a service, and cannot represent neither.
         */
        if (
            ($productId && $serviceId) ||
            (!$productId && !$serviceId)
        ) {
            throw ValidationException::withMessages([
                "items.$index" =>
                    'A sale item must contain either a product or a service.',
            ]);
        }

        $quantity = $this->decimal(
            $item['quantity'] ?? null
        );

        if ($quantity === null || $quantity <= 0) {
            throw ValidationException::withMessages([
                "items.$index.quantity" =>
                    'Quantity must be greater than zero.',
            ]);
        }

        /*
         |--------------------------------------------------------------------------
         | SERVICE SALE
         |--------------------------------------------------------------------------
         */

        if ($serviceId) {
            $service = Service::query()
                ->where('id', $serviceId)
                ->where('business_id', $business->id)
                ->lockForUpdate()
                ->first();

            if (! $service) {
                throw ValidationException::withMessages([
                    "items.$index.service_id" =>
                        'Service does not belong to this business.',
                ]);
            }

            if (! $service->is_active) {
                throw ValidationException::withMessages([
                    "items.$index.service_id" =>
                        'This service is not active.',
                ]);
            }

            /*
             * Snapshot the current service price.
             */
            $unitPrice = $this->money(
                $item['unit_price'] ?? $service->price
            );

            /*
             * Services currently have no inventory cost.
             * We still store unit_cost so historical
             * profitability remains possible later.
             */
            $unitCost = $this->money(
                $item['unit_cost'] ?? 0
            );

            $discount = $this->money(
                $item['discount'] ?? 0
            );

            if ($unitPrice < 0) {
                throw ValidationException::withMessages([
                    "items.$index.unit_price" =>
                        'Unit price cannot be negative.',
                ]);
            }

            if ($unitCost < 0) {
                throw ValidationException::withMessages([
                    "items.$index.unit_cost" =>
                        'Unit cost cannot be negative.',
                ]);
            }

            if ($discount < 0) {
                throw ValidationException::withMessages([
                    "items.$index.discount" =>
                        'Item discount cannot be negative.',
                ]);
            }

            $lineSubtotal = $quantity * $unitPrice;

            if ($discount > $lineSubtotal) {
                throw ValidationException::withMessages([
                    "items.$index.discount" =>
                        'Item discount cannot exceed the item subtotal.',
                ]);
            }

            return [
                'type' => 'service',
                'product' => null,
                'unit' => null,
                'stock' => null,
                'service' => $service,
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
                'unit_cost' => $unitCost,
                'discount' => $discount,
                'total' => $lineSubtotal - $discount,
            ];
        }

        /*
         |--------------------------------------------------------------------------
         | PRODUCT SALE
         |--------------------------------------------------------------------------
         */

        $unitId = $item['product_unit_id'] ?? null;

        if (! $unitId) {
            throw ValidationException::withMessages([
                "items.$index.product_unit_id" =>
                    'Product unit is required.',
            ]);
        }

        /*
         * Lock the product to prevent concurrent modifications.
         */
        $product = Product::query()
            ->where('id', $productId)
            ->where('business_id', $business->id)
            ->lockForUpdate()
            ->first();

        if (! $product) {
            throw ValidationException::withMessages([
                "items.$index.product_id" =>
                    'Product does not belong to this business.',
            ]);
        }

        /*
         * The unit must belong to both the business
         * and selected product.
         */
        $unit = ProductUnit::query()
            ->where('id', $unitId)
            ->where('business_id', $business->id)
            ->where('product_id', $product->id)
            ->lockForUpdate()
            ->first();

        if (! $unit) {
            throw ValidationException::withMessages([
                "items.$index.product_unit_id" =>
                    'Product unit does not belong to the selected product.',
            ]);
        }

        if (! $unit->is_sellable) {
            throw ValidationException::withMessages([
                "items.$index.product_unit_id" =>
                    'This product unit is not sellable.',
            ]);
        }

        /*
         * Lock stock so concurrent sales cannot consume
         * the same inventory.
         */
        $stock = Stock::query()
            ->where('business_id', $business->id)
            ->where('product_id', $product->id)
            ->where('product_unit_id', $unit->id)
            ->lockForUpdate()
            ->first();

        if (! $stock) {
            throw ValidationException::withMessages([
                "items.$index.quantity" =>
                    'No inventory record exists for this product unit.',
            ]);
        }

        $available = (float) $stock->quantity;

        if ($quantity > $available) {
            throw ValidationException::withMessages([
                "items.$index.quantity" =>
                    "Insufficient stock. Available quantity: {$available}.",
            ]);
        }

        $unitPrice = $this->money(
            $item['unit_price'] ?? $unit->selling_price
        );

        $unitCost = $this->money(
            $item['unit_cost'] ?? $unit->cost_price
        );

        $discount = $this->money(
            $item['discount'] ?? 0
        );

        if ($unitPrice < 0) {
            throw ValidationException::withMessages([
                "items.$index.unit_price" =>
                    'Unit price cannot be negative.',
            ]);
        }

        if ($unitCost < 0) {
            throw ValidationException::withMessages([
                "items.$index.unit_cost" =>
                    'Unit cost cannot be negative.',
            ]);
        }

        if ($discount < 0) {
            throw ValidationException::withMessages([
                "items.$index.discount" =>
                    'Item discount cannot be negative.',
            ]);
        }

        $lineSubtotal = $quantity * $unitPrice;

        if ($discount > $lineSubtotal) {
            throw ValidationException::withMessages([
                "items.$index.discount" =>
                    'Item discount cannot exceed the item subtotal.',
            ]);
        }

        $lineTotal = $lineSubtotal - $discount;

        return [
            'type' => 'product',
            'product' => $product,
            'unit' => $unit,
            'stock' => $stock,
            'service' => null,
            'quantity' => $quantity,
            'unit_price' => $unitPrice,
            'unit_cost' => $unitCost,
            'discount' => $discount,
            'total' => $lineTotal,
        ];
    }

    /**
     * Create the SaleItem.
     *
     * Product:
     * - creates SaleItem
     * - deducts stock
     * - creates stock movement
     *
     * Service:
     * - creates SaleItem
     * - does NOT touch inventory
     */
    private function createItemAndMovement(
        Sale $sale,
        User $cashier,
        array $prepared
    ): SaleItem {
        /*
         |--------------------------------------------------------------------------
         | SERVICE
         |--------------------------------------------------------------------------
         */

        if ($prepared['type'] === 'service') {
            return SaleItem::create([
                'sale_id' => $sale->id,
                'product_id' => null,
                'product_unit_id' => null,
                'service_id' => $prepared['service']->id,
                'quantity' => $prepared['quantity'],
                'unit_price' => $prepared['unit_price'],
                'unit_cost' => $prepared['unit_cost'],
                'discount' => $prepared['discount'],
                'total' => $prepared['total'],
            ]);
        }

        /*
         |--------------------------------------------------------------------------
         | PRODUCT
         |--------------------------------------------------------------------------
         */

        /** @var Stock $stock */
        $stock = $prepared['stock'];

        $quantity = $prepared['quantity'];

        $before = (float) $stock->quantity;
        $after = $before - $quantity;

        if ($after < 0) {
            throw ValidationException::withMessages([
                'items' => 'Sale would make stock negative.',
            ]);
        }

        $item = SaleItem::create([
            'sale_id' => $sale->id,
            'product_id' => $prepared['product']->id,
            'product_unit_id' => $prepared['unit']->id,
            'service_id' => null,
            'quantity' => $quantity,
            'unit_price' => $prepared['unit_price'],
            'unit_cost' => $prepared['unit_cost'],
            'discount' => $prepared['discount'],
            'total' => $prepared['total'],
        ]);

        $stock->update([
            'quantity' => $after,
        ]);

        StockMovement::create([
            'business_id' => $sale->business_id,
            'product_id' => $prepared['product']->id,
            'product_unit_id' => $prepared['unit']->id,
            'stock_id' => $stock->id,
            'type' => 'sale',
            'quantity' => -$quantity,
            'quantity_before' => $before,
            'quantity_after' => $after,
            'reference_type' => Sale::class,
            'reference_id' => $sale->id,
            'note' => 'Sale',
            'created_by' => $cashier->id,
        ]);

        return $item;
    }

    private function decimal(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (! is_numeric($value)) {
            return null;
        }

        return (float) $value;
    }

    private function money(mixed $value): float
    {
        if ($value === null || $value === '') {
            return 0.0;
        }

        if (! is_numeric($value)) {
            return 0.0;
        }

        return round((float) $value, 2);
    }
}