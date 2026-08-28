<?php

namespace App\Domains\Customer\Models;

use App\Domains\Organization\Models\Business;
use App\Domains\Sales\Models\Sale;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Customer extends Model
{
    use HasUuids;
    use HasFactory;
    use SoftDeletes;

    protected static function newFactory()
    {
        return \Database\Factories\CustomerFactory::new();
    }

    protected $table = 'customers';

    protected $fillable = [
        'business_id',
        'customer_number',
        'name',
        'phone',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
            'deleted_at' => 'datetime',
        ];
    }

    /**
     * Customer belongs to one business.
     */
    public function business(): BelongsTo
    {
        return $this->belongsTo(
            Business::class
        );
    }

    /**
     * Customer can have many sales.
     */
    public function sales(): HasMany
    {
        return $this->hasMany(
            Sale::class
        );
    }
}