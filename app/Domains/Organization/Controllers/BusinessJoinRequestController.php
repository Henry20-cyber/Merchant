<?php

namespace App\Domains\Organization\Controllers;

use App\Domains\Organization\Models\BusinessJoinRequest;
use App\Domains\Organization\Services\BusinessContextService;
use App\Domains\Organization\Services\BusinessJoinRequestService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BusinessJoinRequestController extends Controller
{
    public function __construct(
        protected BusinessJoinRequestService $joinRequestService,
        protected BusinessContextService $businessContextService,
    ) {
    }

    /**
     * Submit a request to join a business.
     *
     * Employee does not need a current business context.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'merchant_id' => [
                'required',
                'string',
                'max:10',
            ],

            'requested_role_id' => [
                'nullable',
                'integer',
            ],
        ]);

        $joinRequest = $this->joinRequestService->submit(
            $request->user(),
            $validated['merchant_id'],
            $validated['requested_role_id'] ?? null,
        );

        return response()->json([
            'success' => true,
            'message' => 'Join request submitted successfully.',
            'data' => [
                'join_request' => $joinRequest->load([
                    'business',
                    'requestedRole',
                ]),
            ],
        ], 201);
    }


    /**
     * Retrieve pending join requests for the current business.
     *
     * Owner and Manager access this endpoint.
     */
    public function index(Request $request): JsonResponse
    {
        $business = $this->businessContextService
            ->current($request->user());

        abort_unless(
            $business,
            404,
            'Current business not found.'
        );

        $joinRequests = $this->joinRequestService
            ->pending($business);

        return response()->json([
            'success' => true,
            'data' => [
                'join_requests' => $joinRequests,
            ],
        ]);
    }


    /**
     * Approve a pending join request.
     *
     * The approver chooses the final role.
     * The requested role is only a suggestion from the employee.
     */
    public function approve(
        Request $request,
        BusinessJoinRequest $joinRequest
    ): JsonResponse {
        $validated = $request->validate([
            'role_id' => [
                'required',
                'integer',
            ],
        ]);

        $business = $this->businessContextService
            ->current($request->user());

        abort_unless(
            $business,
            404,
            'Current business not found.'
        );

        $joinRequest = $this->joinRequestService->approve(
            $joinRequest,
            $request->user(),
            (int) $validated['role_id'],
            $business,
        );

        return response()->json([
            'success' => true,
            'message' => 'Join request approved successfully.',
            'data' => [
                'join_request' => $joinRequest,
            ],
        ]);
    }


    /**
     * Reject a pending join request.
     */
    public function reject(
        Request $request,
        BusinessJoinRequest $joinRequest
    ): JsonResponse {
        $validated = $request->validate([
            'rejection_reason' => [
                'nullable',
                'string',
                'max:1000',
            ],
        ]);

        $business = $this->businessContextService
            ->current($request->user());

        abort_unless(
            $business,
            404,
            'Current business not found.'
        );

        $joinRequest = $this->joinRequestService->reject(
            $joinRequest,
            $request->user(),
            $business,
            $validated['rejection_reason'] ?? null,
        );

        return response()->json([
            'success' => true,
            'message' => 'Join request rejected.',
            'data' => [
                'join_request' => $joinRequest,
            ],
        ]);
    }
}