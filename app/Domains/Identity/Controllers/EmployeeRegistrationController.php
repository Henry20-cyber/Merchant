<?php

namespace App\Domains\Identity\Controllers;

use App\Domains\Identity\Requests\EmployeeRegistrationRequest;
use App\Domains\Identity\Services\EmployeeRegistrationService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

class EmployeeRegistrationController extends Controller
{
    public function __construct(
        private EmployeeRegistrationService $employeeRegistrationService,
    ) {}

    /**
     * Register an employee and submit a business join request.
     */
    public function register(
        EmployeeRegistrationRequest $request
    ): JsonResponse {
        $result = $this->employeeRegistrationService->register(
            $request->validated()
        );

        return response()->json([
            'success' => true,
            'message' => 'Employee account created. Your join request is pending approval.',
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
                ],

                'join_request' => [
                    'id' => $result['join_request']->id,
                    'status' => $result['join_request']->status,
                    'requested_role_id' =>
                        $result['join_request']->requested_role_id,
                    'requested_at' =>
                        $result['join_request']->requested_at,
                ],
            ],
        ], 201);
    }
}
