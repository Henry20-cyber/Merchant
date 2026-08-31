<?php

namespace App\Domains\Sales\Models;

use App\Domains\Organization\Models\Business;
use App\Domains\Sales\Models\SaleItem;
use App\Models\User;
use App\Domains\Customer\Models\Customer;
use App\Domains\Payment\Models\Payment;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Domains\Receipt\Models\Receipt;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Database\Factories\SaleFactory;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Sale extends Model
{
  use HasUuids;
  use HasFactory;

  protected static function newFactory()
{
    return SaleFactory::new();
}

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

  /**
 * Sale optionally belongs to a customer.
 */
public function customer(): BelongsTo
{
    return $this->belongsTo(
        Customer::class
    );
}

/**
 * Payments made against this sale.
 */
public function payments(): HasMany
{
    return $this->hasMany(
        Payment::class
    );
}

/**
 * Official receipt issued for this sale.
 */
public function receipt(): HasOne
{
    return $this->hasOne(
        Receipt::class
    );
}

}
