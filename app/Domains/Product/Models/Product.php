<?php

namespace App\Domains\Product\Models;

use App\Domains\Catalog\Models\Category;
use App\Domains\Organization\Models\Business;
use App\Domains\Inventory\Models\Stock;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Product extends Model
{
    use HasUuids;
    use SoftDeletes;
    use HasFactory;

    protected $table = 'products';

    protected static function newFactory()
    {
        return \Database\Factories\ProductFactory::new();
    }

    protected $fillable = [
        'business_id',
        'category_id',
        'name',
        'sku',
        'description',
        'image_path',
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

    protected $appends = [
    'image_url',
];

    /**
     * The business this product belongs to.
     */
    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    /**
     * Category this product belongs to.
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /**
     * Units through which this product is sold or purchased.
     */
    public function units(): HasMany
    {
        return $this->hasMany(ProductUnit::class);
    }

    /**
     * Barcodes belonging to this product.
     */
    public function barcodes(): HasMany
    {
        return $this->hasMany(ProductBarcode::class);
    }

    /**
     * Inventory records belonging to this product.
     */
    public function stocks(): HasMany
    {
        return $this->hasMany(Stock::class);
    }

    public function getImageUrlAttribute(): ?string
{
    return $this->image_path
        ? asset('storage/' . $this->image_path)
        : null;
}
}