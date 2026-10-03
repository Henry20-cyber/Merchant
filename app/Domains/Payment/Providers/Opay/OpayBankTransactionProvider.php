<?php

namespace App\Domains\Payment\Providers\Opay;

use App\Domains\Payment\Contracts\BankTransactionProvider;
use App\Domains\Payment\Models\FinancialAccount;
use DateTimeInterface;
use RuntimeException;

class OpayBankTransactionProvider implements BankTransactionProvider
{
    public function verifyWebhook(array $payload, array $headers): bool
{
    $transactionId = (string) ($payload['transactionId'] ?? '');

    $headerTransactionId = $headers['x-opay-tranid']
        ?? $headers['X-Opay-Tranid']
        ?? '';

    $merchantId = $headers['merchantid']
        ?? $headers['merchantId']
        ?? '';

    /*
     * Laravel's Request::headers->all() returns
     * header values as arrays.
     *
     * Example:
     * [
     *     'x-opay-tranid' => ['TX-123']
     * ]
     *
     * Normalize those values to strings first.
     */
    if (is_array($headerTransactionId)) {
        $headerTransactionId = $headerTransactionId[0] ?? '';
    }

    if (is_array($merchantId)) {
        $merchantId = $merchantId[0] ?? '';
    }

    $headerTransactionId = (string) $headerTransactionId;
    $merchantId = (string) $merchantId;

    if ($transactionId === '') {
        return false;
    }

    if ($headerTransactionId === '') {
        return false;
    }

    if (! hash_equals($transactionId, $headerTransactionId)) {
        return false;
    }

    $configuredMerchantId = (string) config(
        'services.opay.merchant_id'
    );

    if (
        $configuredMerchantId !== ''
        && $merchantId !== ''
        && ! hash_equals(
            $configuredMerchantId,
            $merchantId
        )
    ) {
        return false;
    }

    return true;
}

    public function normalizeTransaction(array $payload): array
    {
        $status = strtoupper(
            (string) ($payload['status'] ?? 'PENDING')
        );

        return [
            'provider' => 'opay',

            'provider_transaction_id' =>
                $payload['transactionId'] ?? null,

            'type' => 'credit',

            'amount' =>
                $payload['depositAmount'] ?? null,

            'currency' =>
                $payload['currency'] ?? 'NGN',

            /*
             * The Digital Wallet webhook does not document
             * sender account/name/bank fields.
             */
            'sender_name' => null,
            'sender_account' => null,
            'sender_bank' => null,

            'reference' =>
                $payload['reference']
                ?? $payload['orderNo']
                ?? null,

            'narration' =>
                $payload['notes']
                ?? null,

            'status' => strtolower($status),

            'occurred_at' =>
                $payload['formatDateTime']
                ?? null,

            'metadata' => [
                'provider' => 'opay',

                /*
                 * This identifies the Digital Wallet that received
                 * the money.
                 */
                'deposit_code' =>
                    $payload['depositCode'] ?? null,

                /*
                 * OPay's wallet holder reference.
                 */
                'ref_id' =>
                    $payload['refId'] ?? null,

                'deposit_time' =>
                    $payload['depositTime'] ?? null,

                'error_code' =>
                    $payload['errorCode'] ?? null,

                'error_message' =>
                    $payload['errorMsg'] ?? null,

                'order_no' =>
                    $payload['orderNo'] ?? null,

                'notes' =>
                    $payload['notes'] ?? null,

                'raw' => $payload,
            ],
        ];
    }

    public function transactions(
        FinancialAccount $account,
        DateTimeInterface $from,
        DateTimeInterface $to
    ): array {
        /*
         * Transaction-history synchronization will be implemented
         * separately after the webhook path is working.
         */
        return [];
    }
}