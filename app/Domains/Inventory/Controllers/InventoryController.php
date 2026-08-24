<?php

namespace App\Domains\Inventory\Controllers;

use App\Domains\Inventory\Models\Stock;
use App\Domains\Inventory\Services\StockService;
use App\Domains\Organization\Models\Business;
use App\Domains\Organization\Services\BusinessContextService;
use App\Domains\Product\Models\Product;
use App\Domains\Product\Models\ProductUnit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class InventoryController
{
    public function __construct(
        private readonly StockService $stockService
    ) {
    }

    /**
     * List inventory belonging to the current business.
     */
    public function index(Request $request): JsonResponse
    {
        $business = $this->currentBusiness($request);

        $stocks = Stock::query()
            ->where('business_id', $business->id)
            ->with([
                'product',
                'productUnit',
            ])
            ->orderBy('created_at')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $stocks,
        ]);
    }

    /**
     * Show a single stock record.
     */
    public function show(
        Request $request,
        Stock $stock
    ): JsonResponse {
        $business = $this->currentBusiness($request);

        $this->assertStockBelongsToBusiness(
            $stock,
            $business
        );

        $stock->load([
            'product',
            'productUnit',
        ]);

        return response()->json([
            'success' => true,
            'data' => $stock,
        ]);
    }

    /**
     * Receive stock.
     */
    public function receive(Request $request): JsonResponse
    {
        $business = $this->currentBusiness($request);

        $validated = $request->validate([
            'product_id' => [
                'required',
                'uuid',
            ],

            'product_unit_id' => [
                'required',
                'uuid',
            ],

            'quantity' => [
                'required',
                'numeric',
                'gt:0',
            ],

            'note' => [
                'nullable',
                'string',
                'max:1000',
            ],
        ]);

        [$product, $unit] = $this->resolveProductAndUnit(
            $business,
            $validated['product_id'],
            $validated['product_unit_id']
        );

        /*
         * IMPORTANT:
         *
         * StockService expects ?User.
         * Pass the authenticated User object,
         * NOT $request->user()->id.
         */
        $stock = $this->stockService->receive(
            $business,
            $product,
            $unit,
            (float) $validated['quantity'],
            $validated['note'] ?? null,
            $request->user()
        );

        $stock->load([
            'product',
            'productUnit',
        ]);

        return response()->json([
            'success' => true,
            'data' => $stock,
        ]);
    }

    /**
     * Adjust stock.
     *
     * Positive quantity increases stock.
     * Negative quantity decreases stock.
     */
    public function adjust(Request $request): JsonResponse
    {
        $business = $this->currentBusiness($request);

        $validated = $request->validate([
            'product_id' => [
                'required',
                'uuid',
            ],

            'product_unit_id' => [
                'required',
                'uuid',
            ],

            'quantity' => [
                'required',
                'numeric',
                'not_in:0',
            ],

            'note' => [
                'nullable',
                'string',
                'max:1000',
            ],
        ]);

        [$product, $unit] = $this->resolveProductAndUnit(
            $business,
            $validated['product_id'],
            $validated['product_unit_id']
        );

        $stock = $this->stockService->adjust(
            $business,
            $product,
            $unit,
            (float) $validated['quantity'],
            $validated['note'] ?? null,
            $request->user()
        );

        $stock->load([
            'product',
            'productUnit',
        ]);

        return response()->json([
            'success' => true,
            'data' => $stock,
        ]);
    }

    /**
     * Show stock movement history.
     */
    public function movements(
        Request $request,
        Stock $stock
    ): JsonResponse {
        $business = $this->currentBusiness($request);

        $this->assertStockBelongsToBusiness(
            $stock,
            $business
        );

        $movements = $stock->movements()
            ->orderByDesc('created_at')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $movements,
        ]);
    }

    /**
     * Resolve the authenticated user's current business.
     */
    private function currentBusiness(
        Request $request
    ): Business {
        $user = $request->user();

        abort_if(
            ! $user,
            401,
            'Unauthenticated.'
        );

        $business = app(
            BusinessContextService::class
        )->current($user);

        abort_if(
            ! $business,
            403,
            'No active business context.'
        );

        return $business;
    }

    /**
     * Resolve product and unit while enforcing
     * tenant isolation.
     */
    private function resolveProductAndUnit(
        Business $business,
        string $productId,
        string $unitId
    ): array {
        $product = Product::query()
            ->where('id', $productId)
            ->where('business_id', $business->id)
            ->first();

        if (! $product) {
            abort(
                403,
                'The product does not belong to the current business.'
            );
        }

        $unit = ProductUnit::query()
            ->where('id', $unitId)
            ->where('business_id', $business->id)
            ->where('product_id', $product->id)
            ->first();

        if (! $unit) {
            abort(
                403,
                'The product unit does not belong to this product and business.'
            );
        }

        return [
            $product,
            $unit,
        ];
    }

    /**
     * Ensure the stock belongs to the current business.
     */
    private function assertStockBelongsToBusiness(
        Stock $stock,
        Business $business
    ): void {
        if ($stock->business_id !== $business->id) {
            abort(
                403,
                'This stock record does not belong to the current business.'
            );
        }
    }
}