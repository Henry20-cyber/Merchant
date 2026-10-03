<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('business_join_requests', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->uuid('business_id');
            $table->foreignId('user_id')
                ->constrained('users')
                ->cascadeOnDelete();

            // The role the employee requested.
            // The employer can change this during approval.
            $table->string('requested_role')->nullable();

            $table->enum('status', [
                'pending',
                'approved',
                'rejected',
                'cancelled',
            ])->default('pending');

            $table->foreignId('reviewed_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamp('requested_at')->useCurrent();
            $table->timestamp('reviewed_at')->nullable();

            $table->text('rejection_reason')->nullable();

            $table->timestamps();

            $table->foreign('business_id')
                ->references('id')
                ->on('businesses')
                ->cascadeOnDelete();

            $table->index([
                'business_id',
                'status',
            ]);

            $table->index([
                'user_id',
                'status',
            ]);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('business_join_requests');
    }
};