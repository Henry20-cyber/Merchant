<?php

namespace App\Domains\Credit\Models;

use App\Domains\Customer\Models\Customer;
use App\Domains\Organization\Models\Business;
use App\Domains\Sales\Models\Sale;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Credit extends Model
{
    use HasUuids;
    use HasFactory;

    protected $table = 'credits';

    protected $fillable = [
        'business_id',
        'customer_id',
        'sale_id',
        'original_amount',
        'due_at',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'original_amount' => 'decimal:2',
            'due_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    /**
     * Credit belongs to a business.
     */
    public function business(): BelongsTo
    {
        return $this->belongsTo(
            Business::class
        );
    }

    /**
     * Credit belongs to a customer.
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(
            Customer::class
        );
    }

    /**
     * Credit belongs to the sale that created the receivable.
     */
    public function sale(): BelongsTo
    {
        return $this->belongsTo(
            Sale::class
        );
    }
}