<?php

namespace Tests\Feature\Expenses;

use App\Domains\Expenses\Models\Expense;
use App\Domains\Organization\Models\Branch;
use App\Domains\Organization\Models\Business;
use App\Domains\Organization\Models\BusinessUser;
use App\Domains\Organization\Services\BranchContextService;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Database\Seeders\PermissionSeeder;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\CreatesSubscriptionForBusiness;
use Tests\TestCase;

class ExpenseApiTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PermissionSeeder::class);
    }

    use CreatesSubscriptionForBusiness;
    use RefreshDatabase;

    private function createBusinessWithOwner(): array
    {
        $business = $this->createBusinessWithSubscription();
        $owner = User::factory()->create();

        BusinessUser::create([
            'business_id' => $business->id,
            'user_id' => $owner->id,
            'status' => 'active',
            'joined_at' => now(),
        ]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($business->id);

        app(\App\Domains\Identity\Services\RoleService::class)
            ->assignOwner($owner, $business->id);

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        app(PermissionRegistrar::class)->setPermissionsTeamId($business->id);

        $branchA = Branch::create([
            'business_id' => $business->id,
            'name' => 'Main Branch',
            'code' => 'MAIN-' . $business->id,
            'city' => 'Owerri',
            'state' => 'Imo',
            'country' => 'Nigeria',
            'is_head_office' => true,
        ]);

        $branchB = Branch::create([
            'business_id' => $business->id,
            'name' => 'Second Branch',
            'code' => 'SECOND-' . $business->id,
            'city' => 'Port Harcourt',
            'state' => 'Rivers',
            'country' => 'Nigeria',
            'is_head_office' => false,
        ]);

        app(BranchContextService::class)->set($owner, $business, $branchA);

        return [$business, $owner, $branchA, $branchB];
    }

    private function createCategory(Business $business): string
    {
        return \App\Domains\Expenses\Models\ExpenseCategory::create([
            'business_id' => $business->id,
            'name' => 'Utilities',
            'description' => null,
            'is_active' => true,
        ])->id;
    }

    public function test_expense_creation_uses_active_branch(): void
    {
        [$business, $owner, $branchA, $branchB] = $this->createBusinessWithOwner();
        $categoryId = $this->createCategory($business);

        $response = $this
            ->actingAs($owner)
            ->withHeaders(['X-Business-ID' => $business->id])
            ->withSession(['current_branch_id' => $branchA->id])
            ->postJson('/api/businesses/current/expenses', [
                'category_id' => $categoryId,
                'branch_id' => $branchB->id,
                'amount' => 5000,
                'description' => 'Electricity',
                'expense_date' => now()->toDateString(),
                'payment_method' => 'cash',
            ]);

        $response->assertCreated();
        $this->assertDatabaseHas('expenses', [
            'business_id' => $business->id,
            'branch_id' => $branchA->id,
            'amount' => 5000,
            'description' => 'Electricity',
        ]);
        $this->assertDatabaseMissing('expenses', [
            'business_id' => $business->id,
            'branch_id' => $branchB->id,
            'description' => 'Electricity',
        ]);
    }

    public function test_expense_list_is_scoped_to_active_branch(): void
    {
        [$business, $owner, $branchA, $branchB] = $this->createBusinessWithOwner();
        $categoryId = $this->createCategory($business);

        Expense::create([
            'business_id' => $business->id,
            'branch_id' => $branchA->id,
            'category_id' => $categoryId,
            'user_id' => $owner->id,
            'amount' => 1000,
            'description' => 'A expense',
            'expense_date' => now()->toDateString(),
            'payment_method' => 'cash',
            'status' => 'recorded',
        ]);
        Expense::create([
            'business_id' => $business->id,
            'branch_id' => $branchB->id,
            'category_id' => $categoryId,
            'user_id' => $owner->id,
            'amount' => 2000,
            'description' => 'B expense',
            'expense_date' => now()->toDateString(),
            'payment_method' => 'cash',
            'status' => 'recorded',
        ]);

        $response = $this
            ->actingAs($owner)
            ->withHeaders(['X-Business-ID' => $business->id])
            ->withSession(['current_branch_id' => $branchA->id])
            ->getJson('/api/businesses/current/expenses');

        $response->assertOk();
        $descriptions = collect($response->json('data.data'))->pluck('description');
        $this->assertTrue($descriptions->contains('A expense'));
        $this->assertFalse($descriptions->contains('B expense'));
    }

    public function test_categories_remain_business_wide(): void
    {
        [$business, $owner, $branchA, $branchB] = $this->createBusinessWithOwner();
        $categoryId = $this->createCategory($business);

        app(BranchContextService::class)->set($owner, $business, $branchB);

        $response = $this
            ->actingAs($owner)
            ->withSession(['current_branch_id' => $branchA->id])
            ->withHeaders(['X-Business-ID' => $business->id])
            ->getJson('/api/businesses/current/expenses/categories');

        $response->assertOk();
        $this->assertTrue(
            collect($response->json('data'))->pluck('id')->contains($categoryId)
        );
    }
}
