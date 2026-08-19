<?php

namespace App\Domains\Organization\Controllers;

use App\Domains\Identity\Services\RoleService;
use App\Domains\Organization\Requests\AssignBusinessRoleRequest;
use App\Domains\Organization\Resources\BusinessMemberResource;
use App\Domains\Organization\Services\BusinessContextService;
use App\Domains\Organization\Services\BusinessMemberService;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BusinessMemberController
{
    /**
     * List active members of the current business.
     */
    public function index(
        Request $request,
        BusinessContextService $context,
        BusinessMemberService $memberService
    ): JsonResponse {
        $business = $context->current($request->user());

        if (! $business) {
            return response()->json([
                'success' => false,
                'message' => 'No active business selected.',
                'data' => null,
            ], 404);
        }

        $members = $memberService->activeMembers($business);

        return response()->json([
            'success' => true,
            'data' => BusinessMemberResource::collection($members),
        ]);
    }

    /**
     * Assign a business-scoped role to a member.
     */
    public function assignRole(
        AssignBusinessRoleRequest $request,
        User $user,
        BusinessContextService $context,
        RoleService $roleService
    ): JsonResponse {
        $business = $context->current($request->user());

        if (! $business) {
            return response()->json([
                'success' => false,
                'message' => 'No active business selected.',
                'data' => null,
            ], 404);
        }

        $role = $roleService->assignRole(
            $user,
            $request->validated('role'),
            $business->id
        );

        return response()->json([
            'success' => true,
            'message' => 'Role assigned successfully.',
            'data' => [
                'user_id' => $user->id,
                'role' => $role->name,
                'business_id' => $business->id,
            ],
        ]);
    }
}