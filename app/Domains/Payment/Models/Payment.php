<?php

namespace App\Domains\Payment\Models;

use App\Domains\Organization\Models\Business;
use App\Domains\Sales\Models\Sale;
use App\Domains\Subscription\Models\Subscription;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Payment extends Model
{
    use HasUuids;
    use HasFactory;

    protected $table = 'payments';

    protected $fillable = [
        'business_id',
        'sale_id',
        'subscription_id',
        'amount',
        'method',
        'status',
        'reference',
        'metadata',
        'paid_at',
        'checkout_recovery_id',
        'attempt_number',
        'reconciliation_attempts',
        'next_reconciliation_at',
        'last_provider_status',
        'last_provider_checked_at',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'metadata' => 'array',
            'paid_at' => 'datetime',
            'next_reconciliation_at' => 'datetime',
            'reconciliation_attempts' => 'integer',
            'last_provider_checked_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    /**
     * Payment belongs to a business.
     */
    public function business(): BelongsTo
    {
        return $this->belongsTo(
            Business::class
        );
    }

    /**
     * Payment belongs to a sale.
     */
    public function sale(): BelongsTo
    {
        return $this->belongsTo(
            Sale::class
        );
    }

    /**
     * Payment belongs to a subscription.
     */
    public function subscription(): BelongsTo
    {
        return $this->belongsTo(
            Subscription::class
        );
    }

    public function checkoutRecovery(): BelongsTo
    {
        return $this->belongsTo(SubscriptionCheckoutRecovery::class, 'checkout_recovery_id');
    }
}
