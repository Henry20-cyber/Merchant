<?php

namespace App\Domains\Payment\Models;

use App\Domains\Organization\Models\Business;
use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class SubscriptionPaymentRecoveryAudit extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $table = 'subscription_payment_recovery_audits';

    protected $fillable = ['business_id', 'payment_id', 'actor_id', 'event_type', 'event_payload', 'created_at'];

    protected function casts(): array
    {
        return ['event_payload' => 'array', 'created_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Subscription payment recovery audits are append-only.'));
        static::deleting(fn () => throw new LogicException('Subscription payment recovery audits are append-only.'));
    }

    /** @return BelongsTo<Business, $this> */
    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    /** @return BelongsTo<Payment, $this> */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
