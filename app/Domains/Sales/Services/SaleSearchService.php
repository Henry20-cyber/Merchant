<?php

namespace App\Domains\Sales\Services;

use App\Domains\Organization\Models\Business;
use App\Domains\Sales\Models\Sale;
use Illuminate\Support\Collection;

class SaleSearchService
{
    /**
     * Search historical sales within a business
     * by product or service name.
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
            ->whereHas('items', function ($query) use ($search) {
                $query->where(function ($query) use ($search) {
                    $query
                        ->whereHas('product', function ($query) use ($search) {
                            $query->where(
                                'name',
                                'ILIKE',
                                '%' . $search . '%'
                            );
                        })
                        ->orWhereHas('service', function ($query) use ($search) {
                            $query->where(
                                'name',
                                'ILIKE',
                                '%' . $search . '%'
                            );
                        });
                });
            })
            ->with([
                'cashier:id,name',
                'items.product:id,name',
                'items.service:id,name',
            ])
            ->latest('created_at')
            ->get();

        return $sales
            ->map(function (Sale $sale) use ($search) {
                $matchingItem = $sale->items
                    ->first(function ($item) use ($search) {
                        $searchLower = mb_strtolower($search);

                        $productMatches = $item->product
                            && str_contains(
                                mb_strtolower($item->product->name),
                                $searchLower
                            );

                        $serviceMatches = $item->service
                            && str_contains(
                                mb_strtolower($item->service->name),
                                $searchLower
                            );

                        return $productMatches || $serviceMatches;
                    });

                if (! $matchingItem) {
                    return null;
                }

                $isService = $matchingItem->service !== null;

                return [
                    'sale_id' => $sale->id,

                    'item_type' => $isService
                        ? 'service'
                        : 'product',

                    'item_name' => $isService
                        ? $matchingItem->service->name
                        : $matchingItem->product?->name,

                    'cashier_name' => $sale->cashier?->name,

                    'total' => (float) $sale->total,

                    'created_at' => $sale->created_at
                        ? $sale->created_at->toDateTimeString()
                        : null,
                ];
            })
            ->filter()
            ->values()
            ->all();
    }
}