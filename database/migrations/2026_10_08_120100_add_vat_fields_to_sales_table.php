<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->renameColumn('tax', 'vat_amount');

            $table->decimal('taxable_amount', 14, 2)
                ->default(0)
                ->after('discount');

            $table->boolean('vat_enabled')
                ->default(false)
                ->after('taxable_amount');

            $table->decimal('vat_rate', 5, 2)
                ->default(0)
                ->after('vat_enabled');
        });

        /*
         * Preserve historical totals while migrating the old tax
         * field into VAT terminology.
         */
        DB::statement('
            UPDATE sales
            SET taxable_amount = subtotal - discount,
                vat_enabled = CASE
                    WHEN vat_amount > 0 THEN true
                    ELSE false
                END,
                vat_rate = CASE
                    WHEN vat_amount > 0 THEN 7.50
                    ELSE 0.00
                END
        ');
    }

    public function down(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->renameColumn('vat_amount', 'tax');

            $table->dropColumn([
                'taxable_amount',
                'vat_enabled',
                'vat_rate',
            ]);
        });
    }
};
