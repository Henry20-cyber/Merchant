<?php

namespace App\Domains\Organization\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Domains\Product\Models\Product;
use App\Domains\Product\Models\ProductBarcode;
use Illuminate\Database\Eloquent\SoftDeletes;

class Business extends Model
{
    use HasUuids;
    use SoftDeletes;
    use HasFactory;

    protected static function newFactory()
    {
        return \Database\Factories\BusinessFactory::new();
    }

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
        'barcode_enabled',
        'business_scale',
    ];

    protected function casts(): array
    {
        return [
            'barcode_enabled' => 'boolean',
            'business_scale' => 'string',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    /**
     * Business belongs to one Business Type.
     */
    public function businessType(): BelongsTo
    {
        return $this->belongsTo(
            BusinessType::class,
            'business_type_id'
        );
    }

    /**
     * Business has many Branches.
     */
    public function branches(): HasMany
    {
        return $this->hasMany(Branch::class);
    }

    /**
     * Users who belong to this business.
     */
    public function memberships(): HasMany
    {
        return $this->hasMany(BusinessUser::class);
    }

    /**
     * Users belonging to this business.
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(
            User::class,
            'business_user'
        )
            ->withPivot([
                'id',
                'status',
                'joined_at',
            ])
            ->withTimestamps();
    }

    /**
 * Products belonging to this business.
 */
public function products(): HasMany
{
    return $this->hasMany(Product::class);
}

/**
 * Barcodes belonging to this business.
 */
public function productBarcodes(): HasMany
{
    return $this->hasMany(ProductBarcode::class);
}
}
