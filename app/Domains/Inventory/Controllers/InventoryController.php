<?php

namespace App\Domains\Inventory\Controllers;

use App\Domains\Inventory\Models\Stock;
use App\Domains\Inventory\Services\InventoryAnalyticsService;
use App\Domains\Inventory\Services\StockService;
use App\Domains\Organization\Models\Branch;
use App\Domains\Organization\Models\Business;
use App\Domains\Organization\Services\BranchContextService;
use App\Domains\Organization\Services\BusinessContextService;
use App\Domains\Product\Models\Product;
use App\Domains\Product\Models\ProductUnit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class InventoryController
{
    public function __construct(
        private readonly StockService $stockService,
        private readonly BusinessContextService $businessContextService,
        private readonly BranchContextService $branchContextService,
        private readonly InventoryAnalyticsService $analyticsService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        [$business, $branch] = $this->contexts($request);

        $stocks = Stock::query()
            ->where('business_id', $business->id)
            ->where('branch_id', $branch->id)
            ->with('product')
            ->orderBy('created_at')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $stocks,
        ]);
    }

    public function show(Request $request, Stock $stock): JsonResponse
    {
        [$business, $branch] = $this->contexts($request);

        $this->assertStockBelongsToContext($stock, $business, $branch);

        $stock->load('product');

        return response()->json([
            'success' => true,
            'data' => $stock,
        ]);
    }

    public function receive(Request $request): JsonResponse
    {
        [$business, $branch] = $this->contexts($request);

        $validated = $request->validate([
            'product_id' => ['required', 'uuid'],
            'product_unit_id' => ['required', 'uuid'],
            'quantity' => ['required', 'numeric', 'gt:0'],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        [$product, $unit] = $this->resolveProductAndUnit(
            $business,
            $validated['product_id'],
            $validated['product_unit_id']
        );

        $stock = $this->stockService->receive(
            $business,
            $branch,
            $product,
            $unit,
            (float) $validated['quantity'],
            $validated['note'] ?? null,
            $request->user()
        );

        $stock->load('product', 'branch');

        return response()->json([
            'success' => true,
            'data' => $stock,
        ]);
    }

    public function adjust(Request $request): JsonResponse
    {
        [$business, $branch] = $this->contexts($request);

        $validated = $request->validate([
            'product_id' => ['required', 'uuid'],
            'product_unit_id' => ['required', 'uuid'],
            'quantity' => ['required', 'numeric', 'not_in:0'],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        [$product, $unit] = $this->resolveProductAndUnit(
            $business,
            $validated['product_id'],
            $validated['product_unit_id']
        );

        $stock = $this->stockService->adjust(
            $business,
            $branch,
            $product,
            $unit,
            (float) $validated['quantity'],
            $validated['note'] ?? null,
            $request->user()
        );

        $stock->load('product', 'branch');

        return response()->json([
            'success' => true,
            'data' => $stock,
        ]);
    }

    public function transfer(Request $request): JsonResponse
    {
        [$business, $branch] = $this->contexts($request);

        $validated = $request->validate([
            'to_branch_id' => ['required', 'uuid'],
            'product_id' => ['required', 'uuid'],
            'product_unit_id' => ['required', 'uuid'],
            'quantity' => ['required', 'numeric', 'gt:0'],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        $toBranch = Branch::query()
            ->where('id', $validated['to_branch_id'])
            ->where('business_id', $business->id)
            ->first();

        if (! $toBranch) {
            abort(403, 'Destination branch does not belong to the current business.');
        }

        [$product, $unit] = $this->resolveProductAndUnit(
            $business,
            $validated['product_id'],
            $validated['product_unit_id']
        );

        $result = $this->stockService->transfer(
            $business,
            $branch,
            $toBranch,
            $product,
            $unit,
            (float) $validated['quantity'],
            $validated['note'] ?? null,
            $request->user()
        );

        $result['source']->load('product', 'branch');
        $result['destination']->load('product', 'branch');

        return response()->json([
            'success' => true,
            'message' => 'Stock transferred successfully.',
            'data' => $result,
        ]);
    }

    public function movements(Request $request, Stock $stock): JsonResponse
    {
        [$business, $branch] = $this->contexts($request);

        $this->assertStockBelongsToContext($stock, $business, $branch);

        $movements = $stock->movements()
            ->with(['productUnit', 'creator', 'branch'])
            ->orderByDesc('created_at')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $movements,
        ]);
    }

    public function analytics(Request $request): JsonResponse
    {
        [$business, $branch] = $this->contexts($request);

        $from = $request->date('from');
        $to = $request->date('to');

        return response()->json([
            'success' => true,
            'data' => $this->analyticsService->overview(
                $business,
                $from,
                $to,
                $branch
            ),
        ]);
    }

    private function contexts(Request $request): array
    {
        $user = $request->user();

        abort_if(! $user, 401, 'Unauthenticated.');

        $business = $this->businessContextService->current($user);

        abort_if(! $business, 403, 'No active business context.');

        $branch = $this->branchContextService->current(
            $user,
            $business
        );

        abort_if(! $branch, 400, 'No active branch context.');

        return [$business, $branch];
    }

    private function resolveProductAndUnit(
        Business $business,
        string $productId,
        string $unitId
    ): array {
        $product = Product::query()
            ->whereKey($productId)
            ->where('business_id', $business->id)
            ->first();

        if (! $product) {
            abort(403, 'The product does not belong to the current business.');
        }

        $unit = ProductUnit::query()
            ->whereKey($unitId)
            ->where('business_id', $business->id)
            ->where('product_id', $product->id)
            ->first();

        if (! $unit) {
            abort(403, 'The product unit does not belong to this product and business.');
        }

        return [$product, $unit];
    }

    private function assertStockBelongsToContext(
        Stock $stock,
        Business $business,
        Branch $branch
    ): void {
        if (
            $stock->business_id !== $business->id ||
            $stock->branch_id !== $branch->id
        ) {
            abort(403, 'This stock record does not belong to the current branch.');
        }
    }
}
