<?php

namespace App\Domains\Payment\Services;

use App\Domains\Organization\Models\Business;
use App\Domains\Payment\Models\Payment;
use App\Domains\Sales\Models\Sale;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class PaymentService
{
    /**
     * Supported payment methods within MerchantOS.
     */
    private const PAYMENT_METHODS = [
        'cash',
        'bank_transfer',
        'card',
        'mobile_money',
        'other',
    ];

    /**
     * Create a payment for a sale.
     *
     * The business supplied here is the current business context.
     */
    public function create(
        Business $business,
        Sale $sale,
        array $data
    ): Payment {
        /*
         * -------------------------------------------------------------
         * Validate the payment input.
         * -------------------------------------------------------------
         */
        $validator = Validator::make(
            $data,
            [
                'amount' => [
                    'required',
                    'numeric',
                    'gt:0',
                ],

                'method' => [
                    'required',
                    'string',
                    'in:' . implode(',', self::PAYMENT_METHODS),
                ],

                'status' => [
                    'nullable',
                    'string',
                    'in:pending,paid,failed,refunded,voided',
                ],

                'reference' => [
                    'nullable',
                    'string',
                    'max:255',
                ],

                'metadata' => [
                    'nullable',
                    'array',
                ],
            ]
        );

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        /*
         * -------------------------------------------------------------
         * Tenant isolation.
         * -------------------------------------------------------------
         *
         * A payment must never attach a sale belonging to another
         * business.
         */
        if ($sale->business_id !== $business->id) {
            throw ValidationException::withMessages([
                'sale' => [
                    'The sale does not belong to the current business.',
                ],
            ]);
        }

        /*
         * -------------------------------------------------------------
         * Determine payment status.
         * -------------------------------------------------------------
         *
         * Payments default to "paid".
         */
        $status = $data['status'] ?? 'paid';

        /*
         * -------------------------------------------------------------
         * Prevent overpayment.
         * -------------------------------------------------------------
         *
         * Only paid payments count toward the amount already collected.
         */
        $paidAmount = $this->paidAmount($sale);

        $remainingBalance = $this->decimalSubtract(
            (string) $sale->total,
            $paidAmount
        );

        $amount = (string) $data['amount'];

        if (bccomp($amount, $remainingBalance, 2) === 1) {
            throw ValidationException::withMessages([
                'amount' => [
                    'The payment amount exceeds the remaining balance.',
                ],
            ]);
        }

        /*
         * -------------------------------------------------------------
         * Create payment.
         * -------------------------------------------------------------
         */
        return Payment::create([
            'business_id' => $business->id,
            'sale_id' => $sale->id,
            'amount' => $amount,
            'method' => $data['method'],
            'status' => $status,
            'reference' => $data['reference'] ?? null,
            'metadata' => $data['metadata'] ?? null,

            /*
             * A payment only receives paid_at when it is actually paid.
             */
            'paid_at' => $status === 'paid'
                ? now()
                : null,
        ]);
    }

    /**
     * Calculate the amount already paid for a sale.
     *
     * Only payments with status "paid" are counted.
     */
    public function paidAmount(Sale $sale): string
    {
        $amount = Payment::query()
            ->where('sale_id', $sale->id)
            ->where('business_id', $sale->business_id)
            ->where('status', 'paid')
            ->sum('amount');

        return number_format(
            (float) $amount,
            2,
            '.',
            ''
        );
    }

    /**
     * Calculate the remaining balance for a sale.
     */
    public function remainingBalance(Sale $sale): string
    {
        return $this->decimalSubtract(
            (string) $sale->total,
            $this->paidAmount($sale)
        );
    }

    /**
     * Subtract two monetary values safely.
     */
    private function decimalSubtract(
        string $left,
        string $right
    ): string {
        return bcsub(
            $left,
            $right,
            2
        );
    }
}
