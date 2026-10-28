<?php

namespace Tests\Feature\Credit;

use App\Domains\Credit\Models\Credit;
use App\Domains\Customer\Models\Customer;
use App\Domains\Identity\Services\RoleService;
use App\Domains\Credit\Services\CreditService;
use App\Domains\Organization\Models\Branch;
use App\Domains\Organization\Models\Business;
use App\Domains\Organization\Models\BusinessUser;
use App\Domains\Organization\Services\BranchContextService;
use App\Domains\Sales\Models\Sale;
use App\Domains\Receipt\Models\Receipt;
use App\Domains\Receipt\Services\ReceiptAuditService;
use App\Domains\Receipt\Services\ReceiptQrCodeService;
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
            'vat_amount' => 0,
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


public function test_credit_repayments_sync_sale_and_credit_payment_statuses(): void
{
    [$business, $owner, $branchA] =
        $this->createBusinessWithOwner();

    $credit = $this->createCredit(
        $business,
        $branchA,
        $owner,
        'Repayment Test Customer'
    );

    $service = app(CreditService::class);

    // Record a partial repayment of ₦4,000.
    $service->recordPayment(
        $business,
        $branchA,
        $credit,
        [
            'amount' => 4000,
            'method' => 'cash',
        ]
    );

    $credit->refresh();
    $sale = $credit->sale()->firstOrFail();

    $this->assertSame(
        'partially_paid',
        $sale->payment_status,
        'A partially paid credit sale should have partially_paid status.'
    );

    $this->assertSame(
        'partial',
        $credit->status,
        'A partially repaid credit should have partial status.'
    );

    // Record the remaining ₦6,000.
    $service->recordPayment(
        $business,
        $branchA,
        $credit,
        [
            'amount' => 6000,
            'method' => 'cash',
        ]
    );

    $credit->refresh();
    $sale->refresh();

    $this->assertSame(
        'paid',
        $sale->payment_status,
        'A fully paid credit sale should have paid status.'
    );

    $this->assertSame(
        'settled',
        $credit->status,
        'A fully repaid credit should have settled status.'
    );

    $paymentReceipts = Receipt::query()
        ->where('sale_id', $sale->id)
        ->where('receipt_type', 'payment')
        ->orderBy('issued_at')
        ->get();

    $this->assertCount(2, $paymentReceipts, 'Each successful credit repayment should receive its own receipt.');
    $this->assertNotSame($paymentReceipts[0]->id, $paymentReceipts[1]->id);
    $this->assertNotSame($paymentReceipts[0]->verification_token, $paymentReceipts[1]->verification_token);

    $qrService = app(ReceiptQrCodeService::class);

    foreach ($paymentReceipts as $paymentReceipt) {
        $verificationUrl = $qrService->verificationUrl($paymentReceipt);
        $svg = $qrService->svg($paymentReceipt);
        $path = parse_url($verificationUrl, PHP_URL_PATH);

        $this->assertNotEmpty($path);
        $this->assertStringContainsString('<svg', $svg);
        $this->assertStringContainsString('</svg>', $svg);

        $this->getJson($path)
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.verification_status', 'verified')
            ->assertJsonPath('data.receipt_type', 'payment')
            ->assertJsonPath(
                'data.receipt_number',
                $paymentReceipt->receipt_number,
            );
    }

    $this->assertTrue(
        app(ReceiptAuditService::class)->verifyChain($business->id),
        'The audit chain should remain valid after both payment receipts are verified.',
    );
}
}
