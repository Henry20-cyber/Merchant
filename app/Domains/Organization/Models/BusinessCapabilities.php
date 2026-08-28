<?php

namespace App\Domains\Organization\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BusinessCapabilities extends Model
{
    protected $table = 'business_capabilities';

    protected $primaryKey = 'business_id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'business_id',
        'products_enabled',
        'services_enabled',
    ];

    protected function casts(): array
    {
        return [
            'products_enabled' => 'boolean',
            'services_enabled' => 'boolean',
        ];
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(
            Business::class,
            'business_id'
        );
    }
}
