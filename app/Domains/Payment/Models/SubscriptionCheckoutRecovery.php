<?php

namespace App\Domains\Payment\Models;

use App\Domains\Organization\Models\Business;
use App\Domains\Subscription\Models\Subscription;
use App\Domains\Subscription\Models\SubscriptionPlan;
use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SubscriptionCheckoutRecovery extends Model
{
    use HasUuids;

    protected $fillable = [
        'business_id', 'subscription_id', 'plan_id', 'purchase_terms', 'status',
        'winning_payment_id', 'manual_review_at', 'manual_review_reason',
        'manual_review_resolution', 'manual_review_resolved_at', 'manual_review_resolved_by',
        'last_provider_status', 'retry_reason',
    ];

    protected function casts(): array
    {
        return [
            'purchase_terms' => 'array',
            'manual_review_at' => 'datetime',
            'manual_review_resolved_at' => 'datetime',
        ];
    }

    public function business(): BelongsTo { return $this->belongsTo(Business::class); }
    public function subscription(): BelongsTo { return $this->belongsTo(Subscription::class); }
    public function plan(): BelongsTo { return $this->belongsTo(SubscriptionPlan::class); }
    public function winningPayment(): BelongsTo { return $this->belongsTo(Payment::class, 'winning_payment_id'); }
    public function resolvedBy(): BelongsTo { return $this->belongsTo(User::class, 'manual_review_resolved_by'); }
    public function payments(): HasMany { return $this->hasMany(Payment::class, 'checkout_recovery_id')->orderBy('attempt_number'); }
}
