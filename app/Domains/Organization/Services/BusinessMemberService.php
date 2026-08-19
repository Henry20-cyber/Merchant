<?php

namespace App\Domains\Organization\Services;

use App\Domains\Organization\Models\Business;
use Illuminate\Database\Eloquent\Collection;

class BusinessMemberService
{
    /**
     * Get active members of a business.
     */
    public function activeMembers(Business $business): Collection
    {
        return $business->memberships()
            ->with('user')
            ->where('status', 'active')
            ->get();
    }
}