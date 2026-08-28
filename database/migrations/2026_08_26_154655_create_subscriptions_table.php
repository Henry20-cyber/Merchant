<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscriptions', function (Blueprint $table) {
            /*
             * Subscription identifier.
             */
            $table->uuid('id')->primary();

            /*
             * The MerchantOS business that owns
             * this subscription.
             */
            $table->foreignUuid('business_id')
                ->constrained('businesses')
                ->cascadeOnUpdate()
                ->restrictOnDelete();

            /*
             * The plan currently attached to the business.
             */
            $table->foreignUuid('plan_id')
                ->constrained('subscription_plans')
                ->cascadeOnUpdate()
                ->restrictOnDelete();

            /*
             * Subscription lifecycle.
             *
             * trial
             * active
             * past_due
             * grace_period
             * restricted
             * suspended
             * cancelled
             * expired
             */
            $table->string('status', 50)
                ->default('trial');

            /*
             * Payment provider.
             *
             * Currently:
             * paystack
             *
             * Keeping this generic allows another provider
             * to be introduced later.
             */
            $table->string('provider', 50)
                ->nullable();

            /*
             * Paystack customer identifier.
             */
            $table->string('provider_customer_code', 255)
                ->nullable();

            /*
             * Paystack subscription identifier.
             */
            $table->string('provider_subscription_code', 255)
                ->nullable();

            /*
             * Subscription lifecycle dates.
             */
            $table->timestamp('starts_at')
                ->nullable();

            $table->timestamp('current_period_start')
                ->nullable();

            $table->timestamp('current_period_end')
                ->nullable();

            /*
             * The deadline before MerchantOS moves
             * the subscription into a more restrictive state.
             */
            $table->timestamp('grace_period_ends_at')
                ->nullable();

            /*
             * Cancellation information.
             */
            $table->timestamp('cancelled_at')
                ->nullable();

            /*
             * Final termination/expiration time.
             */
            $table->timestamp('ended_at')
                ->nullable();

            $table->timestamps();

            /*
             * A business should have one current subscription
             * record at a time.
             */
            $table->unique('business_id');

            /*
             * Common subscription queries.
             */
            $table->index([
                'business_id',
                'status',
            ]);

            $table->index('provider_customer_code');

            $table->index('provider_subscription_code');

            $table->index('current_period_end');

            $table->index('grace_period_ends_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscriptions');
    }
};