<?php

namespace App\Domains\Identity\Controllers;

use App\Domains\Identity\Requests\CreateBusinessRoleRequest;
use App\Domains\Identity\Requests\UpdateBusinessRoleRequest;
use App\Domains\Identity\Resources\RoleResource;
use App\Domains\Identity\Services\RoleManagementService;
use App\Domains\Organization\Services\BusinessContextService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RoleController
{
    /**
     * Create a custom role for the current business.
     */
    public function store(
        CreateBusinessRoleRequest $request,
        BusinessContextService $context,
        RoleManagementService $roleManagementService
    ): JsonResponse {
        $business = $context->current($request->user());

        if (! $business) {
            return response()->json([
                'success' => false,
                'message' => 'No active business selected.',
                'data' => null,
            ], 404);
        }

        $role = $roleManagementService->create(
            $request->user(),
            $business->id,
            $request->validated('name'),
            $request->validated('permissions')
        );

        return response()->json([
            'success' => true,
            'message' => 'Role created successfully.',
            'data' => [
                'id' => $role->id,
                'name' => $role->name,
                'business_id' => $role->team_id,
                'is_system' => $role->is_system,
                'permissions' => $role->permissions
                    ->pluck('name')
                    ->values(),
            ],
        ], 201);
    }

    /**
 * List roles belonging to the current business.
 */
public function index(
    Request $request,
    BusinessContextService $context,
    RoleManagementService $roleManagementService
): JsonResponse {
    $business = $context->current($request->user());

    if (! $business) {
        return response()->json([
            'success' => false,
            'message' => 'No active business selected.',
            'data' => null,
        ], 404);
    }

    $roles = $roleManagementService->listForBusiness(
        $business->id
    );

    return response()->json([
        'success' => true,
        'data' => RoleResource::collection($roles),
    ]);
}

/**
 * Update a custom role belonging to the current business.
 */
public function update(
    UpdateBusinessRoleRequest $request,
    string $role,
    BusinessContextService $context,
    RoleManagementService $roleManagementService
): JsonResponse {
    $business = $context->current($request->user());

    if (! $business) {
        return response()->json([
            'success' => false,
            'message' => 'No active business selected.',
            'data' => null,
        ], 404);
    }

    $updatedRole = $roleManagementService->update(
        $request->user(),
        $business->id,
        $role,
        $request->validated('name'),
        $request->validated('permissions')
    );

    return response()->json([
        'success' => true,
        'message' => 'Role updated successfully.',
        'data' => new RoleResource($updatedRole),
    ]);
}
}