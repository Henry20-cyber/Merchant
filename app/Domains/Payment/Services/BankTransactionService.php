<?php

namespace App\Domains\Payment\Services;

use App\Domains\Organization\Models\Business;
use App\Domains\Payment\Models\FinancialAccount;
use App\Domains\Payment\Models\IncomingBankTransaction;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class BankTransactionService
{
    /**
     * Record an incoming provider transaction.
     *
     * The transaction is scoped to the supplied business
     * and financial account.
     *
     * Provider transaction IDs are treated as idempotency keys.
     */
    public function record(
        Business $business,
        FinancialAccount $financialAccount,
        array $data
    ): IncomingBankTransaction {
        if (
            (string) $financialAccount->business_id
            !== (string) $business->id
        ) {
            throw new RuntimeException(
                'The financial account does not belong to the supplied business.'
            );
        }

        $provider = $data['provider'] ?? null;
        $providerTransactionId =
            $data['provider_transaction_id'] ?? null;

        if (! $provider) {
            throw new RuntimeException(
                'Bank transaction provider is required.'
            );
        }

        if (! $providerTransactionId) {
            throw new RuntimeException(
                'Provider transaction ID is required.'
            );
        }

        return DB::transaction(function () use (
            $business,
            $financialAccount,
            $data,
            $provider,
            $providerTransactionId
        ) {
            $existing = IncomingBankTransaction::query()
                ->where('provider', $provider)
                ->where(
                    'provider_transaction_id',
                    $providerTransactionId
                )
                ->lockForUpdate()
                ->first();

            if ($existing) {
                return $existing;
            }

            try {
                return IncomingBankTransaction::create([
                    'business_id' => $business->id,
                    'financial_account_id' =>
                        $financialAccount->id,

                    'provider' => $provider,
                    'provider_transaction_id' =>
                        $providerTransactionId,

                    'type' =>
                        $data['type'] ?? 'credit',

                    'amount' =>
                        $data['amount'],

                    'currency' =>
                        $data['currency'] ?? 'NGN',

                    'sender_name' =>
                        $data['sender_name'] ?? null,

                    'sender_account' =>
                        $data['sender_account'] ?? null,

                    'sender_bank' =>
                        $data['sender_bank'] ?? null,

                    'reference' =>
                        $data['reference'] ?? null,

                    'narration' =>
                        $data['narration'] ?? null,

                    'status' =>
                        $data['status'] ?? 'received',

                    'occurred_at' =>
                        $data['occurred_at'] ?? null,

                    'received_at' =>
                        $data['received_at'] ?? now(),

                    'metadata' =>
                        $data['metadata'] ?? null,
                ]);
            } catch (QueryException $exception) {
                /*
                 * Another request may have inserted the same
                 * provider transaction between our lookup and
                 * INSERT.
                 *
                 * The database unique constraint remains the
                 * final idempotency protection.
                 */
                $existing = IncomingBankTransaction::query()
                    ->where('provider', $provider)
                    ->where(
                        'provider_transaction_id',
                        $providerTransactionId
                    )
                    ->first();

                if ($existing) {
                    return $existing;
                }

                throw $exception;
            }
        });
    }
}
