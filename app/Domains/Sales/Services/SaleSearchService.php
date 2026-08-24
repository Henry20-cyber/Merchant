<?php

namespace App\Domains\Sales\Services;

use App\Domains\Organization\Models\Business;
use App\Domains\Sales\Models\Sale;
use Illuminate\Support\Collection;

class SaleSearchService
{
    /**
     * Search historical sales within a business.
     */
    public function search(
        Business $business,
        string $search
    ): array {
        $search = trim($search);

        if ($search === '') {
            return [];
        }

        $sales = Sale::query()
            ->where('business_id', $business->id)
            ->whereHas('items.product', function ($query) use ($search) {
                $query->where(
                    'name',
                    'ILIKE',
                    '%' . $search . '%'
                );
            })
            ->with([
                'cashier:id,name',
                'items.product:id,name',
            ])
            ->latest('created_at')
            ->get();

        return $sales
            ->map(function (Sale $sale) use ($search) {
                $matchingItem = $sale->items
                    ->first(function ($item) use ($search) {
                        return $item->product
                            && str_contains(
                                mb_strtolower($item->product->name),
                                mb_strtolower($search)
                            );
                    });

                return [
                    'sale_id' => $sale->id,
                    'product_name' => $matchingItem?->product?->name,
                    'cashier_name' => $sale->cashier?->name,
                    'total' => (float) $sale->total,
                    'created_at' => $sale->created_at?->toDateTimeString(),
                ];
            })
            ->values()
            ->all();
    }
}
