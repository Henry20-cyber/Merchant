<?php

namespace Tests\Feature\Payment;

use App\Domains\Payment\Contracts\PaymentGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PaystackGatewayTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set(
            'services.paystack.secret_key',
            'sk_test_account_automated_tests'
        );

        config()->set(
            'services.paystack.base_url',
            'https://api.paystack.co'
        );
    }

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

                    'gateway_response' => 'Successful',

                    'message' => null,

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

        expect($result['gateway_response'])
            ->toBe('Successful');

        expect($result['message'])
            ->toBeNull();

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
 * Paystack recurring subscription can be enabled again.
 */
public function test_paystack_subscription_can_be_enabled(): void
{
    Http::fake([
        'https://api.paystack.co/subscription/enable' =>
            Http::response([
                'status' => true,
                'message' => 'Subscription enabled successfully',
            ], 200),
    ]);

    $gateway = app(PaymentGateway::class);

    $result = $gateway->enableSubscription(
        'SUB_test_123',
        'EMAIL_TOKEN_test_123'
    );

    expect($result['success'])
        ->toBeTrue();

    Http::assertSent(function ($request) {
        return $request->url() ===
            'https://api.paystack.co/subscription/enable'

            && $request->method() === 'POST'

            && $request['code'] === 'SUB_test_123'

            && $request['token'] === 'EMAIL_TOKEN_test_123';
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


    public function test_paystack_verification_returns_numeric_customer_id(): void
    {
        Http::fake(['https://api.paystack.co/transaction/verify/*' => Http::response([
            'status' => true,
            'data' => [
                'status' => 'success', 'reference' => 'customer-id-reference',
                'amount' => 100000, 'currency' => 'NGN',
                'customer' => ['id' => 123456, 'customer_code' => 'CUS_numeric_test'],
                'authorization' => ['authorization_code' => 'AUTH_numeric_test'],
            ],
        ], 200)]);

        $result = app(PaymentGateway::class)->verify('customer-id-reference');
        expect($result['customer_id'])->toBe(123456)
            ->and($result['customer_code'])->toBe('CUS_numeric_test');
    }

    public function test_paystack_plan_can_be_fetched_by_plan_code(): void
    {
        Http::fake(['https://api.paystack.co/plan/PLN_test_123' => Http::response([
            'status' => true,
            'data' => ['id' => 987, 'plan_code' => 'PLN_test_123', 'name' => 'Account Monthly', 'amount' => 500000, 'currency' => 'NGN'],
        ], 200)]);

        $result = app(PaymentGateway::class)->fetchPlan('PLN_test_123');
        expect($result['success'])->toBeTrue()
            ->and($result['data']['id'])->toBe(987)
            ->and($result['data']['plan_code'])->toBe('PLN_test_123');
        Http::assertSent(fn ($request) => $request->method() === 'GET'
            && $request->url() === 'https://api.paystack.co/plan/PLN_test_123');
    }

    public function test_paystack_subscriptions_can_be_listed_with_supported_filters(): void
    {
        Http::fake(['https://api.paystack.co/subscription*' => Http::response([
            'status' => true,
            'data' => [['id' => 321, 'subscription_code' => 'SUB_list_test']],
            'meta' => ['total' => 1, 'skipped' => 0, 'perPage' => 25, 'page' => 2],
        ], 200)]);

        $result = app(PaymentGateway::class)->listSubscriptions([
            'perPage' => 25, 'page' => 2, 'customer' => 123456, 'plan' => 987,
        ]);
        expect($result['success'])->toBeTrue()
            ->and($result['data'])->toHaveCount(1)
            ->and($result['data'][0]['subscription_code'])->toBe('SUB_list_test')
            ->and($result['meta']['total'])->toBe(1);

        Http::assertSent(function ($request) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
            return $request->method() === 'GET'
                && parse_url($request->url(), PHP_URL_PATH) === '/subscription'
                && (int) ($query['perPage'] ?? 0) === 25
                && (int) ($query['page'] ?? 0) === 2
                && (int) ($query['customer'] ?? 0) === 123456
                && (int) ($query['plan'] ?? 0) === 987;
        });
    }

    public function test_paystack_subscription_can_be_fetched_by_subscription_code(): void
    {
        Http::fake(['https://api.paystack.co/subscription/SUB_fetch_test' => Http::response([
            'status' => true,
            'data' => [
                'id' => 654, 'subscription_code' => 'SUB_fetch_test', 'email_token' => 'EMAIL_fetch_test',
                'status' => 'active', 'customer' => ['id' => 123456, 'customer_code' => 'CUS_fetch_test'],
                'plan' => ['id' => 987, 'plan_code' => 'PLN_fetch_test'],
                'authorization' => ['authorization_code' => 'AUTH_fetch_test'],
            ],
        ], 200)]);

        $result = app(PaymentGateway::class)->fetchSubscription('SUB_fetch_test');
        expect($result['success'])->toBeTrue()
            ->and($result['data']['subscription_code'])->toBe('SUB_fetch_test')
            ->and($result['data']['customer']['id'])->toBe(123456);
    }

    public function test_subscription_list_rejects_unknown_filters_and_unbounded_page_size(): void
    {
        $gateway = app(PaymentGateway::class);
        expect(fn () => $gateway->listSubscriptions(['email' => 'customer@example.com']))
            ->toThrow(\RuntimeException::class, 'Unsupported Paystack subscription list filter');
        expect(fn () => $gateway->listSubscriptions(['perPage' => 1000]))
            ->toThrow(\RuntimeException::class, 'pagination values are invalid');
    }

    public function test_paystack_subscription_lookup_http_errors_are_normalized(): void
    {
        Http::fake([
            'https://api.paystack.co/plan/*' => Http::response(['message' => 'Server error'], 500),
            'https://api.paystack.co/subscription*' => Http::response(['message' => 'Server error'], 500),
        ]);
        $gateway = app(PaymentGateway::class);
        expect(fn () => $gateway->fetchPlan('PLN_error'))
            ->toThrow(\RuntimeException::class, 'Unable to fetch Paystack plan.');
        expect(fn () => $gateway->listSubscriptions())
            ->toThrow(\RuntimeException::class, 'Unable to list Paystack subscriptions.');
        expect(fn () => $gateway->fetchSubscription('SUB_error'))
            ->toThrow(\RuntimeException::class, 'Unable to fetch Paystack subscription.');
    }

}
