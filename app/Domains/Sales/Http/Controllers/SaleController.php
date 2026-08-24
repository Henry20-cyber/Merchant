<?php

namespace App\Domains\Sales\Http\Controllers;

use App\Domains\Organization\Models\Business;
use App\Domains\Sales\Services\SaleService;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreSaleRequest;
use Illuminate\Http\JsonResponse;

class SaleController extends Controller
{
    public function store(
        StoreSaleRequest $request,
        SaleService $saleService
    ): JsonResponse {
        $user = $request->user();

        /*
         * The SetCurrentBusiness middleware has already
         * validated the business context and configured
         * Spatie's permission team.
         *
         * We still resolve the business from the explicit
         * request context so the service receives the actual
         * Business model.
         */
        $businessId = $request->header('X-Business-ID');

        if (! $businessId) {
            return response()->json([
                'success' => false,
                'message' => 'Business context is required.',
            ], 400);
        }

        $business = Business::query()
            ->where('id', $businessId)
            ->whereHas('memberships', function ($query) use ($user) {
                $query
                    ->where('user_id', $user->id)
                    ->where('status', 'active');
            })
            ->first();

        if (! $business) {
            return response()->json([
                'success' => false,
                'message' => 'You do not belong to this business.',
            ], 403);
        }

        /*
         * Sales are business-changing operations.
         */
        if (! $user->can('sales.create')) {
            return response()->json([
                'success' => false,
                'message' => 'You do not have permission to create sales.',
            ], 403);
        }

        $sale = $saleService->create(
            $business,
            $user,
            $request->validated('items'),
            [
                'customer_id' => $request->validated('customer_id'),
                'discount' => $request->validated('discount', 0),
                'tax' => $request->validated('tax', 0),
                'payment_method' => $request->validated(
                    'payment_method',
                    'cash'
                ),
                'payment_status' => $request->validated(
                    'payment_status',
                    'paid'
                ),
                'status' => $request->validated(
                    'status',
                    'completed'
                ),
            ]
        );

        return response()->json([
            'success' => true,
            'message' => 'Sale created successfully.',
            'data' => $sale,
        ], 201);
    }
}
