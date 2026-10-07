<?php

namespace App\Domains\Sales\Http\Controllers;

use App\Domains\Organization\Services\BranchContextService;
use App\Domains\Organization\Services\BusinessContextService;
use App\Domains\Sales\Models\Sale;
use App\Domains\Sales\Services\SaleService;
use App\Domains\Sales\Services\SalesAnalyticsService;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreSaleRequest;
use Carbon\Carbon;
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
        BusinessContextService $businessContext,
        BranchContextService $branchContext
    ): JsonResponse {
        $user = $request->user();

        $business = $businessContext->current($user);

        if (! $business) {
            return response()->json([
                'success' => false,
                'message' => 'Business context is required.',
            ], 400);
        }

        $branch = $branchContext->current(
            $user,
            $business
        );

        if (! $branch) {
            return response()->json([
                'success' => false,
                'message' => 'Branch context is required.',
            ], 400);
        }

        $sale = $saleService->create(
            $business,
            $branch,
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
                'due_at' => $request->validated('due_at'),
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
     * List sales for the current branch.
     */
    public function index(
        Request $request,
        BusinessContextService $businessContext,
        BranchContextService $branchContext
    ): JsonResponse {
        $user = $request->user();

        $business = $businessContext->current($user);

        if (! $business) {
            return response()->json([
                'success' => false,
                'message' => 'Business context is required.',
            ], 400);
        }

        $branch = $branchContext->current(
            $user,
            $business
        );

        if (! $branch) {
            return response()->json([
                'success' => false,
                'message' => 'Branch context is required.',
            ], 400);
        }

        $query = Sale::query()
            ->where('business_id', $business->id)
            ->where('branch_id', $branch->id)
            ->with([
                'customer:id,name,phone',
                'cashier:id,name',
                'receipt:id,sale_id,receipt_number,status',
                'items.product',
                'items.productUnit',
            ])
            ->latest();

        if ($request->filled('search')) {
            $search = trim(
                $request->string('search')->toString()
            );

            $query->where(function ($q) use ($search) {
                $q->where(
                    'id',
                    'ilike',
                    "%{$search}%"
                )->orWhereHas(
                    'customer',
                    function ($customerQuery) use ($search) {
                        $customerQuery
                            ->where(
                                'name',
                                'ilike',
                                "%{$search}%"
                            )
                            ->orWhere(
                                'email',
                                'ilike',
                                "%{$search}%"
                            )
                            ->orWhere(
                                'phone',
                                'ilike',
                                "%{$search}%"
                            );
                    }
                );
            });
        }

        if ($request->filled('payment_status')) {
            $query->where(
                'payment_status',
                $request->string('payment_status')->toString()
            );
        }

        if ($request->filled('status')) {
            $query->where(
                'status',
                $request->string('status')->toString()
            );
        }

        if ($request->filled('start_date')) {
            $query->whereDate(
                'created_at',
                '>=',
                $request->date('start_date')
            );
        }

        if ($request->filled('end_date')) {
            $query->whereDate(
                'created_at',
                '<=',
                $request->date('end_date')
            );
        }

        $perPage = min(
            max(
                (int) $request->input('per_page', 20),
                1
            ),
            100
        );

        $sales = $query->paginate($perPage);

        $sales->getCollection()->transform(
            function (Sale $sale) {
                $sale->setRelation(
                    'items',
                    $sale->items->map(function ($item) {
                        $conversionQuantity = $item->productUnit
                            ? (float) $item->productUnit->quantity
                            : 1;

                        $soldQuantity = (float) $item->quantity;

                        $item->setAttribute(
                            'base_quantity',
                            $soldQuantity * $conversionQuantity
                        );

                        $item->setAttribute(
                            'conversion_quantity',
                            $conversionQuantity
                        );

                        return $item;
                    })
                );

                return $sale;
            }
        );

        return response()->json([
            'success' => true,
            'data' => $sales,
        ]);
    }

    /**
     * Get sales dashboard analytics.
     *
     * Dashboard analytics are scoped to
     * the current business branch.
     */
    public function dashboard(
        Request $request,
        SalesAnalyticsService $analyticsService,
        BusinessContextService $businessContext,
        BranchContextService $branchContext
    ): JsonResponse {
        $user = $request->user();

        $business = $businessContext->current($user);

        if (! $business) {
            return response()->json([
                'success' => false,
                'message' => 'Business context is required.',
            ], 400);
        }

        $branch = $branchContext->current(
            $user,
            $business
        );

        if (! $branch) {
            return response()->json([
                'success' => false,
                'message' => 'Branch context is required.',
            ], 400);
        }

        $analytics = $analyticsService->dashboard(
            $business,
            $branch,
            now()
        );

        return response()->json([
            'success' => true,
            'data' => $analytics,
        ]);
    }

    /**
     * Get business-wide advanced analytics.
     */
    public function advancedAnalytics(
        Request $request,
        SalesAnalyticsService $analyticsService,
        BusinessContextService $businessContext
    ): JsonResponse {
        $business = $businessContext->current(
            $request->user()
        );

        if (! $business) {
            return response()->json([
                'success' => false,
                'message' => 'Business context is required.',
            ], 400);
        }

        $startDate = $request->filled('start_date')
            ? Carbon::parse(
                $request->input('start_date')
            )
            : null;

        $endDate = $request->filled('end_date')
            ? Carbon::parse(
                $request->input('end_date')
            )
            : null;

        $analytics = $analyticsService->advanced(
            $business,
            now(),
            $startDate,
            $endDate,
        );

        return response()->json([
            'success' => true,
            'data' => $analytics,
        ]);
    }

    /**
     * Show a sale belonging to the current branch.
     */
    public function show(
        Request $request,
        string $sale,
        BusinessContextService $businessContext,
        BranchContextService $branchContext
    ): JsonResponse {
        $user = $request->user();

        $business = $businessContext->current($user);

        if (! $business) {
            return response()->json([
                'success' => false,
                'message' => 'Business context is required.',
            ], 400);
        }

        $branch = $branchContext->current(
            $user,
            $business
        );

        if (! $branch) {
            return response()->json([
                'success' => false,
                'message' => 'Branch context is required.',
            ], 400);
        }

        $record = Sale::query()
            ->where('business_id', $business->id)
            ->where('branch_id', $branch->id)
            ->where('id', $sale)
            ->with([
                'customer',
                'cashier:id,name,email',
                'items.product',
                'items.productUnit',
                'items.service',
                'payments',
                'receipt',
            ])
            ->first();

        if (! $record) {
            return response()->json([
                'success' => false,
                'message' => 'Sale not found.',
            ], 404);
        }

        $record->setRelation(
            'items',
            $record->items->map(function ($item) {
                $conversionQuantity = $item->productUnit
                    ? (float) $item->productUnit->quantity
                    : 1;

                $soldQuantity = (float) $item->quantity;

                $item->setAttribute(
                    'base_quantity',
                    $soldQuantity * $conversionQuantity
                );

                $item->setAttribute(
                    'conversion_quantity',
                    $conversionQuantity
                );

                return $item;
            })
        );

        return response()->json([
            'success' => true,
            'data' => $record,
        ]);
    }
}