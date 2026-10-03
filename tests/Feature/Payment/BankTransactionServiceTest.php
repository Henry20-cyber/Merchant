<?php

namespace Tests\Feature\Payment;

use App\Domains\Organization\Models\Business;
use App\Domains\Payment\Models\FinancialAccount;
use App\Domains\Payment\Models\IncomingBankTransaction;
use App\Domains\Payment\Services\BankTransactionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BankTransactionServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_records_an_incoming_bank_transaction(): void
    {
        $business = Business::factory()->create();

        $account = FinancialAccount::create([
            'business_id' => $business->id,
            'provider' => 'opay',
            'name' => 'Main OPay Account',
            'account_identifier' => '09012345678',
            'currency' => 'NGN',
            'is_active' => true,
        ]);

        $service = app(BankTransactionService::class);

        $transaction = $service->record(
            $business,
            $account,
            [
                'provider' => 'opay',
                'provider_transaction_id' => 'OPAY-TXN-001',
                'type' => 'credit',
                'amount' => '3200.00',
                'currency' => 'NGN',
                'sender_name' => 'John Doe',
                'sender_account' => '0123456789',
                'sender_bank' => 'GTBank',
                'reference' => 'MOS-REF-001',
                'narration' => 'Payment for goods',
                'status' => 'success',
                'occurred_at' => now(),
                'metadata' => [
                    'source' => 'opay_webhook',
                ],
            ]
        );

        expect($transaction)
            ->toBeInstanceOf(
                IncomingBankTransaction::class
            );

        $this->assertDatabaseHas(
            'incoming_bank_transactions',
            [
                'id' => $transaction->id,
                'business_id' => $business->id,
                'financial_account_id' => $account->id,
                'provider' => 'opay',
                'provider_transaction_id' => 'OPAY-TXN-001',
                'amount' => '3200.00',
            ]
        );
    }

    public function test_it_returns_existing_transaction_when_webhook_is_replayed(): void
    {
        $business = Business::factory()->create();

        $account = FinancialAccount::create([
            'business_id' => $business->id,
            'provider' => 'opay',
            'name' => 'Main OPay Account',
            'account_identifier' => '09012345678',
            'currency' => 'NGN',
            'is_active' => true,
        ]);

        $service = app(BankTransactionService::class);

        $data = [
            'provider' => 'opay',
            'provider_transaction_id' => 'OPAY-TXN-DUPLICATE',
            'type' => 'credit',
            'amount' => '5000.00',
            'currency' => 'NGN',
            'sender_name' => 'Jane Doe',
            'status' => 'success',
        ];

        $first = $service->record(
            $business,
            $account,
            $data
        );

        $second = $service->record(
            $business,
            $account,
            $data
        );

        expect($second->id)
            ->toBe($first->id);

        expect(
            IncomingBankTransaction::query()
                ->where(
                    'provider_transaction_id',
                    'OPAY-TXN-DUPLICATE'
                )
                ->count()
        )->toBe(1);
    }

    public function test_it_rejects_a_financial_account_from_another_business(): void
    {
        $businessA = Business::factory()->create();
        $businessB = Business::factory()->create();

        $accountB = FinancialAccount::create([
            'business_id' => $businessB->id,
            'provider' => 'opay',
            'name' => 'Business B OPay Account',
            'currency' => 'NGN',
            'is_active' => true,
        ]);

        $service = app(BankTransactionService::class);

        expect(fn () => $service->record(
            $businessA,
            $accountB,
            [
                'provider' => 'opay',
                'provider_transaction_id' => 'OPAY-CROSS-TENANT',
                'amount' => '1000.00',
            ]
        ))->toThrow(
            \RuntimeException::class,
            'The financial account does not belong to the supplied business.'
        );

        $this->assertDatabaseCount(
            'incoming_bank_transactions',
            0
        );
    }
}
