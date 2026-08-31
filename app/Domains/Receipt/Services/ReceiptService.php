<?php

namespace App\Domains\Receipt\Services;

use App\Domains\Customer\Models\Customer;
use App\Domains\Organization\Models\Business;
use App\Domains\Receipt\Models\Receipt;
use App\Domains\Sales\Models\Sale;
use App\Domains\Sales\Models\SaleItem;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ReceiptService
{
    public function __construct(
        private ReceiptNumberGenerator $numberGenerator
    ) {
    }

    /**
     * Issue an immutable receipt for a completed, paid sale.
     *
     * The receipt contains a historical snapshot of the
     * customer-facing transaction.
     */
    public function issue(
        Sale $sale,
        User $issuedBy
    ): Receipt {
        return DB::transaction(function () use (
            $sale,
            $issuedBy
        ): Receipt {
            /*
             * Reload the sale inside the transaction.
             *
             * This prevents us from relying on a stale model
             * instance supplied by a controller or another service.
             */
            $sale = Sale::query()
                ->whereKey($sale->id)
                ->lockForUpdate()
                ->first();

            if (! $sale) {
                throw ValidationException::withMessages([
                    'sale' => 'The sale could not be found.',
                ]);
            }

            /*
             * A receipt must belong to a real business.
             */
            $business = Business::query()
                ->whereKey($sale->business_id)
                ->first();

            if (! $business) {
                throw ValidationException::withMessages([
                    'business' => 'The sale business could not be found.',
                ]);
            }

            /*
             * The issuing user must be associated with the sale's
             * business.
             *
             * This protects the service even if it is called outside
             * the HTTP middleware layer.
             */
            $isMember = $business->memberships()
                ->where('user_id', $issuedBy->id)
                ->where('status', 'active')
                ->exists();

            if (! $isMember) {
                throw ValidationException::withMessages([
                    'issued_by' =>
                        'The issuing user does not belong to this business.',
                ]);
            }

            /*
             * Only completed sales receive customer receipts.
             */
            if ($sale->status !== 'completed') {
                throw ValidationException::withMessages([
                    'sale' =>
                        'A receipt can only be issued for a completed sale.',
                ]);
            }

            /*
             * Only paid sales receive a normal completed receipt.
             */
            if ($sale->payment_status !== 'paid') {
                throw ValidationException::withMessages([
                    'payment_status' =>
                        'A receipt can only be issued for a paid sale.',
                ]);
            }

            /*
             * The database already enforces one receipt per sale.
             *
             * We check here first so callers receive a meaningful
             * validation error instead of a raw database exception.
             */
            $existingReceipt = Receipt::query()
                ->where('sale_id', $sale->id)
                ->lockForUpdate()
                ->first();

            if ($existingReceipt) {
                throw ValidationException::withMessages([
                    'sale' =>
                        'A receipt has already been issued for this sale.',
                ]);
            }

            /*
             * Load all relationships required by the snapshot.
             *
             * We deliberately load these before constructing the
             * historical document.
             */
            $sale->load([
                'items.product',
                'items.productUnit',
                'items.service',
                'customer',
                'cashier',
                'payments',
            ]);

            /*
             * Generate the business-scoped receipt number.
             */
            $receiptNumber = $this->numberGenerator->next(
                $business
            );

            /*
             * Build the immutable customer-facing snapshot.
             */
            $snapshot = $this->buildSnapshot(
                $business,
                $sale,
                $receiptNumber
            );

            /*
             * Create the permanent receipt record.
             */
            return Receipt::create([
                'business_id' => $business->id,
                'sale_id' => $sale->id,
                'receipt_number' => $receiptNumber,
                'status' => 'issued',
                'issued_by' => $issuedBy->id,
                'issued_at' => now(),
                'snapshot' => $snapshot,
            ]);
        });
    }

    /**
     * Build the historical customer-facing representation
     * of a sale.
     *
     * Internal accounting information such as unit_cost is
     * intentionally excluded.
     */
    private function buildSnapshot(
        Business $business,
        Sale $sale,
        string $receiptNumber
    ): array {
        return [
            'version' => 1,

            'business' => [
                'id' => $business->id,
                'name' => $business->name,
                'email' => $business->email,
                'phone' => $business->phone,
                'website' => $business->website,
                'registration_number' =>
                    $business->registration_number,
                'tax_number' => $business->tax_number,
                'logo' => $business->logo,
                'currency' => $business->currency,
                'timezone' => $business->timezone,
                'country' => $business->default_country,
            ],

            'receipt' => [
                'number' => $receiptNumber,
                'status' => 'issued',
                'issued_at' => now()->toISOString(),
            ],

            'sale' => [
                'id' => $sale->id,
                'subtotal' => $this->money($sale->subtotal),
                'discount' => $this->money($sale->discount),
                'tax' => $this->money($sale->tax),
                'total' => $this->money($sale->total),
                'payment_method' => $sale->payment_method,
                'payment_status' => $sale->payment_status,
                'status' => $sale->status,
            ],

            'customer' => $this->customerSnapshot(
                $sale->customer
            ),

            'cashier' => $this->cashierSnapshot(
                $sale->cashier
            ),

            'items' => $sale->items
                ->map(
                    fn (SaleItem $item) =>
                        $this->itemSnapshot($item)
                )
                ->values()
                ->all(),

            'payments' => $sale->payments
                ->map(
                    fn ($payment) => [
                        'id' => $payment->id,
                        'amount' => $this->money(
                            $payment->amount
                        ),
                        'method' => $payment->method,
                        'status' => $payment->status,
                        'reference' => $payment->reference,
                        'paid_at' => $payment->paid_at
                            ? $payment->paid_at->toISOString()
                            : null,
                    ]
                )
                ->values()
                ->all(),
        ];
    }

    /**
     * Build customer snapshot.
     */
    private function customerSnapshot(
        ?Customer $customer
    ): ?array {
        if (! $customer) {
            return null;
        }

        return [
            'id' => $customer->id,
            'customer_number' => $customer->customer_number,
            'name' => $customer->name,
            'phone' => $customer->phone,
        ];
    }

    /**
     * Build cashier snapshot.
     */
    private function cashierSnapshot(
        ?User $cashier
    ): ?array {
        if (! $cashier) {
            return null;
        }

        return [
            'id' => $cashier->id,
            'name' => $cashier->name,
        ];
    }

    /**
     * Build a customer-facing sale item snapshot.
     */
    private function itemSnapshot(
        SaleItem $item
    ): array {
        $isService = $item->service_id !== null;

        $name = $isService
            ? $item->service?->name
            : $item->product?->name;

        return [
            'id' => $item->id,

            'type' => $isService
                ? 'service'
                : 'product',

            'name' => $name,

            'quantity' => $this->decimal(
                $item->quantity
            ),

            'unit_price' => $this->money(
                $item->unit_price
            ),

            'discount' => $this->money(
                $item->discount
            ),

            'total' => $this->money(
                $item->total
            ),
        ];
    }

    /**
     * Format monetary values consistently.
     */
    private function money(
        mixed $value
    ): string {
        return number_format(
            (float) $value,
            2,
            '.',
            ''
        );
    }

    /**
     * Format quantities without destroying precision.
     */
    private function decimal(
        mixed $value
    ): string {
        return number_format(
            (float) $value,
            4,
            '.',
            ''
        );
    }
}
