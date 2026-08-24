<?php

namespace App\Domains\Sales\Models;

use App\Domains\Organization\Models\Business;
use App\Domains\Sales\Models\SaleItem;
use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Sale extends Model
{
  use HasUuids;
  use HasFactory;

  protected $table = 'sales';

  protected $fillable = [
    'business_id',
    'cashier_id',
    'customer_id',
    'subtotal',
    'discount',
    'tax',
    'total',
    'payment_method',
    'payment_status',
    'status',
  ];

  protected function casts(): array
  {
    return [
      'subtotal' => 'decimal:2',
      'discount' => 'decimal:2',
      'tax' => 'decimal:2',
      'total' => 'decimal:2',
      'created_at' => 'datetime',
      'updated_at' => 'datetime',
    ];
  }

  public function business(): BelongsTo
  {
    return $this->belongsTo(Business::class);
  }

  public function cashier(): BelongsTo
  {
    return $this->belongsTo(User::class, 'cashier_id');
  }

  public function items(): HasMany
  {
    return $this->hasMany(SaleItem::class);
  }
}
