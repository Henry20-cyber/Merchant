<?php

namespace Tests\Feature\Receipt;

use App\Domains\Organization\Models\Branch;
use App\Domains\Organization\Models\Business;
use App\Domains\Organization\Models\BusinessUser;
use App\Domains\Receipt\Models\Receipt;
use App\Domains\Sales\Models\Sale;
use App\Models\User;
use App\Domains\Organization\Services\BranchContextService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;
use Tests\Support\CreatesSubscriptionForBusiness;

class ReceiptApiTest extends TestCase
{
    use CreatesSubscriptionForBusiness;

    use RefreshDatabase;

    /*
    |--------------------------------------------------------------------------
    | Setup
    |--------------------------------------------------------------------------
    */

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)
            ->forgetCachedPermissions();
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers
    |--------------------------------------------------------------------------
    */

    private function setPermissionTeam(
        Business $business
    ): void {
        app(PermissionRegistrar::class)
            ->setPermissionsTeamId(
                $business->id
            );
    }

    private function createBusinessWithUser(): array
    {
        $business = $this->createBusinessWithSubscription();

        $user = User::factory()->create();

        BusinessUser::create([
            'business_id' => $business->id,
            'user_id' => $user->id,
            'status' => 'active',
            'joined_at' => now(),
        ]);

        $this->setPermissionTeam(
            $business
        );

        $branch = Branch::create([
            'business_id' => $business->id,
            'name' => 'Main Branch',
            'code' => 'MAIN-' . $business->id,
            'city' => 'Owerri',
            'state' => 'Imo',
            'country' => 'Nigeria',
            'is_head_office' => true,
        ]);

        app(BranchContextService::class)->set(
            $user,
            $business,
            $branch
        );

        return [
            $business,
            $user,
        ];
    }

    private function giveReceiptViewPermission(
        User $user,
        Business $business
    ): void {
        $this->setPermissionTeam(
            $business
        );

        $permission = Permission::firstOrCreate([
            'name' => 'receipts.view',
            'guard_name' => 'web',
        ]);

        $role = Role::firstOrCreate([
            'name' => 'Receipt API Tester',
            'guard_name' => 'web',
        ]);

        $role->givePermissionTo(
            $permission
        );

        $user->assignRole(
            $role
        );
    }

    private function createReceipt(
        Business $business,
        User $user
    ): Receipt {
        $branch = Branch::query()
            ->where('business_id', $business->id)
            ->where('is_head_office', true)
            ->firstOrFail();

        $sale = Sale::factory()->create([
            'business_id' => $business->id,
            'branch_id' => $branch->id,
            'cashier_id' => $user->id,
            'payment_status' => 'paid',
            'status' => 'completed',
            'subtotal' => 10000,
            'discount' => 0,
            'vat_amount' => 0,
            'total' => 10000,
            'payment_method' => 'cash',
        ]);

        return Receipt::factory()->create([
            'business_id' => $business->id,
            'sale_id' => $sale->id,
            'issued_by' => $user->id,
            'status' => 'issued',
            'receipt_number' => 'RCPT-000001',
            'issued_at' => now(),
            'snapshot' => [
                'version' => 1,

                'receipt' => [
                    'number' => 'RCPT-000001',
                    'status' => 'issued',
                ],

                'business' => [
                    'name' => $business->name,
                    'currency' => 'NGN',
                ],

                'sale' => [
                    'id' => $sale->id,
                    'subtotal' => '10000.00',
                    'discount' => '0.00',
                    'vat_amount' => '0.00',
                    'total' => '10000.00',
                    'payment_method' => 'cash',
                    'payment_status' => 'paid',
                    'status' => 'completed',
                ],

                'cashier' => [
                    'id' => $user->id,
                    'name' => $user->name,
                ],

                'customer' => null,

                'items' => [],
            ],
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Authentication
    |--------------------------------------------------------------------------
    */

    public function test_unauthenticated_user_cannot_list_receipts(): void
    {
        $response = $this->getJson(
            '/api/businesses/current/receipts'
        );

        $response->assertUnauthorized();
    }

    /*
    |--------------------------------------------------------------------------
    | Authorization
    |--------------------------------------------------------------------------
    */

    public function test_user_without_receipt_view_permission_cannot_list_receipts(): void
    {
        [$business, $user] =
            $this->createBusinessWithUser();

        $this->setPermissionTeam(
            $business
        );

        $response = $this
            ->actingAs($user)
            ->withHeaders([
                'X-Business-ID' => $business->id,
            ])
            ->getJson(
                '/api/businesses/current/receipts'
            );

        $response->assertForbidden();
    }

    /*
    |--------------------------------------------------------------------------
    | Listing
    |--------------------------------------------------------------------------
    */
    public function test_user_with_receipt_view_permission_can_list_receipts(): void
    {
        [$business, $user] =
            $this->createBusinessWithUser();

        $this->giveReceiptViewPermission(
            $user,
            $business
        );

        $receipt = $this->createReceipt(
            $business,
            $user
        );

        $response = $this
            ->actingAs($user)
            ->withHeaders([
                'X-Business-ID' => $business->id,
            ])
            ->getJson(
                '/api/businesses/current/receipts'
            );

        $response->assertSuccessful();

        $this->assertTrue(
            $response->json('success')
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Show
    |--------------------------------------------------------------------------
    */

    public function test_user_can_view_receipt(): void
    {
        [$business, $user] =
            $this->createBusinessWithUser();

        $this->giveReceiptViewPermission(
            $user,
            $business
        );

        $receipt = $this->createReceipt(
            $business,
            $user
        );

        $response = $this
            ->actingAs($user)
            ->withHeaders([
                'X-Business-ID' => $business->id,
            ])
            ->getJson(
                "/api/businesses/current/receipts/{$receipt->id}"
            );

        $response
            ->assertSuccessful()
            ->assertJsonPath(
                'success',
                true
            )
            ->assertJsonPath(
                'data.id',
                $receipt->id
            )
            ->assertJsonPath(
                'data.receipt_number',
                'RCPT-000001'
            )
            ->assertJsonPath(
                'data.receipt.version',
                1
            );
    }

    /*
    |--------------------------------------------------------------------------
    | Tenant Isolation
    |--------------------------------------------------------------------------
    */

    public function test_receipt_from_another_business_cannot_be_viewed(): void
    {
        [$businessA, $userA] =
            $this->createBusinessWithUser();

        $this->giveReceiptViewPermission(
            $userA,
            $businessA
        );

        [$businessB, $userB] =
            $this->createBusinessWithUser();

        $receipt = $this->createReceipt(
            $businessB,
            $userB
        );

        $branchA = Branch::query()
            ->where('business_id', $businessA->id)
            ->where('is_head_office', true)
            ->firstOrFail();

        app(BranchContextService::class)->set(
            $userA,
            $businessA,
            $branchA
        );

        $response = $this
            ->actingAs($userA)
            ->withHeaders([
                'X-Business-ID' => $businessA->id,
            ])
            ->getJson(
                "/api/businesses/current/receipts/{$receipt->id}"
            );

        $response->assertNotFound();
    }

    public function test_receipt_list_is_scoped_to_current_business(): void
    {
        [$businessA, $userA] =
            $this->createBusinessWithUser();

        $this->giveReceiptViewPermission(
            $userA,
            $businessA
        );

        [$businessB, $userB] =
            $this->createBusinessWithUser();

        $receiptA = $this->createReceipt(
            $businessA,
            $userA
        );

        $receiptB = $this->createReceipt(
            $businessB,
            $userB
        );

        $branchA = Branch::query()
            ->where('business_id', $businessA->id)
            ->where('is_head_office', true)
            ->firstOrFail();

        app(BranchContextService::class)->set(
            $userA,
            $businessA,
            $branchA
        );

        $response = $this
            ->actingAs($userA)
            ->withHeaders([
                'X-Business-ID' => $businessA->id,
            ])
            ->getJson(
                '/api/businesses/current/receipts'
            );

        $response
            ->assertSuccessful()
            ->assertJsonPath(
                'success',
                true
            );

        $ids = collect(
            $response->json('data')
        )->pluck('id');

        $this->assertTrue(
            $ids->contains($receiptA->id)
        );

        $this->assertFalse(
            $ids->contains($receiptB->id)
        );
    }
}
