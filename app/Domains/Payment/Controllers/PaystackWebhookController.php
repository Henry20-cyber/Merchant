<?php

namespace App\Domains\Payment\Controllers;

use App\Domains\Payment\Models\PaymentWebhookEvent;
use App\Domains\Payment\Services\PaymentWebhookEventProcessingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PaystackWebhookController
{
    public function __construct(
        private PaymentWebhookEventProcessingService $processingService,
    ) {
    }

    public function handle(Request $request): JsonResponse
    {
        $signature = $request->header('x-paystack-signature');

        if (! $signature) {
            return response()->json([
                'success' => false,
                'message' => 'Missing Paystack signature.',
            ], 401);
        }

        $secret = (string) config('services.paystack.secret_key');

        if ($secret === '') {
            return response()->json([
                'success' => false,
                'message' => 'Paystack secret key is not configured.',
            ], 500);
        }

        $rawBody = $request->getContent();
        $expectedSignature = hash_hmac('sha512', $rawBody, $secret);

        if (! hash_equals($expectedSignature, $signature)) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid Paystack signature.',
            ], 401);
        }

        $payload = $request->json()->all();
        $providerEventId = hash('sha256', 'paystack:' . $rawBody);

        $event = $this->processingService->recordAndProcess(
            $payload,
            $providerEventId,
            'paystack',
        );

        if ($event->status !== 'failed') {
            return response()->json(['success' => true]);
        }

        $message = $this->failureResponseMessage($event);

        return response()->json([
            'success' => false,
            'message' => $message,
        ], 422);
    }

    private function failureResponseMessage(PaymentWebhookEvent $event): string
    {
        if (
            $event->event === 'charge.success'
            && $event->error_message === 'Payment reference missing.'
        ) {
            return 'Payment reference missing.';
        }

        if ($event->event === 'charge.success') {
            return 'Unable to confirm payment.';
        }

        if (in_array($event->event, ['charge.failed', 'invoice.payment_failed'], true)) {
            return 'Unable to process failed payment.';
        }

        return 'Unable to process Paystack webhook.';
    }
}
