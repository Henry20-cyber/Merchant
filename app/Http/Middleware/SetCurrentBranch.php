<?php

namespace App\Http\Middleware;

use App\Domains\Organization\Services\BranchContextService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SetCurrentBranch
{
    public function __construct(
        private readonly BranchContextService $branchContextService,
    ) {
    }

    public function handle(
        Request $request,
        Closure $next
    ): Response {
        $user = $request->user();

        if (! $user) {
            $request->attributes->set('current_branch', null);

            return $next($request);
        }

        $business = $request->attributes->get('current_business');

        if (! $business) {
            $request->attributes->set('current_branch', null);

            return $next($request);
        }

        $branch = $this->branchContextService->current(
            $user,
            $business
        );

        /*
         * A branch can become invalid because it was deleted,
         * the business context changed, or the membership changed.
         *
         * Clear the stale session value instead of allowing a
         * previous business/location to leak into the request.
         */
        if (! $branch && session()->has('current_branch_id')) {
            $this->branchContextService->clear();
        }

        $request->attributes->set(
            'current_branch',
            $branch
        );

        return $next($request);
    }
}
