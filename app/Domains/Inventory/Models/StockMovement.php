<?php

namespace App\Domains\Inventory\Models;

use App\Domains\Inventory\Models\Stock;
use App\Domains\Organization\Models\Business;
use App\Domains\Product\Models\Product;
use App\Domains\Product\Models\ProductUnit;
use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockMovement extends Model
{
    use HasUuids;

    protected $table = 'stock_movements';

    protected $fillable = [
        'business_id',
        'product_id',
        'product_unit_id',
        'stock_id',
        'type',
        'quantity',
        'quantity_before',
        'quantity_after',
        'reference_type',
        'reference_id',
        'note',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:4',
            'quantity_before' => 'decimal:4',
            'quantity_after' => 'decimal:4',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function productUnit(): BelongsTo
    {
        return $this->belongsTo(ProductUnit::class);
    }

    public function stock(): BelongsTo
    {
        return $this->belongsTo(Stock::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
