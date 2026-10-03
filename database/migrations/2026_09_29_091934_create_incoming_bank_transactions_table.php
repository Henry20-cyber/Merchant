<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('incoming_bank_transactions', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->foreignUuid('business_id')
                ->constrained('businesses')
                ->cascadeOnUpdate()
                ->restrictOnDelete();

            $table->foreignUuid('financial_account_id')
                ->constrained('financial_accounts')
                ->cascadeOnUpdate()
                ->restrictOnDelete();

            /*
             * Provider identity.
             *
             * Example:
             * opay
             * moniepoint
             */
            $table->string('provider', 50);

            /*
             * Immutable transaction identity supplied
             * by the provider.
             */
            $table->string('provider_transaction_id', 255);

            /*
             * credit = money came into the merchant account.
             */
            $table->string('type', 20)
                ->default('credit');

            $table->decimal('amount', 14, 2);

            $table->string('currency', 3)
                ->default('NGN');

            /*
             * Information about the person who sent the money.
             */
            $table->string('sender_name')->nullable();
            $table->string('sender_account')->nullable();
            $table->string('sender_bank')->nullable();

            /*
             * References supplied by the provider/customer.
             */
            $table->string('reference')->nullable();
            $table->text('narration')->nullable();

            /*
             * Provider transaction status.
             */
            $table->string('status', 50)
                ->default('received');

            /*
             * When the transaction occurred at the provider.
             */
            $table->timestamp('occurred_at')->nullable();

            /*
             * When MerchantOS received the transaction.
             */
            $table->timestamp('received_at')->nullable();

            /*
             * Raw provider response.
             *
             * Extremely useful for debugging and reconciliation.
             */
            $table->jsonb('metadata')->nullable();

            $table->timestamps();

            /*
             * CRITICAL:
             *
             * The same provider transaction must never
             * be inserted twice.
             */
            $table->unique(
                [
                    'provider',
                    'provider_transaction_id',
                ],
                'incoming_bank_transactions_provider_transaction_unique'
            );

            $table->index([
                'business_id',
                'financial_account_id',
                'occurred_at',
            ]);

            $table->index([
                'business_id',
                'status',
            ]);

            $table->index([
                'business_id',
                'reference',
            ]);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('incoming_bank_transactions');
    }
};