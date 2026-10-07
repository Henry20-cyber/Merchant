<?php

namespace App\Http\Controllers;

use App\Domains\Organization\Models\Branch;
use App\Domains\Organization\Models\Business;
use Illuminate\Http\Request;

abstract class Controller
{
    /**
     * Get the business resolved by SetCurrentBusiness middleware.
     *
     * Business context must be established before a controller
     * attempts to perform business-scoped operations.
     */
    protected function currentBusiness(Request $request): Business
    {
        $business = $request->attributes->get(
            'current_business'
        );

        abort_if(
            ! $business instanceof Business,
            403,
            'No business context selected.'
        );

        return $business;
    }

    /**
     * Get the branch resolved by SetCurrentBranch middleware.
     *
     * Branch-scoped domains must not accept an arbitrary branch ID from
     * the client. They operate on the authenticated user's active branch.
     */
    protected function currentBranch(Request $request): Branch
    {
        $branch = $request->attributes->get(
            'current_branch'
        );

        abort_if(
            ! $branch instanceof Branch,
            403,
            'No branch context selected.'
        );

        return $branch;
    }
}