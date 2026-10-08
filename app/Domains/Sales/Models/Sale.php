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
use App\Domains\Credit\Models\Credit;
use App\Domains\Organization\Models\Branch;
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
     'branch_id',
    'cashier_id',
    'customer_id',
    'subtotal',
    'discount',
    'taxable_amount',
    'vat_enabled',
    'vat_rate',
    'vat_amount',
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
      'taxable_amount' => 'decimal:2',
      'vat_enabled' => 'boolean',
      'vat_rate' => 'decimal:2',
      'vat_amount' => 'decimal:2',
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
   * Credit record created for this sale, if applicable.
   */
  public function credit(): HasOne
  {
    return $this->hasOne(
      Credit::class
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

  public function branch(): BelongsTo
{
    return $this->belongsTo(Branch::class);
}
}
