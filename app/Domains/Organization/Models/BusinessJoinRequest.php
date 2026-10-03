<?php

namespace App\Domains\Organization\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Permission\Models\Role;

class BusinessJoinRequest extends Model
{
    use HasUuids;

    protected $table = 'business_join_requests';

    protected $fillable = [
        'business_id',
        'user_id',
        'requested_role_id',
        'status',
        'reviewed_by',
        'requested_at',
        'reviewed_at',
        'rejection_reason',
    ];

    protected $casts = [
        'requested_at' => 'datetime',
        'reviewed_at' => 'datetime',
    ];

    public function business(): BelongsTo
    {
        return $this->belongsTo(
            Business::class,
            'business_id'
        );
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(
            User::class,
            'user_id'
        );
    }

    public function requestedRole(): BelongsTo
    {
        return $this->belongsTo(
            Role::class,
            'requested_role_id'
        );
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(
            User::class,
            'reviewed_by'
        );
    }
}