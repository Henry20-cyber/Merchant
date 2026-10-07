<?php

namespace App\Domains\Expenses\Services;

use App\Domains\Expenses\Models\Expense;
use App\Domains\Expenses\Models\ExpenseCategory;
use App\Domains\Organization\Models\Branch;
use App\Domains\Organization\Models\Business;
use App\Models\User;

class ExpenseService
{
    public function list(
        Business $business,
        Branch $branch,
        array $filters = []
    ): array {
        $this->assertBranchBelongsToBusiness($business, $branch);

        $query = Expense::query()
            ->where('business_id', $business->id)
            ->where('branch_id', $branch->id)
            ->with([
                'category:id,name',
                'branch:id,name',
            ])
            ->latest('expense_date')
            ->latest();

        if (! empty($filters['start_date'])) {
            $query->whereDate(
                'expense_date',
                '>=',
                $filters['start_date']
            );
        }

        if (! empty($filters['end_date'])) {
            $query->whereDate(
                'expense_date',
                '<=',
                $filters['end_date']
            );
        }

        if (! empty($filters['category_id'])) {
            $query->where('category_id', $filters['category_id']);
        }

        if (! empty($filters['search'])) {
            $query->where(function ($q) use ($filters) {
                $q->where(
                    'description',
                    'ilike',
                    '%' . $filters['search'] . '%'
                )->orWhere(
                    'reference',
                    'ilike',
                    '%' . $filters['search'] . '%'
                );
            });
        }

        return $query
            ->paginate(
                min(
                    max((int) ($filters['per_page'] ?? 20), 1),
                    100
                )
            )
            ->toArray();
    }

    public function create(
        Business $business,
        Branch $branch,
        User $user,
        array $data
    ): Expense {
        $this->assertBranchBelongsToBusiness($business, $branch);
        $this->assertCategoryBelongsToBusiness(
            $business,
            $data['category_id'] ?? null
        );

        // Branch context is authoritative. Never trust a client-supplied
        // branch_id to select another location.
        unset($data['branch_id']);

        return Expense::create(
            $data + [
                'business_id' => $business->id,
                'branch_id' => $branch->id,
                'user_id' => $user->id,
                'status' => $data['status'] ?? 'recorded',
            ]
        );
    }

    public function update(
        Business $business,
        Branch $branch,
        Expense $expense,
        array $data
    ): Expense {
        $this->assertBranchBelongsToBusiness($business, $branch);
        $this->assertExpenseBelongsToBranch(
            $business,
            $branch,
            $expense
        );
        $this->assertCategoryBelongsToBusiness(
            $business,
            $data['category_id'] ?? $expense->category_id
        );

        // The active branch is authoritative for the record.
        unset($data['branch_id']);

        $expense->update($data);

        return $expense
            ->refresh()
            ->load([
                'category:id,name',
                'branch:id,name',
            ]);
    }

    public function delete(
        Business $business,
        Branch $branch,
        Expense $expense
    ): void {
        $this->assertBranchBelongsToBusiness($business, $branch);
        $this->assertExpenseBelongsToBranch(
            $business,
            $branch,
            $expense
        );

        $expense->delete();
    }

    public function categories(Business $business)
    {
        return ExpenseCategory::query()
            ->where('business_id', $business->id)
            ->where('is_active', true)
            ->orderBy('name')
            ->get();
    }

    public function createCategory(
        Business $business,
        array $data
    ): ExpenseCategory {
        return ExpenseCategory::firstOrCreate(
            [
                'business_id' => $business->id,
                'name' => $data['name'],
            ],
            [
                'description' => $data['description'] ?? null,
                'is_active' => true,
            ]
        );
    }

    public function summary(
        Business $business,
        Branch $branch,
        ?string $start = null,
        ?string $end = null
    ): array {
        $this->assertBranchBelongsToBusiness($business, $branch);

        $query = Expense::query()
            ->where('business_id', $business->id)
            ->where('branch_id', $branch->id)
            ->where('status', 'recorded');

        if ($start) {
            $query->whereDate('expense_date', '>=', $start);
        }

        if ($end) {
            $query->whereDate('expense_date', '<=', $end);
        }

        $total = (float) $query->sum('amount');

        $rows = (clone $query)
            ->leftJoin(
                'expense_categories',
                'expense_categories.id',
                '=',
                'expenses.category_id'
            )
            ->selectRaw(
                "COALESCE(expense_categories.name,'Uncategorised') name, SUM(expenses.amount) amount"
            )
            ->groupBy('expense_categories.name')
            ->orderByDesc('amount')
            ->limit(8)
            ->get()
            ->map(fn ($row) => [
                'name' => $row->name,
                'amount' => (float) $row->amount,
            ])
            ->values()
            ->all();

        return [
            'total' => $total,
            'by_category' => $rows,
        ];
    }

    private function assertCategoryBelongsToBusiness(
        Business $business,
        ?string $id
    ): void {
        if (
            $id
            && ! ExpenseCategory::query()
                ->whereKey($id)
                ->where('business_id', $business->id)
                ->exists()
        ) {
            abort(422, 'Invalid expense category.');
        }
    }

    private function assertBranchBelongsToBusiness(
        Business $business,
        Branch $branch
    ): void {
        if ($branch->business_id !== $business->id) {
            abort(403, 'Branch does not belong to this business.');
        }
    }

    private function assertExpenseBelongsToBranch(
        Business $business,
        Branch $branch,
        Expense $expense
    ): void {
        if (
            $expense->business_id !== $business->id
            || $expense->branch_id !== $branch->id
        ) {
            abort(404);
        }
    }
}
