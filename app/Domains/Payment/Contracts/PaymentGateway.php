<?php

namespace App\Domains\Payment\Contracts;

interface PaymentGateway
{
    /**
     * Initialize a payment transaction.
     *
     * @param array{
     *     email: string,
     *     amount: int,
     *     reference: string,
     *     callback_url?: string|null,
     *     metadata?: array|null
     * } $data
     *
     * @return array{
     *     success: bool,
     *     authorization_url: string|null,
     *     access_code: string|null,
     *     reference: string|null,
     *     raw: array
     * }
     */
    public function initialize(array $data): array;

    /**
     * Verify a payment transaction.
     *
     * @return array{
     *     success: bool,
     *     status: string|null,
     *     reference: string|null,
     *     amount: int|null,
     *     currency: string|null,
     *     authorization_code: string|null,
     *     customer_code: string|null,
     *     customer_id: int|null,
     *     raw: array
     * }
     */
    public function verify(string $reference): array;

    /**
     * Create a recurring subscription with the payment provider.
     *
     * @param array{
     *     customer_code: string,
     *     plan_code: string,
     *     authorization_code?: string|null
     * } $data
     *
     * @return array{
     *     success: bool,
     *     subscription_code: string|null,
     *     customer_code: string|null,
     *     email_token: string|null,
     *     raw: array
     * }
     */
    public function createSubscription(array $data): array;

    /**
     * Fetch a Paystack plan by numeric ID or plan code.
     * @return array{success: bool, data: array, raw: array}
     */
    public function fetchPlan(string $identifier): array;

    /**
     * List Paystack subscriptions using supported filters.
     * @param array{perPage?: int, page?: int, customer?: int, plan?: int} $filters
     * @return array{success: bool, data: array, meta: array, raw: array}
     */
    public function listSubscriptions(array $filters = []): array;

    /**
     * Fetch one Paystack subscription by numeric ID or subscription code.
     * @return array{success: bool, data: array, raw: array}
     */
    public function fetchSubscription(string $identifier): array;

    /**
     * Disable a recurring subscription.
     *
     * @return array{
     *     success: bool,
     *     raw: array
     * }
     */
    public function disableSubscription(
        string $subscriptionCode,
        string $emailToken
    ): array;

    /**
     * Re-enable a recurring subscription.
     *
     * @return array{
     *     success: bool,
     *     raw: array
     * }
     */
    public function enableSubscription(
        string $subscriptionCode,
        string $emailToken
    ): array;
}