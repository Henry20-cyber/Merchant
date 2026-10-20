<?php

namespace App\Domains\Sales\Services;

use App\Domains\Organization\Models\Branch;
use App\Domains\Organization\Models\Business;
use App\Domains\Sales\Models\Sale;
use App\Domains\Sales\Models\SaleItem;
use App\Domains\Expenses\Models\Expense;
use Carbon\CarbonInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

class SalesAnalyticsService
{
    /**
     * Build the analytics used by the MerchantOS dashboard.
     *
     * Dashboard analytics are scoped to the current branch.
     */
    public function dashboard(
        Business $business,
        Branch $branch,
        CarbonInterface $date
    ): array {
        $dailyStart = $date->copy()->startOfDay();
        $dailyEnd = $date->copy()->endOfDay();

        $weeklyStart = $date->copy()->startOfWeek();
        $weeklyEnd = $date->copy()->endOfWeek();

        $monthlyStart = $date->copy()->startOfMonth();
        $monthlyEnd = $date->copy()->endOfMonth();

        // Daily financial metrics
        $dailyRevenue = $this->revenueBetween(
            $business,
            $branch,
            $dailyStart,
            $dailyEnd
        );

        $dailyExpenses = $this->expensesBetween(
            $business,
            $branch,
            $dailyStart,
            $dailyEnd
        );

        // Weekly financial metrics
        $weeklyRevenue = $this->revenueBetween(
            $business,
            $branch,
            $weeklyStart,
            $weeklyEnd
        );

        $weeklyExpenses = $this->expensesBetween(
            $business,
            $branch,
            $weeklyStart,
            $weeklyEnd
        );

        // Monthly financial metrics
        $monthlyRevenue = $this->revenueBetween(
            $business,
            $branch,
            $monthlyStart,
            $monthlyEnd
        );

        $monthlyExpenses = $this->expensesBetween(
            $business,
            $branch,
            $monthlyStart,
            $monthlyEnd
        );

        return [
            'daily' => [
                'revenue' => $dailyRevenue,
                'expenses' => $dailyExpenses,
                'net_result' => $dailyRevenue - $dailyExpenses,
                'transactions' => $this->transactionsBetween(
                    $business,
                    $branch,
                    $dailyStart,
                    $dailyEnd
                ),
                'top_items' => $this->topItemsBetween(
                    $business,
                    $branch,
                    $dailyStart,
                    $dailyEnd
                ),
            ],

            'weekly' => [
                'revenue' => $weeklyRevenue,
                'expenses' => $weeklyExpenses,
                'net_result' => $weeklyRevenue - $weeklyExpenses,
                'transactions' => $this->transactionsBetween(
                    $business,
                    $branch,
                    $weeklyStart,
                    $weeklyEnd
                ),
                'top_items' => $this->topItemsBetween(
                    $business,
                    $branch,
                    $weeklyStart,
                    $weeklyEnd
                ),
            ],

            'monthly' => [
                'revenue' => $monthlyRevenue,
                'expenses' => $monthlyExpenses,
                'net_result' => $monthlyRevenue - $monthlyExpenses,
                'transactions' => $this->transactionsBetween(
                    $business,
                    $branch,
                    $monthlyStart,
                    $monthlyEnd
                ),
                'top_items' => $this->topItemsBetween(
                    $business,
                    $branch,
                    $monthlyStart,
                    $monthlyEnd
                ),
            ],

            'top_items' => $this->topItems($business, $branch),

            'revenue_breakdown' => $this->revenueBreakdown(
                $business,
                $branch
            ),

            'advanced' => $this->dashboardAdvanced(
                $business,
                $branch,
                $date
            ),
        ];
    }

    /**
     * Dashboard-ready advanced analytics for the current business.
     *
     * This method intentionally uses aggregate queries instead of issuing
     * separate queries for every day. The previous implementation performed
     * two sales queries per day for a 14-day period, which could become very
     * expensive as the sales table grows.
     *
     * This remains business-wide.
     */
    public function advanced(
        Business $business,
        Branch $branch,
        CarbonInterface $date,
        ?CarbonInterface $startDate = null,
        ?CarbonInterface $endDate = null,
    ): array {
        $start = ($startDate ?? $date->copy()->subDays(13))->startOfDay();
        $end = ($endDate ?? $date)->endOfDay();

        // One query for all daily revenue + transaction counts in the period.
        $dailyRows = DB::table('sales')
            ->leftJoin('payments', function ($join) use ($business) {
                $join->on('payments.sale_id', '=', 'sales.id')
                    ->where('payments.business_id', $business->id)
                    ->where('payments.status', 'paid');
            })
            ->where('sales.business_id', $business->id)
            ->where('sales.branch_id', $branch->id)
            ->whereBetween('sales.created_at', [$start, $end])
            ->selectRaw('DATE(sales.created_at) AS sale_date')
            ->selectRaw('COALESCE(SUM(payments.amount), 0) AS revenue')
            ->selectRaw('COUNT(DISTINCT sales.id) AS transactions')
            ->groupByRaw('DATE(sales.created_at)')
            ->orderBy('sale_date')
            ->get()
            ->keyBy(fn($row) => (string) $row->sale_date);

        // Sales value is aggregated separately so multiple payment records
        // cannot multiply a sale's total.
        $dailySalesRows = DB::table('sales')
            ->where('business_id', $business->id)
            ->where('branch_id', $branch->id)
            ->whereBetween('created_at', [$start, $end])
            ->selectRaw('DATE(created_at) AS sale_date')
            ->selectRaw('COALESCE(SUM(total), 0) AS sales_value')
            ->groupByRaw('DATE(created_at)')
            ->orderBy('sale_date')
            ->get()
            ->keyBy(fn($row) => (string) $row->sale_date);

        $dailyExpenseRows = Expense::query()
            ->where('business_id', $business->id)
            ->where('branch_id', $branch->id)
            ->where('status', 'recorded')
            ->whereBetween('expense_date', [
                $start->toDateString(),
                $end->toDateString(),
            ])
            ->selectRaw("TO_CHAR(expense_date, 'YYYY-MM-DD') AS expense_date")
            ->selectRaw('SUM(amount) AS expenses')
            ->groupByRaw("TO_CHAR(expense_date, 'YYYY-MM-DD')")
            ->orderBy('expense_date')
            ->get()
            ->keyBy(fn($row) => substr((string) $row->expense_date, 0, 10));

        $daily = [];

        for (
            $cursor = $start->copy();
            $cursor->lte($end);
        ) {
            $dateKey = $cursor->toDateString();

            $row = $dailyRows->get($dateKey);
            $salesRow = $dailySalesRows->get($dateKey);
            $expenseRow = $dailyExpenseRows->get($dateKey);

            $salesValue = $salesRow
                ? (float) $salesRow->sales_value
                : 0.0;

            $revenue = $row ? (float) $row->revenue : 0.0;
            $expenses = $expenseRow ? (float) $expenseRow->expenses : 0.0;

            $daily[] = [
                'date' => $cursor->format('M j'),
                'sales_value' => $salesValue,
                'revenue' => $revenue,
                'expenses' => $expenses,
                'net_result' => $revenue - $expenses,
                'transactions' => $row ? (int) $row->transactions : 0,
            ];

            $cursor = $cursor->copy()->addDay();
        }

        // One aggregate query for the period total.
        $periodTotals = DB::table('sales')
            ->leftJoin('payments', function ($join) use ($business) {
                $join->on('payments.sale_id', '=', 'sales.id')
                    ->where('payments.business_id', $business->id)
                    ->where('payments.status', 'paid');
            })
            ->where('sales.business_id', $business->id)
            ->where('sales.branch_id', $branch->id)
            ->whereBetween('sales.created_at', [$start, $end])
            ->selectRaw('COALESCE(SUM(payments.amount), 0) AS revenue')
            ->selectRaw('COUNT(DISTINCT sales.id) AS transactions')
            ->first();

        $salesValue = (float) DB::table('sales')
            ->where('business_id', $business->id)
            ->where('branch_id', $branch->id)
            ->whereBetween('created_at', [$start, $end])
            ->sum('total');

        // Aggregate paid payments per sale first to prevent duplicate
        // credit amounts when a customer makes multiple repayments.
        $paidPaymentsBySale = DB::table('payments')
            ->select('sale_id')
            ->selectRaw('SUM(amount) AS paid_amount')
            ->where('business_id', $business->id)
            ->where('status', 'paid')
            ->whereNotNull('sale_id')
            ->groupBy('sale_id');

        $creditTotals = DB::table('credits')
            ->join('sales', 'sales.id', '=', 'credits.sale_id')
            ->leftJoinSub(
                $paidPaymentsBySale,
                'paid_payments',
                fn($join) => $join->on(
                    'paid_payments.sale_id',
                    '=',
                    'sales.id'
                )
            )
            ->where('credits.business_id', $business->id)
            ->where('sales.business_id', $business->id)
            ->where('sales.branch_id', $branch->id)
            ->whereBetween('sales.created_at', [$start, $end])
            ->selectRaw(
                'COALESCE(SUM(credits.original_amount), 0) AS credit_sold'
            )
            ->selectRaw(
                'COALESCE(SUM(COALESCE(paid_payments.paid_amount, 0)), 0) AS credit_repayments'
            )
            ->selectRaw(
                'COALESCE(SUM(GREATEST(
            credits.original_amount - COALESCE(paid_payments.paid_amount, 0),
            0
        )), 0) AS outstanding_credit'
            )
            ->first();

        $creditSold = (float) ($creditTotals?->credit_sold ?? 0);
        $creditRepayments = (float) ($creditTotals?->credit_repayments ?? 0);
        $outstandingCredit = (float) ($creditTotals?->outstanding_credit ?? 0);

        $creditCollectionProgress = $creditSold > 0
            ? round(($creditRepayments / $creditSold) * 100, 2)
            : 0.0;

        $revenue = (float) ($periodTotals?->revenue ?? 0);
        $transactions = (int) ($periodTotals?->transactions ?? 0);

        // One aggregate query for top products/services in the same period.
        $top = $this->topItems(
            $business,
            $branch,
            8,
            $start,
            $end
        );

        // Advanced analytics use the selected period consistently.
        $expenses = $this->expensesBetween(
            $business,
            $branch,
            $start,
            $end
        );

        $netResult = $revenue - $expenses;

        $revenueBreakdown = $this->revenueBreakdown(
            $business,
            $branch,
            $start,
            $end,
        );

        return [
            'period' => [
                'start' => $start->toDateString(),
                'end' => $end->toDateString(),
            ],
            'sales_value' => $salesValue,
            'revenue' => $revenue,
            'expenses' => $expenses,
            'net_result' => $netResult,
            'transactions' => $transactions,
            'average_transaction_value' => $transactions > 0
                ? round($revenue / $transactions, 2)
                : 0,
            'credit_performance' => [
                'credit_sold' => $creditSold,
                'repayments_received' => $creditRepayments,
                'outstanding_credit' => $outstandingCredit,
                'collection_progress' => $creditCollectionProgress,
            ],
            'daily' => $daily,
            'top_items' => $top,
            'revenue_breakdown' => $revenueBreakdown,
        ];
    }

    public function overview(
        Business $business,
        ?string $startDate = null,
        ?string $endDate = null
    ): array {
        $query = Sale::query()->where('business_id', $business->id);

        $this->applyDateRange($query, $startDate, $endDate);

        $sales = $query->get();

        $grossSales = (float) $sales->sum('subtotal');
        $discount = (float) $sales->sum('discount');
        $vat = (float) $sales->sum('vat_amount');
        $total = (float) $sales->sum('total');
        $revenue = $grossSales - $discount;

        $saleIds = $sales->pluck('id');

        $items = SaleItem::query()
            ->whereIn('sale_id', $saleIds)
            ->get();

        $cogs = (float) $items->sum(
            fn($item) => (float) $item->quantity * (float) $item->unit_cost
        );

        $unitsSold = (int) $items->sum(
            fn($item) => (float) $item->quantity
        );

        $grossProfit = $revenue - $cogs;

        $grossMargin = $revenue > 0
            ? round(($grossProfit / $revenue) * 100, 2)
            : 0.0;

        return [
            'gross_sales' => $grossSales,
            'discount' => $discount,
            'vat_amount' => $vat,
            'revenue' => $revenue,
            'total' => $total,
            'cogs' => $cogs,
            'gross_profit' => $grossProfit,
            'gross_margin' => $grossMargin,
            'units_sold' => $unitsSold,
            'transactions' => $sales->count(),
        ];
    }

    public function topSellingProducts(
        Business $business,
        int $limit = 10,
        ?string $startDate = null,
        ?string $endDate = null
    ): array {
        $query = SaleItem::query()
            ->join('sales', 'sales.id', '=', 'sale_items.sale_id')
            ->join('products', 'products.id', '=', 'sale_items.product_id')
            ->where('sales.business_id', $business->id)
            ->whereNotNull('sale_items.product_id');

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
            ->selectRaw('SUM(sale_items.quantity) AS units_sold')
            ->selectRaw('SUM(sale_items.total) AS revenue')
            ->groupBy('sale_items.product_id', 'products.name')
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

    public function productProfitability(
        Business $business,
        ?string $startDate = null,
        ?string $endDate = null
    ): array {
        $query = SaleItem::query()
            ->join('sales', 'sales.id', '=', 'sale_items.sale_id')
            ->join('products', 'products.id', '=', 'sale_items.product_id')
            ->where('sales.business_id', $business->id)
            ->whereNotNull('sale_items.product_id');

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
            ->selectRaw('SUM(sale_items.quantity) AS units_sold')
            ->selectRaw('SUM(sale_items.total) AS revenue')
            ->selectRaw(
                'SUM(sale_items.quantity * sale_items.unit_cost) AS cogs'
            )
            ->selectRaw(
                'SUM(sale_items.total) - SUM(sale_items.quantity * sale_items.unit_cost) AS gross_profit'
            )
            ->groupBy('sale_items.product_id', 'products.name')
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
                        ? round(($grossProfit / $revenue) * 100, 4)
                        : 0.0,
                ];
            })
            ->values()
            ->all();
    }


    private function revenueBetween(
        Business $business,
        ?Branch $branch,
        CarbonInterface $start,
        CarbonInterface $end
    ): float {
        $query = DB::table('payments')
            ->join('sales', 'sales.id', '=', 'payments.sale_id')
            ->where('payments.business_id', $business->id)
            ->where('sales.business_id', $business->id)
            ->where('payments.status', 'paid')
            ->whereBetween('sales.created_at', [$start, $end]);

        if ($branch !== null) {
            $query->where('sales.branch_id', $branch->id);
        }

        return (float) $query->sum('payments.amount');
    }


    private function dashboardAdvanced(
        Business $business,
        Branch $branch,
        CarbonInterface $date
    ): array {
        $start = $date->copy()->subDays(13)->startOfDay();
        $end = $date->copy()->endOfDay();

        $dailyRows = DB::table('sales')
            ->leftJoin('payments', function ($join) use ($business) {
                $join->on('payments.sale_id', '=', 'sales.id')
                    ->where('payments.business_id', $business->id)
                    ->where('payments.status', 'paid');
            })
            ->where('sales.business_id', $business->id)
            ->where('sales.branch_id', $branch->id)
            ->whereBetween('sales.created_at', [$start, $end])
            ->selectRaw('DATE(sales.created_at) AS sale_date')
            ->selectRaw('COALESCE(SUM(payments.amount), 0) AS revenue')
            ->selectRaw('COUNT(DISTINCT sales.id) AS transactions')
            ->groupByRaw('DATE(sales.created_at)')
            ->orderBy('sale_date')
            ->get()
            ->keyBy(fn($row) => (string) $row->sale_date);

        $daily = [];

        for ($day = 0; $day < 14; $day++) {
            $cursor = $start->copy()->addDays($day);
            $dateKey = $cursor->toDateString();

            $row = $dailyRows->get($dateKey);

            $daily[] = [
                'date' => $cursor->format('M j'),
                'revenue' => $row
                    ? (float) $row->revenue
                    : 0.0,
                'transactions' => $row
                    ? (int) $row->transactions
                    : 0,
            ];
        }

        $totals = DB::table('sales')
            ->leftJoin('payments', function ($join) use ($business) {
                $join->on('payments.sale_id', '=', 'sales.id')
                    ->where('payments.business_id', $business->id)
                    ->where('payments.status', 'paid');
            })
            ->where('sales.business_id', $business->id)
            ->where('sales.branch_id', $branch->id)
            ->whereBetween('sales.created_at', [$start, $end])
            ->selectRaw('COALESCE(SUM(payments.amount), 0) AS revenue')
            ->selectRaw('COUNT(DISTINCT sales.id) AS transactions')
            ->first();

        $revenue = (float) ($totals?->revenue ?? 0);
        $transactions = (int) ($totals?->transactions ?? 0);

        $topItems = $this->topItems(
            $business,
            $branch,
            5,
            $start,
            $end
        );

        $bestDay = collect($daily)
            ->sortByDesc('revenue')
            ->first();

        return [
            'period' => [
                'start' => $start->toDateString(),
                'end' => $end->toDateString(),
            ],

            'revenue' => $revenue,

            'transactions' => $transactions,

            'average_transaction_value' => $transactions > 0
                ? round($revenue / $transactions, 2)
                : 0,

            'daily' => $daily,

            'top_items' => $topItems,

            'top_services' => $this->topServices(
                $business,
                $branch,
                5,
                $start,
                $end
            ),

            'best_day' => $bestDay,
        ];
    }

    private function transactionsBetween(
        Business $business,
        ?Branch $branch,
        CarbonInterface $start,
        CarbonInterface $end
    ): int {
        $query = Sale::query()
            ->where('business_id', $business->id)
            ->whereBetween('created_at', [$start, $end]);

        if ($branch !== null) {
            $query->where('branch_id', $branch->id);
        }

        return $query->count();
    }

    private function topItemsBetween(
        Business $business,
        Branch $branch,
        CarbonInterface $start,
        CarbonInterface $end
    ): array {
        $periodRevenue = $this->revenueBetween(
            $business,
            $branch,
            $start,
            $end
        );

        return $this->topItems(
            $business,
            $branch,
            3,
            $start,
            $end,
            $periodRevenue
        );
    }

    private function topItems(
        Business $business,
        ?Branch $branch = null,
        int $limit = 3,
        ?CarbonInterface $start = null,
        ?CarbonInterface $end = null,
        ?float $grandRevenue = null
    ): array {
        $query = SaleItem::query()
            ->join('sales', 'sales.id', '=', 'sale_items.sale_id')
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
            ->where('sales.business_id', $business->id);

        if ($branch !== null) {
            $query->where('sales.branch_id', $branch->id);
        }

        if ($start !== null && $end !== null) {
            $query->whereBetween('sales.created_at', [$start, $end]);
        }

        $rows = $query
            ->selectRaw(
                "CASE WHEN sale_items.service_id IS NULL THEN 'product' ELSE 'service' END AS item_type"
            )
            ->selectRaw(
                'COALESCE(products.name, services.name) AS name'
            )
            ->selectRaw('SUM(sale_items.quantity) AS units_sold')
            ->selectRaw('SUM(sale_items.total) AS revenue')
            ->groupBy(
                'sale_items.product_id',
                'sale_items.service_id',
                'products.name',
                'services.name'
            )
            ->orderByDesc('revenue')
            ->limit($limit)
            ->get();

        if ($grandRevenue === null) {
            $revenueQuery = Sale::query()
                ->where('business_id', $business->id);

            if ($branch !== null) {
                $revenueQuery->where('branch_id', $branch->id);
            }

            if ($start !== null && $end !== null) {
                $revenueQuery->whereBetween(
                    'created_at',
                    [$start, $end]
                );
            }

            $grandRevenue = (float) $revenueQuery->sum('total');
        }

        return $rows
            ->map(function ($row) use ($grandRevenue): array {
                $revenue = (float) $row->revenue;

                return [
                    'item_type' => $row->item_type,
                    'type' => $row->item_type,
                    'name' => $row->name,
                    'units_sold' => (float) $row->units_sold,
                    'revenue' => $revenue,
                    'percentage' => $grandRevenue > 0
                        ? round(($revenue / $grandRevenue) * 100, 1)
                        : 0.0,
                ];
            })
            ->values()
            ->all();
    }


    /**
     * Return the highest-revenue services for the selected branch and period.
     */
    private function topServices(
        Business $business,
        ?Branch $branch = null,
        int $limit = 5,
        ?CarbonInterface $start = null,
        ?CarbonInterface $end = null,
    ): array {
        $query = SaleItem::query()
            ->join('sales', 'sales.id', '=', 'sale_items.sale_id')
            ->join('services', 'services.id', '=', 'sale_items.service_id')
            ->where('sales.business_id', $business->id)
            ->whereNotNull('sale_items.service_id');

        if ($branch !== null) {
            $query->where('sales.branch_id', $branch->id);
        }

        if ($start !== null && $end !== null) {
            $query->whereBetween('sales.created_at', [$start, $end]);
        }

        $rows = $query
            ->select([
                'sale_items.service_id',
                'services.name as service_name',
            ])
            ->selectRaw('SUM(sale_items.quantity) AS units_sold')
            ->selectRaw('SUM(sale_items.total) AS revenue')
            ->groupBy('sale_items.service_id', 'services.name')
            ->orderByDesc('revenue')
            ->limit($limit)
            ->get();

        $serviceRevenue = (float) SaleItem::query()
            ->join('sales', 'sales.id', '=', 'sale_items.sale_id')
            ->where('sales.business_id', $business->id)
            ->whereNotNull('sale_items.service_id')
            ->when(
                $branch !== null,
                fn($query) => $query->where(
                    'sales.branch_id',
                    $branch->id
                )
            )
            ->when(
                $start !== null && $end !== null,
                fn($query) => $query->whereBetween(
                    'sales.created_at',
                    [$start, $end]
                )
            )
            ->sum('sale_items.total');

        return $rows
            ->map(function ($row) use ($serviceRevenue): array {
                $revenue = (float) $row->revenue;

                return [
                    'service_id' => $row->service_id,
                    'service_name' => $row->service_name,
                    'name' => $row->service_name,
                    'item_type' => 'service',
                    'units_sold' => (float) $row->units_sold,
                    'revenue' => $revenue,
                    'percentage' => $serviceRevenue > 0
                        ? round(($revenue / $serviceRevenue) * 100, 1)
                        : 0.0,
                ];
            })
            ->values()
            ->all();
    }


    private function revenueBreakdown(
        Business $business,
        ?Branch $branch = null,
        ?CarbonInterface $start = null,
        ?CarbonInterface $end = null
    ): array {
        $query = SaleItem::query()
            ->join(
                'sales',
                'sales.id',
                '=',
                'sale_items.sale_id'
            )
            ->where('sales.business_id', $business->id);

        if ($branch !== null) {
            $query->where('sales.branch_id', $branch->id);
        }

        if ($start !== null && $end !== null) {
            $query->whereBetween(
                'sales.created_at',
                [$start, $end]
            );
        }

        // One query replaces the previous two independent aggregate queries.
        $row = $query
            ->selectRaw(
                "COALESCE(SUM(CASE WHEN sale_items.product_id IS NOT NULL THEN sale_items.total ELSE 0 END), 0) AS product_revenue"
            )
            ->selectRaw(
                "COALESCE(SUM(CASE WHEN sale_items.service_id IS NOT NULL THEN sale_items.total ELSE 0 END), 0) AS service_revenue"
            )
            ->first();

        $productRevenue = (float) ($row?->product_revenue ?? 0);
        $serviceRevenue = (float) ($row?->service_revenue ?? 0);
        $total = $productRevenue + $serviceRevenue;

        return [
            'products' => [
                'revenue' => $productRevenue,
                'percentage' => $total > 0
                    ? round(($productRevenue / $total) * 100, 1)
                    : 0.0,
            ],
            'services' => [
                'revenue' => $serviceRevenue,
                'percentage' => $total > 0
                    ? round(($serviceRevenue / $total) * 100, 1)
                    : 0.0,
            ],
            'total' => $total,
        ];
    }

    private function applyDateRange(
        $query,
        ?string $startDate,
        ?string $endDate
    ): void {
        if ($startDate !== null) {
            $query->whereDate('created_at', '>=', $startDate);
        }

        if ($endDate !== null) {
            $query->whereDate('created_at', '<=', $endDate);
        }
    }

    private function applyDateRangeToQuery(
        $query,
        ?string $startDate,
        ?string $endDate,
        string $column
    ): void {
        if ($startDate !== null) {
            $query->whereDate($column, '>=', $startDate);
        }

        if ($endDate !== null) {
            $query->whereDate($column, '<=', $endDate);
        }
    }

    private function expensesBetween(
        Business $business,
        ?Branch $branch,
        CarbonInterface $start,
        CarbonInterface $end
    ): float {
        $query = Expense::query()
            ->where('business_id', $business->id)
            ->where('status', 'recorded')
            ->whereBetween('expense_date', [
                $start->toDateString(),
                $end->toDateString(),
            ]);

        /*
         * The dashboard is branch-scoped.
         *
         * However, branch_id is currently nullable on expenses,
         * so only explicitly assigned branch expenses are included
         * when a branch context is active.
         */
        if ($branch !== null) {
            $query->where('branch_id', $branch->id);
        }

        return (float) $query->sum('amount');
    }
}
