<?php

namespace App\Domains\Organization\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Business extends Model
{
    use HasUuids;
    use SoftDeletes;

    protected $table = 'businesses';

    protected $fillable = [
        'business_type_id',
        'name',
        'slug',
        'email',
        'phone',
        'website',
        'registration_number',
        'tax_number',
        'logo',
        'currency',
        'timezone',
        'default_country',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    /**
     * Business belongs to one Business Type.
     */
    public function businessType(): BelongsTo
    {
        return $this->belongsTo(BusinessType::class, 'business_type_id');
    }

    /**
     * Business has many Branches.
     */
     public function branches(): HasMany
    {
       return $this->hasMany(Branch::class);
    } 
}