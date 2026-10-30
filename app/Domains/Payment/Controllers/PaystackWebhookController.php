<?php

namespace App\Domains\Payment\Controllers;

use App\Domains\Payment\Models\PaymentWebhookEvent;
use App\Domains\Payment\Services\PaymentConfirmationService;
use App\Domains\Payment\Services\SubscriptionPaymentFailureService;
use App\Domains\Payment\Services\SubscriptionRenewalService;
use App\Domains\Subscription\Services\SubscriptionBillingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Throwable;

class PaystackWebhookController
{
    /**
     * An event that has remained in "received" for longer
     * than this is considered abandoned and may be retried.
     */
    private const STALE_EVENT_MINUTES = 10;

    public function __construct(
        private PaymentConfirmationService $confirmationService,
        private SubscriptionRenewalService $renewalService,
        private SubscriptionPaymentFailureService $failureService,
        private SubscriptionBillingService $billingService,
    ) {
    }

    public function handle(Request $request): JsonResponse
    {
        /*
        |--------------------------------------------------------------------------
        | 1. Verify Paystack signature
        |--------------------------------------------------------------------------
        */

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
            'services.paystack.secret_key'
        );

        if ($secret === '') {
            return response()->json([
                'success' => false,
                'message' =>
                    'Paystack secret key is not configured.',
            ], 500);
        }

        $rawBody = $request->getContent();

        $expectedSignature = hash_hmac(
            'sha512',
            $rawBody,
            $secret
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

        /*
        |--------------------------------------------------------------------------
        | 2. Parse payload
        |--------------------------------------------------------------------------
        */

        $payload = $request->json()->all();

        $event = (string) data_get(
            $payload,
            'event',
            ''
        );

        $reference = data_get(
            $payload,
            'data.reference'
        );

        /*
        |--------------------------------------------------------------------------
        | 3. Generate deterministic event ID
        |--------------------------------------------------------------------------
        |
        | Paystack does not currently provide a dedicated webhook
        | event ID that MerchantOS can reliably use here.
        |
        | Hashing the exact raw request body means the same webhook
        | body always produces the same identifier.
        |
        */

        $providerEventId = hash(
            'sha256',
            'paystack:' . $rawBody
        );

        /*
        |--------------------------------------------------------------------------
        | 4. Webhook idempotency
        |--------------------------------------------------------------------------
        */

        $webhookEvent = PaymentWebhookEvent::query()
            ->where('provider', 'paystack')
            ->where('provider_event_id', $providerEventId)
            ->first();

        if ($webhookEvent !== null) {
            if ($webhookEvent->status === 'processed') {
                return response()->json(['success' => true]);
            }

            // A conditional UPDATE acts as the retry lease. Only one worker
            // can claim a failed or stale event at a time.
            if (! $this->claimRetryableEvent($webhookEvent)) {
                return response()->json(['success' => true]);
            }

            $webhookEvent->refresh();
        } else {
            try {
                // A successful insert is the first delivery's atomic claim.
                $webhookEvent = PaymentWebhookEvent::create([
                    'provider' => 'paystack',
                    'provider_event_id' => $providerEventId,
                    'event' => $event,
                    'reference' => $reference,
                    'status' => 'received',
                    'payload' => $payload,
                ]);
            } catch (\Illuminate\Database\UniqueConstraintViolationException) {
                // Another delivery inserted the same deterministic event ID.
                $webhookEvent = PaymentWebhookEvent::query()
                    ->where('provider', 'paystack')
                    ->where('provider_event_id', $providerEventId)
                    ->first();

                if ($webhookEvent === null) {
                    throw new \RuntimeException(
                        'Unable to retrieve the existing Paystack webhook event.'
                    );
                }

                if (
                    $webhookEvent->status === 'processed' ||
                    ! $this->claimRetryableEvent($webhookEvent)
                ) {
                    return response()->json(['success' => true]);
                }

                $webhookEvent->refresh();
            }
        }

        /*
        |--------------------------------------------------------------------------
        | 5. Process webhook
        |--------------------------------------------------------------------------
        */

        try {
            /*
            |--------------------------------------------------------------------------
            | Unsupported events
            |--------------------------------------------------------------------------
            */

            if (! in_array(
                $event,
                [
                    'charge.success',
                    'charge.failed',
                    'invoice.payment_failed',
                    'subscription.not_renew',
                    'subscription.disable',
                ],
                true
            )) {
                $this->markProcessed(
                    $webhookEvent
                );

                return response()->json([
                    'success' => true,
                ]);
            }

            /*
            |--------------------------------------------------------------------------
            | Successful charge
            |--------------------------------------------------------------------------
            */

            if ($event === 'charge.success') {
                if (! $reference) {
                    throw ValidationException::withMessages([
                        'reference' =>
                            'Payment reference missing.',
                    ]);
                }

                /*
                |--------------------------------------------------------------------------
                | Determine whether this is a recurring payment
                |--------------------------------------------------------------------------
                */

                $subscriptionCode =
                    data_get(
                        $payload,
                        'data.subscription.subscription_code'
                    )
                    ?? data_get(
                        $payload,
                        'data.subscription_code'
                    )
                    ?? data_get(
                        $payload,
                        'data.plan.subscription_code'
                    );

                /*
                |--------------------------------------------------------------------------
                | Recurring subscription payment
                |--------------------------------------------------------------------------
                */

                if ($subscriptionCode) {
                    $this->renewalService->renew([
                        'reference' =>
                            $reference,

                        'customer_code' =>
                            data_get(
                                $payload,
                                'data.customer.customer_code'
                            ),

                        'subscription_code' =>
                            $subscriptionCode,

                        'amount' =>
                            data_get(
                                $payload,
                                'data.amount'
                            ),

                        'currency' =>
                            data_get(
                                $payload,
                                'data.currency'
                            ),

                        'paid_at' =>
                            data_get(
                                $payload,
                                'data.paid_at'
                            ),
                    ]);
                }

                /*
                |--------------------------------------------------------------------------
                | Initial MerchantOS payment
                |--------------------------------------------------------------------------
                */

                else {
                    $this->confirmationService->confirm(
                        $reference
                    );
                }
            }

            /*
            |--------------------------------------------------------------------------
            | Failed recurring payment
            |--------------------------------------------------------------------------
            */

            if (in_array(
                $event,
                [
                    'charge.failed',
                    'invoice.payment_failed',
                ],
                true
            )) {
                $customerCode =
                    data_get(
                        $payload,
                        'data.customer.customer_code'
                    );

                $subscriptionCode =
                    data_get(
                        $payload,
                        'data.subscription.subscription_code'
                    )
                    ?? data_get(
                        $payload,
                        'data.subscription_code'
                    );

                /*
                 * Only process the failure when Paystack supplied
                 * the identifiers required to match the subscription.
                 */
                if (
                    $customerCode &&
                    $subscriptionCode
                ) {
                    $this->failureService->fail([
                        'customer_code' =>
                            $customerCode,

                        'subscription_code' =>
                            $subscriptionCode,
                    ]);
                }
            }

            /*
            |--------------------------------------------------------------------------
            | Subscription renewal state
            |--------------------------------------------------------------------------
            */

            if (in_array(
                $event,
                [
                    'subscription.not_renew',
                    'subscription.disable',
                ],
                true
            )) {
                $subscriptionCode =
                    data_get(
                        $payload,
                        'data.subscription_code'
                    )
                    ?? data_get(
                        $payload,
                        'data.subscription.subscription_code'
                    );

                if ($subscriptionCode) {
                    if ($event === 'subscription.not_renew') {
                        $this->billingService->markNonRenewing(
                            'paystack',
                            $subscriptionCode,
                        );
                    } else {
                        $this->billingService->markDisabled(
                            'paystack',
                            $subscriptionCode,
                        );
                    }
                }
            }

            /*
            |--------------------------------------------------------------------------
            | 6. Mark successfully processed
            |--------------------------------------------------------------------------
            */

            $this->markProcessed(
                $webhookEvent
            );

            return response()->json([
                'success' => true,
            ]);
        }

        /*
        |--------------------------------------------------------------------------
        | 7. Validation failure
        |--------------------------------------------------------------------------
        */

        catch (ValidationException $exception) {
            $message = $this->validationMessage(
                $exception
            );

            $this->markFailed(
                $webhookEvent,
                $message
            );

            return response()->json([
                'success' => false,
                'message' => $message,
            ], 422);
        }

        /*
        |--------------------------------------------------------------------------
        | 8. Any other failure
        |--------------------------------------------------------------------------
        */

        catch (Throwable $exception) {
            report($exception);

            $this->markFailed(
                $webhookEvent,
                $exception->getMessage()
                    ?: 'Webhook processing failed.'
            );

            return response()->json([
                'success' => false,
                'message' =>
                    $this->failureResponseMessage(
                        $exception,
                        $event
                    ),
            ], 422);
        }
    }

    /**
     * Atomically claim a failed or abandoned webhook for retry.
     *
     * The conditional update prevents two deliveries from reclaiming the
     * same row after both have read its previous status/timestamp.
     */
    private function claimRetryableEvent(PaymentWebhookEvent $event): bool
    {
        $query = PaymentWebhookEvent::query()->whereKey($event->getKey());

        if ($event->status === 'failed') {
            $query->where('status', 'failed');
        } elseif ($event->status === 'received') {
            $staleAt = now()->subMinutes(self::STALE_EVENT_MINUTES);

            $query->where('status', 'received')
                ->where(function ($staleQuery) use ($staleAt): void {
                    $staleQuery->where('updated_at', '<=', $staleAt)
                        ->orWhereNull('updated_at');
                });
        } else {
            return false;
        }

        return $query->update([
            'status' => 'received',
            'error_message' => null,
            'processed_at' => null,
            'updated_at' => now(),
        ]) === 1;
    }

    /**
     * Mark a webhook event as successfully processed.
     */
    private function markProcessed(
        PaymentWebhookEvent $event
    ): void {
        $event->forceFill([
            'status' => 'processed',
            'error_message' => null,
            'processed_at' => now(),
        ])->save();
    }

    /**
     * Mark a webhook event as failed.
     */
    private function markFailed(
        PaymentWebhookEvent $event,
        string $message
    ): void {
        $event->forceFill([
            'status' => 'failed',
            'error_message' => $message,
            'processed_at' => null,
        ])->save();
    }

    /**
     * Extract a clean message from a Laravel validation exception.
     */
    private function validationMessage(
        ValidationException $exception
    ): string {
        $errors = $exception->errors();

        foreach ($errors as $messages) {
            if (! empty($messages)) {
                return (string) $messages[0];
            }
        }

        return $exception->getMessage()
            ?: 'Webhook validation failed.';
    }

    /**
     * Preserve the public webhook response contract.
     */
    private function failureResponseMessage(
        Throwable $exception,
        string $event
    ): string {
        if ($event === 'charge.success') {
            return 'Unable to confirm payment.';
        }

        if (
            $event === 'charge.failed' ||
            $event === 'invoice.payment_failed'
        ) {
            return 'Unable to process failed payment.';
        }

        return 'Unable to process Paystack webhook.';
    }
}