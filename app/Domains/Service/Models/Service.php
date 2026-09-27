<?php

namespace App\Domains\Service\Models;

use App\Domains\Catalog\Models\Category;
use App\Domains\Organization\Models\Business;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Service extends Model
{
    use HasUuids;
    use HasFactory;
    use SoftDeletes;

    protected $table = 'services';

    protected $fillable = [
        'business_id',
        'category_id',
        'name',
        'description',
        'image_path',
        'price',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'is_active' => 'boolean',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
            'deleted_at' => 'datetime',
        ];
    }

    protected $appends = [
    'image_url',
];

public function getImageUrlAttribute(): ?string
{
    return $this->image_path
        ? asset('storage/' . $this->image_path)
        : null;
}

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }
}
