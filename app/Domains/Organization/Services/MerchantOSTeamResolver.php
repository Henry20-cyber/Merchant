<?php

namespace App\Domains\Organization\Services;

use App\Domains\Organization\Models\Business;
use App\Domains\Organization\Models\BusinessUser;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Spatie\Permission\Contracts\PermissionsTeamResolver;

class MerchantOSTeamResolver implements PermissionsTeamResolver
{
    /**
     * The currently resolved Spatie permission team.
     */
    private int|string|null $teamId = null;

    /**
     * Resolve the current Spatie permission team.
     */
    public function getPermissionsTeamId(): int|string|null
    {
        if ($this->teamId !== null) {
            return $this->teamId;
        }

        $user = Auth::user();

        if (! $user) {
            return null;
        }

        $business = app(BusinessContextService::class)
            ->current($user);

        return $business?->getKey();
    }

    /**
     * Set the current Spatie permission team.
     *
     * If the authenticated user belongs to this business,
     * also synchronize MerchantOS's business context.
     *
     * If the user does not belong to the business, we still
     * allow Spatie's team state to change because internal
     * system operations may provision or manipulate another
     * business. We simply do not change the user's active
     * MerchantOS business context.
     */
    public function setPermissionsTeamId(
        int|string|Model|null $id
    ): void {
        if ($id instanceof Model) {
            $id = $id->getKey();
        }

        $this->teamId = $id;

        if ($id === null) {
            return;
        }

        $user = Auth::user();

        if (! $user) {
            return;
        }

        /*
         * Only synchronize MerchantOS business context if
         * the authenticated user is actually a member of
         * this business.
         */
        $belongsToBusiness = BusinessUser::query()
            ->where('user_id', $user->id)
            ->where('business_id', $id)
            ->where('status', 'active')
            ->exists();

        if (! $belongsToBusiness) {
            return;
        }

        $business = Business::query()
            ->whereKey($id)
            ->first();

        if (! $business) {
            return;
        }

        app(BusinessContextService::class)
            ->set($user, $business);
    }
}