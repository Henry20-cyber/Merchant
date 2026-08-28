<?php

namespace App\Domains\Payment\Contracts;

interface PaymentGateway
{
    /**
     * Initialize a payment transaction.
     *
     * @param array{
     *     email: string,
     *     amount: int,
     *     reference: string,
     *     callback_url?: string|null,
     *     metadata?: array|null
     * } $data
     *
     * @return array{
     *     success: bool,
     *     authorization_url: string|null,
     *     access_code: string|null,
     *     reference: string|null,
     *     raw: array
     * }
     */
    public function initialize(array $data): array;

    /**
     * Verify a payment transaction.
     *
     * @return array{
     *     success: bool,
     *     status: string|null,
     *     reference: string|null,
     *     amount: int|null,
     *     currency: string|null,
     *     raw: array
     * }
     */
    public function verify(string $reference): array;
}

