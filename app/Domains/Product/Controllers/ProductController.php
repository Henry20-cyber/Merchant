<?php

namespace App\Domains\Product\Controllers;

use App\Http\Controllers\Controller;
use App\Domains\Product\Models\Product;
use App\Domains\Product\Models\ProductUnit;
use App\Domains\Product\Services\ProductService;
use App\Domains\Product\Models\ProductBarcode;
use App\Domains\Product\Services\ProductBarcodeRegistrationService;
use App\Domains\Product\Models\ProductBarcodeBranchMapping;
use Illuminate\Validation\ValidationException;
use App\Domains\Catalog\Models\Category;
use Illuminate\Validation\Rule;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProductController extends Controller
{
    public function __construct(
        private ProductService $productService,
        private ProductBarcodeRegistrationService $barcodeRegistrationService,
    ) {}



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
            ->with([
                'units',
                'category',
            ])
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
                'sometimes',
                'required',
                'string',
                'max:255',
            ],

            'sku' => [
                'sometimes',
                'nullable',
                'string',
                'max:255',
                Rule::unique('products', 'sku')
                    ->where(fn($query) => $query->where(
                        'business_id',
                        $business->id
                    )),
            ],

            'description' => [
                'nullable',
                'string',
            ],

            'image' => [
                'sometimes',
                'nullable',
                'image',
                'mimes:jpg,jpeg,png,webp',
                'max:5120',
            ],

            'status' => [
                'nullable',
                'string',
                'max:50',
            ],

            'category_id' => [
                'sometimes',
                'nullable',
                'uuid',
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
                'sku' => $validated['sku'] ?? null,
                'description' => $validated['description'] ?? null,
                'image' => $validated['image'] ?? null,
                'status' => $validated['status'] ?? 'active',
                'category_id' => $validated['category_id'] ?? null,
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

        $product->load('units', 'category');

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

        $product->load('units', 'category');

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
                'nullable',
                'string',
                'max:255',
                Rule::unique('products', 'sku')
                    ->where(fn($query) => $query->where(
                        'business_id',
                        $business->id
                    ))
                    ->ignore($product->id),
            ],

            'description' => [
                'nullable',
                'string',
            ],

            'image' => [
                'sometimes',
                'nullable',
                'image',
                'mimes:jpg,jpeg,png,webp',
                'max:5120',
            ],

            'status' => [
                'sometimes',
                'string',
                'max:50',
            ],

            'category_id' => [
                'sometimes',
                'nullable',
                'uuid',
            ],
        ]);

        $product = $this->productService->updateProduct(
            $product,
            $business,
            $validated
        );

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

    /**
     * Register a barcode for a specific sellable product unit.
     */
    public function storeBarcode(
        Request $request,
        Product $product
    ): JsonResponse {
        $business = $this->currentBusiness($request);

        $this->ensureProductBelongsToBusiness(
            $product,
            $business
        );

        $validated = $request->validate([
            'product_unit_id' => [
                'required',
                'uuid',
            ],
            'barcode' => [
                'required',
                'string',
                'max:255',
            ],
            'type' => [
                'sometimes',
                'required',
                'string',
                'max:50',
            ],
            'is_primary' => [
                'sometimes',
                'boolean',
            ],
        ]);

        $unit = ProductUnit::query()
            ->where('business_id', $business->id)
            ->where('product_id', $product->id)
            ->whereKey($validated['product_unit_id'])
            ->firstOrFail();

        $barcode = $this->barcodeRegistrationService->register(
            $business,
            $product,
            $unit,
            $validated['barcode'],
            $validated['type'] ?? 'manufacturer',
            $validated['is_primary'] ?? false,
        );

        return response()->json([
            'success' => true,
            'message' => 'Barcode registered successfully.',
            'barcode' => $barcode,
        ], 201);
    }

    /**
 * Resolve a barcode within the active business and branch.
 */
public function lookupBarcode(Request $request): JsonResponse
{
    $business = $this->currentBusiness($request);
    $branch = $this->currentBranch($request);

    abort_unless(
        $business->barcode_enabled,
        403,
        'Barcode functionality is not enabled for this business.'
    );

    abort_unless(
        $branch->business_id === $business->id,
        403,
        'The active branch does not belong to this business.'
    );

    $validated = $request->validate([
        'barcode' => ['required', 'string', 'max:255'],
    ]);

    $barcodeValue = trim($validated['barcode']);

    $mapping = ProductBarcodeBranchMapping::query()
        ->where('business_id', $business->id)
        ->where('branch_id', $branch->id)
        ->whereHas('productBarcode', function ($query) use (
            $business,
            $barcodeValue
        ) {
            $query
                ->where('business_id', $business->id)
                ->where('barcode', $barcodeValue)
                ->whereNotNull('product_unit_id');
        })
        ->with('productBarcode')
        ->first();

    if (! $mapping) {
        return response()->json([
            'success' => false,
            'message' => 'Barcode not found at this branch.',
        ], 404);
    }

    $barcode = $mapping->productBarcode;

    $product = Product::query()
        ->where('business_id', $business->id)
        ->whereKey($barcode->product_id)
        ->first();

    $unit = ProductUnit::query()
        ->where('business_id', $business->id)
        ->where('product_id', $barcode->product_id)
        ->whereKey($barcode->product_unit_id)
        ->where('is_sellable', true)
        ->whereNull('deleted_at')
        ->first();

    if (! $product || ! $unit) {
        return response()->json([
            'success' => false,
            'message' => 'Barcode not found at this branch.',
        ], 404);
    }

    return response()->json([
        'success' => true,
        'product' => $product,
        'unit' => $unit,
        'barcode' => $barcode,
    ]);
}
}
