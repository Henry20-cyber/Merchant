<?php

namespace App\Domains\Payment\Services;

use App\Domains\Payment\Models\PaymentWebhookEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class PaymentWebhookEventProcessor
{
    public function __construct(
        private PaymentConfirmationService $confirmationService,
        private SubscriptionRenewalService $renewalService,
        private SubscriptionPaymentFailureService $failureService,
    ) {
    }

    /**
     * Process a Paystack webhook event.
     *
     * The provider event ID is used for webhook-level
     * idempotency while the payment reference remains
     * responsible for transaction-level idempotency.
     *
     * @param array<string, mixed> $payload
     */
    public function process(
        array $payload,
        string $providerEventId,
    ): PaymentWebhookEvent {
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
         * Fast path for an already-recorded event.
         */
        $existing = PaymentWebhookEvent::query()
            ->where('provider', 'paystack')
            ->where(
                'provider_event_id',
                $providerEventId
            )
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        try {
            return DB::transaction(
                function () use (
                    $payload,
                    $providerEventId,
                    $event,
                    $reference
                ): PaymentWebhookEvent {
                    /*
                     * Re-check inside the transaction.
                     *
                     * This protects against two identical webhook
                     * requests arriving at nearly the same time.
                     */
                    $existing = PaymentWebhookEvent::query()
                        ->where('provider', 'paystack')
                        ->where(
                            'provider_event_id',
                            $providerEventId
                        )
                        ->lockForUpdate()
                        ->first();

                    if ($existing !== null) {
                        return $existing;
                    }

                    $record = PaymentWebhookEvent::create([
                        'provider' => 'paystack',
                        'provider_event_id' =>
                            $providerEventId,
                        'event' => $event,
                        'reference' => $reference,
                        'status' => 'received',
                        'payload' => $payload,
                    ]);

                    /*
                     * Ignore events that MerchantOS does not
                     * currently process.
                     */
                    if (
                        ! in_array(
                            $event,
                            [
                                'charge.success',
                                'invoice.payment_failed',
                                'charge.failed',
                            ],
                            true
                        )
                    ) {
                        return $this->markProcessed(
                            $record
                        );
                    }

                    /*
                     * -------------------------------------------------
                     * SUCCESSFUL CHARGE
                     * -------------------------------------------------
                     */
                    if ($event === 'charge.success') {
                        if (! $reference) {
                            throw ValidationException::withMessages([
                                'reference' =>
                                    'Payment reference missing.',
                            ]);
                        }

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
                         * A subscription code means this is a
                         * recurring subscription payment.
                         */
                        if ($subscriptionCode) {
                            $customerCode =
                                data_get(
                                    $payload,
                                    'data.customer.customer_code'
                                );

                            $amount =
                                data_get(
                                    $payload,
                                    'data.amount'
                                );

                            $currency =
                                data_get(
                                    $payload,
                                    'data.currency'
                                );

                            $paidAt =
                                data_get(
                                    $payload,
                                    'data.paid_at'
                                );

                            $this->renewalService->renew([
                                'reference' =>
                                    $reference,

                                'customer_code' =>
                                    $customerCode,

                                'subscription_code' =>
                                    $subscriptionCode,

                                'amount' =>
                                    $amount,

                                'currency' =>
                                    $currency,

                                'paid_at' =>
                                    $paidAt,
                            ]);
                        } else {
                            /*
                             * No subscription code means this is
                             * an initial MerchantOS checkout.
                             */
                            $this->confirmationService->confirm(
                                $reference
                            );
                        }
                    }

                    /*
                     * -------------------------------------------------
                     * FAILED RECURRING PAYMENT
                     * -------------------------------------------------
                     */
                    if (
                        in_array(
                            $event,
                            [
                                'invoice.payment_failed',
                                'charge.failed',
                            ],
                            true
                        )
                    ) {
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
                         * A failed event without subscription
                         * identifiers cannot safely modify a
                         * MerchantOS subscription.
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

                    return $this->markProcessed(
                        $record
                    );
                }
            );
        } catch (\Throwable $exception) {
            /*
             * If an event record exists, retain the failure
             * information for diagnostics and retry handling.
             */
            $record = PaymentWebhookEvent::query()
                ->where('provider', 'paystack')
                ->where(
                    'provider_event_id',
                    $providerEventId
                )
                ->first();

            if ($record !== null) {
                $record->forceFill([
                    'status' => 'failed',
                    'error_message' =>
                        $exception->getMessage(),
                ])->save();
            }

            throw $exception;
        }
    }

    private function markProcessed(
        PaymentWebhookEvent $event
    ): PaymentWebhookEvent {
        $event->forceFill([
            'status' => 'processed',
            'processed_at' => now(),
        ])->save();

        return $event->refresh();
    }
}