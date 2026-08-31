<?php

namespace App\Domains\Payment\Gateways;

use App\Domains\Payment\Contracts\PaymentGateway;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class PaystackGateway implements PaymentGateway
{
    private string $secretKey;

    private string $baseUrl;

    public function __construct()
    {
        $this->secretKey = (string) config(
            'services.paystack.secret_key'
        );

        $this->baseUrl = rtrim(
            (string) config(
                'services.paystack.base_url',
                'https://api.paystack.co'
            ),
            '/'
        );

        if ($this->secretKey === '') {
            throw new RuntimeException(
                'Paystack secret key is not configured.'
            );
        }
    }

    /**
     * Initialize a Paystack transaction.
     */
    public function initialize(array $data): array
    {
        try {
            $response = Http::withToken($this->secretKey)
                ->acceptJson()
                ->post(
                    $this->baseUrl . '/transaction/initialize',
                    [
                        'email' => $data['email'],
                        'amount' => $data['amount'],
                        'reference' => $data['reference'],

                        'callback_url' =>
                        $data['callback_url'] ?? null,

                        'metadata' =>
                        $data['metadata'] ?? null,
                    ]
                )
                ->throw();
        } catch (RequestException $exception) {
            throw new RuntimeException(
                'Unable to initialize Paystack transaction.',
                previous: $exception
            );
        }

        $payload = $response->json();

        if (! ($payload['status'] ?? false)) {
            throw new RuntimeException(
                $payload['message']
                    ?? 'Paystack transaction initialization failed.'
            );
        }

        $data = $payload['data'] ?? [];

        return [
            'success' => true,

            'authorization_url' =>
            $data['authorization_url'] ?? null,

            'access_code' =>
            $data['access_code'] ?? null,

            'reference' =>
            $data['reference'] ?? null,

            'raw' => $payload,
        ];
    }

    /**
     * Verify a Paystack transaction.
     */
    public function verify(string $reference): array
    {
        try {
            $response = Http::withToken($this->secretKey)
                ->acceptJson()
                ->get(
                    $this->baseUrl .
                        '/transaction/verify/' .
                        urlencode($reference)
                )
                ->throw();
        } catch (RequestException $exception) {
            throw new RuntimeException(
                'Unable to verify Paystack transaction.',
                previous: $exception
            );
        }

        $payload = $response->json();

        if (! ($payload['status'] ?? false)) {
            throw new RuntimeException(
                $payload['message']
                    ?? 'Paystack transaction verification failed.'
            );
        }

        $data = $payload['data'] ?? [];

        return [
            'success' => true,

            'status' =>
            $data['status'] ?? null,

            'reference' =>
            $data['reference'] ?? null,

            'amount' =>
            isset($data['amount'])
                ? (int) $data['amount']
                : null,

            'currency' =>
            $data['currency'] ?? null,

            'authorization_code' =>
            data_get(
                $data,
                'authorization.authorization_code'
            ),

            'customer_code' =>
            data_get(
                $data,
                'customer.customer_code'
            ),

            'raw' => $payload,
        ];
    }

    /**
     * Create a recurring subscription with Paystack.
     */
    public function createSubscription(array $data): array
    {
        try {
            $response = Http::withToken($this->secretKey)
                ->acceptJson()
                ->post(
                    $this->baseUrl . '/subscription',
                    [
                        'customer' =>
                        $data['customer_code'],

                        'plan' =>
                        $data['plan_code'],

                        'authorization' =>
                        $data['authorization_code'] ?? null,
                    ]
                )
                ->throw();
        } catch (RequestException $exception) {
            throw new RuntimeException(
                'Unable to create Paystack subscription.',
                previous: $exception
            );
        }

        $payload = $response->json();

        if (! ($payload['status'] ?? false)) {
            throw new RuntimeException(
                $payload['message']
                    ?? 'Paystack subscription creation failed.'
            );
        }

        $subscription = $payload['data'] ?? [];

        return [
            'success' => true,

            'subscription_code' =>
            $subscription['subscription_code']
                ?? null,

            'customer_code' =>
            $subscription['customer']['customer_code']
                ?? $data['customer_code']
                ?? null,

            'email_token' =>
            $subscription['email_token']
                ?? null,

            'raw' => $payload,
        ];
    }

    /**
     * Disable a recurring subscription with Paystack.
     */
   /**
 * Disable a recurring subscription.
 *
 * Paystack requires both the subscription code
 * and email token to disable a subscription.
 *
 * @return array{
 *     success: bool,
 *     raw: array
 * }
 */

public function disableSubscription(
    string $subscriptionCode,
    string $emailToken
): array {
    try {
        $response = Http::withToken($this->secretKey)
            ->acceptJson()
            ->post(
                $this->baseUrl . '/subscription/disable',
                [
                    'code' => $subscriptionCode,
                    'token' => $emailToken,
                ]
            )
            ->throw();
    } catch (RequestException $exception) {
        throw new RuntimeException(
            'Unable to disable Paystack subscription.',
            previous: $exception
        );
    }

    $payload = $response->json();

    if (! ($payload['status'] ?? false)) {
        throw new RuntimeException(
            $payload['message']
                ?? 'Paystack subscription disabling failed.'
        );
    }

    return [
        'success' => true,
        'raw' => $payload,
    ];
}
}
