<?php

namespace App\Domains\Product\Models;

use App\Domains\Organization\Models\Business;
use App\Domains\Product\Models\Product;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use App\Domains\Inventory\Models\Stock;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class ProductUnit extends Model
{
    use HasUuids;
    use HasFactory;
    use SoftDeletes;

    protected $table = 'product_units';

    protected static function newFactory()
    {
        return \Database\Factories\ProductUnitFactory::new();
    }

    protected $fillable = [
        'business_id',
        'product_id',
        'name',
        'quantity',
        'cost_price',
        'selling_price',
        'currency',
        'is_base_unit',
        'is_sellable',
        'is_purchasable',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:4',
            'cost_price' => 'decimal:2',
            'selling_price' => 'decimal:2',
            'is_base_unit' => 'boolean',
            'is_sellable' => 'boolean',
            'is_purchasable' => 'boolean',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
            'deleted_at' => 'datetime',
        ];
    }

    /**
     * The business this unit belongs to.
     */
    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    /**
     * The product this unit belongs to.
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
 * Current inventory balance for this unit.
 */
public function stock(): HasOne
{
    return $this->hasOne(Stock::class);
}
}