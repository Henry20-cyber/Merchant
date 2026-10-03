<?php

namespace App\Domains\Payment\Models;

use App\Domains\Organization\Models\Business;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IncomingBankTransaction extends Model
{
    use HasUuids;
    use HasFactory;

    protected $table = 'incoming_bank_transactions';

    protected $fillable = [
        'business_id',
        'financial_account_id',
        'provider',
        'provider_transaction_id',
        'type',
        'amount',
        'currency',
        'sender_name',
        'sender_account',
        'sender_bank',
        'reference',
        'narration',
        'status',
        'occurred_at',
        'received_at',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'occurred_at' => 'datetime',
            'received_at' => 'datetime',
            'metadata' => 'array',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    /**
     * Transaction belongs to a MerchantOS business.
     */
    public function business(): BelongsTo
    {
        return $this->belongsTo(
            Business::class
        );
    }

    /**
     * Transaction belongs to the financial account
     * into which the money was received.
     */
    public function financialAccount(): BelongsTo
    {
        return $this->belongsTo(
            FinancialAccount::class
        );
    }
}
