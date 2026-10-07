<?php

namespace App\Domains\Expenses\Controllers;

use App\Domains\Expenses\Models\Expense;
use App\Domains\Expenses\Requests\StoreExpenseCategoryRequest;
use App\Domains\Expenses\Requests\StoreExpenseRequest;
use App\Domains\Expenses\Requests\UpdateExpenseRequest;
use App\Domains\Expenses\Services\ExpenseService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ExpenseController extends Controller
{
    public function __construct(
        private readonly ExpenseService $expenseService,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $business = $this->currentBusiness($request);
        $branch = $this->currentBranch($request);

        return response()->json([
            'success' => true,
            'data' => $this->expenseService->list(
                $business,
                $branch,
                $request->only([
                    'start_date',
                    'end_date',
                    'category_id',
                    'search',
                    'per_page',
                ])
            ),
        ]);
    }

    public function store(StoreExpenseRequest $request): JsonResponse
    {
        $business = $this->currentBusiness($request);
        $branch = $this->currentBranch($request);

        return response()->json([
            'success' => true,
            'data' => $this->expenseService->create(
                $business,
                $branch,
                $request->user(),
                $request->validated()
            ),
        ], 201);
    }

    public function update(
        UpdateExpenseRequest $request,
        Expense $expense
    ): JsonResponse {
        $business = $this->currentBusiness($request);
        $branch = $this->currentBranch($request);

        return response()->json([
            'success' => true,
            'data' => $this->expenseService->update(
                $business,
                $branch,
                $expense,
                $request->validated()
            ),
        ]);
    }

    public function destroy(
        Request $request,
        Expense $expense
    ): JsonResponse {
        $business = $this->currentBusiness($request);
        $branch = $this->currentBranch($request);

        $this->expenseService->delete(
            $business,
            $branch,
            $expense
        );

        return response()->json([
            'success' => true,
            'message' => 'Expense deleted.',
        ]);
    }

    public function categories(Request $request): JsonResponse
    {
        $business = $this->currentBusiness($request);

        return response()->json([
            'success' => true,
            'data' => $this->expenseService->categories($business),
        ]);
    }

    public function storeCategory(
        StoreExpenseCategoryRequest $request
    ): JsonResponse {
        $business = $this->currentBusiness($request);

        return response()->json([
            'success' => true,
            'data' => $this->expenseService->createCategory(
                $business,
                $request->validated()
            ),
        ], 201);
    }

    public function summary(Request $request): JsonResponse
    {
        $business = $this->currentBusiness($request);
        $branch = $this->currentBranch($request);

        return response()->json([
            'success' => true,
            'data' => $this->expenseService->summary(
                $business,
                $branch,
                $request->input('start_date'),
                $request->input('end_date')
            ),
        ]);
    }
}
