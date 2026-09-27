<?php

namespace App\Domains\Inventory\Services;

use App\Domains\Inventory\Models\Stock;
use App\Domains\Inventory\Models\StockMovement;
use App\Domains\Organization\Models\Business;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class InventoryAnalyticsService
{
    /**
     * Return the complete inventory analytics snapshot
     * for a business.
     */
    public function overview(
        Business $business,
        ?Carbon $from = null,
        ?Carbon $to = null
    ): array {
        return [
            'overview' => $this->overviewMetrics($business),

            'movement_summary' => $this->movementSummary(
                $business,
                $from,
                $to
            ),

            'top_products' => $this->topSellingProducts(
                $business,
                $from,
                $to
            ),

            'slow_products' => $this->slowMovingProducts(
                $business,
                $from,
                $to
            ),

            'low_stock' => $this->lowStockProducts($business),

            'out_of_stock' => $this->outOfStockProducts($business),
        ];
    }

    /**
     * Current inventory overview.
     *
     * Stock quantity is stored in canonical base units.
     */
    public function overviewMetrics(Business $business): array
    {
        $stocks = Stock::query()
            ->where('business_id', $business->id);

        $products = DB::table('products')
            ->where('business_id', $business->id)
            ->whereNull('deleted_at')
            ->count();

        $productUnits = DB::table('product_units')
            ->where('business_id', $business->id)
            ->whereNull('deleted_at')
            ->count();

        $stockQuantity = (float) (
            (clone $stocks)->sum('quantity')
        );

        $lowStock = (clone $stocks)
            ->where('reorder_level', '>', 0)
            ->whereColumn('quantity', '<=', 'reorder_level')
            ->where('quantity', '>', 0)
            ->count();

        $outOfStock = (clone $stocks)
            ->where('quantity', '<=', 0)
            ->count();

        return [
            'products' => $products,
            'product_units' => $productUnits,
            'units_in_stock' => $stockQuantity,
            'low_stock_products' => $lowStock,
            'out_of_stock_products' => $outOfStock,
        ];
    }

    /**
     * Summarize inventory movements.
     *
     * Receive:
     *   quantity is positive.
     *
     * Sale:
     *   quantity/base_quantity are negative in the stock ledger.
     *
     * Adjustment:
     *   quantity/base_quantity may be positive or negative.
     *
     * For reporting, "sold" represents the absolute quantity sold.
     */
    public function movementSummary(
        Business $business,
        ?Carbon $from = null,
        ?Carbon $to = null
    ): array {
        $query = StockMovement::query()
            ->where('business_id', $business->id);

        $this->applyDateRange($query, $from, $to);

        $received = (clone $query)
            ->where('type', 'receive')
            ->sum('base_quantity');

        $sold = (clone $query)
            ->where('type', 'sale')
            ->sum(DB::raw('ABS(base_quantity)'));

        $adjusted = (clone $query)
            ->where('type', 'adjustment')
            ->sum('base_quantity');

        return [
            'received' => (float) $received,
            'sold' => (float) $sold,
            'adjusted' => (float) $adjusted,
        ];
    }

    /**
     * Products with the highest quantity sold.
     *
     * Sales are aggregated by product rather than by Stock.
     *
     * base_quantity is used because inventory analytics must
     * operate in canonical base units.
     */
    public function topSellingProducts(
        Business $business,
        ?Carbon $from = null,
        ?Carbon $to = null,
        int $limit = 10
    ): Collection {
        $query = StockMovement::query()
            ->select('product_id')
            ->selectRaw(
                'SUM(ABS(base_quantity)) as units_sold'
            )
            ->where('business_id', $business->id)
            ->where('type', 'sale');

        $this->applyDateRange($query, $from, $to);

        return $query
            ->with([
                'product:id,name,sku',
            ])
            ->groupBy('product_id')
            ->orderByDesc('units_sold')
            ->limit($limit)
            ->get()
            ->map(function (StockMovement $movement): array {
                return [
                    'product_id' => $movement->product_id,
                    'product_name' => $movement->product?->name,
                    'sku' => $movement->product?->sku,
                    'units_sold' => (float) $movement->units_sold,
                ];
            })
            ->values();
    }

    /**
     * Products with the lowest sales activity.
     *
     * Products that have never had a sale during the requested
     * period are included with zero sales.
     */
    public function slowMovingProducts(
        Business $business,
        ?Carbon $from = null,
        ?Carbon $to = null,
        int $limit = 10
    ): Collection {
        $salesQuery = StockMovement::query()
            ->select('product_id')
            ->selectRaw(
                'SUM(ABS(base_quantity)) as units_sold'
            )
            ->where('business_id', $business->id)
            ->where('type', 'sale');

        $this->applyDateRange($salesQuery, $from, $to);

        $sales = $salesQuery
            ->groupBy('product_id')
            ->get()
            ->keyBy('product_id');

        $stocks = Stock::query()
            ->where('business_id', $business->id)
            ->with([
                'product:id,name,sku',
            ])
            ->get();

        return $stocks
            ->map(function (Stock $stock) use ($sales): array {
                $sale = $sales->get($stock->product_id);

                return [
                    'stock_id' => $stock->id,
                    'product_id' => $stock->product_id,
                    'product_name' => $stock->product?->name,
                    'sku' => $stock->product?->sku,
                    'units_sold' => (float) (
                        $sale?->units_sold ?? 0
                    ),
                    'current_stock' => (float) $stock->quantity,
                ];
            })
            ->sortBy([
                ['units_sold', 'asc'],
                ['current_stock', 'desc'],
            ])
            ->take($limit)
            ->values();
    }

    /**
     * Products below their configured reorder level.
     */
    public function lowStockProducts(
        Business $business
    ): Collection {
        return Stock::query()
            ->where('business_id', $business->id)
            ->where('reorder_level', '>', 0)
            ->whereColumn('quantity', '<=', 'reorder_level')
            ->where('quantity', '>', 0)
            ->with([
                'product:id,name,sku',
            ])
            ->orderBy('quantity')
            ->get()
            ->map(function (Stock $stock): array {
                return [
                    'stock_id' => $stock->id,
                    'product_id' => $stock->product_id,
                    'product_name' => $stock->product?->name,
                    'sku' => $stock->product?->sku,
                    'quantity' => (float) $stock->quantity,
                    'reorder_level' => (float) $stock->reorder_level,
                ];
            })
            ->values();
    }

    /**
     * Products with no available stock.
     */
    public function outOfStockProducts(
        Business $business
    ): Collection {
        return Stock::query()
            ->where('business_id', $business->id)
            ->where('quantity', '<=', 0)
            ->with([
                'product:id,name,sku',
            ])
            ->orderBy('updated_at')
            ->get()
            ->map(function (Stock $stock): array {
                return [
                    'stock_id' => $stock->id,
                    'product_id' => $stock->product_id,
                    'product_name' => $stock->product?->name,
                    'sku' => $stock->product?->sku,
                    'quantity' => (float) $stock->quantity,
                ];
            })
            ->values();
    }

    /**
     * Apply an optional inclusive date range.
     */
    private function applyDateRange(
        $query,
        ?Carbon $from,
        ?Carbon $to
    ): void {
        if ($from) {
            $query->where(
                'created_at',
                '>=',
                $from->copy()->startOfDay()
            );
        }

        if ($to) {
            $query->where(
                'created_at',
                '<=',
                $to->copy()->endOfDay()
            );
        }
    }
}