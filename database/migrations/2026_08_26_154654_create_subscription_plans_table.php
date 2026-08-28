<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscription_plans', function (Blueprint $table) {
            $table->uuid('id')->primary();

            /*
             * Human-readable plan name.
             *
             * Examples:
             * Low
             * Medium
             * Large
             */
            $table->string('name', 100);

            /*
             * Stable identifier used by application code.
             *
             * Examples:
             * low
             * medium
             * large
             */
            $table->string('slug', 100)->unique();

            $table->text('description')->nullable();

            /*
             * Subscription price.
             */
            $table->decimal('price', 14, 2);

            /*
             * ISO currency code.
             *
             * Example:
             * NGN
             */
            $table->string('currency', 3)->default('NGN');

            /*
             * How often the customer is billed.
             *
             * monthly
             * yearly
             */
            $table->string('billing_interval', 20)
                ->default('monthly');

            /*
             * Recommended/maximum customer capacity.
             *
             * These are application-level limits,
             * not PostgreSQL constraints.
             */
            $table->unsignedInteger('customer_limit')
                ->nullable();

            /*
             * Maximum number of staff/users allowed
             * by the plan.
             */
            $table->unsignedInteger('user_limit')
                ->nullable();

            /*
             * Maximum number of branches.
             */
            $table->unsignedInteger('branch_limit')
                ->nullable();

            /*
             * Feature configuration.
             *
             * Example:
             *
             * {
             *     "pos": true,
             *     "inventory": true,
             *     "advanced_reports": false
             * }
             */
            $table->jsonb('features')
                ->nullable();

            /*
             * Paystack plan code.
             *
             * This remains nullable because the internal
             * MerchantOS plan can exist before Paystack
             * configuration.
             */
            $table->string('paystack_plan_code', 255)
                ->nullable()
                ->unique();

            $table->boolean('is_active')
                ->default(true);

            $table->timestamps();

            $table->index('is_active');
            $table->index('billing_interval');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscription_plans');
    }
};