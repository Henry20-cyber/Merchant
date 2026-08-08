<?php

namespace App\Domains\Organization\Services;

use Illuminate\Support\Facades\Auth;
use Spatie\Permission\Contracts\PermissionsTeamResolver;

class MerchantOSTeamResolver implements PermissionsTeamResolver
{
    public function getPermissionsTeamId(): ?int
    {
        $user = Auth::user();

        if (! $user) {
            return null;
        }

        $businessContext = app(BusinessContextService::class);

        $business = $businessContext->current($user);

        return $business?->id;
    }

    public function setPermissionsTeamId($id): void
    {
        if ($id === null) {
            app(BusinessContextService::class)->clear();

            return;
        }

        $user = Auth::user();

        if (! $user) {
            return;
        }

        $business = \App\Domains\Organization\Models\Business::find($id);

        if (! $business) {
            return;
        }

        app(BusinessContextService::class)->set($user, $business);
    }
}