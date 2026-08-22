<?php

namespace App\Domains\Product\Models;

use App\Domains\Organization\Models\Business;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductBarcode extends Model
{
    use HasUuids;

    protected $table = 'product_barcodes';

    protected $fillable = [
        'business_id',
        'product_id',
        'barcode',
        'type',
        'is_primary',
    ];

    protected function casts(): array
    {
        return [
            'is_primary' => 'boolean',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    /**
     * The business this barcode belongs to.
     */
    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    /**
     * The product this barcode identifies.
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function productUnit(): BelongsTo
{
    return $this->belongsTo(ProductUnit::class);
}
}