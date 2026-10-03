<?php

namespace App\Domains\Identity\Services;

use App\Domains\Organization\Models\Business;
use App\Domains\Organization\Services\BusinessJoinRequestService;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class EmployeeRegistrationService
{
    public function __construct(
        private BusinessJoinRequestService $joinRequestService,
    ) {}

    public function register(array $data): array
    {
        return DB::transaction(function () use ($data) {
            $merchantId = strtoupper(trim($data['merchant_id']));

            $business = Business::query()
                ->where('merchant_id', $merchantId)
                ->whereNull('deleted_at')
                ->first();

            if (! $business) {
                throw ValidationException::withMessages([
                    'merchant_id' => 'Business not found.',
                ]);
            }

            if (
                User::query()
                    ->where('email', $data['email'])
                    ->exists()
            ) {
                throw ValidationException::withMessages([
                    'email' => 'A user with this email already exists.',
                ]);
            }

            $user = User::create([
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => Hash::make($data['password']),
            ]);

            $joinRequest = $this->joinRequestService->submit(
                $user,
                $merchantId,
                $data['requested_role_id'] ?? null,
            );

            return [
                'user' => $user,
                'business' => $business,
                'join_request' => $joinRequest,
            ];
        });
    }
}