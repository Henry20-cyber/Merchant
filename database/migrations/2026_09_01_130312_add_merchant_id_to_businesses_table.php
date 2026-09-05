<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('businesses', function (Blueprint $table) {
            $table->string('merchant_id', 10)
                ->nullable()
                ->after('id');
        });

        $businesses = DB::table('businesses')
            ->select('id')
            ->whereNull('merchant_id')
            ->get();

        foreach ($businesses as $business) {
            do {
                $merchantId = 'MCH-' . strtoupper(
                    Str::random(6)
                );
            } while (
                DB::table('businesses')
                    ->where('merchant_id', $merchantId)
                    ->exists()
            );

            DB::table('businesses')
                ->where('id', $business->id)
                ->update([
                    'merchant_id' => $merchantId,
                ]);
        }

        Schema::table('businesses', function (Blueprint $table) {
            $table->unique('merchant_id');
        });
    }

    public function down(): void
    {
        Schema::table('businesses', function (Blueprint $table) {
            $table->dropUnique(['merchant_id']);
            $table->dropColumn('merchant_id');
        });
    }
};