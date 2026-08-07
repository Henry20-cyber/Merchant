<?php

namespace App\Domains\Organization\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Branch extends Model
{
    use HasUuids;
    use SoftDeletes;

    protected $table = 'branches';

    protected $fillable = [

        'business_id',

        'name',

        'code',

        'phone',

        'email',

        'address',

        'city',

        'state',

        'country',

        'is_head_office',

    ];

    protected function casts(): array
    {
        return [

            'is_head_office' => 'boolean',

            'created_at' => 'datetime',

            'updated_at' => 'datetime',

        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Relationships
    |--------------------------------------------------------------------------
    */

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }
}