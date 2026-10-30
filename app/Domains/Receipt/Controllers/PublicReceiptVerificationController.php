<?php

namespace App\Domains\Receipt\Controllers;

use App\Domains\Receipt\Models\Receipt;
use App\Domains\Receipt\Services\ReceiptAuditService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class PublicReceiptVerificationController
{
    public function show(
        Request $request,
        string $token,
        ReceiptAuditService $auditService,
    ): Response {
        abort_unless(preg_match('/^[a-f0-9]{64}$/', $token) === 1, 404);

        $receipt = Receipt::query()
            ->where('verification_token', $token)
            ->with(['business', 'sale', 'payment'])
            ->first();

        abort_unless($receipt, 404);

        $snapshotAuthentic = $auditService->snapshotIsAuthentic($receipt);
        $chainAuthentic = $auditService->verifyChain($receipt->business_id);
        $integrityValid = $snapshotAuthentic && $chainAuthentic;
        $verified = $integrityValid && $receipt->status === 'issued';
        $verificationStatus = ! $integrityValid
            ? 'integrity_check_failed'
            : ($receipt->status === 'voided' ? 'voided' : ($receipt->status === 'issued' ? 'verified' : 'not_issued'));

        // Verification requests are audit events; the payload intentionally excludes
        // IP addresses, user agents, customer information, and full receipt contents.
        $auditService->record(
            $receipt->business,
            'public_receipt_verification',
            [
                'outcome' => $verificationStatus,
                'receipt_number' => $receipt->receipt_number,
            ],
            $receipt,
        );

        $snapshot = $receipt->snapshot ?? [];
        $amount = $receipt->receipt_type === 'payment'
            ? data_get($snapshot, 'payment.amount')
            : data_get($snapshot, 'sale.total');

        $summary = [
            'verified' => $verified,
            'verification_status' => $verificationStatus,
            'transaction_status' => $receipt->receipt_type === 'payment'
                ? $receipt->payment?->status
                : $receipt->sale?->payment_status,
            'receipt_number' => $receipt->receipt_number,
            'receipt_type' => data_get($snapshot, 'receipt.type', $receipt->receipt_type),
            'business_name' => data_get($snapshot, 'business.name', $receipt->business?->name),
            'issued_at' => $receipt->issued_at?->toISOString(),
            'amount' => $amount,
            'currency' => data_get($snapshot, 'business.currency'),
        ];

        if (! $request->expectsJson()) {
            return response()->view('receipts.verify', ['verification' => $summary]);
        }

        return response()->json(['success' => true, 'data' => $summary]);
    }
}
