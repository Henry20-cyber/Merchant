<?php

namespace App\Domains\Organization\Services;

use App\Domains\Organization\Models\Business;
use App\Domains\Organization\Models\BusinessUser;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class BusinessMemberService
{
    /**
     * Get active members of a business.
     */
    public function activeMembers(
        Business $business
    ): Collection {
        return $business->memberships()
            ->with('user')
            ->where('status', 'active')
            ->get();
    }

    /**
     * Remove an employee from the current business.
     *
     * The global user account remains intact.
     * Only the business membership and business-scoped
     * role assignment are removed.
     */
   public function removeMember(
    Business $business,
    User $user,
    User $actor
): void {
    $membership = BusinessUser::query()
        ->where('business_id', $business->id)
        ->where('user_id', $user->id)
        ->where('status', 'active')
        ->first();

    if (! $membership) {
        abort(404, 'Business member not found.');
    }

    setPermissionsTeamId($business->id);

    if (! $actor->hasPermissionTo('users.remove')) {
        abort(403, 'You do not have permission to remove employees.');
    }

    if ($user->id === $actor->id) {
        throw ValidationException::withMessages([
            'member' => [
                'You cannot remove yourself from the business.',
            ],
        ]);
    }

    if ($user->hasRole('Owner')) {
        throw ValidationException::withMessages([
            'member' => [
                'The business Owner cannot be removed.',
            ],
        ]);
    }

    DB::transaction(function () use (
        $business,
        $user,
        $membership
    ) {
        $membership->update([
            'status' => 'inactive',
        ]);

        DB::table(
            config('permission.table_names.model_has_roles')
        )
            ->where(
                config('permission.column_names.model_morph_key'),
                $user->getKey()
            )
            ->where(
                'model_type',
                $user->getMorphClass()
            )
            ->where(
                config('permission.column_names.team_foreign_key'),
                $business->id
            )
            ->delete();
    });

    $user->unsetRelation('roles');
    $user->unsetRelation('permissions');

    app(
        \Spatie\Permission\PermissionRegistrar::class
    )->forgetCachedPermissions();
}
}