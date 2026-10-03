<?php

use App\Domains\Payment\Models\IncomingBankTransaction;

test('incoming bank transaction has expected fillable attributes', function () {
    $transaction = new IncomingBankTransaction();

    expect($transaction->getFillable())
        ->toContain(
            'business_id',
            'financial_account_id',
            'provider',
            'provider_transaction_id',
            'type',
            'amount',
            'currency',
            'sender_name',
            'sender_account',
            'sender_bank',
            'reference',
            'narration',
            'status',
            'occurred_at',
            'received_at',
            'metadata',
        );
});

test('incoming bank transaction defines expected casts', function () {
    $transaction = new IncomingBankTransaction();

    expect($transaction->getCasts())
        ->toMatchArray([
            'amount' => 'decimal:2',
            'occurred_at' => 'datetime',
            'received_at' => 'datetime',
            'metadata' => 'array',
        ]);
});