<?php

namespace App\Domains\Payment\Contracts;

use App\Domains\Payment\Models\FinancialAccount;

interface BankTransactionProvider
{
    public function verifyWebhook(
        array $payload,
        array $headers
    ): bool;

    public function normalizeTransaction(
        array $payload
    ): array;

    public function transactions(
        FinancialAccount $account,
        \DateTimeInterface $from,
        \DateTimeInterface $to
    ): array;
}