<?php

namespace App\Domains\Catalog\Controllers;

use App\Domains\Catalog\Models\Category;
use App\Domains\Catalog\Services\CategoryService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CategoryController extends Controller
{
    public function __construct(
        private CategoryService $categoryService
    ) {
    }

    private function ensureCategoryBelongsToBusiness(
        Category $category,
        $business
    ): void {
        abort_unless(
            $category->business_id === $business->id,
            404
        );
    }

    public function index(Request $request): JsonResponse
    {
        $business = $this->currentBusiness($request);

        $categories = Category::query()
            ->where('business_id', $business->id)
            ->with([
                'parent:id,name',
            ])
            ->withCount([
                'products',
                'services',
                'children',
            ])
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        return response()->json([
            'success' => true,
            'categories' => $categories,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $business = $this->currentBusiness($request);

        $validated = $request->validate([
            'name' => [
                'required',
                'string',
                'max:255',
            ],

            'parent_id' => [
                'nullable',
                'uuid',
            ],

            'description' => [
                'nullable',
                'string',
            ],

            'sort_order' => [
                'nullable',
                'integer',
                'min:0',
            ],

            'status' => [
                'nullable',
                'string',
                'in:active,inactive',
            ],
        ]);

        $category = $this->categoryService->create(
            $business,
            $validated
        );

        $category->load([
            'parent:id,name',
        ]);

        return response()->json([
            'success' => true,
            'category' => $category,
        ], 201);
    }

    public function show(
        Request $request,
        Category $category
    ): JsonResponse {
        $business = $this->currentBusiness($request);

        $this->ensureCategoryBelongsToBusiness(
            $category,
            $business
        );

        $category->load([
            'parent:id,name',
            'children',
        ]);

        $category->loadCount([
            'products',
            'services',
        ]);

        return response()->json([
            'success' => true,
            'category' => $category,
        ]);
    }

    public function update(
        Request $request,
        Category $category
    ): JsonResponse {
        $business = $this->currentBusiness($request);

        $this->ensureCategoryBelongsToBusiness(
            $category,
            $business
        );

        $validated = $request->validate([
            'name' => [
                'sometimes',
                'required',
                'string',
                'max:255',
            ],

            'parent_id' => [
                'sometimes',
                'nullable',
                'uuid',
            ],

            'description' => [
                'sometimes',
                'nullable',
                'string',
            ],

            'sort_order' => [
                'sometimes',
                'integer',
                'min:0',
            ],

            'status' => [
                'sometimes',
                'string',
                'in:active,inactive',
            ],
        ]);

        $category = $this->categoryService->update(
            $category,
            $business,
            $validated
        );

        $category->load([
            'parent:id,name',
        ]);

        return response()->json([
            'success' => true,
            'category' => $category,
        ]);
    }

    public function destroy(
        Request $request,
        Category $category
    ): JsonResponse {
        $business = $this->currentBusiness($request);

        $this->ensureCategoryBelongsToBusiness(
            $category,
            $business
        );

        $this->categoryService->delete(
            $category,
            $business
        );

        return response()->json([
            'success' => true,
            'message' => 'Category deleted successfully.',
        ]);
    }
}