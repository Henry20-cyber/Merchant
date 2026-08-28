<?php

namespace App\Domains\Product\Services;

use App\Domains\Organization\Models\Business;
use App\Domains\Product\Models\Product;
use App\Domains\Search\Results\SearchResult;

class ProductSearchService
{
    /**
     * Search products belonging to a business.
     */
    public function search(
        Business $business,
        string $search
    ): array {
        $search = trim($search);

        if ($search === '') {
            return [];
        }

        return Product::query()
            ->where('business_id', $business->id)
            ->where(function ($query) use ($search) {
                $query
                    ->where('name', 'ILIKE', '%' . $search . '%')
                    ->orWhere('sku', 'ILIKE', '%' . $search . '%')
                    ->orWhere(
                        'description',
                        'ILIKE',
                        '%' . $search . '%'
                    );
            })
            ->orderBy('name')
            ->limit(20)
            ->get()
            ->map(function (Product $product) {
                return new SearchResult(
                    type: 'product',
                    id: $product->id,
                    title: $product->name,
                    subtitle: $product->sku,
                    metadata: [
                        'status' => $product->status,
                    ],
                );
            })
            ->map(fn (SearchResult $result) => $result->toArray())
            ->all();
    }
}