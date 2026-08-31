<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('receipts', function (Blueprint $table) {
            /*
             * Immutable customer-facing representation of the sale
             * at the moment the receipt was issued.
             *
             * PostgreSQL JSONB allows us to preserve structured
             * historical receipt data while still allowing the
             * renderer to consume it naturally.
             */
            $table->jsonb('snapshot')
                ->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('receipts', function (Blueprint $table) {
            $table->dropColumn('snapshot');
        });
    }
};