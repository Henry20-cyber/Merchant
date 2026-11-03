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
                ->connectTimeout(5)
                ->timeout(15)
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
                ->connectTimeout(5)
                ->timeout(15)
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

            'customer_id' =>
            data_get($data, 'customer.id') !== null
                ? (int) data_get($data, 'customer.id')
                : null,

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
                ->connectTimeout(5)
                ->timeout(15)
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
     * Fetch a Paystack plan by numeric ID or plan code.
     * @return array{success: bool, data: array, raw: array}
     */
    public function fetchPlan(string $identifier): array
    {
        return $this->getResource('/plan/' . rawurlencode($identifier), 'Unable to fetch Paystack plan.');
    }

    /**
     * List Paystack subscriptions. Pagination is intentionally bounded.
     * @param array{perPage?: int, page?: int, customer?: int, plan?: int} $filters
     * @return array{success: bool, data: array, meta: array, raw: array}
     */
    public function listSubscriptions(array $filters = []): array
    {
        $allowed = ['perPage', 'page', 'customer', 'plan'];
        $unknown = array_diff(array_keys($filters), $allowed);
        if ($unknown !== []) {
            throw new RuntimeException('Unsupported Paystack subscription list filter: ' . implode(', ', $unknown));
        }

        $perPage = (int) ($filters['perPage'] ?? 50);
        $page = (int) ($filters['page'] ?? 1);
        if ($perPage < 1 || $perPage > 100 || $page < 1) {
            throw new RuntimeException('Paystack subscription pagination values are invalid.');
        }

        $query = ['perPage' => $perPage, 'page' => $page];
        foreach (['customer', 'plan'] as $key) {
            if (array_key_exists($key, $filters)) {
                $value = filter_var($filters[$key], FILTER_VALIDATE_INT);
                if ($value === false || $value < 1) {
                    throw new RuntimeException("Paystack subscription {$key} filter must be a positive integer.");
                }
                $query[$key] = $value;
            }
        }

        try {
            $response = Http::withToken($this->secretKey)
                ->acceptJson()->connectTimeout(5)->timeout(15)
                ->get($this->baseUrl . '/subscription', $query)->throw();
        } catch (RequestException $exception) {
            throw new RuntimeException('Unable to list Paystack subscriptions.', previous: $exception);
        }

        $payload = $response->json();
        if (! ($payload['status'] ?? false)) {
            throw new RuntimeException($payload['message'] ?? 'Paystack subscription listing failed.');
        }
        $data = $payload['data'] ?? [];
        if (! is_array($data)) {
            throw new RuntimeException('Paystack returned an invalid subscription list.');
        }

        return [
            'success' => true,
            'data' => $data,
            'meta' => is_array($payload['meta'] ?? null) ? $payload['meta'] : [],
            'raw' => $payload,
        ];
    }

    /**
     * Fetch a Paystack subscription by numeric ID or subscription code.
     * @return array{success: bool, data: array, raw: array}
     */
    public function fetchSubscription(string $identifier): array
    {
        return $this->getResource('/subscription/' . rawurlencode($identifier), 'Unable to fetch Paystack subscription.');
    }

    /** @return array{success: bool, data: array, raw: array} */
    private function getResource(string $path, string $failureMessage): array
    {
        try {
            $response = Http::withToken($this->secretKey)
                ->acceptJson()->connectTimeout(5)->timeout(15)
                ->get($this->baseUrl . $path)->throw();
        } catch (RequestException $exception) {
            throw new RuntimeException($failureMessage, previous: $exception);
        }

        $payload = $response->json();
        if (! ($payload['status'] ?? false)) {
            throw new RuntimeException($payload['message'] ?? $failureMessage);
        }
        $data = $payload['data'] ?? [];
        if (! is_array($data)) {
            throw new RuntimeException('Paystack returned an invalid resource response.');
        }
        return ['success' => true, 'data' => $data, 'raw' => $payload];
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
            ->connectTimeout(5)
            ->timeout(15)
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

/**
 * Re-enable a recurring subscription with Paystack.
 */
public function enableSubscription(
    string $subscriptionCode,
    string $emailToken
): array {
    try {
        $response = Http::withToken($this->secretKey)
            ->acceptJson()
            ->connectTimeout(5)
            ->timeout(15)
            ->post(
                $this->baseUrl . '/subscription/enable',
                [
                    'code' => $subscriptionCode,
                    'token' => $emailToken,
                ]
            )
            ->throw();
    } catch (RequestException $exception) {
        throw new RuntimeException(
            'Unable to enable Paystack subscription.',
            previous: $exception
        );
    }

    $payload = $response->json();

    if (! ($payload['status'] ?? false)) {
        throw new RuntimeException(
            $payload['message']
                ?? 'Paystack subscription enabling failed.'
        );
    }

    return [
        'success' => true,
        'raw' => $payload,
    ];
}
}
