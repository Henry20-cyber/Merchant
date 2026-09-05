<?php

namespace App\Domains\Catalog\Models;

use App\Domains\Organization\Models\Business;
use App\Domains\Product\Models\Product;
use App\Domains\Service\Models\Service;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Category extends Model
{
    use HasUuids;
    use SoftDeletes;
    use HasFactory;

    protected $table = 'categories';

    protected $fillable = [
        'business_id',
        'parent_id',
        'name',
        'slug',
        'description',
        'sort_order',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
            'deleted_at' => 'datetime',
        ];
    }

    /**
     * The business this category belongs to.
     */
    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    /**
     * Parent category.
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(
            self::class,
            'parent_id'
        );
    }

    /**
     * Child categories.
     */
    public function children(): HasMany
    {
        return $this->hasMany(
            self::class,
            'parent_id'
        );
    }

    /**
     * Products assigned to this category.
     */
    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    /**
     * Services assigned to this category.
     */
    public function services(): HasMany
    {
        return $this->hasMany(Service::class);
    }
}