<?php

namespace App\Domains\Identity\Services;

use App\Domains\Organization\Models\BusinessUser;
use App\Domains\Organization\Services\BusinessService;
use App\Domains\Organization\Services\MerchantOSTeamResolver;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class RegistrationService
{
    public function __construct(
        private BusinessService $businessService,
        private AuthenticationService $authenticationService,
        private RoleService $roleService,
        private MerchantOSTeamResolver $teamResolver
    ) {
    }

    /**
     * Register a new MerchantOS owner and business.
     */
    public function register(array $data): array
    {
        return DB::transaction(function () use ($data) {
            $user = User::create([
                'name' => $data['owner']['name'],
                'email' => $data['owner']['email'],
                'password' => $data['owner']['password'],
            ]);

            $business = $this->businessService->registerBusiness(
                $data['business']
            );

            $membership = BusinessUser::create([
                'business_id' => $business->id,
                'user_id' => $user->id,
                'status' => 'active',
                'joined_at' => now(),
            ]);

            /*
             * Authenticate the newly registered owner.
             */
            $this->authenticationService->login($user);

            /*
             * Make the newly created business the active Spatie team.
             */
            $this->teamResolver->setPermissionsTeamId(
                $business->id
            );

            /*
             * Create and assign the business-scoped Owner role.
             */
            $this->roleService->assignOwner(
                $user,
                $business->id
            );

            return [
                'user' => $user,
                'business' => $business,
                'membership' => $membership,
            ];
        });
    }
}