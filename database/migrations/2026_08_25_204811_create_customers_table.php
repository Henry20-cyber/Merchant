<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->uuid('business_id');

            /*
             * Human-facing identifier.
             *
             * Example:
             * CUS-000001
             */
            $table->string('customer_number', 30);

            /*
             * Customer information is intentionally optional.
             *
             * A business can maintain a customer record
             * without requiring the customer to disclose
             * personal information.
             */
            $table->string('name')->nullable();

            $table->string('phone', 30)->nullable();

            $table->string('status', 20)->default('active');

            $table->timestamps();

            $table->softDeletes();

            /*
             * Tenant-scoped lookup/indexes.
             */
            $table->index('business_id');

            $table->index([
                'business_id',
                'status',
            ]);

            /*
             * Customer numbers only need to be unique
             * within a business.
             */
            $table->unique([
                'business_id',
                'customer_number',
            ]);

            $table->foreign('business_id')
                ->references('id')
                ->on('businesses')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customers');
    }
};