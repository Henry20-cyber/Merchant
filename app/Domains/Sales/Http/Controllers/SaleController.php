<?php

namespace App\Domains\Sales\Http\Controllers;

use App\Domains\Organization\Services\BusinessContextService;
use App\Domains\Sales\Services\SaleService;
use App\Domains\Sales\Services\SalesAnalyticsService;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreSaleRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SaleController extends Controller
{
    /**
     * Create a completed sale.
     */
    public function store(
        StoreSaleRequest $request,
        SaleService $saleService,
        BusinessContextService $businessContext
    ): JsonResponse {
        $user = $request->user();

        /*
         * The business.context middleware has already
         * established the authenticated user's current
         * business.
         */
        $business = $businessContext->current($user);

        if (! $business) {
            return response()->json([
                'success' => false,
                'message' => 'Business context is required.',
            ], 400);
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

    /**
     * Get sales dashboard analytics.
     */
    public function dashboard(
        Request $request,
        SalesAnalyticsService $analyticsService,
        BusinessContextService $businessContext
    ): JsonResponse {
        $user = $request->user();

        /*
         * Resolve the business from MerchantOS's existing
         * business context.
         *
         * Do NOT require X-Business-ID here.
         */
        $business = $businessContext->current($user);

        if (! $business) {
            return response()->json([
                'success' => false,
                'message' => 'Business context is required.',
            ], 400);
        }

        /*
         * SalesAnalyticsService owns the analytics logic.
         *
         * Controller responsibilities:
         * - authentication
         * - business context
         * - authorization middleware
         * - HTTP response
         */
        $analytics = $analyticsService->dashboard(
            $business,
            now()
        );

        return response()->json([
            'success' => true,
            'data' => $analytics,
        ]);
    }
}