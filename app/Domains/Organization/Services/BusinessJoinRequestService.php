<?php

namespace App\Domains\Organization\Services;

use App\Domains\Identity\Services\RoleService;
use App\Domains\Subscription\Services\SubscriptionLimitService;
use App\Domains\Organization\Models\Business;
use App\Domains\Organization\Models\BusinessJoinRequest;
use App\Domains\Organization\Models\BusinessUser;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;
use  Spatie\Permission\setPermissionsTeamId;

class BusinessJoinRequestService
{
    public function __construct(
        private RoleService $roleService,
        private SubscriptionLimitService $subscriptionLimitService,
    ) {}

    /**
     * Submit a request to join a business.
     */
    public function submit(
        User $user,
        string $merchantId,
        ?int $requestedRoleId = null,
    ): BusinessJoinRequest {
        return DB::transaction(function () use (
            $user,
            $merchantId,
            $requestedRoleId
        ) {
            $business = Business::query()
                ->where('merchant_id', $merchantId)
                ->whereNull('deleted_at')
                ->first();

            if (! $business) {
                throw ValidationException::withMessages([
                    'merchant_id' => 'Business not found.',
                ]);
            }

            /*
             * A current member cannot request to join
             * a business they already belong to.
             */
            $existingMembership = BusinessUser::query()
                ->where('business_id', $business->id)
                ->where('user_id', $user->id)
                ->where('status', 'active')
                ->exists();

            if ($existingMembership) {
                throw ValidationException::withMessages([
                    'merchant_id' => 'You are already a member of this business.',
                ]);
            }

            /*
             * Only one pending request per user/business.
             */
            $existingRequest = BusinessJoinRequest::query()
                ->where('business_id', $business->id)
                ->where('user_id', $user->id)
                ->where('status', 'pending')
                ->exists();

            if ($existingRequest) {
                throw ValidationException::withMessages([
                    'merchant_id' => 'You already have a pending request for this business.',
                ]);
            }

            /*
             * A requested role is only a preference.
             * It does not grant any permission.
             */
            if ($requestedRoleId !== null) {
                $role = Role::query()
                    ->whereKey($requestedRoleId)
                    ->where('guard_name', 'web')
                    ->where('team_id', $business->id)
                    ->first();

                if (! $role) {
                    throw ValidationException::withMessages([
                        'requested_role_id' => 'The requested role does not belong to this business.',
                    ]);
                }

                /*
                 * Employees must never request Owner as a role.
                 * The Owner role belongs to business ownership,
                 * not employee onboarding.
                 */
                if ($role->name === 'Owner') {
                    throw ValidationException::withMessages([
                        'requested_role_id' => 'The Owner role cannot be requested through employee onboarding.',
                    ]);
                }
            }

            return BusinessJoinRequest::create([
                'business_id' => $business->id,
                'user_id' => $user->id,
                'requested_role_id' => $requestedRoleId,
                'status' => 'pending',
                'requested_at' => now(),
            ]);
        });
    }

    /**
     * Get pending join requests for a business.
     */
    public function pending(Business $business): Collection
    {
        return BusinessJoinRequest::query()
            ->with([
                'user',
                'requestedRole',
            ])
            ->where('business_id', $business->id)
            ->where('status', 'pending')
            ->orderBy('requested_at')
            ->get();
    }

    /**
     * Approve a join request and assign the final role.
     *
     * The final role is chosen by the business,
     * not automatically trusted from the employee request.
     */
    public function approve(
        BusinessJoinRequest $joinRequest,
        User $approver,
        int $finalRoleId,
        Business $business,
    ): BusinessJoinRequest {
        return DB::transaction(function () use (
            $joinRequest,
            $approver,
            $finalRoleId,
            $business
        ) {
            $request = BusinessJoinRequest::query()
                ->lockForUpdate()
                ->whereKey($joinRequest->id)
                ->where('business_id', $business->id)
                ->where('status', 'pending')
                ->first();

            if (! $request) {
                throw ValidationException::withMessages([
                    'request' => 'This join request is no longer pending.',
                ]);
            }

            /*
             * The approver must be an active member.
             */
            $approverMembership = BusinessUser::query()
                ->where('business_id', $business->id)
                ->where('user_id', $approver->id)
                ->where('status', 'active')
                ->exists();

            if (! $approverMembership) {
                abort(403, 'You are not a member of this business.');
            }

            setPermissionsTeamId($business->id);

            if (! $approver->can('users.join_requests.review')) {
                abort(403, 'You do not have permission to review join requests.');
            }

            /*
             * Resolve the final role strictly inside this business.
             */
            $role = Role::query()
                ->whereKey($finalRoleId)
                ->where('guard_name', 'web')
                ->where('team_id', $business->id)
                ->first();

            if (! $role) {
                throw ValidationException::withMessages([
                    'role_id' => 'The selected role does not belong to this business.',
                ]);
            }

            /*
             * Owner should never be granted through employee
             * onboarding.
             */
            if ($role->name === 'Owner') {
                throw ValidationException::withMessages([
                    'role_id' => 'The Owner role cannot be assigned through employee onboarding.',
                ]);
            }

            /*
             * Prevent approving someone who somehow became
             * an active member between request and approval.
             */
            $membership = BusinessUser::query()
                ->where('business_id', $business->id)
                ->where('user_id', $request->user_id)
                ->first();

            if ($membership && $membership->status === 'active') {
                throw ValidationException::withMessages([
                    'request' => 'This user is already an active member of the business.',
                ]);
            }

            /*
             * A new active membership consumes one user seat.
             * Existing active members are rejected above, so this
             * check only runs for a new/reactivated seat.
             */
            $this->subscriptionLimitService->ensureUserCapacity($business);

            /*
             * Create or reactivate the business membership first.
             * RoleService requires active membership.
             */
            if ($membership) {
                $membership->update([
                    'status' => 'active',
                    'joined_at' => now(),
                ]);
            } else {
                $membership = BusinessUser::create([
                    'business_id' => $business->id,
                    'user_id' => $request->user_id,
                    'status' => 'active',
                    'joined_at' => now(),
                ]);
            }

            /*
             * Now that membership exists, RoleService can safely
             * assign the business-scoped role.
             */
            $this->roleService->assignRole(
                $request->user,
                $role->name,
                $business->id
            );

            $request->update([
                'status' => 'approved',
                'reviewed_by' => $approver->id,
                'reviewed_at' => now(),
                'rejection_reason' => null,
            ]);

            return $request->fresh([
                'business',
                'user',
                'requestedRole',
                'reviewer',
            ]);
        });
    }

    /**
     * Reject a pending join request.
     */
    public function reject(
        BusinessJoinRequest $joinRequest,
        User $reviewer,
        Business $business,
        ?string $reason = null,
    ): BusinessJoinRequest {
        return DB::transaction(function () use (
            $joinRequest,
            $reviewer,
            $business,
            $reason
        ) {
            $request = BusinessJoinRequest::query()
                ->lockForUpdate()
                ->whereKey($joinRequest->id)
                ->where('business_id', $business->id)
                ->where('status', 'pending')
                ->first();

            if (! $request) {
                throw ValidationException::withMessages([
                    'request' => 'This join request is no longer pending.',
                ]);
            }

            $isMember = BusinessUser::query()
                ->where('business_id', $business->id)
                ->where('user_id', $reviewer->id)
                ->where('status', 'active')
                ->exists();

            if (! $isMember) {
                abort(403, 'You are not a member of this business.');
            }

            setPermissionsTeamId($business->id);

            if (! $reviewer->can('users.join_requests.review')) {
                abort(403, 'You do not have permission to review join requests.');
            }

            $request->update([
                'status' => 'rejected',
                'reviewed_by' => $reviewer->id,
                'reviewed_at' => now(),
                'rejection_reason' => $reason,
            ]);

            return $request->fresh([
                'business',
                'user',
                'requestedRole',
                'reviewer',
            ]);
        });
    }
}
