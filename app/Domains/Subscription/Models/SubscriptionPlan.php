<?php

namespace App\Domains\Subscription\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $id
 * @property string $name
 * @property string $slug
 * @property string|null $description
 * @property numeric-string|float $price
 * @property string $currency
 * @property string $billing_interval
 * @property int|null $customer_limit
 * @property int|null $user_limit
 * @property int|null $branch_limit
 * @property int|null $transaction_daily_limit
 * @property int|null $transaction_monthly_limit
 * @property array<string, mixed>|null $features
 * @property string|null $paystack_plan_code
 * @property bool $is_active
 * @property-read \Illuminate\Database\Eloquent\Collection<int, Subscription> $subscriptions
 */
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
    /**
     * @return HasMany<Subscription, $this>
     */
    public function subscriptions(): HasMany
    {
        return $this->hasMany(
            Subscription::class,
            'plan_id'
        );
    }
}