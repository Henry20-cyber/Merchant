<?php

use App\Domains\Payment\Providers\Opay\OpayBankTransactionProvider;
use Tests\TestCase;

uses(TestCase::class);

test('opay digital wallet webhook is normalized into merchantos format', function () {
    $provider = new OpayBankTransactionProvider();

    $payload = [
        'status' => 'SUCCESS',
        'transactionId' => '20220319702512368471543808',
        'reference' => '220617145660907314088',
        'depositCode' => '6126390625',
        'refId' => 'refer1200000850',
        'currency' => 'NGN',
        'depositAmount' => '800.00',
        'depositTime' => '1663153219000',
        'formatDateTime' => '2026-09-29 10:00:00',
        'orderNo' => '230221010175463341',
        'notes' => 'Payment for goods',
    ];

    $result = $provider->normalizeTransaction($payload);

    expect($result)
        ->toMatchArray([
            'provider' => 'opay',
            'provider_transaction_id' =>
                '20220319702512368471543808',
            'type' => 'credit',
            'amount' => '800.00',
            'currency' => 'NGN',
            'sender_name' => null,
            'sender_account' => null,
            'sender_bank' => null,
            'reference' => '220617145660907314088',
            'narration' => 'Payment for goods',
            'status' => 'success',
            'occurred_at' => '2026-09-29 10:00:00',
        ]);

    expect($result['metadata'])
        ->toMatchArray([
            'provider' => 'opay',
            'deposit_code' => '6126390625',
            'ref_id' => 'refer1200000850',
            'deposit_time' => '1663153219000',
            'order_no' => '230221010175463341',
            'notes' => 'Payment for goods',
        ]);
});

test('opay digital wallet webhook verifies matching transaction header', function () {
    $provider = new OpayBankTransactionProvider();

    $payload = [
        'transactionId' => 'TX-123456',
    ];

    $headers = [
        'x-opay-tranid' => 'TX-123456',
        'merchantId' => config('services.opay.merchant_id'),
    ];

    expect(
        $provider->verifyWebhook($payload, $headers)
    )->toBeTrue();
});

test('opay digital wallet webhook rejects mismatched transaction header', function () {
    $provider = new OpayBankTransactionProvider();

    $payload = [
        'transactionId' => 'TX-123456',
    ];

    $headers = [
        'x-opay-tranid' => 'TX-DIFFERENT',
        'merchantId' => config('services.opay.merchant_id'),
    ];

    expect(
        $provider->verifyWebhook($payload, $headers)
    )->toBeFalse();
});