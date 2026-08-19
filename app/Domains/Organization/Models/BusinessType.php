<?php

namespace App\Domains\Organization\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class BusinessType extends Model
{
    use HasUuids;
    use HasFactory;

    protected static function newFactory()
{
    return \Database\Factories\BusinessTypeFactory::new();
}

    /**
     * The table associated with the model.
     */
    protected $table = 'business_types';

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'name',
        'icon',
        'description',
        'is_active',
    ];

    /**
     * Cast attributes to native PHP types.
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    /**
     * A Business Type has many Businesses.
     */
    public function businesses()
    {
      //  return $this->hasMany(Business::class);
    }
}