<?php

namespace App\Domains\Sales\Services;

use App\Domains\Organization\Models\Business;
use App\Domains\Sales\Models\Sale;
use App\Domains\Sales\Models\SaleItem;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class SalesAnalyticsService
{
    /**
     * Return sales performance metrics for a business.
     *
     * When a date range is supplied, both dates are inclusive.
     */
    public function overview(
        Business $business,
        $from = null,
        $to = null
    ): array {
        /*
         * Normalize the requested date range.
         *
         * startOfDay() means the entire starting date is included.
         * endOfDay() means the entire ending date is included.
         */
        $fromDate = $from !== null
            ? Carbon::parse($from)->startOfDay()
            : null;

        $toDate = $to !== null
            ? Carbon::parse($to)->endOfDay()
            : null;

        /*
         * Base sales query.
         *
         * Every sales metric must use this same business and
         * date scope.
         */
        $sales = Sale::query()
            ->where('business_id', $business->id);

        if ($fromDate !== null) {
            $sales->where('created_at', '>=', $fromDate);
        }

        if ($toDate !== null) {
            $sales->where('created_at', '<=', $toDate);
        }

        /*
         * Sale items are scoped through their parent sale because
         * sale_items does not contain business_id.
         */
        $items = SaleItem::query()
            ->whereHas('sale', function ($query) use (
                $business,
                $fromDate,
                $toDate
            ) {
                $query
                    ->where('business_id', $business->id);

                if ($fromDate !== null) {
                    $query->where(
                        'created_at',
                        '>=',
                        $fromDate
                    );
                }

                if ($toDate !== null) {
                    $query->where(
                        'created_at',
                        '<=',
                        $toDate
                    );
                }
            });

        /*
         * Gross sales come from the historical selling price
         * stored on each SaleItem.
         */
        $grossSales = (float) $items
            ->sum(DB::raw('quantity * unit_price'));

        /*
         * Cost of goods sold comes from the historical cost
         * snapshot stored on each SaleItem.
         */
        $cogs = (float) $items
            ->sum(DB::raw('quantity * unit_cost'));

        /*
         * Total quantity sold during the requested period.
         */
        $unitsSold = (float) $items
            ->sum('quantity');

        /*
         * Number of sales transactions during the requested period.
         */
        $transactions = (int) $sales->count();

        /*
         * Discounts and taxes belong to the Sale itself.
         */
        $discount = (float) $sales
            ->sum('discount');

        $tax = (float) $sales
            ->sum('tax');

        /*
         * Revenue is gross sales after discounts.
         *
         * Tax is not considered revenue.
         */
        $revenue = $grossSales - $discount;

        /*
         * Total amount paid by customers.
         */
        $total = $revenue + $tax;

        /*
         * Gross profit is revenue minus COGS.
         */
        $grossProfit = $revenue - $cogs;

        /*
         * Gross margin percentage.
         */
        $grossMargin = $revenue > 0
            ? round(($grossProfit / $revenue) * 100, 2)
            : 0.0;

        return [
            'gross_sales' => $grossSales,
            'discount' => $discount,
            'tax' => $tax,
            'revenue' => $revenue,
            'cogs' => $cogs,
            'gross_profit' => $grossProfit,
            'gross_margin' => $grossMargin,
            'total' => $total,
            'units_sold' => $unitsSold,
            'transactions' => $transactions,
        ];
    }

   public function topSellingProducts(
    Business $business,
    int $limit = 10,
    ?string $startDate = null,
    ?string $endDate = null
): array {
    $items = SaleItem::query()
        ->select([
            'product_id',
            DB::raw('SUM(quantity) as units_sold'),
            DB::raw('SUM(quantity * unit_price) as revenue'),
        ])
        ->whereHas('sale', function ($query) use (
            $business,
            $startDate,
            $endDate
        ) {
            $query->where('business_id', $business->id);

            if ($startDate !== null) {
                $query->where(
                    'created_at',
                    '>=',
                    "{$startDate} 00:00:00"
                );
            }

            if ($endDate !== null) {
                $query->where(
                    'created_at',
                    '<=',
                    "{$endDate} 23:59:59"
                );
            }
        })
        ->with('product:id,name')
        ->groupBy('product_id')
        ->orderByDesc('units_sold')
        ->limit($limit)
        ->get();

    return $items
        ->map(function (SaleItem $item) {
            return [
                'product_id' => $item->product_id,
                'product_name' => $item->product?->name,
                'units_sold' => (float) $item->units_sold,
                'revenue' => (float) $item->revenue,
            ];
        })
        ->values()
        ->all();
}

public function productProfitability(
    Business $business,
    int $limit = 10,
    ?string $startDate = null,
    ?string $endDate = null
): array {
    $items = SaleItem::query()
        ->select([
            'product_id',
            DB::raw('SUM(quantity) as units_sold'),
            DB::raw('SUM(quantity * unit_price) as revenue'),
            DB::raw('SUM(quantity * unit_cost) as cogs'),
            DB::raw(
                'SUM(quantity * (unit_price - unit_cost)) as gross_profit'
            ),
        ])
        ->whereHas('sale', function ($query) use (
            $business,
            $startDate,
            $endDate
        ) {
            $query->where('business_id', $business->id);

            if ($startDate !== null) {
                $query->where(
                    'created_at',
                    '>=',
                    "{$startDate} 00:00:00"
                );
            }

            if ($endDate !== null) {
                $query->where(
                    'created_at',
                    '<=',
                    "{$endDate} 23:59:59"
                );
            }
        })
        ->with('product:id,name')
        ->groupBy('product_id')
        ->orderByDesc('gross_profit')
        ->limit($limit)
        ->get();

    return $items
        ->map(function (SaleItem $item) {
            $revenue = (float) $item->revenue;
            $grossProfit = (float) $item->gross_profit;

            $grossMargin = $revenue > 0
                ? ($grossProfit / $revenue) * 100
                : 0;

            return [
                'product_id' => $item->product_id,
                'product_name' => $item->product?->name,
                'units_sold' => (float) $item->units_sold,
                'revenue' => $revenue,
                'cogs' => (float) $item->cogs,
                'gross_profit' => $grossProfit,
                'gross_margin' => round($grossMargin, 4),
            ];
        })
        ->values()
        ->all();
}
}