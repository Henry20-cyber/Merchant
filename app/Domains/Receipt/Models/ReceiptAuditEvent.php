<?php

namespace App\Domains\Receipt\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use LogicException;

class ReceiptAuditEvent extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $table = 'receipt_audit_events';

    protected $fillable = [
        'business_id', 'receipt_id', 'payment_id', 'actor_id',
        'event_sequence', 'event_type', 'event_payload', 'previous_hash', 'event_hash', 'created_at',
    ];

    protected function casts(): array
    {
        return ['event_payload' => 'array', 'created_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Receipt audit events are append-only.'));
        static::deleting(fn () => throw new LogicException('Receipt audit events are append-only.'));
    }
}
