<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('business_join_requests', function (Blueprint $table) {
            $table->unsignedBigInteger('requested_role_id')
                ->nullable()
                ->after('user_id');

            $table->foreign('requested_role_id')
                ->references('id')
                ->on('roles')
                ->nullOnDelete();

            $table->index('requested_role_id');
        });
    }

    public function down(): void
    {
        Schema::table('business_join_requests', function (Blueprint $table) {
            $table->dropForeign([
                'requested_role_id',
            ]);

            $table->dropIndex([
                'requested_role_id',
            ]);

            $table->dropColumn('requested_role_id');
        });
    }
};