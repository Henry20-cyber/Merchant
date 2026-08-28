<?php

namespace App\Domains\Subscription\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SubscriptionPlan extends Model
{
    use HasUuids;
    use HasFactory;

    protected $table = 'subscription_plans';

    protected $fillable = [
        'name',
        'slug',
        'description',
        'price',
        'currency',
        'billing_interval',
        'customer_limit',
        'user_limit',
        'branch_limit',
        'transaction_daily_limit',
        'transaction_monthly_limit',
        'features',
        'paystack_plan_code',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'customer_limit' => 'integer',
            'user_limit' => 'integer',
            'branch_limit' => 'integer',
            'transaction_daily_limit' => 'integer',
            'transaction_monthly_limit' => 'integer',
            'features' => 'array',
            'is_active' => 'boolean',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    /**
     * A plan can be assigned to many subscriptions.
     */
    public function subscriptions(): HasMany
    {
        return $this->hasMany(
            Subscription::class,
            'plan_id'
        );
    }
}