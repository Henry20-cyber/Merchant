<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('usage_records', function (Blueprint $table) {
            /*
             * Usage record identifier.
             */
            $table->uuid('id')->primary();

            /*
             * The MerchantOS business that owns this usage.
             */
            $table->foreignUuid('business_id')
                ->constrained('businesses')
                ->cascadeOnUpdate()
                ->restrictOnDelete();

            /*
             * Machine-readable usage metric.
             *
             * Examples:
             *
             * sales.transactions
             * customers.count
             * products.count
             * users.count
             * branches.count
             */
            $table->string('metric', 100);

            /*
             * Number of units consumed.
             *
             * Resource counts may use the same structure later,
             * while metered operations such as transactions can
             * increment this value.
             */
            $table->unsignedInteger('quantity')
                ->default(0);

            /*
             * Inclusive beginning of the usage period.
             */
            $table->timestamp('period_start');

            /*
             * Exclusive end of the usage period.
             *
             * We use [period_start, period_end) to avoid
             * boundary problems between billing periods.
             */
            $table->timestamp('period_end');

            $table->timestamps();

            /*
             * A business can only have one usage bucket for a
             * particular metric within a particular period.
             */
            $table->unique([
                'business_id',
                'metric',
                'period_start',
                'period_end',
            ]);

            /*
             * Common usage queries.
             */
            $table->index([
                'business_id',
                'metric',
            ]);

            $table->index([
                'period_start',
                'period_end',
            ]);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('usage_records');
    }
};