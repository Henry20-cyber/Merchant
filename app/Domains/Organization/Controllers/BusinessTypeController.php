<?php

namespace App\Domains\Organization\Controllers;

use App\Http\Controllers\Controller;
use App\Domains\Organization\Services\BusinessTypeService;
use App\Domains\Organization\Resources\BusinessTypeResource;
use Illuminate\Http\JsonResponse;

class BusinessTypeController extends Controller
{
    public function __construct(
        protected BusinessTypeService $service
    ) {}

    public function index(): JsonResponse
    {
        $businessTypes = $this->service->index();

        return response()->json([
            'success' => true,
            'message' => 'Business types retrieved successfully.',
            'data' => BusinessTypeResource::collection($businessTypes),
        ]);
    }
}