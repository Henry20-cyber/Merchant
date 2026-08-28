<?php

namespace Tests\Feature\Payment;

use App\Domains\Payment\Services\PaymentConfirmationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class PaystackWebhookTest extends TestCase
{
    use RefreshDatabase;

    private string $secret = 'test-paystack-webhook-secret';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.paystack.webhook_secret' => $this->secret,
        ]);
    }

    public function test_webhook_rejects_missing_signature(): void
    {
        $response = $this->postJson(
            '/api/webhooks/paystack',
            [
                'event' => 'charge.success',
                'data' => [
                    'reference' => 'MERCHANTOS-TEST-001',
                ],
            ]
        );

        $response
            ->assertStatus(401)
            ->assertJson([
                'success' => false,
                'message' => 'Missing Paystack signature.',
            ]);
    }

    public function test_webhook_rejects_invalid_signature(): void
    {
        $payload = [
            'event' => 'charge.success',
            'data' => [
                'reference' => 'MERCHANTOS-TEST-002',
            ],
        ];

        $body = json_encode(
            $payload,
            JSON_UNESCAPED_SLASHES
        );

        $response = $this->call(
            'POST',
            '/api/webhooks/paystack',
            [],
            [],
            [],
            [
                'HTTP_X_PAYSTACK_SIGNATURE' => 'invalid-signature',
                'CONTENT_TYPE' => 'application/json',
            ],
            $body
        );

        $response
            ->assertStatus(401)
            ->assertJson([
                'success' => false,
                'message' => 'Invalid Paystack signature.',
            ]);
    }

    public function test_webhook_accepts_valid_signature(): void
    {
        $payload = [
            'event' => 'charge.success',
            'data' => [
                'reference' => 'MERCHANTOS-TEST-003',
            ],
        ];

        $confirmationService = Mockery::mock(
            PaymentConfirmationService::class
        );

        $confirmationService
            ->shouldReceive('confirm')
            ->once()
            ->with('MERCHANTOS-TEST-003');

        $this->app->instance(
            PaymentConfirmationService::class,
            $confirmationService
        );

        $response = $this->postSignedWebhook($payload);

        $response
            ->assertStatus(200)
            ->assertJson([
                'success' => true,
            ]);
    }

    public function test_non_success_event_is_acknowledged_without_confirmation(): void
    {
        $confirmationService = Mockery::mock(
            PaymentConfirmationService::class
        );

        $confirmationService
            ->shouldReceive('confirm')
            ->never();

        $this->app->instance(
            PaymentConfirmationService::class,
            $confirmationService
        );

        $payload = [
            'event' => 'charge.failed',
            'data' => [
                'reference' => 'MERCHANTOS-TEST-004',
            ],
        ];

        $response = $this->postSignedWebhook($payload);

        $response
            ->assertStatus(200)
            ->assertJson([
                'success' => true,
            ]);
    }

    public function test_charge_success_requires_payment_reference(): void
    {
        $payload = [
            'event' => 'charge.success',
            'data' => [],
        ];

        $response = $this->postSignedWebhook($payload);

        $response
            ->assertStatus(422)
            ->assertJson([
                'success' => false,
                'message' => 'Payment reference missing.',
            ]);
    }

    public function test_webhook_does_not_require_authenticated_user(): void
    {
        $payload = [
            'event' => 'charge.success',
            'data' => [
                'reference' => 'MERCHANTOS-TEST-005',
            ],
        ];

        $confirmationService = Mockery::mock(
            PaymentConfirmationService::class
        );

        $confirmationService
            ->shouldReceive('confirm')
            ->once()
            ->with('MERCHANTOS-TEST-005');

        $this->app->instance(
            PaymentConfirmationService::class,
            $confirmationService
        );

        $this->assertGuest();

        $response = $this->postSignedWebhook($payload);

        $response->assertStatus(200);
    }

    /**
     * Send a webhook using the exact raw JSON body that
     * was used to generate the HMAC signature.
     */
    private function postSignedWebhook(array $payload)
    {
        $body = json_encode(
            $payload,
            JSON_UNESCAPED_SLASHES
        );

        $signature = hash_hmac(
            'sha512',
            $body,
            $this->secret
        );

        return $this->call(
            'POST',
            '/api/webhooks/paystack',
            [],
            [],
            [],
            [
                'HTTP_X_PAYSTACK_SIGNATURE' => $signature,
                'CONTENT_TYPE' => 'application/json',
            ],
            $body
        );
    }
}