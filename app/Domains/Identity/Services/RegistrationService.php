<?php

namespace App\Domains\Identity\Services;

use App\Domains\Organization\Models\BusinessUser;
use App\Domains\Organization\Services\BusinessService;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class RegistrationService
{
    public function __construct(
        private BusinessService $businessService,
        private RoleService $roleService,
    ) {}

    public function register(array $data): array
    {
        return DB::transaction(function () use ($data) {

            /*
             * 1. Create the owner account.
             */
            $owner = User::create([
                'name' => $data['owner']['name'],
                'email' => $data['owner']['email'],
                'password' => Hash::make(
                    $data['owner']['password']
                ),
            ]);

            /*
             * 2. Create the business and its
             *    business-level infrastructure.
             */
            $business = $this->businessService->registerBusiness(
                $data['business']
            );

            /*
             * 3. Create the owner's membership.
             */
            $membership = BusinessUser::create([
                'business_id' => $business->id,
                'user_id' => $owner->id,
                'status' => 'active',
                'joined_at' => now(),
            ]);

            /*
             * 4. Provision business roles and assign
             *    the Owner role to the creator.
             */
            $this->roleService->assignOwner(
                $owner,
                $business->id
            );

            /*
             * 5. Authenticate the newly-created owner.
             *
             * This is what allows the frontend to continue
             * directly into the dashboard.
             */
            Auth::login($owner);

            /*
             * 6. Establish the current business context.
             */
            session([
                'current_business_id' => $business->id,
            ]);

            return [
                'user' => $owner,
                'business' => $business,
                'membership' => $membership,
            ];
        });
    }
}