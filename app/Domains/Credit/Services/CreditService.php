<?php

namespace App\Domains\Credit\Services;

use App\Domains\Credit\Models\Credit;
use App\Domains\Organization\Models\Business;
use App\Domains\Organization\Models\Branch;
use App\Domains\Payment\Services\PaymentService;
use App\Domains\Sales\Models\Sale;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CreditService
{
    public function __construct(
        private PaymentService $paymentService
    ) {
    }

    /**
     * Create a credit record for a sale.
     *
     * A credit sale must belong to the current business,
     * have an active registered customer, and must use
     * the "credit" payment method.
     */
    public function create(
        Business $business,
        Branch $branch,
        Sale $sale,
        ?string $dueAt = null
    ): Credit {
        $this->assertBranchContext($business, $branch, $sale);

        if ($sale->business_id !== $business->id) {
            throw ValidationException::withMessages([
                'sale' => [
                    'The sale does not belong to the current business.',
                ],
            ]);
        }

        if (! $sale->customer_id) {
            throw ValidationException::withMessages([
                'customer_id' => [
                    'A credit sale requires a registered customer.',
                ],
            ]);
        }

        if ($sale->payment_method !== 'credit') {
            throw ValidationException::withMessages([
                'payment_method' => [
                    'The sale payment method must be credit.',
                ],
            ]);
        }

        if ($sale->payment_status === 'paid') {
            throw ValidationException::withMessages([
                'payment_status' => [
                    'A fully paid sale cannot create a credit record.',
                ],
            ]);
        }

        if (Credit::query()
            ->where('sale_id', $sale->id)
            ->exists()
        ) {
            throw ValidationException::withMessages([
                'sale' => [
                    'A credit record already exists for this sale.',
                ],
            ]);
        }

        return Credit::create([
            'business_id' => $business->id,
            'customer_id' => $sale->customer_id,
            'sale_id' => $sale->id,
            'original_amount' => $sale->total,
            'due_at' => $dueAt,
            'status' => 'outstanding',
        ]);
    }

    /**
     * Calculate the total amount paid against a credit sale.
     */
    public function amountPaid(Credit $credit): string
    {
        return $this->paymentService->paidAmount(
            $credit->sale
        );
    }

    /**
     * Calculate the outstanding balance.
     */
    public function outstandingAmount(Credit $credit): string
    {
        return $this->paymentService->remainingBalance(
            $credit->sale
        );
    }

    /**
     * Determine the current credit status.
     */
    public function determineStatus(Credit $credit): string
    {
        $outstanding = $this->outstandingAmount($credit);

        if (bccomp($outstanding, '0.00', 2) === 0) {
            return 'settled';
        }

        $paid = $this->amountPaid($credit);

        if (bccomp($paid, '0.00', 2) === 1) {
            return 'partial';
        }

        return 'outstanding';
    }

    /**
     * Refresh the credit status from its payments.
     */
    public function refreshStatus(Credit $credit): Credit
    {
        $credit->status = $this->determineStatus($credit);
        $credit->save();

        return $credit->refresh();
    }

    /**
     * Record a repayment against a credit sale.
     *
     * The existing PaymentService remains the source of truth
     * for the actual payment.
     */
    public function recordPayment(
        Business $business,
        Branch $branch,
        Credit $credit,
        array $data
    ) {
        return DB::transaction(function () use (
            $business,
            $branch,
            $credit,
            $data
        ) {
            if ($credit->business_id !== $business->id) {
                throw ValidationException::withMessages([
                    'credit' => [
                        'The credit does not belong to the current business.',
                    ],
                ]);
            }

            $credit->loadMissing('sale');

            if (! $credit->sale) {
                throw ValidationException::withMessages([
                    'credit' => [
                        'The credit sale could not be found.',
                    ],
                ]);
            }

            $this->assertBranchContext(
                $business,
                $branch,
                $credit->sale
            );

            $payment = $this->paymentService->create(
                $business,
                $credit->sale,
                $data
            );

            $this->refreshStatus($credit);

            return $payment;
        });
    }

    private function assertBranchContext(
        Business $business,
        Branch $branch,
        Sale $sale
    ): void {
        if ($branch->business_id !== $business->id) {
            abort(403, 'Branch does not belong to this business.');
        }

        if ($sale->business_id !== $business->id || $sale->branch_id !== $branch->id) {
            throw ValidationException::withMessages([
                'sale' => [
                    'The sale does not belong to the current branch.',
                ],
            ]);
        }
    }
}
