<?php

namespace App\Domains\Payment\Models;

use App\Domains\Organization\Models\Business;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use App\Domains\Payment\Models\IncomingBankTransaction;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FinancialAccount extends Model
{
    use HasUuids;
    use HasFactory;

    protected $table = 'financial_accounts';

    protected $fillable = [
        'business_id',
        'provider',
        'name',
        'account_identifier',
        'currency',
        'metadata',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'is_active' => 'boolean',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    /**
     * Financial account belongs to a business.
     */
    public function business(): BelongsTo
    {
        return $this->belongsTo(
            Business::class
        );
    }

    /**
 * Transactions received through this financial account.
 */
public function incomingBankTransactions(): HasMany
{
    return $this->hasMany(
        IncomingBankTransaction::class
    );
}
}