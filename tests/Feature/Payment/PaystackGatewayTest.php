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

                    'authorization' => [
                        'authorization_code' =>
                        'AUTH_test_123456',
                    ],

                    'customer' => [
                        'customer_code' =>
                        'CUS_test_123456',
                    ],
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

        expect($result['authorization_code'])
            ->toBe('AUTH_test_123456');

        expect($result['customer_code'])
            ->toBe('CUS_test_123456');

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

        expect(fn() => $gateway->initialize([
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

        expect(fn() => $gateway->verify(
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

        expect(fn() => $gateway->initialize([
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

        expect(fn() => $gateway->verify(
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

    /**
     * Paystack recurring subscription can be created.
     */
    public function test_paystack_subscription_can_be_created(): void
    {
        Http::fake([
            'https://api.paystack.co/subscription' =>
            Http::response([
                'status' => true,
                'message' => 'Subscription successfully created',
                'data' => [
                    'subscription_code' => 'SUB_test_123',
                    'email_token' => 'EMAIL_TOKEN_test_123',
                    'customer' => [
                        'customer_code' => 'CUS_test_123',
                    ],
                ],
            ], 200),
        ]);

        $gateway = app(PaymentGateway::class);

        $result = $gateway->createSubscription([
            'customer_code' => 'CUS_test_123',
            'plan_code' => 'PLN_test_123',
            'authorization_code' => 'AUTH_test_123',
        ]);

        expect($result['success'])
            ->toBeTrue();

        expect($result['subscription_code'])
            ->toBe('SUB_test_123');

        expect($result['customer_code'])
            ->toBe('CUS_test_123');

        expect($result['email_token'])
            ->toBe('EMAIL_TOKEN_test_123');

        Http::assertSent(function ($request) {
            return $request->url() ===
                'https://api.paystack.co/subscription'

                && $request->method() === 'POST'

                && $request['customer'] ===
                'CUS_test_123'

                && $request['plan'] ===
                'PLN_test_123'

                && $request['authorization'] ===
                'AUTH_test_123';
        });
    }

    /**
 * Paystack recurring subscription can be disabled.
 */
public function test_paystack_subscription_can_be_disabled(): void
{
    Http::fake([
        'https://api.paystack.co/subscription/disable' =>
            Http::response([
                'status' => true,
                'message' => 'Subscription disabled successfully',
            ], 200),
    ]);

    $gateway = app(PaymentGateway::class);

    $result = $gateway->disableSubscription(
        'SUB_test_123',
        'EMAIL_TOKEN_test_123'
    );

    expect($result['success'])
        ->toBeTrue();

    Http::assertSent(function ($request) {
        return $request->url() ===
            'https://api.paystack.co/subscription/disable'

            && $request->method() === 'POST'

            && $request['code'] ===
                'SUB_test_123'

            && $request['token'] ===
                'EMAIL_TOKEN_test_123';
    });
}

/**
 * Failed Paystack subscription creation is rejected.
 */
public function test_failed_subscription_creation_throws_exception(): void
{
    Http::fake([
        'https://api.paystack.co/subscription' =>
            Http::response([
                'status' => false,
                'message' => 'Invalid plan',
            ], 200),
    ]);

    $gateway = app(PaymentGateway::class);

    expect(fn () => $gateway->createSubscription([
        'customer_code' => 'CUS_test_123',
        'plan_code' => 'PLN_invalid',
        'authorization_code' => 'AUTH_test_123',
    ]))->toThrow(
        \RuntimeException::class,
        'Invalid plan'
    );
}

/**
 * Failed Paystack subscription disabling is rejected.
 */
public function test_failed_subscription_disabling_throws_exception(): void
{
    Http::fake([
        'https://api.paystack.co/subscription/disable' =>
            Http::response([
                'status' => false,
                'message' => 'Invalid token',
            ], 200),
    ]);

    $gateway = app(PaymentGateway::class);

    expect(fn () => $gateway->disableSubscription(
        'SUB_test_123',
        'INVALID_TOKEN'
    ))->toThrow(
        \RuntimeException::class,
        'Invalid token'
    );
}
}
