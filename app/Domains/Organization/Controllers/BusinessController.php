<?php

namespace App\Domains\Organization\Controllers;

use App\Http\Controllers\Controller;
use App\Domains\Organization\Requests\StoreBusinessRequest;
use App\Domains\Organization\Resources\BusinessResource;
use App\Domains\Organization\Services\BusinessService;

class BusinessController extends Controller
{
    public function __construct(
        private BusinessService $businessService
    ) {
    }

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
}