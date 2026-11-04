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
        Schema::create('subscription_checkout_recoveries', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('business_id')->constrained('businesses')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignUuid('subscription_id')->nullable()->constrained('subscriptions')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignUuid('plan_id')->nullable()->constrained('subscription_plans')->cascadeOnUpdate()->restrictOnDelete();
            $table->jsonb('purchase_terms');
            $table->string('status', 32)->default('pending');
            $table->string('last_provider_status', 32)->nullable();
            $table->string('retry_reason', 32)->nullable();
            $table->uuid('winning_payment_id')->nullable();
            $table->timestamp('manual_review_at')->nullable();
            $table->text('manual_review_reason')->nullable();
            $table->text('manual_review_resolution')->nullable();
            $table->timestamp('manual_review_resolved_at')->nullable();
            $table->foreignId('manual_review_resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['business_id', 'status', 'created_at']);
            $table->index(['business_id', 'plan_id', 'status']);
        });

        Schema::table('payments', function (Blueprint $table): void {
            $table->foreignUuid('checkout_recovery_id')
                ->nullable()
                ->constrained('subscription_checkout_recoveries')
                ->cascadeOnUpdate()
                ->restrictOnDelete();
            $table->unsignedSmallInteger('attempt_number')->nullable();
            $table->unsignedSmallInteger('reconciliation_attempts')->default(0);
            $table->timestamp('next_reconciliation_at')->nullable();
            $table->string('last_provider_status', 32)->nullable();
            $table->timestamp('last_provider_checked_at')->nullable();
            $table->unique(['checkout_recovery_id', 'attempt_number'], 'payments_recovery_attempt_unique');
            $table->index(['checkout_recovery_id', 'status']);
        });

        // Preserve legacy one-time subscription payment history. Historical
        // attempts cannot be reliably grouped by intent, so each begins as
        // its own recovery; future retries attach to a definitively failed one.
        DB::table('payments')
            ->where('method', 'paystack')
            ->whereNull('sale_id')
            ->whereIn('status', ['pending', 'failed', 'paid'])
            ->whereJsonContains('metadata->type', 'subscription')
            ->orderBy('id')
            ->chunkById(500, function ($payments): void {
                foreach ($payments as $payment) {
                    $metadata = is_array($payment->metadata)
                        ? $payment->metadata
                        : (json_decode((string) $payment->metadata, true) ?: []);
                    $planId = $metadata['plan_id'] ?? null;
                    if ($planId !== null
                        && ! DB::table('subscription_plans')->where('id', $planId)->exists()) {
                        $planId = null;
                    }
                    $termsComplete = filled($planId)
                        && filled($metadata['billing_interval'] ?? null)
                        && array_key_exists('currency', $metadata)
                        && array_key_exists('paystack_plan_code', $metadata);
                    $recoveryId = (string) Str::uuid();
                    $recoveryStatus = match ($payment->status) {
                        'paid' => 'succeeded',
                        'failed' => 'retryable',
                        'pending' => 'pending',
                        default => 'manual_review',
                    };
                    if ($payment->status === 'paid' && ! $termsComplete) {
                        $recoveryStatus = 'manual_review';
                    }

                    DB::table('subscription_checkout_recoveries')->insert([
                        'id' => $recoveryId,
                        'business_id' => $payment->business_id,
                        'subscription_id' => $payment->subscription_id,
                        'plan_id' => $planId,
                        'purchase_terms' => json_encode([
                            'plan_id' => $planId,
                            'plan_slug' => $metadata['plan_slug'] ?? null,
                            'amount' => (string) $payment->amount,
                            'currency' => isset($metadata['currency']) ? strtoupper((string) $metadata['currency']) : null,
                            'billing_interval' => $metadata['billing_interval'] ?? null,
                            'paystack_plan_code' => $metadata['paystack_plan_code'] ?? null,
                            'subscription_id' => $metadata['subscription_id'] ?? $payment->subscription_id,
                        ]),
                        'status' => $recoveryStatus,
                        'winning_payment_id' => $payment->status === 'paid' ? $payment->id : null,
                        'manual_review_at' => $recoveryStatus === 'manual_review' ? now() : null,
                        'manual_review_reason' => $recoveryStatus === 'manual_review'
                            ? ($payment->status === 'paid'
                                ? 'Legacy paid payment has incomplete captured subscription terms.'
                                : 'Legacy payment has an unsupported local status.')
                            : null,
                        'created_at' => $payment->created_at ?? now(),
                        'updated_at' => now(),
                    ]);

                    DB::table('payments')->where('id', $payment->id)->update([
                        'checkout_recovery_id' => $recoveryId,
                        'attempt_number' => 1,
                    ]);
                }
            });

        Schema::table('subscription_checkout_recoveries', function (Blueprint $table): void {
            $table->foreign('winning_payment_id')
                ->references('id')
                ->on('payments')
                ->cascadeOnUpdate()
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('subscription_checkout_recoveries', function (Blueprint $table): void {
            $table->dropForeign(['winning_payment_id']);
        });

        Schema::table('payments', function (Blueprint $table): void {
            $table->dropForeign(['checkout_recovery_id']);
            $table->dropUnique('payments_recovery_attempt_unique');
            $table->dropIndex(['checkout_recovery_id', 'status']);
            $table->dropColumn([
                'checkout_recovery_id',
                'attempt_number',
                'reconciliation_attempts',
                'next_reconciliation_at',
                'last_provider_status',
                'last_provider_checked_at',
            ]);
        });

        Schema::dropIfExists('subscription_checkout_recoveries');
    }
};
