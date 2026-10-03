<?php

use App\Domains\Organization\Models\Business;
use App\Domains\Payment\Models\FinancialAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('opay webhook records an incoming transaction', function () {
    $business = Business::factory()->create();

    $financialAccount = FinancialAccount::create([
        'business_id' => $business->id,
        'provider' => 'opay',
        'name' => 'Chioma Baby Stores OPay',
        'account_identifier' => '6126390625',
        'currency' => 'NGN',
        'is_active' => true,
    ]);

    $payload = [
        'status' => 'SUCCESS',
        'transactionId' => 'OPAY-TX-001',
        'reference' => 'OPAY-REF-001',
        'depositCode' => '6126390625',
        'refId' => 'refer1200000850',
        'currency' => 'NGN',
        'depositAmount' => '3200.00',
        'depositTime' => '1663153219000',
        'formatDateTime' => '2026-09-29 10:00:00',
        'orderNo' => 'OPAY-ORDER-001',
        'notes' => 'Payment for goods',
    ];

    $response = $this
        ->withHeaders([
            'X-Opay-Tranid' => 'OPAY-TX-001',
            'merchantId' => config('services.opay.merchant_id'),
        ])
        ->postJson('/api/webhooks/opay', $payload);

    $response
        ->assertOk()
        ->assertJson([
            'code' => '00000',
            'message' => 'SUCCESSFUL',
        ]);

    $this->assertDatabaseHas(
        'incoming_bank_transactions',
        [
            'business_id' => $business->id,
            'financial_account_id' => $financialAccount->id,
            'provider' => 'opay',
            'provider_transaction_id' => 'OPAY-TX-001',
            'type' => 'credit',
            'amount' => '3200.00',
            'currency' => 'NGN',
            'reference' => 'OPAY-REF-001',
            'status' => 'success',
        ]
    );
});

test('opay webhook is idempotent when the same transaction is delivered twice', function () {
    $business = Business::factory()->create();

    $financialAccount = FinancialAccount::create([
        'business_id' => $business->id,
        'provider' => 'opay',
        'name' => 'Chioma Baby Stores OPay',
        'account_identifier' => '6126390625',
        'currency' => 'NGN',
        'is_active' => true,
    ]);

    $payload = [
        'status' => 'SUCCESS',
        'transactionId' => 'OPAY-TX-REPLAY-001',
        'reference' => 'OPAY-REF-REPLAY-001',
        'depositCode' => '6126390625',
        'refId' => 'refer1200000850',
        'currency' => 'NGN',
        'depositAmount' => '5000.00',
        'depositTime' => '1663153219000',
        'formatDateTime' => '2026-09-29 11:00:00',
        'orderNo' => 'OPAY-ORDER-REPLAY-001',
        'notes' => 'Replay test',
    ];

    $headers = [
        'X-Opay-Tranid' => 'OPAY-TX-REPLAY-001',
        'merchantId' => config('services.opay.merchant_id'),
    ];

    $this
        ->withHeaders($headers)
        ->postJson('/api/webhooks/opay', $payload)
        ->assertOk();

    $this
        ->withHeaders($headers)
        ->postJson('/api/webhooks/opay', $payload)
        ->assertOk();

    expect(
        \App\Domains\Payment\Models\IncomingBankTransaction::query()
            ->where(
                'provider_transaction_id',
                'OPAY-TX-REPLAY-001'
            )
            ->count()
    )->toBe(1);
});

test('opay webhook rejects an invalid transaction header', function () {
    $payload = [
        'status' => 'SUCCESS',
        'transactionId' => 'OPAY-TX-INVALID',
        'depositCode' => '6126390625',
        'currency' => 'NGN',
        'depositAmount' => '3200.00',
    ];

    $response = $this
        ->withHeaders([
            'X-Opay-Tranid' => 'SOME-OTHER-TRANSACTION',
            'merchantId' => config('services.opay.merchant_id'),
        ])
        ->postJson('/api/webhooks/opay', $payload);

    $response
        ->assertUnauthorized()
        ->assertJson([
            'code' => '00001',
        ]);
});
