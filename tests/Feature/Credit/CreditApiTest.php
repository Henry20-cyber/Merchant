<?php

namespace Tests\Feature\Credit;

use App\Domains\Credit\Models\Credit;
use App\Domains\Customer\Models\Customer;
use App\Domains\Identity\Services\RoleService;
use App\Domains\Organization\Models\Branch;
use App\Domains\Organization\Models\Business;
use App\Domains\Organization\Models\BusinessUser;
use App\Domains\Organization\Services\BranchContextService;
use App\Domains\Sales\Models\Sale;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Database\Seeders\PermissionSeeder;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\CreatesSubscriptionForBusiness;
use Tests\TestCase;

class CreditApiTest extends TestCase
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

        app(PermissionRegistrar::class)
            ->setPermissionsTeamId($business->id);

        app(RoleService::class)->assignOwner(
            $owner,
            $business->id
        );

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

        app(BranchContextService::class)->set(
            $owner,
            $business,
            $branchA
        );

        return [$business, $owner, $branchA, $branchB];
    }

    private function createCredit(
        Business $business,
        Branch $branch,
        User $owner,
        string $name
    ): Credit {
        $customer = Customer::factory()->create([
            'business_id' => $business->id,
            'name' => $name,
        ]);

        $sale = Sale::factory()->create([
            'business_id' => $business->id,
            'branch_id' => $branch->id,
            'cashier_id' => $owner->id,
            'customer_id' => $customer->id,
            'payment_method' => 'credit',
            'payment_status' => 'pending',
            'status' => 'completed',
            'subtotal' => 10000,
            'discount' => 0,
            'tax' => 0,
            'total' => 10000,
        ]);

        return Credit::create([
            'business_id' => $business->id,
            'customer_id' => $customer->id,
            'sale_id' => $sale->id,
            'original_amount' => 10000,
            'due_at' => now()->addDays(7),
            'status' => 'outstanding',
        ]);
    }

    public function test_credit_list_is_scoped_to_current_branch(): void
    {
        [$business, $owner, $branchA, $branchB] =
            $this->createBusinessWithOwner();

        $creditA = $this->createCredit(
            $business,
            $branchA,
            $owner,
            'Branch A Customer'
        );

        $creditB = $this->createCredit(
            $business,
            $branchB,
            $owner,
            'Branch B Customer'
        );

        $response = $this
            ->actingAs($owner)
            ->withSession([
                'current_branch_id' => $branchA->id,
            ])
            ->withHeaders([
                'X-Business-ID' => $business->id,
            ])
            ->getJson('/api/businesses/current/credits');

        $response->assertOk();

        $ids = collect($response->json('data'))->pluck('id');

        $this->assertTrue($ids->contains($creditA->id));
        $this->assertFalse($ids->contains($creditB->id));
    }

    public function test_credit_from_another_branch_cannot_be_viewed(): void
    {
        [$business, $owner, $branchA, $branchB] =
            $this->createBusinessWithOwner();

        $creditB = $this->createCredit(
            $business,
            $branchB,
            $owner,
            'Branch B Customer'
        );

        $response = $this
            ->actingAs($owner)
            ->withSession([
                'current_branch_id' => $branchA->id,
            ])
            ->withHeaders([
                'X-Business-ID' => $business->id,
            ])
            ->getJson(
                "/api/businesses/current/credits/{$creditB->id}"
            );

        $response->assertNotFound();
    }

    public function test_credit_from_another_branch_cannot_receive_payment(): void
    {
        [$business, $owner, $branchA, $branchB] =
            $this->createBusinessWithOwner();

        $creditB = $this->createCredit(
            $business,
            $branchB,
            $owner,
            'Branch B Customer'
        );

        $response = $this
            ->actingAs($owner)
            ->withSession([
                'current_branch_id' => $branchA->id,
            ])
            ->withHeaders([
                'X-Business-ID' => $business->id,
            ])
            ->postJson(
                "/api/businesses/current/credits/{$creditB->id}/payments",
                [
                    'amount' => 1000,
                    'method' => 'cash',
                ]
            );

        $response->assertNotFound();
    }
}
