<?php

namespace App\Domains\Sales\Services;

use App\Domains\Organization\Models\Business;
use App\Domains\Sales\Models\Sale;
use App\Domains\Sales\Models\SaleItem;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

class SalesAnalyticsService
{
    /**
     * Build the analytics used by the MerchantOS dashboard.
     *
     * Provides:
     *
     * - Daily revenue
     * - Daily transaction count
     * - Daily top items
     * - Weekly revenue
     * - Weekly transaction count
     * - Weekly top items
     * - Monthly revenue
     * - Monthly transaction count
     * - Monthly top items
     * - Overall top three products/services
     * - Product/service revenue breakdown
     */
    public function dashboard(
        Business $business,
        CarbonInterface $date
    ): array {
        $dailyStart = $date->copy()->startOfDay();
        $dailyEnd = $date->copy()->endOfDay();

        $weeklyStart = $date->copy()->startOfWeek();
        $weeklyEnd = $date->copy()->endOfWeek();

        $monthlyStart = $date->copy()->startOfMonth();
        $monthlyEnd = $date->copy()->endOfMonth();

        return [
            'daily' => [
                'revenue' => $this->revenueBetween(
                    $business,
                    $dailyStart,
                    $dailyEnd
                ),

                'transactions' => $this->transactionsBetween(
                    $business,
                    $dailyStart,
                    $dailyEnd
                ),

                'top_items' => $this->topItemsBetween(
                    $business,
                    $dailyStart,
                    $dailyEnd
                ),
            ],

            'weekly' => [
                'revenue' => $this->revenueBetween(
                    $business,
                    $weeklyStart,
                    $weeklyEnd
                ),

                'transactions' => $this->transactionsBetween(
                    $business,
                    $weeklyStart,
                    $weeklyEnd
                ),

                'top_items' => $this->topItemsBetween(
                    $business,
                    $weeklyStart,
                    $weeklyEnd
                ),
            ],

            'monthly' => [
                'revenue' => $this->revenueBetween(
                    $business,
                    $monthlyStart,
                    $monthlyEnd
                ),

                'transactions' => $this->transactionsBetween(
                    $business,
                    $monthlyStart,
                    $monthlyEnd
                ),

                'top_items' => $this->topItemsBetween(
                    $business,
                    $monthlyStart,
                    $monthlyEnd
                ),
            ],

            'top_items' => $this->topItems($business),

            'revenue_breakdown' => $this->revenueBreakdown(
                $business
            ),
        ];
    }

    /**
     * General sales overview.
     *
     * If no date range is supplied, all business sales
     * are included.
     */
    public function overview(
        Business $business,
        ?string $startDate = null,
        ?string $endDate = null
    ): array {
        $query = Sale::query()
            ->where('business_id', $business->id);

        $this->applyDateRange(
            $query,
            $startDate,
            $endDate
        );

        $sales = $query->get();

        $grossSales = (float) $sales->sum('subtotal');
        $discount = (float) $sales->sum('discount');
        $tax = (float) $sales->sum('tax');
        $total = (float) $sales->sum('total');

        /*
         * Revenue is sales after discount but before tax.
         *
         * Example:
         *
         * subtotal = 10,000
         * discount = 1,000
         * tax      = 500
         *
         * revenue = 9,000
         */
        $revenue = $grossSales - $discount;

        /*
         * COGS comes from the historical unit_cost stored
         * on each SaleItem.
         */
        $saleIds = $sales->pluck('id');

        $items = SaleItem::query()
            ->whereIn('sale_id', $saleIds)
            ->get();

        $cogs = (float) $items->sum(
            fn ($item) =>
                (float) $item->quantity *
                (float) $item->unit_cost
        );

        $unitsSold = (int) $items->sum(
            fn ($item) =>
                (float) $item->quantity
        );

        $grossProfit = $revenue - $cogs;

        $grossMargin = $revenue > 0
            ? round(
                ($grossProfit / $revenue) * 100,
                2
            )
            : 0.0;

        return [
            'gross_sales' => $grossSales,
            'discount' => $discount,
            'tax' => $tax,
            'revenue' => $revenue,
            'total' => $total,
            'cogs' => $cogs,
            'gross_profit' => $grossProfit,
            'gross_margin' => $grossMargin,
            'units_sold' => $unitsSold,
            'transactions' => $sales->count(),
        ];
    }

    /**
     * Get top-selling products.
     *
     * Ranked by units sold.
     *
     * Optional:
     * - $limit
     * - $startDate
     * - $endDate
     */
    public function topSellingProducts(
        Business $business,
        int $limit = 10,
        ?string $startDate = null,
        ?string $endDate = null
    ): array {
        $query = SaleItem::query()
            ->join(
                'sales',
                'sales.id',
                '=',
                'sale_items.sale_id'
            )
            ->join(
                'products',
                'products.id',
                '=',
                'sale_items.product_id'
            )
            ->where(
                'sales.business_id',
                $business->id
            )
            ->whereNotNull(
                'sale_items.product_id'
            );

        $this->applyDateRangeToQuery(
            $query,
            $startDate,
            $endDate,
            'sales.created_at'
        );

        return $query
            ->select([
                'sale_items.product_id',
                'products.name as product_name',
            ])
            ->selectRaw(
                'SUM(sale_items.quantity) AS units_sold'
            )
            ->selectRaw(
                'SUM(sale_items.total) AS revenue'
            )
            ->groupBy(
                'sale_items.product_id',
                'products.name'
            )
            ->orderByDesc('units_sold')
            ->limit($limit)
            ->get()
            ->map(function ($row): array {
                return [
                    'product_id' => $row->product_id,
                    'product_name' => $row->product_name,
                    'units_sold' => (int) $row->units_sold,
                    'revenue' => (float) $row->revenue,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * Calculate profitability for each product.
     *
     * Ranked by gross profit.
     */
    public function productProfitability(
        Business $business,
        ?string $startDate = null,
        ?string $endDate = null
    ): array {
        $query = SaleItem::query()
            ->join(
                'sales',
                'sales.id',
                '=',
                'sale_items.sale_id'
            )
            ->join(
                'products',
                'products.id',
                '=',
                'sale_items.product_id'
            )
            ->where(
                'sales.business_id',
                $business->id
            )
            ->whereNotNull(
                'sale_items.product_id'
            );

        $this->applyDateRangeToQuery(
            $query,
            $startDate,
            $endDate,
            'sales.created_at'
        );

        return $query
            ->select([
                'sale_items.product_id',
                'products.name as product_name',
            ])
            ->selectRaw(
                'SUM(sale_items.quantity) AS units_sold'
            )
            ->selectRaw(
                'SUM(sale_items.total) AS revenue'
            )
            ->selectRaw(
                'SUM(
                    sale_items.quantity * sale_items.unit_cost
                ) AS cogs'
            )
            ->selectRaw(
                'SUM(sale_items.total) -
                 SUM(
                    sale_items.quantity * sale_items.unit_cost
                 ) AS gross_profit'
            )
            ->groupBy(
                'sale_items.product_id',
                'products.name'
            )
            ->orderByDesc('gross_profit')
            ->get()
            ->map(function ($row): array {
                $revenue = (float) $row->revenue;
                $cogs = (float) $row->cogs;
                $grossProfit = (float) $row->gross_profit;

                return [
                    'product_id' => $row->product_id,
                    'product_name' => $row->product_name,
                    'units_sold' => (int) $row->units_sold,
                    'revenue' => $revenue,
                    'cogs' => $cogs,
                    'gross_profit' => $grossProfit,
                    'gross_margin' => $revenue > 0
                        ? round(
                            ($grossProfit / $revenue) * 100,
                            4
                        )
                        : 0.0,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * Calculate revenue for a business within a date range.
     */
    private function revenueBetween(
        Business $business,
        CarbonInterface $start,
        CarbonInterface $end
    ): float {
        return (float) Sale::query()
            ->where(
                'business_id',
                $business->id
            )
            ->whereBetween(
                'created_at',
                [$start, $end]
            )
            ->sum('total');
    }

    /**
     * Count transactions within a date range.
     */
    private function transactionsBetween(
        Business $business,
        CarbonInterface $start,
        CarbonInterface $end
    ): int {
        return Sale::query()
            ->where(
                'business_id',
                $business->id
            )
            ->whereBetween(
                'created_at',
                [$start, $end]
            )
            ->count();
    }

    /**
     * Get the top three products/services within a period.
     */
    private function topItemsBetween(
        Business $business,
        CarbonInterface $start,
        CarbonInterface $end
    ): array {
        $rows = SaleItem::query()
            ->join(
                'sales',
                'sales.id',
                '=',
                'sale_items.sale_id'
            )
            ->leftJoin(
                'products',
                'products.id',
                '=',
                'sale_items.product_id'
            )
            ->leftJoin(
                'services',
                'services.id',
                '=',
                'sale_items.service_id'
            )
            ->where(
                'sales.business_id',
                $business->id
            )
            ->whereBetween(
                'sales.created_at',
                [$start, $end]
            )
            ->selectRaw("
                CASE
                    WHEN sale_items.service_id IS NULL
                    THEN 'product'
                    ELSE 'service'
                END AS item_type
            ")
            ->selectRaw("
                COALESCE(
                    products.name,
                    services.name
                ) AS name
            ")
            ->selectRaw(
                'SUM(sale_items.total) AS revenue'
            )
            ->groupBy(
                'sale_items.product_id',
                'sale_items.service_id',
                'products.name',
                'services.name'
            )
            ->orderByDesc('revenue')
            ->limit(3)
            ->get();

        $periodRevenue = (float) Sale::query()
            ->where(
                'business_id',
                $business->id
            )
            ->whereBetween(
                'created_at',
                [$start, $end]
            )
            ->sum('total');

        return $rows
            ->map(function ($row) use ($periodRevenue): array {
                $revenue = (float) $row->revenue;

                $percentage = $periodRevenue > 0
                    ? round(
                        ($revenue / $periodRevenue) * 100,
                        1
                    )
                    : 0.0;

                return [
                    'item_type' => $row->item_type,
                    'type' => $row->item_type,
                    'name' => $row->name,
                    'revenue' => $revenue,
                    'percentage' => $percentage,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * Get the overall top three products/services.
     */
    private function topItems(
        Business $business
    ): array {
        $rows = SaleItem::query()
            ->join(
                'sales',
                'sales.id',
                '=',
                'sale_items.sale_id'
            )
            ->leftJoin(
                'products',
                'products.id',
                '=',
                'sale_items.product_id'
            )
            ->leftJoin(
                'services',
                'services.id',
                '=',
                'sale_items.service_id'
            )
            ->where(
                'sales.business_id',
                $business->id
            )
            ->selectRaw("
                CASE
                    WHEN sale_items.service_id IS NULL
                    THEN 'product'
                    ELSE 'service'
                END AS item_type
            ")
            ->selectRaw("
                COALESCE(
                    products.name,
                    services.name
                ) AS name
            ")
            ->selectRaw(
                'SUM(sale_items.total) AS revenue'
            )
            ->groupBy(
                'sale_items.product_id',
                'sale_items.service_id',
                'products.name',
                'services.name'
            )
            ->orderByDesc('revenue')
            ->limit(3)
            ->get();

        $grandRevenue = (float) Sale::query()
            ->where(
                'business_id',
                $business->id
            )
            ->sum('total');

        return $rows
            ->map(function ($row) use ($grandRevenue): array {
                $revenue = (float) $row->revenue;

                $percentage = $grandRevenue > 0
                    ? round(
                        ($revenue / $grandRevenue) * 100,
                        1
                    )
                    : 0.0;

                return [
                    'item_type' => $row->item_type,
                    'type' => $row->item_type,
                    'name' => $row->name,
                    'revenue' => $revenue,
                    'percentage' => $percentage,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * Product versus service revenue.
     */
    private function revenueBreakdown(
        Business $business
    ): array {
        $productRevenue = (float) SaleItem::query()
            ->join(
                'sales',
                'sales.id',
                '=',
                'sale_items.sale_id'
            )
            ->where(
                'sales.business_id',
                $business->id
            )
            ->whereNotNull(
                'sale_items.product_id'
            )
            ->sum('sale_items.total');

        $serviceRevenue = (float) SaleItem::query()
            ->join(
                'sales',
                'sales.id',
                '=',
                'sale_items.sale_id'
            )
            ->where(
                'sales.business_id',
                $business->id
            )
            ->whereNotNull(
                'sale_items.service_id'
            )
            ->sum('sale_items.total');

        $total = $productRevenue + $serviceRevenue;

        return [
            'products' => [
                'revenue' => $productRevenue,
                'percentage' => $total > 0
                    ? round(
                        ($productRevenue / $total) * 100,
                        1
                    )
                    : 0.0,
            ],

            'services' => [
                'revenue' => $serviceRevenue,
                'percentage' => $total > 0
                    ? round(
                        ($serviceRevenue / $total) * 100,
                        1
                    )
                    : 0.0,
            ],

            'total' => $total,
        ];
    }

    /**
     * Apply an optional date range to an Eloquent query.
     */
    private function applyDateRange(
        $query,
        ?string $startDate,
        ?string $endDate
    ): void {
        if ($startDate !== null) {
            $query->whereDate(
                'created_at',
                '>=',
                $startDate
            );
        }

        if ($endDate !== null) {
            $query->whereDate(
                'created_at',
                '<=',
                $endDate
            );
        }
    }

    /**
     * Apply an optional date range to a query using
     * a qualified datetime column.
     */
    private function applyDateRangeToQuery(
        $query,
        ?string $startDate,
        ?string $endDate,
        string $column
    ): void {
        if ($startDate !== null) {
            $query->whereDate(
                $column,
                '>=',
                $startDate
            );
        }

        if ($endDate !== null) {
            $query->whereDate(
                $column,
                '<=',
                $endDate
            );
        }
    }
}