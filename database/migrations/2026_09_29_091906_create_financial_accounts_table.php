<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('financial_accounts', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->foreignUuid('business_id')
                ->constrained('businesses')
                ->cascadeOnUpdate()
                ->restrictOnDelete();

            /*
             * Provider supplying transaction data.
             *
             * Examples:
             * opay
             * moniepoint
             * gtbank
             * etc.
             */
            $table->string('provider', 50);

            /*
             * Human-readable account name.
             *
             * Example:
             * "Main OPay Account"
             */
            $table->string('name');

            /*
             * Provider's account identifier.
             *
             * This might be an account number, wallet ID,
             * merchant ID, etc., depending on the provider.
             */
            $table->string('account_identifier')->nullable();

            $table->string('currency', 3)
                ->default('NGN');

            /*
             * Provider-specific connection/configuration.
             *
             * NEVER store raw secrets here.
             */
            $table->jsonb('metadata')->nullable();

            $table->boolean('is_active')
                ->default(true);

            $table->timestamps();

            $table->index([
                'business_id',
                'provider',
            ]);

            $table->index([
                'business_id',
                'is_active',
            ]);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('financial_accounts');
    }
};