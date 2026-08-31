<?php

namespace App\Domains\Subscription\Models;

use App\Domains\Organization\Models\Business;
use App\Domains\Payment\Models\Payment;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

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
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];
}

    public function business(): BelongsTo
    {
        return $this->belongsTo(
            Business::class
        );
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(
            SubscriptionPlan::class,
            'plan_id'
        );
    }

    public function payments(): HasMany
    {
        return $this->hasMany(
            Payment::class,
            'subscription_id'
        );
    }
}