<?php

namespace App\Domains\Product\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductBarcodeBranchMapping extends Model
{
    use HasUuids;

    protected $table = 'product_barcode_branch_mappings';

    protected $fillable = [
        'business_id',
        'branch_id',
        'product_barcode_id',
    ];

    public function productBarcode(): BelongsTo
    {
        return $this->belongsTo(ProductBarcode::class);
    }
}