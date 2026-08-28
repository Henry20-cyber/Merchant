<?php

namespace Tests\Feature\Payment;

use App\Domains\Payment\Contracts\PaymentGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PaystackGatewayTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The gateway can be resolved from Laravel's container.
     */
    public function test_payment_gateway_can_be_resolved(): void
    {
        $gateway = app(PaymentGateway::class);

        expect($gateway)
            ->toBeInstanceOf(
                \App\Domains\Payment\Gateways\PaystackGateway::class
            );
    }

    /**
     * Paystack transaction initialization sends the correct data.
     */
    public function test_paystack_transaction_can_be_initialized(): void
    {
        Http::fake([
            'https://api.paystack.co/transaction/initialize' =>
                Http::response([
                    'status' => true,
                    'message' => 'Authorization URL created',
                    'data' => [
                        'authorization_url' =>
                            'https://checkout.paystack.com/test123',

                        'access_code' =>
                            'test_access_code',

                        'reference' =>
                            'merchantos-test-reference',
                    ],
                ], 200),
        ]);

        $gateway = app(PaymentGateway::class);

        $result = $gateway->initialize([
            'email' => 'customer@example.com',
            'amount' => 500000,
            'reference' => 'merchantos-test-reference',
            'callback_url' =>
                'https://merchantos.test/payment/callback',
            'metadata' => [
                'business_id' => 'business-123',
                'subscription_id' => 'subscription-123',
            ],
        ]);

        expect($result['success'])
            ->toBeTrue();

        expect($result['authorization_url'])
            ->toBe(
                'https://checkout.paystack.com/test123'
            );

        expect($result['access_code'])
            ->toBe('test_access_code');

        expect($result['reference'])
            ->toBe('merchantos-test-reference');

        Http::assertSent(function ($request) {
            return $request->url() ===
                'https://api.paystack.co/transaction/initialize'

                && $request->method() === 'POST'

                && $request['email'] ===
                    'customer@example.com'

                && $request['amount'] ===
                    500000

                && $request['reference'] ===
                    'merchantos-test-reference';
        });
    }

    /**
     * Paystack transaction verification works.
     */
    public function test_paystack_transaction_can_be_verified(): void
    {
        Http::fake([
            'https://api.paystack.co/transaction/verify/*' =>
                Http::response([
                    'status' => true,
                    'message' => 'Verification successful',

                    'data' => [
                        'status' => 'success',

                        'reference' =>
                            'merchantos-test-reference',

                        'amount' => 500000,

                        'currency' => 'NGN',
                    ],
                ], 200),
        ]);

        $gateway = app(PaymentGateway::class);

        $result = $gateway->verify(
            'merchantos-test-reference'
        );

        expect($result['success'])
            ->toBeTrue();

        expect($result['status'])
            ->toBe('success');

        expect($result['reference'])
            ->toBe('merchantos-test-reference');

        expect($result['amount'])
            ->toBe(500000);

        expect($result['currency'])
            ->toBe('NGN');

        Http::assertSent(function ($request) {
            return str_contains(
                $request->url(),
                '/transaction/verify/'
            );
        });
    }

    /**
     * Failed transaction initialization is rejected.
     */
    public function test_failed_transaction_initialization_throws_exception(): void
    {
        Http::fake([
            'https://api.paystack.co/transaction/initialize' =>
                Http::response([
                    'status' => false,
                    'message' => 'Invalid amount',
                ], 200),
        ]);

        $gateway = app(PaymentGateway::class);

        expect(fn () => $gateway->initialize([
            'email' => 'customer@example.com',
            'amount' => 500000,
            'reference' => 'merchantos-test-reference',
        ]))->toThrow(
            \RuntimeException::class,
            'Invalid amount'
        );
    }

    /**
     * Failed transaction verification is rejected.
     */
    public function test_failed_transaction_verification_throws_exception(): void
    {
        Http::fake([
            'https://api.paystack.co/transaction/verify/*' =>
                Http::response([
                    'status' => false,
                    'message' => 'Transaction not found',
                ], 200),
        ]);

        $gateway = app(PaymentGateway::class);

        expect(fn () => $gateway->verify(
            'invalid-reference'
        ))->toThrow(
            \RuntimeException::class,
            'Transaction not found'
        );
    }

    /**
     * HTTP errors during initialization are converted
     * into application-level exceptions.
     */
    public function test_http_error_during_initialization_is_handled(): void
    {
        Http::fake([
            'https://api.paystack.co/transaction/initialize' =>
                Http::response([
                    'message' => 'Server error',
                ], 500),
        ]);

        $gateway = app(PaymentGateway::class);

        expect(fn () => $gateway->initialize([
            'email' => 'customer@example.com',
            'amount' => 500000,
            'reference' => 'merchantos-test-reference',
        ]))->toThrow(
            \RuntimeException::class,
            'Unable to initialize Paystack transaction.'
        );
    }

    /**
     * HTTP errors during verification are handled.
     */
    public function test_http_error_during_verification_is_handled(): void
    {
        Http::fake([
            'https://api.paystack.co/transaction/verify/*' =>
                Http::response([
                    'message' => 'Server error',
                ], 500),
        ]);

        $gateway = app(PaymentGateway::class);

        expect(fn () => $gateway->verify(
            'merchantos-test-reference'
        ))->toThrow(
            \RuntimeException::class,
            'Unable to verify Paystack transaction.'
        );
    }

    /**
     * Metadata is sent to Paystack during initialization.
     */
    public function test_metadata_is_sent_to_paystack(): void
    {
        Http::fake([
            'https://api.paystack.co/transaction/initialize' =>
                Http::response([
                    'status' => true,
                    'data' => [
                        'authorization_url' =>
                            'https://checkout.paystack.com/test',

                        'access_code' =>
                            'test_access_code',

                        'reference' =>
                            'merchantos-test-reference',
                    ],
                ], 200),
        ]);

        $gateway = app(PaymentGateway::class);

        $metadata = [
            'business_id' => 'business-123',
            'plan_id' => 'plan-123',
            'subscription_id' => 'subscription-123',
        ];

        $gateway->initialize([
            'email' => 'customer@example.com',
            'amount' => 2000000,
            'reference' => 'merchantos-test-reference',
            'metadata' => $metadata,
        ]);

        Http::assertSent(function ($request) use ($metadata) {
            return $request['metadata'] === $metadata;
        });
    }
}
