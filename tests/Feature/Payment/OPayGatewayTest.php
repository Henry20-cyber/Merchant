<?php

namespace Tests\Unit\Domains\Payment\Gateways;

use App\Domains\Payment\Gateways\OPayGateway;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class OPayGatewayTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set(
            'services.opay.public_key',
            'OPAY_PUBLIC_TEST'
        );

        config()->set(
            'services.opay.secret_key',
            'OPAY_SECRET_TEST'
        );

        config()->set(
            'services.opay.merchant_id',
            'MERCHANT_TEST'
        );

        config()->set(
            'services.opay.base_url',
            'https://testapi.opaycheckout.com'
        );

        config()->set(
            'services.opay.country',
            'NG'
        );

        config()->set(
            'services.opay.currency',
            'NGN'
        );

        config()->set(
            'services.opay.return_url',
            'http://localhost:5173/payment/opay/callback'
        );

        config()->set(
            'services.opay.callback_url',
            'http://localhost:8000/api/webhooks/opay'
        );

        config()->set(
            'services.opay.cancel_url',
            'http://localhost:5173/payment/opay/cancel'
        );
    }

    public function test_initialize_sends_correct_cashier_request(): void
    {
        Http::fake([
            'https://testapi.opaycheckout.com/*' => Http::response([
                'code' => '00000',
                'message' => 'SUCCESSFUL',
                'data' => [
                    'reference' => 'MERCHANTOS-OPAY-TEST',
                    'orderNo' => 'OPAY-ORDER-001',
                    'cashierUrl' =>
                        'https://testcashier.opaycheckout.com/test',
                ],
            ], 200),
        ]);

        $gateway = new OPayGateway();

        $result = $gateway->initialize([
            'email' => 'merchant@example.com',
            'amount' => 300000,
            'reference' => 'MERCHANTOS-OPAY-TEST',
            'callback_url' =>
                'http://localhost:5173/payment/opay/callback',
            'metadata' => [
                'product_name' => 'MerchantOS Starter',
                'product_description' =>
                    'MerchantOS subscription payment',
            ],
        ]);

        $this->assertTrue($result['success']);

        $this->assertSame(
            'https://testcashier.opaycheckout.com/test',
            $result['authorization_url']
        );

        $this->assertSame(
            'OPAY-ORDER-001',
            $result['access_code']
        );

        $this->assertSame(
            'MERCHANTOS-OPAY-TEST',
            $result['reference']
        );

        Http::assertSent(function ($request) {
            return $request->url() ===
                'https://testapi.opaycheckout.com/api/v1/international/cashier/create'

                && $request->header('Authorization')[0] ===
                    'Bearer OPAY_PUBLIC_TEST'

                && $request->header('MerchantId')[0] ===
                    'MERCHANT_TEST'

                && $request['country'] === 'NG'

                && $request['reference'] ===
                    'MERCHANTOS-OPAY-TEST'

                && $request['amount']['total'] === 300000

                && $request['amount']['currency'] === 'NGN'

                && $request['returnUrl'] ===
                    'http://localhost:5173/payment/opay/callback'

                && $request['callbackUrl'] ===
                    'http://localhost:8000/api/webhooks/opay'

                && $request['cancelUrl'] ===
                    'http://localhost:5173/payment/opay/cancel'

                && $request['customerVisitSource'] ===
                    'BROWSER'

                && $request['expireAt'] === 30

                && $request['userInfo']['userEmail'] ===
                    'merchant@example.com'

                && $request['product']['name'] ===
                    'MerchantOS Starter';
        });
    }

    public function test_verify_sends_hmac_sha512_signature(): void
    {
        $payload = [
            'reference' => 'MERCHANTOS-OPAY-TEST',
            'country' => 'NG',
        ];

        $json = json_encode(
            $payload,
            JSON_UNESCAPED_SLASHES
        );

        $expectedSignature = hash_hmac(
            'sha512',
            $json,
            'OPAY_SECRET_TEST'
        );

        Http::fake([
            'https://testapi.opaycheckout.com/*' => Http::response([
                'code' => '00000',
                'message' => 'SUCCESSFUL',
                'data' => [
                    'reference' =>
                        'MERCHANTOS-OPAY-TEST',

                    'status' => 'SUCCESS',

                    'amount' => [
                        'total' => 300000,
                        'currency' => 'NGN',
                    ],
                ],
            ], 200),
        ]);

        $gateway = new OPayGateway();

        $result = $gateway->verify(
            'MERCHANTOS-OPAY-TEST'
        );

        $this->assertTrue($result['success']);

        $this->assertSame(
            'SUCCESS',
            $result['status']
        );

        $this->assertSame(
            'MERCHANTOS-OPAY-TEST',
            $result['reference']
        );

        $this->assertSame(
            300000,
            $result['amount']
        );

        $this->assertSame(
            'NGN',
            $result['currency']
        );

        Http::assertSent(function ($request) use (
            $expectedSignature
        ) {
            return $request->url() ===
                'https://testapi.opaycheckout.com/api/v1/international/cashier/status'

                && $request->header('Authorization')[0] ===
                    'Bearer ' . $expectedSignature

                && $request->header('MerchantId')[0] ===
                    'MERCHANT_TEST'

                && $request['reference'] ===
                    'MERCHANTOS-OPAY-TEST'

                && $request['country'] === 'NG';
        });
    }

    public function test_verify_returns_false_for_failed_transaction(): void
    {
        Http::fake([
            'https://testapi.opaycheckout.com/*' => Http::response([
                'code' => '00000',
                'message' => 'SUCCESSFUL',
                'data' => [
                    'reference' =>
                        'MERCHANTOS-OPAY-TEST',

                    'status' => 'FAIL',

                    'amount' => [
                        'total' => 300000,
                        'currency' => 'NGN',
                    ],
                ],
            ], 200),
        ]);

        $gateway = new OPayGateway();

        $result = $gateway->verify(
            'MERCHANTOS-OPAY-TEST'
        );

        $this->assertFalse($result['success']);

        $this->assertSame(
            'FAIL',
            $result['status']
        );
    }

    public function test_initialize_throws_when_opay_rejects_request(): void
    {
        Http::fake([
            'https://testapi.opaycheckout.com/*' => Http::response([
                'code' => '02004',
                'message' =>
                    'Payment reference already exists.',
            ], 200),
        ]);

        $gateway = new OPayGateway();

        $this->expectException(
            RuntimeException::class
        );

        $gateway->initialize([
            'email' => 'merchant@example.com',
            'amount' => 300000,
            'reference' => 'DUPLICATE-REFERENCE',
        ]);
    }

    public function test_verify_throws_when_opay_rejects_request(): void
    {
        Http::fake([
            'https://testapi.opaycheckout.com/*' => Http::response([
                'code' => '02004',
                'message' =>
                    'Transaction not found.',
            ], 200),
        ]);

        $gateway = new OPayGateway();

        $this->expectException(
            RuntimeException::class
        );

        $gateway->verify(
            'UNKNOWN-REFERENCE'
        );
    }

    public function test_create_subscription_is_not_implemented(): void
    {
        $gateway = new OPayGateway();

        $this->expectException(
            RuntimeException::class
        );

        $gateway->createSubscription([
            'customer_code' => 'CUSTOMER',
            'plan_code' => 'PLAN',
        ]);
    }

    public function test_disable_subscription_is_not_implemented(): void
    {
        $gateway = new OPayGateway();

        $this->expectException(
            RuntimeException::class
        );

        $gateway->disableSubscription(
            'SUBSCRIPTION',
            'TOKEN'
        );
    }
}