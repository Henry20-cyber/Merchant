<?php

namespace App\Domains\Product\Models;

use App\Domains\Organization\Models\Business;
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
    'name',
    'sku',
    'description',
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
   * The business this product belongs to.
   */
  public function business(): BelongsTo
  {
    return $this->belongsTo(Business::class);
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
}
