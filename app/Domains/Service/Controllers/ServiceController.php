<?php

namespace App\Domains\Service\Controllers;

use App\Domains\Service\Models\Service;
use App\Domains\Service\Services\ServiceService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ServiceController extends Controller
{
    public function __construct(
        private ServiceService $serviceService
    ) {
    }

    /**
     * Ensure the service belongs to the current business.
     */
    private function ensureServiceBelongsToBusiness(
        Service $service,
        $business
    ): void {
        abort_unless(
            $service->business_id === $business->id,
            404
        );
    }

    /**
     * List services belonging to the current business.
     */
    public function index(Request $request): JsonResponse
    {
        $business = $this->currentBusiness($request);

        $services = Service::query()
            ->where('business_id', $business->id)
            ->with('category')
            ->latest()
            ->get();

        return response()->json([
            'success' => true,
            'services' => $services,
        ]);
    }

    /**
     * Create a service.
     */
    public function store(Request $request): JsonResponse
    {
        $business = $this->currentBusiness($request);

        $validated = $request->validate([
            'name' => [
                'required',
                'string',
                'max:255',
            ],

            'description' => [
                'nullable',
                'string',
            ],

            'price' => [
                'required',
                'numeric',
                'min:0',
            ],

            'is_active' => [
                'nullable',
                'boolean',
            ],

            'category_id' => [
                'nullable',
                'uuid',
            ],
        ]);

        $service = $this->serviceService->create(
            $business,
            $validated
        );

        return response()->json([
            'success' => true,
            'service' => $service,
        ], 201);
    }

    /**
     * Show a service belonging to the current business.
     */
    public function show(
        Request $request,
        Service $service
    ): JsonResponse {
        $business = $this->currentBusiness($request);

        $this->ensureServiceBelongsToBusiness(
            $service,
            $business
        );

        $service->load('category');

        return response()->json([
            'success' => true,
            'service' => $service,
        ]);
    }

    /**
     * Update a service.
     */
    public function update(
        Request $request,
        Service $service
    ): JsonResponse {
        $business = $this->currentBusiness($request);

        $this->ensureServiceBelongsToBusiness(
            $service,
            $business
        );

        $validated = $request->validate([
            'name' => [
                'sometimes',
                'required',
                'string',
                'max:255',
            ],

            'description' => [
                'sometimes',
                'nullable',
                'string',
            ],

            'price' => [
                'sometimes',
                'numeric',
                'min:0',
            ],

            'is_active' => [
                'sometimes',
                'boolean',
            ],

            'category_id' => [
                'sometimes',
                'nullable',
                'uuid',
            ],
        ]);

        $service = $this->serviceService->update(
            $service,
            $business,
            $validated
        );

        return response()->json([
            'success' => true,
            'service' => $service,
        ]);
    }

    /**
     * Delete a service.
     */
    public function destroy(
        Request $request,
        Service $service
    ): JsonResponse {
        $business = $this->currentBusiness($request);

        $this->ensureServiceBelongsToBusiness(
            $service,
            $business
        );

        $this->serviceService->delete(
            $service,
            $business
        );

        return response()->json([
            'success' => true,
            'message' => 'Service deleted successfully.',
        ]);
    }
}