<?php

namespace App\Domains\Subscription\Models;

use App\Domains\Organization\Models\Business;
use App\Domains\Payment\Models\Payment;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $business_id
 * @property string $plan_id
 * @property string $status
 * @property string|null $provider
 * @property string|null $provider_customer_code
 * @property string|null $provider_authorization_code
 * @property string|null $provider_subscription_code
 * @property string|null $provider_email_token
 * @property bool $auto_renew
 * @property \Illuminate\Support\Carbon|null $starts_at
 * @property \Illuminate\Support\Carbon|null $current_period_start
 * @property \Illuminate\Support\Carbon|null $current_period_end
 * @property \Illuminate\Support\Carbon|null $grace_period_ends_at
 * @property \Illuminate\Support\Carbon|null $restricted_at
 * @property \Illuminate\Support\Carbon|null $cancelled_at
 * @property \Illuminate\Support\Carbon|null $ended_at
 * @property-read Business|null $business
 * @property-read SubscriptionPlan|null $plan
 */
class Subscription extends Model
{
    use HasUuids;
    use HasFactory;

    protected $table = 'subscriptions';

    protected $fillable = [
        'business_id',
        'plan_id',
        'status',
        'provider',
        'provider_customer_code',
        'provider_authorization_code',
        'provider_subscription_code',
        'provider_email_token',
        'auto_renew',
        'starts_at',
        'current_period_start',
        'current_period_end',
        'grace_period_ends_at',
        'restricted_at',
        'cancelled_at',
        'ended_at',
    ];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'current_period_start' => 'datetime',
            'current_period_end' => 'datetime',
            'grace_period_ends_at' => 'datetime',
            'restricted_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'ended_at' => 'datetime',
            'auto_renew' => 'boolean',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Business, $this> */
    public function business(): BelongsTo
    {
        return $this->belongsTo(
            Business::class
        );
    }

    /** @return BelongsTo<SubscriptionPlan, $this> */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(
            SubscriptionPlan::class,
            'plan_id'
        );
    }

    /** @return HasMany<Payment, $this> */
    public function payments(): HasMany
    {
        return $this->hasMany(
            Payment::class,
            'subscription_id'
        );
    }
}