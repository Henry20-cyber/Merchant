<?php

namespace Tests\Feature\Receipt;

use App\Domains\Identity\Services\RoleService;
use App\Domains\Organization\Models\Branch;
use App\Domains\Organization\Models\Business;
use App\Domains\Organization\Models\BusinessUser;
use App\Domains\Organization\Services\BranchContextService;
use App\Domains\Receipt\Models\Receipt;
use App\Domains\Sales\Models\Sale;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreatesSubscriptionForBusiness;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class ReceiptPdfTest extends TestCase
{
    use RefreshDatabase;
    use CreatesSubscriptionForBusiness;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PermissionSeeder::class);
    }

    private function createBusinessWithOwner(): array
    {
         $business = $this->createBusinessWithSubscription([
        'features' => [
            'receipts' => true,
        ],
    ]);

        $owner = User::factory()->create();

        BusinessUser::create([
            'business_id' => $business->id,
            'user_id' => $owner->id,
            'status' => 'active',
            'joined_at' => now(),
        ]);

        setPermissionsTeamId($business->id);

        app(RoleService::class)->assignOwner(
            $owner,
            $business->id
        );

        app(PermissionRegistrar::class)
            ->forgetCachedPermissions();

        setPermissionsTeamId($business->id);

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
            $owner,
            $business,
            $branch
        );

        return [$business, $owner];
    }

    private function createReceipt(
        Business $business,
        User $owner
    ): Receipt {
        $branch = Branch::query()
            ->where('business_id', $business->id)
            ->where('is_head_office', true)
            ->firstOrFail();

        $sale = Sale::factory()->create([
            'business_id' => $business->id,
            'branch_id' => $branch->id,
            'cashier_id' => $owner->id,
            'status' => 'completed',
            'payment_status' => 'paid',
            'payment_method' => 'cash',
            'subtotal' => 10000,
            'discount' => 0,
            'vat_amount' => 0,
            'total' => 10000,
        ]);

        return Receipt::factory()->create([
            'business_id' => $business->id,
            'sale_id' => $sale->id,
            'issued_by' => $owner->id,
        ]);
    }

    public function test_authenticated_user_can_download_receipt_pdf(): void
    {
        [$business, $owner] =
            $this->createBusinessWithOwner();

        $receipt = $this->createReceipt($business, $owner);

        $response = $this
            ->actingAs($owner)
            ->withHeaders([
                'X-Business-ID' => $business->id,
            ])
            ->get(
                "/api/businesses/current/receipts/{$receipt->id}/pdf"
            );

        $response->assertOk();

        $response->assertHeader(
            'Content-Type',
            'application/pdf'
        );
    }

    public function test_58mm_pdf_is_supported(): void
    {
        [$business, $owner] =
            $this->createBusinessWithOwner();

        $receipt = $this->createReceipt($business, $owner);

        $response = $this
            ->actingAs($owner)
            ->withHeaders([
                'X-Business-ID' => $business->id,
            ])
            ->get(
                "/api/businesses/current/receipts/{$receipt->id}/pdf?format=58mm"
            );

        $response->assertOk();

        $response->assertHeader(
            'Content-Type',
            'application/pdf'
        );
    }

    public function test_80mm_pdf_is_supported(): void
    {
        [$business, $owner] =
            $this->createBusinessWithOwner();

        $receipt = $this->createReceipt($business, $owner);

        $response = $this
            ->actingAs($owner)
            ->withHeaders([
                'X-Business-ID' => $business->id,
            ])
            ->get(
                "/api/businesses/current/receipts/{$receipt->id}/pdf?format=80mm"
            );

        $response->assertOk();

        $response->assertHeader(
            'Content-Type',
            'application/pdf'
        );
    }

    public function test_a4_pdf_is_supported(): void
    {
        [$business, $owner] =
            $this->createBusinessWithOwner();

        $receipt = $this->createReceipt($business, $owner);

        $response = $this
            ->actingAs($owner)
            ->withHeaders([
                'X-Business-ID' => $business->id,
            ])
            ->get(
                "/api/businesses/current/receipts/{$receipt->id}/pdf?format=a4"
            );

        $response->assertOk();

        $response->assertHeader(
            'Content-Type',
            'application/pdf'
        );
    }

    public function test_unsupported_pdf_format_is_rejected(): void
    {
        [$business, $owner] =
            $this->createBusinessWithOwner();

        $receipt = $this->createReceipt($business, $owner);

        $response = $this
            ->actingAs($owner)
            ->withHeaders([
                'X-Business-ID' => $business->id,
            ])
            ->get(
                "/api/businesses/current/receipts/{$receipt->id}/pdf?format=letter"
            );

        $response->assertStatus(422);
    }

    public function test_receipt_from_another_business_cannot_be_downloaded(): void
    {
        [$businessA, $ownerA] =
            $this->createBusinessWithOwner();

        [$businessB, $ownerB] =
            $this->createBusinessWithOwner();

        $receiptB = $this->createReceipt($businessB, $ownerB);

        $branchA = Branch::query()
            ->where('business_id', $businessA->id)
            ->where('is_head_office', true)
            ->firstOrFail();

        app(BranchContextService::class)->set(
            $ownerA,
            $businessA,
            $branchA
        );

        $response = $this
            ->actingAs($ownerA)
            ->withHeaders([
                'X-Business-ID' => $businessA->id,
            ])
            ->get(
                "/api/businesses/current/receipts/{$receiptB->id}/pdf"
            );

        $response->assertNotFound();
    }

    public function test_inventory_staff_cannot_download_pdf(): void
    {
        [$business, $owner] =
            $this->createBusinessWithOwner();

        $receipt = $this->createReceipt($business, $owner);

        $user = User::factory()->create();

        BusinessUser::create([
            'business_id' => $business->id,
            'user_id' => $user->id,
            'status' => 'active',
            'joined_at' => now(),
        ]);

        setPermissionsTeamId($business->id);

        app(RoleService::class)->assignRole(
            $user,
            'Inventory Staff',
            $business->id
        );

        setPermissionsTeamId($business->id);

        $branch = Branch::query()
            ->where('business_id', $business->id)
            ->where('is_head_office', true)
            ->firstOrFail();

        app(BranchContextService::class)->set(
            $user,
            $business,
            $branch
        );

        $response = $this
            ->actingAs($user)
            ->withHeaders([
                'X-Business-ID' => $business->id,
            ])
            ->get(
                "/api/businesses/current/receipts/{$receipt->id}/pdf"
            );

        $response->assertForbidden();
    }

    public function test_cashier_can_print_receipt_pdf(): void
    {
        [$business, $owner] =
            $this->createBusinessWithOwner();

        $receipt = $this->createReceipt($business, $owner);

        $cashier = User::factory()->create();

        BusinessUser::create([
            'business_id' => $business->id,
            'user_id' => $cashier->id,
            'status' => 'active',
            'joined_at' => now(),
        ]);

        setPermissionsTeamId($business->id);

        app(RoleService::class)->assignRole(
            $cashier,
            'Cashier',
            $business->id
        );

        setPermissionsTeamId($business->id);

        $branch = Branch::query()
            ->where('business_id', $business->id)
            ->where('is_head_office', true)
            ->firstOrFail();

        app(BranchContextService::class)->set(
            $cashier,
            $business,
            $branch
        );

        $response = $this
            ->actingAs($cashier)
            ->withHeaders([
                'X-Business-ID' => $business->id,
            ])
            ->get(
                "/api/businesses/current/receipts/{$receipt->id}/pdf"
            );

        $response->assertOk();

        $response->assertHeader(
            'Content-Type',
            'application/pdf'
        );
    }
}
