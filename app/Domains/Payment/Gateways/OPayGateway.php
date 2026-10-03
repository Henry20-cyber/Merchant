<?php

namespace App\Domains\Payment\Gateways;

use App\Domains\Payment\Contracts\PaymentGateway;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class OPayGateway implements PaymentGateway
{
    private string $publicKey;

    private string $secretKey;

    private string $merchantId;

    private string $baseUrl;

    private string $country;

    private string $currency;

    public function __construct()
    {
        $this->publicKey = (string) config(
            'services.opay.public_key'
        );

        $this->secretKey = (string) config(
            'services.opay.secret_key'
        );

        $this->merchantId = (string) config(
            'services.opay.merchant_id'
        );

        $this->baseUrl = rtrim(
            (string) config(
                'services.opay.base_url',
                'https://testapi.opaycheckout.com'
            ),
            '/'
        );

        $this->country = (string) config(
            'services.opay.country',
            'NG'
        );

        $this->currency = (string) config(
            'services.opay.currency',
            'NGN'
        );

        if ($this->publicKey === '') {
            throw new RuntimeException(
                'OPay public key is not configured.'
            );
        }

        if ($this->secretKey === '') {
            throw new RuntimeException(
                'OPay secret key is not configured.'
            );
        }

        if ($this->merchantId === '') {
            throw new RuntimeException(
                'OPay merchant ID is not configured.'
            );
        }
    }

    /**
     * Initialize an OPay Cashier payment.
     *
     * OPay expects the amount in the smallest currency unit.
     */
    public function initialize(array $data): array
    {
        $payload = [
            'country' => $this->country,

            'reference' => $data['reference'],

            'amount' => [
                'total' => $data['amount'],
                'currency' => $this->currency,
            ],

            'returnUrl' =>
                $data['callback_url']
                ?? config('services.opay.return_url'),

            'callbackUrl' =>
                config('services.opay.callback_url'),

            'cancelUrl' =>
                config('services.opay.cancel_url'),

            'customerVisitSource' => 'BROWSER',

            'expireAt' => 30,

            'userInfo' => [
                'userEmail' => $data['email'],
            ],

            'product' => [
                'name' =>
                    data_get(
                        $data,
                        'metadata.product_name',
                        'MerchantOS Payment'
                    ),

                'description' =>
                    data_get(
                        $data,
                        'metadata.product_description',
                        'MerchantOS payment'
                    ),
            ],
        ];

        try {
            $response = Http::acceptJson()
                ->withHeaders([
                    'Authorization' =>
                        'Bearer ' . $this->publicKey,

                    'MerchantId' =>
                        $this->merchantId,
                ])
                ->post(
                    $this->baseUrl .
                        '/api/v1/international/cashier/create',
                    $payload
                )
                ->throw();
        } catch (RequestException $exception) {
            throw new RuntimeException(
                'Unable to initialize OPay transaction.',
                previous: $exception
            );
        }

        $result = $response->json();

        if (($result['code'] ?? null) !== '00000') {
            throw new RuntimeException(
                $result['message']
                    ?? 'OPay transaction initialization failed.'
            );
        }

        $transaction = $result['data'] ?? [];

        return [
            'success' => true,

            'authorization_url' =>
                $transaction['cashierUrl'] ?? null,

            'access_code' =>
                $transaction['orderNo'] ?? null,

            'reference' =>
                $transaction['reference']
                ?? $data['reference']
                ?? null,

            'raw' => $result,
        ];
    }

    /**
     * Verify an OPay payment.
     */
    public function verify(string $reference): array
    {
        $payload = [
            'reference' => $reference,
            'country' => $this->country,
        ];

        $signature = $this->generateSignature($payload);

        try {
            $response = Http::acceptJson()
                ->withHeaders([
                    'Authorization' =>
                        'Bearer ' . $signature,

                    'MerchantId' =>
                        $this->merchantId,
                ])
                ->post(
                    $this->baseUrl .
                        '/api/v1/international/cashier/status',
                    $payload
                )
                ->throw();
        } catch (RequestException $exception) {
            throw new RuntimeException(
                'Unable to verify OPay transaction.',
                previous: $exception
            );
        }

        $result = $response->json();

        if (($result['code'] ?? null) !== '00000') {
            throw new RuntimeException(
                $result['message']
                    ?? 'OPay transaction verification failed.'
            );
        }

        $transaction = $result['data'] ?? [];

        $status = strtoupper(
            (string) ($transaction['status'] ?? '')
        );

        $success = $status === 'SUCCESS';

        return [
            'success' => $success,

            'status' => $status ?: null,

            'reference' =>
                $transaction['reference']
                ?? $reference,

            'amount' =>
                isset($transaction['amount']['total'])
                    ? (int) $transaction['amount']['total']
                    : null,

            'currency' =>
                $transaction['amount']['currency']
                ?? $this->currency,

            'authorization_code' => null,

            'customer_code' => null,

            'raw' => $result,
        ];
    }

    /**
     * OPay recurring subscriptions are not implemented
     * through this adapter yet.
     *
     * Subscription enforcement is intentionally deferred.
     */
    public function createSubscription(array $data): array
    {
        throw new RuntimeException(
            'OPay recurring subscriptions are not implemented yet.'
        );
    }

    /**
     * OPay recurring subscriptions are not implemented
     * through this adapter yet.
     */
    public function disableSubscription(
        string $subscriptionCode,
        string $emailToken
    ): array {
        throw new RuntimeException(
            'OPay recurring subscriptions are not implemented yet.'
        );
    }

    /**
     * Generate OPay HMAC-SHA512 request signature.
     */
    private function generateSignature(array $payload): string
    {
        return hash_hmac(
            'sha512',
            json_encode(
                $payload,
                JSON_UNESCAPED_SLASHES
            ),
            $this->secretKey
        );
    }
}
