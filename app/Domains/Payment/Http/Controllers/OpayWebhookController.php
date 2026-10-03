<?php

namespace App\Domains\Payment\Http\Controllers;

use App\Domains\Payment\Models\FinancialAccount;
use App\Domains\Payment\Providers\Opay\OpayBankTransactionProvider;
use App\Domains\Payment\Services\BankTransactionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OpayWebhookController
{
    public function __construct(
        private readonly OpayBankTransactionProvider $provider,
        private readonly BankTransactionService $bankTransactionService,
    ) {
    }

    public function handle(Request $request): JsonResponse
    {
        $payload = $request->all();

        /*
         * 1. Verify that this webhook is actually addressed
         *    to our OPay merchant and matches the transaction ID.
         */
        if (! $this->provider->verifyWebhook(
            $payload,
            $request->headers->all()
        )) {
            return response()->json([
                'code' => '00001',
                'message' => 'Invalid webhook.',
            ], 401);
        }

        /*
         * 2. We need the Digital Wallet number because
         *    it tells us which MerchantOS financial account
         *    received the transaction.
         */
        $depositCode = $payload['depositCode'] ?? null;

        if (! $depositCode) {
            return response()->json([
                'code' => '00002',
                'message' => 'Deposit code is required.',
            ], 422);
        }

        /*
         * 3. Resolve the MerchantOS financial account.
         *
         *    provider = opay
         *    account_identifier = OPay depositCode
         */
        $financialAccount = FinancialAccount::query()
            ->where('provider', 'opay')
            ->where('account_identifier', $depositCode)
            ->where('is_active', true)
            ->first();

        if (! $financialAccount) {
            /*
             * Do not accept a transaction that we cannot associate
             * with a MerchantOS business.
             */
            return response()->json([
                'code' => '00003',
                'message' => 'Financial account not found.',
            ], 404);
        }

        /*
         * 4. Convert OPay's payload into MerchantOS's
         *    normalized transaction structure.
         */
        $transaction = $this->provider
            ->normalizeTransaction($payload);

        /*
         * 5. Persist the transaction.
         *
         *    BankTransactionService already handles idempotency,
         *    so replaying the same OPay webhook won't create
         *    another transaction.
         */
        $this->bankTransactionService->record(
            $financialAccount->business,
            $financialAccount,
            $transaction
        );

        /*
         * 6. Tell OPay that MerchantOS successfully received
         *    and processed the notification.
         */
        return response()->json([
            'code' => '00000',
            'message' => 'SUCCESSFUL',
        ]);
    }
}
