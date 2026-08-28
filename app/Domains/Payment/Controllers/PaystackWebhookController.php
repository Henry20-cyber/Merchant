<?php

namespace App\Domains\Payment\Controllers;

use App\Domains\Payment\Services\PaymentConfirmationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PaystackWebhookController
{
    public function __construct(
        private PaymentConfirmationService $confirmationService,
    ) {
    }

    public function handle(Request $request): JsonResponse
    {
        $signature = $request->header(
            'x-paystack-signature'
        );

        if (! $signature) {
            return response()->json([
                'success' => false,
                'message' => 'Missing Paystack signature.',
            ], 401);
        }

        $secret = (string) config(
            'services.paystack.webhook_secret'
        );

        if ($secret === '') {
            return response()->json([
                'success' => false,
                'message' => 'Paystack webhook secret is not configured.',
            ], 500);
        }

        $expectedSignature = hash_hmac(
            'sha512',
            $request->getContent(),
            $secret,
        );

        if (! hash_equals(
            $expectedSignature,
            $signature
        )) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid Paystack signature.',
            ], 401);
        }

        $event = $request->json()->all();

        /*
         * We currently care about successful charges.
         */
        if (($event['event'] ?? null) !== 'charge.success') {
            return response()->json([
                'success' => true,
            ]);
        }

        $reference = data_get(
            $event,
            'data.reference'
        );

        if (! $reference) {
            return response()->json([
                'success' => false,
                'message' => 'Payment reference missing.',
            ], 422);
        }

        $this->confirmationService->confirm(
            $reference
        );

        return response()->json([
            'success' => true,
        ]);
    }
}