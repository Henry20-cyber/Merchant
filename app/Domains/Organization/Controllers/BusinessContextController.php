<?php

namespace App\Domains\Organization\Controllers;

use App\Domains\Organization\Models\Business;
use App\Domains\Organization\Services\BusinessContextService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BusinessContextController
{
    public function set(
        Request $request,
        Business $business,
        BusinessContextService $context
    ): JsonResponse {
        $user = $request->user();

        $context->set($user, $business);

        return response()->json([
            'success' => true,
            'message' => 'Business context switched successfully.',
            'data' => [
                'business_id' => $business->id,
                'business_name' => $business->name,
            ],
        ]);
    }

    public function current(
        Request $request,
        BusinessContextService $context
    ): JsonResponse {
        $business = $context->current($request->user());

        if (! $business) {
            return response()->json([
                'success' => false,
                'message' => 'No active business selected.',
                'data' => null,
            ], 404);
        }

        return response()->json([
            'success' => true,
            'message' => 'Current business retrieved successfully.',
            'data' => [
                'business_id' => $business->id,
                'business_name' => $business->name,
            ],
        ]);
    }

    public function clear(
        BusinessContextService $context
    ): JsonResponse {
        $context->clear();

        return response()->json([
            'success' => true,
            'message' => 'Business context cleared.',
        ]);
    }

    
}