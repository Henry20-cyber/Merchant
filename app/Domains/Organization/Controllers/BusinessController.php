<?php

namespace App\Domains\Organization\Controllers;

use App\Http\Controllers\Controller;
use App\Domains\Organization\Requests\StoreBusinessRequest;
use App\Domains\Organization\Resources\BusinessResource;
use App\Domains\Organization\Services\BusinessService;
use App\Domains\Organization\Services\BusinessContextService;
use App\Domains\Organization\Requests\UpdateBusinessRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BusinessController extends Controller
{
    public function __construct(
        private BusinessService $businessService
    ) {
    }

    /**
     * Register a new business.
     */
    public function store(StoreBusinessRequest $request)
    {
        $business = $this->businessService->registerBusiness(
            $request->validated()
        );

        return response()->json([
            'success' => true,
            'message' => 'Business registered successfully.',
            'data' => new BusinessResource($business),
        ], 201);
    }

    /**
     * Get the currently active business.
     */
    public function current(
        Request $request,
        BusinessContextService $businessContext
    ): JsonResponse {
        $user = $request->user();

        $business = $businessContext->current($user);

        if (! $business) {
            return response()->json([
                'success' => false,
                'message' => 'No active business found.',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'id' => $business->id,
                'name' => $business->name,
                'slug' => $business->slug,
                'status' => $business->status,
            ],
        ]);
    }

    /**
 * Get a business accessible to the authenticated user.
 */
public function show(
    Request $request,
    string $business,
    BusinessService $businessService
): JsonResponse {
    $user = $request->user();

    $businessModel = $businessService->getForUser(
        $user,
        $business
    );

    return response()->json([
        'success' => true,
        'data' => new BusinessResource($businessModel),
    ]);
}

/**
 * Update a business.
 */
public function update(
    UpdateBusinessRequest $request,
    string $business,
    BusinessService $businessService
): JsonResponse {
    $updatedBusiness = $businessService->updateForUser(
        $request->user(),
        $business,
        $request->validated()
    );

    return response()->json([
        'success' => true,
        'message' => 'Business updated successfully.',
        'data' => new BusinessResource($updatedBusiness),
    ]);
}
}