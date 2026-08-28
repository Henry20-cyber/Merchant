<?php

namespace App\Domains\Subscription\Models;

use App\Domains\Organization\Models\Business;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UsageRecord extends Model
{
    use HasUuids;
    use HasFactory;

    protected $table = 'usage_records';

    protected $fillable = [
        'business_id',
        'metric',
        'quantity',
        'period_start',
        'period_end',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'period_start' => 'datetime',
            'period_end' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    /**
     * Usage record belongs to a business.
     */
    public function business(): BelongsTo
    {
        return $this->belongsTo(
            Business::class
        );
    }
}