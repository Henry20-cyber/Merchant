<?php

namespace App\Domains\Inventory\Models;

use App\Domains\Inventory\Models\StockMovement;
use App\Domains\Organization\Models\Business;
use App\Domains\Product\Models\Product;
use App\Domains\Product\Models\ProductUnit;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Stock extends Model
{
    use HasUuids;

    protected $table = 'stocks';

    protected $fillable = [
        'business_id',
        'product_id',
        'product_unit_id',
        'quantity',
        'reorder_level',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:4',
            'reorder_level' => 'decimal:4',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    /**
     * The business this stock belongs to.
     */
    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    /**
     * The product this stock belongs to.
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * The product unit represented by this stock record.
     */
    public function productUnit(): BelongsTo
    {
        return $this->belongsTo(ProductUnit::class);
    }

    /**
     * Stock movement history.
     */
    public function movements(): HasMany
    {
        return $this->hasMany(
            StockMovement::class,
            'stock_id'
        );
    }
}