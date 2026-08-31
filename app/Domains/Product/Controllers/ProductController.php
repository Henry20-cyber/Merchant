<?php

namespace App\Domains\Product\Controllers;

use App\Http\Controllers\Controller;
use App\Domains\Product\Models\Product;
use App\Domains\Product\Models\ProductUnit;
use App\Domains\Product\Services\ProductService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProductController extends Controller
{
    public function __construct(
        private ProductService $productService
    ) {
    }



    /**
     * Ensure the product belongs to the current business.
     */
    private function ensureProductBelongsToBusiness(
        Product $product,
        $business
    ): void {
        abort_unless(
            $product->business_id === $business->id,
            404
        );
    }

    /**
     * Ensure the unit belongs to the specified product.
     */
    private function ensureUnitBelongsToProduct(
        ProductUnit $unit,
        Product $product
    ): void {
        abort_unless(
            $unit->product_id === $product->id,
            404
        );
    }

    /**
     * List products belonging to the current business.
     */
    public function index(Request $request): JsonResponse
    {
        $business = $this->currentBusiness($request);

        $products = Product::query()
            ->where('business_id', $business->id)
            ->with('units')
            ->latest()
            ->get();

        return response()->json([
            'success' => true,
            'products' => $products,
        ]);
    }

    /**
     * Create a product with its mandatory base unit.
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

            'sku' => [
                'required',
                'string',
                'max:255',
            ],

            'description' => [
                'nullable',
                'string',
            ],

            'status' => [
                'nullable',
                'string',
                'max:50',
            ],

            'base_unit' => [
                'required',
                'array',
            ],

            'base_unit.name' => [
                'required',
                'string',
                'max:100',
            ],

            'base_unit.quantity' => [
                'nullable',
                'integer',
                'min:1',
            ],

            'base_unit.cost_price' => [
                'required',
                'numeric',
                'min:0',
            ],

            'base_unit.selling_price' => [
                'required',
                'numeric',
                'min:0',
            ],

            'base_unit.currency' => [
                'required',
                'string',
                'size:3',
            ],

            'base_unit.is_sellable' => [
                'nullable',
                'boolean',
            ],

            'base_unit.is_purchasable' => [
                'nullable',
                'boolean',
            ],
        ]);

        $product = $this->productService->createProduct(
            $business,
            [
                'name' => $validated['name'],
                'sku' => $validated['sku'],
                'description' => $validated['description'] ?? null,
                'status' => $validated['status'] ?? 'active',
            ],
            [
                'name' => $validated['base_unit']['name'],
                'quantity' => $validated['base_unit']['quantity'] ?? 1,
                'cost_price' => $validated['base_unit']['cost_price'],
                'selling_price' => $validated['base_unit']['selling_price'],
                'currency' => $validated['base_unit']['currency'],
                'is_sellable' =>
                    $validated['base_unit']['is_sellable'] ?? true,
                'is_purchasable' =>
                    $validated['base_unit']['is_purchasable'] ?? true,
            ]
        );

        $product->load('units');

        return response()->json([
            'success' => true,
            'product' => $product,
        ], 201);
    }

    /**
     * Show a product belonging to the current business.
     */
    public function show(
        Request $request,
        Product $product
    ): JsonResponse {
        $business = $this->currentBusiness($request);

        $this->ensureProductBelongsToBusiness(
            $product,
            $business
        );

        $product->load('units');

        return response()->json([
            'success' => true,
            'product' => $product,
        ]);
    }

    /**
     * Update a product.
     */
    public function update(
        Request $request,
        Product $product
    ): JsonResponse {
        $business = $this->currentBusiness($request);

        $this->ensureProductBelongsToBusiness(
            $product,
            $business
        );

        $validated = $request->validate([
            'name' => [
                'sometimes',
                'required',
                'string',
                'max:255',
            ],

            'sku' => [
                'sometimes',
                'required',
                'string',
                'max:255',
            ],

            'description' => [
                'nullable',
                'string',
            ],

            'status' => [
                'sometimes',
                'string',
                'max:50',
            ],
        ]);

        $product->update($validated);

        $product->load('units');

        return response()->json([
            'success' => true,
            'product' => $product,
        ]);
    }

    /**
     * Delete a product.
     */
    public function destroy(
        Request $request,
        Product $product
    ): JsonResponse {
        $business = $this->currentBusiness($request);

        $this->ensureProductBelongsToBusiness(
            $product,
            $business
        );

        $product->delete();

        return response()->json([
            'success' => true,
            'message' => 'Product deleted successfully.',
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Product Units
    |--------------------------------------------------------------------------
    */

    /**
     * Add a non-base/bulk unit to a product.
     *
     * Example:
     *
     * Carton = 12 Pieces
     */
    public function storeUnit(
        Request $request,
        Product $product
    ): JsonResponse {
        $business = $this->currentBusiness($request);

        $this->ensureProductBelongsToBusiness(
            $product,
            $business
        );

        $validated = $request->validate([
            'name' => [
                'required',
                'string',
                'max:100',
            ],

            'quantity' => [
                'required',
                'integer',
                'min:2',
            ],

            'cost_price' => [
                'required',
                'numeric',
                'min:0',
            ],

            'selling_price' => [
                'required',
                'numeric',
                'min:0',
            ],

            'currency' => [
                'nullable',
                'string',
                'size:3',
            ],

            'is_sellable' => [
                'nullable',
                'boolean',
            ],

            'is_purchasable' => [
                'nullable',
                'boolean',
            ],
        ]);

        $unit = $this->productService->addUnit(
            $product,
            $business,
            $validated
        );

        return response()->json([
            'success' => true,
            'message' => 'Product unit created successfully.',
            'unit' => $unit,
        ], 201);
    }

    /**
     * Update a product unit.
     */
    public function updateUnit(
        Request $request,
        Product $product,
        ProductUnit $unit
    ): JsonResponse {
        $business = $this->currentBusiness($request);

        $this->ensureProductBelongsToBusiness(
            $product,
            $business
        );

        $this->ensureUnitBelongsToProduct(
            $unit,
            $product
        );

        $validated = $request->validate([
            'name' => [
                'sometimes',
                'required',
                'string',
                'max:100',
            ],

            'quantity' => [
                'sometimes',
                'required',
                'integer',
                'min:1',
            ],

            'cost_price' => [
                'sometimes',
                'required',
                'numeric',
                'min:0',
            ],

            'selling_price' => [
                'sometimes',
                'required',
                'numeric',
                'min:0',
            ],

            'currency' => [
                'sometimes',
                'required',
                'string',
                'size:3',
            ],

            'is_sellable' => [
                'sometimes',
                'boolean',
            ],

            'is_purchasable' => [
                'sometimes',
                'boolean',
            ],
        ]);

        $unit = $this->productService->updateUnit(
            $unit,
            $business,
            $validated
        );

        return response()->json([
            'success' => true,
            'message' => 'Product unit updated successfully.',
            'unit' => $unit,
        ]);
    }

    /**
     * Promote a unit to become the base unit.
     *
     * The service itself enforces the quantity = 1 rule.
     */
    public function setBaseUnit(
        Request $request,
        Product $product,
        ProductUnit $unit
    ): JsonResponse {
        $business = $this->currentBusiness($request);

        $this->ensureProductBelongsToBusiness(
            $product,
            $business
        );

        $this->ensureUnitBelongsToProduct(
            $unit,
            $product
        );

        $unit = $this->productService->setBaseUnit(
            $unit,
            $business
        );

        return response()->json([
            'success' => true,
            'message' => 'Product base unit updated successfully.',
            'unit' => $unit,
        ]);
    }

    /**
     * Delete a product unit.
     *
     * The base unit cannot be deleted.
     */
    public function destroyUnit(
        Request $request,
        Product $product,
        ProductUnit $unit
    ): JsonResponse {
        $business = $this->currentBusiness($request);

        $this->ensureProductBelongsToBusiness(
            $product,
            $business
        );

        $this->ensureUnitBelongsToProduct(
            $unit,
            $product
        );

        $this->productService->removeUnit(
            $unit,
            $business
        );

        return response()->json([
            'success' => true,
            'message' => 'Product unit deleted successfully.',
        ]);
    }
}