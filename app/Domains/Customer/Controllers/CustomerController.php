<?php

namespace App\Domains\Customer\Controllers;

use App\Domains\Customer\Models\Customer;
use App\Domains\Customer\Resources\CustomerResource;
use App\Domains\Customer\Services\CustomerService;
use App\Domains\Organization\Services\BusinessContextService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CustomerController
{
    public function __construct(
        private CustomerService $customerService,
        private BusinessContextService $businessContext
    ) {
    }

    /**
     * Get the authenticated user's current business.
     */
    private function currentBusiness(Request $request)
    {
        $business = $this->businessContext->current(
            $request->user()
        );

        abort_if(
            ! $business,
            403,
            'No business context selected.'
        );

        return $business;
    }

    /**
     * Ensure the customer belongs to the current business.
     */
    private function ensureCustomerBelongsToBusiness(
        Customer $customer,
        $business
    ): void {
        abort_unless(
            $customer->business_id === $business->id,
            404
        );
    }

    /**
     * List customers belonging to the current business.
     */
    public function index(Request $request): JsonResponse
    {
        $business = $this->currentBusiness($request);

        $customers = Customer::query()
            ->where('business_id', $business->id)
            ->latest()
            ->get();

        return response()->json([
            'success' => true,
            'customers' => CustomerResource::collection(
                $customers
            ),
        ]);
    }

    /**
     * Create a customer.
     */
    public function store(Request $request): JsonResponse
    {
        $business = $this->currentBusiness($request);

        $validated = $request->validate([
            'name' => [
                'nullable',
                'string',
                'max:255',
            ],

            'phone' => [
                'nullable',
                'string',
                'max:50',
            ],
        ]);

        $customer = $this->customerService->create(
            $business,
            $validated
        );

        return response()->json([
            'success' => true,
            'message' => 'Customer created successfully.',
            'customer' => new CustomerResource($customer),
        ], 201);
    }

    /**
     * Show a customer belonging to the current business.
     */
    public function show(
        Request $request,
        Customer $customer
    ): JsonResponse {
        $business = $this->currentBusiness($request);

        $this->ensureCustomerBelongsToBusiness(
            $customer,
            $business
        );

        return response()->json([
            'success' => true,
            'customer' => new CustomerResource($customer),
        ]);
    }

    /**
     * Update a customer.
     */
    public function update(
        Request $request,
        Customer $customer
    ): JsonResponse {
        $business = $this->currentBusiness($request);

        $this->ensureCustomerBelongsToBusiness(
            $customer,
            $business
        );

        $validated = $request->validate([
            'name' => [
                'sometimes',
                'nullable',
                'string',
                'max:255',
            ],

            'phone' => [
                'sometimes',
                'nullable',
                'string',
                'max:50',
            ],

            'status' => [
                'sometimes',
                'string',
                'in:active,inactive',
            ],
        ]);

        $customer = $this->customerService->update(
            $business,
            $customer,
            $validated
        );

        return response()->json([
            'success' => true,
            'message' => 'Customer updated successfully.',
            'customer' => new CustomerResource($customer),
        ]);
    }

    /**
     * Deactivate a customer.
     */
    public function destroy(
        Request $request,
        Customer $customer
    ): JsonResponse {
        $business = $this->currentBusiness($request);

        $this->ensureCustomerBelongsToBusiness(
            $customer,
            $business
        );

        $customer = $this->customerService->deactivate(
            $business,
            $customer
        );

        return response()->json([
            'success' => true,
            'message' => 'Customer deactivated successfully.',
            'customer' => new CustomerResource($customer),
        ]);
    }
}
