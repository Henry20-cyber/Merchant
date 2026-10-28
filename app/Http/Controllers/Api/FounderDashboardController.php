<?php

namespace App\Http\Controllers\Api;

use App\Domains\Organization\Models\Business;
use App\Domains\Payment\Models\Payment;
use App\Domains\Subscription\Models\Subscription;
use App\Http\Controllers\Controller;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class FounderDashboardController extends Controller
{
    public function overview(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);

        $to = CarbonImmutable::parse($validated['to'] ?? now()->toDateString())->endOfDay();
        $from = CarbonImmutable::parse($validated['from'] ?? $to->subDays(29)->toDateString())->startOfDay();

        if ($from->diffInDays($to) > 366) {
            throw ValidationException::withMessages([
                'from' => 'Choose a date range of 367 days or less.',
            ]);
        }

        $businesses = Business::query(); // Soft-deleted businesses are excluded by the model.
        $subscriptions = Subscription::query();
        $subscriptionPayments = Payment::query()
            ->whereNotNull('subscription_id')
            ->whereNotNull('paid_at');

        $businessCount = (clone $businesses)->count();
        $newBusinesses = (clone $businesses)->whereBetween('created_at', [$from, $to])->count();
        $newUsers = User::query()->whereBetween('created_at', [$from, $to])->count();
        $activeSubscriptions = (clone $subscriptions)->where('status', 'active')->count();
        $trialSubscriptions = (clone $subscriptions)->where('status', 'trial')->count();
        $pastDueSubscriptions = (clone $subscriptions)->whereIn('status', ['past_due', 'overdue'])->count();

        $revenue = (clone $subscriptionPayments)
            ->whereBetween('paid_at', [$from, $to])
            ->sum('amount');

        $failedPayments = Payment::query()
            ->whereNotNull('subscription_id')
            ->where('status', 'failed')
            ->whereBetween('created_at', [$from, $to])
            ->count();

        // PostgreSQL date grouping; aggregate only successful/settled subscription payments.
        $monthlyRevenue = (clone $subscriptionPayments)
            ->whereBetween('paid_at', [$from, $to])
            ->selectRaw("TO_CHAR(DATE_TRUNC('month', paid_at), 'YYYY-MM') AS period, SUM(amount) AS total")
            ->groupByRaw("DATE_TRUNC('month', paid_at)")
            ->orderByRaw("DATE_TRUNC('month', paid_at)")
            ->get()
            ->keyBy('period');

        $revenueTrend = [];
        $cursor = $from->startOfMonth();
        $lastMonth = $to->startOfMonth();
        while ($cursor <= $lastMonth) {
            $period = $cursor->format('Y-m');
            $revenueTrend[] = [
                'period' => $period,
                'label' => $cursor->format('M Y'),
                'revenue' => round((float) ($monthlyRevenue->get($period)->total ?? 0), 2),
            ];
            $cursor = $cursor->addMonth();
        }

        $recentBusinesses = Business::query()
            ->select(['id', 'name', 'status', 'created_at'])
            ->latest('created_at')
            ->limit(6)
            ->get()
            ->map(fn (Business $business) => [
                'id' => $business->id,
                'name' => $business->name,
                'status' => $business->status,
                'created_at' => $business->created_at?->toIso8601String(),
            ]);

        return response()->json([
            'success' => true,
            'data' => [
                'range' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
                'metrics' => [
                    'businesses_total' => $businessCount,
                    'businesses_new' => $newBusinesses,
                    'users_new' => $newUsers,
                    'subscriptions_active' => $activeSubscriptions,
                    'subscriptions_trial' => $trialSubscriptions,
                    'subscriptions_past_due' => $pastDueSubscriptions,
                    'subscription_revenue' => round((float) $revenue, 2),
                    'subscription_payments_failed' => $failedPayments,
                ],
                'revenue_trend' => $revenueTrend,
                'recent_businesses' => $recentBusinesses,
            ],
        ]);
    }
}
