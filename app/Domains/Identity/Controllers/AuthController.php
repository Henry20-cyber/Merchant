<?php

namespace App\Domains\Identity\Controllers;

use App\Domains\Identity\Requests\LoginRequest;
use Illuminate\Support\Facades\Auth;
use App\Domains\Identity\Requests\RegisterRequest;
use App\Domains\Identity\Services\RegistrationService;
use App\Domains\Identity\Services\RoleService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Hash;
use App\Domains\Identity\Requests\ForgotPasswordRequest;
use App\Domains\Identity\Requests\ResetPasswordRequest;
use App\Domains\Identity\Requests\UpdateProfileRequest;
use App\Domains\Identity\Services\AuthenticationService;
use App\Domains\Organization\Services\BusinessContextService;

class AuthController extends Controller
{
    public function __construct(
        private RegistrationService $registrationService,
        private AuthenticationService $authenticationService,
        private BusinessContextService $businessContext,
        private RoleService $roleService,
    ) {}

    /**
     * Register a new MerchantOS owner and business.
     */
    public function register(RegisterRequest $request): JsonResponse
    {
        $result = $this->registrationService->register(
            $request->validated()
        );

        return response()->json([
            'success' => true,
            'message' => 'Account and business registered successfully.',

            'data' => [
                'user' => [
                    'id' => $result['user']->id,
                    'name' => $result['user']->name,
                    'email' => $result['user']->email,
                ],

                'business' => [
                    'id' => $result['business']->id,
                    'merchant_id' => $result['business']->merchant_id,
                    'name' => $result['business']->name,
                    'slug' => $result['business']->slug,
                    'status' => $result['business']->status,
                ],

                'membership' => [
                    'id' => $result['membership']->id,
                    'status' => $result['membership']->status,
                    'role' => 'Owner',
                ],
            ],
        ], 201);
    }

    public function login(LoginRequest $request): JsonResponse
    {
        $user = $this->authenticationService->attempt(
            $request->validated('identifier'),
            $request->validated('password')
        );

        if (! $user) {
            return response()->json([
                'success' => false,
                'message' => 'These credentials do not match our records.',
            ], 401);
        }

        /*
     * Prevent a previous business context from surviving
     * into this authenticated session.
     */
        $this->businessContext->clear();

        /*
     * Prevent session fixation.
     */
        $request->session()->regenerate();

        $businesses = $this->businessContext
            ->activeBusinesses($user);

        $currentBusiness = null;

        /*
     * If the user belongs to exactly one active business,
     * automatically select it.
     */
        if ($businesses->count() === 1) {
            $currentBusiness = $businesses->first();

            $this->businessContext->set(
                $user,
                $currentBusiness
            );
        }

        return response()->json([
            'success' => true,
            'message' => 'Login successful.',
            'data' => [
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                ],

                'business' => $currentBusiness ? [
                    'id' => $currentBusiness->id,
                    'merchant_id' => $currentBusiness->merchant_id,
                    'name' => $currentBusiness->name,
                    'slug' => $currentBusiness->slug,
                ] : null,

                'requires_business_selection' =>
                $businesses->count() > 1,
            ],
        ]);
    }

   /**
 * Return the authenticated user's current business context
 * and effective permissions.
 */
public function me(Request $request): JsonResponse
{
    $user = $request->user();

    $business = $this->businessContext->current($user);

    $role = null;
    $permissions = [];

    if ($business) {
        $role = $this->roleService->getBusinessRole(
            $user,
            $business->id
        );

        $permissions = $this->roleService->getEffectivePermissions(
            $user,
            $business->id
        );
    }

    return response()->json([
        'success' => true,

        'user' => [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'phone' => $user->phone,
        ],

        'business' => $business
            ? [
                'id' => $business->id,
                'merchant_id' => $business->merchant_id,
                'name' => $business->name,
                'slug' => $business->slug,
                'phone' => $business->phone,
                'email' => $business->email,
                'address' => $business->address,
                'city' => $business->city,
                'state' => $business->state,
                'status' => $business->status,
            ]
            : null,

        'membership' => [
            'role' => $role?->name,
        ],

        'permissions' => $permissions,
    ]);
}


    public function updateProfile(UpdateProfileRequest $request): JsonResponse
    {
        $user = $request->user();
        $user->update($request->validated());
        return response()->json([
            'success' => true,
            'message' => 'Profile updated successfully.',
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'phone' => $user->phone,
            ],
        ]);
    }

    public function forgotPassword(ForgotPasswordRequest $request): JsonResponse
    {
        $status = Password::sendResetLink($request->validated());

        // Never reveal whether an email exists in MerchantOS.
        if ($status !== Password::RESET_LINK_SENT) {
            return response()->json([
                'success' => true,
                'message' => 'If an account exists for that email, a password reset link has been sent.',
            ]);
        }

        return response()->json([
            'success' => true,
            'message' => 'If an account exists for that email, a password reset link has been sent.',
        ]);
    }

    public function resetPassword(ResetPasswordRequest $request): JsonResponse
    {
        $status = Password::reset(
            $request->validated(),
            function ($user, $password) {
                $user->forceFill(['password' => Hash::make($password)])->save();
                $user->setRememberToken(str()->random(60));
            }
        );

        if ($status !== Password::PASSWORD_RESET) {
            return response()->json([
                'success' => false,
                'message' => __($status),
            ], 422);
        }

        return response()->json([
            'success' => true,
            'message' => 'Password reset successfully. You can now sign in.',
        ]);
    }

    public function logout(): JsonResponse
    {
        $this->authenticationService->logout();

        $this->businessContext->clear();

        request()->session()->invalidate();
        request()->session()->regenerateToken();

        return response()->json([
            'success' => true,
            'message' => 'Logout successful.',
        ]);
    }
}
