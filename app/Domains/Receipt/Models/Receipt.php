<?php

namespace App\Domains\Receipt\Models;

use App\Domains\Organization\Models\Business;
use App\Domains\Sales\Models\Sale;
use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Receipt extends Model
{
    use HasUuids;
    use HasFactory;

    protected static function newFactory()
    {
        return \Database\Factories\ReceiptFactory::new();
    }

    protected $table = 'receipts';

    protected $fillable = [
        'business_id',
        'sale_id',
        'receipt_number',
        'status',
        'issued_by',
        'issued_at',
        'snapshot',
    ];

    protected function casts(): array
    {
        return [
            'snapshot' => 'array',
            'issued_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Relationships
    |--------------------------------------------------------------------------
    */

    /**
     * Receipt belongs to a business.
     */
    public function business(): BelongsTo
    {
        return $this->belongsTo(
            Business::class
        );
    }

    /**
     * Receipt belongs to exactly one sale.
     */
    public function sale(): BelongsTo
    {
        return $this->belongsTo(
            Sale::class
        );
    }

    /**
     * User who issued the receipt.
     */
    public function issuedBy(): BelongsTo
    {
        return $this->belongsTo(
            User::class,
            'issued_by'
        );
    }
}