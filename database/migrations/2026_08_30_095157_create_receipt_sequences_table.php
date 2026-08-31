<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('receipt_sequences', function (Blueprint $table) {
            /*
             * One sequence belongs to one MerchantOS business.
             *
             * UUID is used because businesses use UUID primary keys.
             */
            $table->foreignUuid('business_id')
                ->primary()
                ->constrained('businesses')
                ->cascadeOnUpdate()
                ->restrictOnDelete();

            /*
             * The next receipt number to allocate.
             *
             * Example:
             *
             * next_number = 18
             *
             * means the next receipt will be:
             *
             * RCPT-000018
             */
            $table->unsignedBigInteger('next_number')
                ->default(1);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('receipt_sequences');
    }
};