<?php

namespace App\Domains\Payment\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PaymentWebhookEvent extends Model
{
    use HasUuids;
    use HasFactory;

    protected $table = 'payment_webhook_events';

    protected $fillable = [
        'provider',
        'provider_event_id',
        'event',
        'reference',
        'status',
        'payload',
        'error_message',
        'processed_at',
        'processing_token',
        'processing_started_at',
        'attempts',
        'next_attempt_at',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'processed_at' => 'datetime',
            'processing_token' => 'string',
            'processing_started_at' => 'datetime',
            'attempts' => 'integer',
            'next_attempt_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }
}
