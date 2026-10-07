<?php

namespace App\Domains\Sales\Services;

use App\Domains\Customer\Models\Customer;
use App\Domains\Inventory\Models\Stock;
use App\Domains\Inventory\Models\StockMovement;
use App\Domains\Inventory\Services\InventoryQuantityConverter;
use App\Domains\Organization\Models\Business;
use App\Domains\Organization\Models\Branch;
use App\Domains\Product\Models\Product;
use App\Domains\Product\Models\ProductUnit;
use App\Domains\Subscription\Services\UsageService;
use App\Domains\Receipt\Services\ReceiptService;
use App\Domains\Payment\Services\PaymentService;
use App\Domains\Credit\Services\CreditService;
use App\Domains\Service\Models\Service;
use App\Domains\Sales\Models\Sale;
use App\Domains\Sales\Models\SaleItem;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SaleService
{
    private UsageService $usageService;

    private PaymentService $paymentService;

    private ReceiptService $receiptService;

    private InventoryQuantityConverter $quantityConverter;

    private CreditService $creditService;

    public function __construct(
        UsageService $usageService,
        PaymentService $paymentService,
        ReceiptService $receiptService,
        InventoryQuantityConverter $quantityConverter,
        CreditService $creditService
    ) {
        $this->usageService = $usageService;
        $this->paymentService = $paymentService;
        $this->receiptService = $receiptService;
        $this->quantityConverter = $quantityConverter;
        $this->creditService = $creditService;
    }

    /**
     * Create a sale and process all items atomically.
     *
     * A sale item can be either:
     *
     * - a physical product
     * - a service
     *
     * Product sales affect inventory.
     * Service sales do not affect inventory.
     *
     * Inventory is stored in canonical base units.
     *
     * Example:
     *
     * Product:
     *   Piece = base unit, quantity 1
     *   Pack  = quantity 12
     *
     * Selling 2 Packs means:
     *
     *   sale quantity = 2
     *   base quantity = 24
     *
     * Stock is therefore reduced by 24.
     */
    public function create(
        Business $business,
        Branch $branch,
        User $cashier,
        array $items,
        array $saleData = [],
    ): Sale {
        if ($items === []) {
            throw ValidationException::withMessages([
                'items' => 'A sale must contain at least one item.',
            ]);
        }

        return DB::transaction(function () use (
            $business,
            $branch,
            $cashier,
            $items,
            $saleData
        ): Sale {
            /*
             * Consume the business transaction usage allowance.
             */
            $this->usageService->consumeTransaction($business);

            $preparedItems = [];

            $subtotal = 0.0;

            /*
             * --------------------------------------------------------------
             * CUSTOMER
             * --------------------------------------------------------------
             *
             * Customer is optional because MerchantOS supports walk-in
             * sales.
             *
             * If a customer is supplied:
             *
             * - customer must belong to this business
             * - customer must be active
             */
            $customer = null;

            $customerId = $saleData['customer_id'] ?? null;

            if ($customerId) {
                $customer = Customer::query()
                    ->where('id', $customerId)
                    ->where('business_id', $business->id)
                    ->first();

                if (! $customer) {
                    throw ValidationException::withMessages([
                        'customer_id' =>
                        'Customer does not belong to this business.',
                    ]);
                }

                if ($customer->status !== 'active') {
                    throw ValidationException::withMessages([
                        'customer_id' =>
                        'This customer is not active.',
                    ]);
                }
            }

            /*
             * --------------------------------------------------------------
             * PREPARE ITEMS
             * --------------------------------------------------------------
             *
             * Validate every item before creating the sale or changing
             * inventory.
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

            /*
             * --------------------------------------------------------------
             * SALE TOTALS
             * --------------------------------------------------------------
             */
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
                    'discount' =>
                    'Discount cannot exceed the subtotal.',
                ]);
            }

            $total = $subtotal - $discount + $tax;


            /*
 * --------------------------------------------------------------
 * PAYMENT STATE
 * --------------------------------------------------------------
 *
 * Credit is a receivable, not an actual payment method handled
 * by PaymentService. A credit sale must always begin unpaid.
 */
            $paymentMethod = $saleData['payment_method'] ?? 'cash';
            $paymentStatus = $saleData['payment_status'] ?? 'paid';

            if ($paymentMethod === 'credit') {
                if (! $customer) {
                    throw ValidationException::withMessages([
                        'customer_id' => 'A credit sale requires a registered customer.',
                    ]);
                }

                $paymentStatus = 'unpaid';
            }

            /*
             * --------------------------------------------------------------
             * CREATE SALE
             * --------------------------------------------------------------
             */
            $sale = Sale::create([
                'business_id' => $business->id,
                'branch_id' => $branch->id,
                'cashier_id' => $cashier->id,
                'customer_id' => $customer?->id,
                'subtotal' => $subtotal,
                'discount' => $discount,
                'tax' => $tax,
                'total' => $total,
                'payment_method' => $paymentMethod,
                'payment_status' => $paymentStatus,
                'status' => $saleData['status'] ?? 'completed',
            ]);

            /*
             * --------------------------------------------------------------
             * CREATE ITEMS + INVENTORY MOVEMENTS
             * --------------------------------------------------------------
             */
            foreach ($preparedItems as $prepared) {
                $this->createItemAndMovement(
                    $sale,
                    $cashier,
                    $prepared
                );
            }

            /*
 * --------------------------------------------------------------
 * PAYMENT / CREDIT
 * --------------------------------------------------------------
 *
 * Credit is not recorded as a Payment. Instead, create a
 * receivable linked to this sale. Actual repayments will later
 * be recorded through CreditService → PaymentService.
 */
            if ($sale->status === 'completed') {
                if ($sale->payment_method === 'credit') {
                    $this->creditService->create(
                        $business,
                        $sale,
                        $saleData['due_at'] ?? null,
                    );

                    $this->receiptService->issueCredit(
                        $sale,
                        $cashier
                    );
                } else {
                    $paymentStatus = $sale->payment_status;

                    $this->paymentService->create(
                        $business,
                        $sale,
                        [
                            'amount' => $sale->total,
                            'method' => $sale->payment_method,
                            'status' => $paymentStatus,
                        ]
                    );

                    /*
         * ----------------------------------------------------------
         * RECEIPT
         * ----------------------------------------------------------
         */
                    if ($paymentStatus === 'paid') {
                        $this->receiptService->issue(
                            $sale,
                            $cashier
                        );
                    }
                }
            }

            /*
             * Return the complete sale with its relationships.
             */
            return $sale->fresh([
                'items.product',
                'items.productUnit',
                'items.service',
                'cashier',
                'customer',
            ]);
        });
    }

    /**
     * Validate and prepare a single sale item.
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
         * An item cannot be both a product and service.
         *
         * It also cannot be neither.
         */
        if (
            ($productId && $serviceId) ||
            (! $productId && ! $serviceId)
        ) {
            throw ValidationException::withMessages([
                "items.$index" =>
                'A sale item must contain either a product or a service.',
            ]);
        }

        /*
         * Quantity is required for both products and services.
         */
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
         * --------------------------------------------------------------
         * SERVICE
         * --------------------------------------------------------------
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
             * Use supplied price when explicitly provided.
             * Otherwise use catalog service price.
             */
            $unitPrice = $this->money(
                $item['unit_price'] ?? $service->price
            );

            /*
             * Services currently have no inventory cost.
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

            $lineTotal = $lineSubtotal - $discount;

            return [
                'type' => 'service',

                'product' => null,

                'unit' => null,

                'stock' => null,

                'service' => $service,

                'quantity' => $quantity,

                /*
                 * Services do not have inventory conversion.
                 */
                'base_quantity' => null,

                'unit_price' => $unitPrice,

                'unit_cost' => $unitCost,

                'discount' => $discount,

                'total' => $lineTotal,
            ];
        }

        /*
         * --------------------------------------------------------------
         * PRODUCT
         * --------------------------------------------------------------
         *
         * Product sales require a specific product unit.
         *
         * Example:
         *
         * product = Coca Cola
         * unit    = Pack
         * quantity = 2
         *
         * If Pack.quantity = 12:
         *
         * base quantity = 2 × 12 = 24
         */
        $unitId = $item['product_unit_id'] ?? null;

        if (! $unitId) {
            throw ValidationException::withMessages([
                "items.$index.product_unit_id" =>
                'Product unit is required.',
            ]);
        }

        /*
         * Find the product within the current business.
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
         * Find the selected unit.
         *
         * The unit must belong to:
         *
         * - the current business
         * - the selected product
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
         * --------------------------------------------------------------
         * CONVERT SALE QUANTITY TO BASE UNITS
         * --------------------------------------------------------------
         *
         * This is the important inventory change.
         *
         * We no longer store stock separately for each ProductUnit.
         *
         * Stock stores one canonical quantity per product.
         *
         * Example:
         *
         * Piece:
         *   quantity = 1
         *
         * Pack:
         *   quantity = 12
         *
         * Sale:
         *   2 Packs
         *
         * Base quantity:
         *   2 × 12 = 24
         */
        $baseQuantity = $this->quantityConverter->toBaseUnits(
            $quantity,
            $unit
        );

        /*
         * --------------------------------------------------------------
         * STOCK
         * --------------------------------------------------------------
         *
         * IMPORTANT:
         *
         * Do NOT query:
         *
         * ->where('product_unit_id', ...)
         *
         * because stocks no longer have product_unit_id.
         *
         * There is one stock record per business + product.
         */
        $stock = Stock::query()
            ->where('business_id', $business->id)
            ->where('product_id', $product->id)
            ->lockForUpdate()
            ->first();

        if (! $stock) {
            throw ValidationException::withMessages([
                "items.$index.quantity" =>
                'No inventory record exists for this product.',
            ]);
        }

        /*
         * Stock quantity is stored in canonical base units.
         */
        $available = (float) $stock->quantity;

        if ($baseQuantity > $available) {
            throw ValidationException::withMessages([
                "items.$index.quantity" =>
                "Insufficient stock. Available base quantity: {$available}.",
            ]);
        }

        /*
         * Price comes from the selected ProductUnit unless the
         * client explicitly supplies a selling price.
         */
        $unitPrice = $this->money(
            $item['unit_price'] ?? $unit->selling_price
        );

        /*
         * Cost always comes from the catalog unit.
         *
         * We intentionally ignore client-supplied unit_cost for
         * physical products.
         */
        $unitCost = $this->money(
            $unit->cost_price
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

        /*
         * Calculate the commercial sale total.
         *
         * quantity remains in the selected selling unit.
         */
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

            /*
             * Commercial quantity.
             *
             * Example:
             * 2 Packs
             */
            'quantity' => $quantity,

            /*
             * Inventory quantity.
             *
             * Example:
             * 24 Pieces
             */
            'base_quantity' => $baseQuantity,

            'unit_price' => $unitPrice,

            'unit_cost' => $unitCost,

            'discount' => $discount,

            'total' => $lineTotal,
        ];
    }

    /**
     * Create SaleItem and, for products, update stock and create
     * a stock movement.
     */
    private function createItemAndMovement(
        Sale $sale,
        User $cashier,
        array $prepared
    ): SaleItem {
        /*
         * --------------------------------------------------------------
         * SERVICE
         * --------------------------------------------------------------
         *
         * Services do not affect inventory.
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
         * --------------------------------------------------------------
         * PRODUCT
         * --------------------------------------------------------------
         */

        /** @var Stock $stock */
        $stock = $prepared['stock'];

        /*
         * Commercial quantity.
         *
         * Example:
         * 2 Packs
         */
        $quantity = $prepared['quantity'];

        /*
         * Canonical inventory quantity.
         *
         * Example:
         * 24 Pieces
         */
        $baseQuantity = $prepared['base_quantity'];

        /*
         * Stock is always stored in base units.
         */
        $before = (float) $stock->quantity;

        $after = $before - $baseQuantity;

        /*
         * Defensive check.
         *
         * prepareItem() already checked this, but keeping this
         * check here protects the actual mutation.
         */
        if ($after < 0) {
            throw ValidationException::withMessages([
                'items' => 'Sale would make stock negative.',
            ]);
        }

        /*
         * --------------------------------------------------------------
         * SALE ITEM
         * --------------------------------------------------------------
         *
         * SaleItem preserves the unit in which the customer bought
         * the product.
         */
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

        /*
         * --------------------------------------------------------------
         * UPDATE STOCK
         * --------------------------------------------------------------
         *
         * Stock quantity is canonical base quantity.
         */
        $stock->update([
            'quantity' => $after,
        ]);

        /*
         * --------------------------------------------------------------
         * STOCK MOVEMENT
         * --------------------------------------------------------------
         *
         * Keep both:
         *
         * quantity:
         *   what was commercially sold
         *
         * base_quantity:
         *   actual inventory impact
         *
         * Example:
         *
         * quantity      = -2 Packs
         * base_quantity = -24 Pieces
         */
        StockMovement::create([
            'business_id' => $sale->business_id,

            'product_id' => $prepared['product']->id,

            'product_unit_id' => $prepared['unit']->id,

            'stock_id' => $stock->id,

            'type' => 'sale',

            'quantity' => -$quantity,

            'base_quantity' => -$baseQuantity,

            'quantity_before' => $before,

            'quantity_after' => $after,

            'reference_type' => Sale::class,

            'reference_id' => $sale->id,

            'note' => 'Sale',

            'created_by' => $cashier->id,
        ]);

        return $item;
    }

    /**
     * Convert a value to a positive decimal quantity.
     */
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

    /**
     * Convert a value to money with two decimal places.
     */
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
