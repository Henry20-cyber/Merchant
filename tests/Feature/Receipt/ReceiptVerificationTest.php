<?php

namespace Tests\Feature\Receipt;

use App\Domains\Organization\Models\Business;
use App\Domains\Organization\Models\BusinessUser;
use App\Domains\Receipt\Models\Receipt;
use App\Domains\Receipt\Services\ReceiptService;
use App\Domains\Receipt\Services\ReceiptAuditService;
use App\Domains\Receipt\Services\ReceiptQrCodeService;
use App\Domains\Sales\Models\Sale;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReceiptVerificationTest extends TestCase
{
    use RefreshDatabase;

    private function issueReceipt(): Receipt
    {
        $business = Business::factory()->create([
            'name' => 'Verification Test Store',
            'currency' => 'NGN',
        ]);
        $cashier = User::factory()->create();
        BusinessUser::create([
            'business_id' => $business->id,
            'user_id' => $cashier->id,
            'status' => 'active',
            'joined_at' => now(),
        ]);
        $sale = Sale::factory()->create([
            'business_id' => $business->id,
            'cashier_id' => $cashier->id,
            'status' => 'completed',
            'payment_method' => 'cash',
            'payment_status' => 'paid',
            'subtotal' => 1500,
            'discount' => 0,
            'taxable_amount' => 1500,
            'vat_enabled' => false,
            'vat_rate' => 0,
            'vat_amount' => 0,
            'total' => 1500,
        ]);

        return app(ReceiptService::class)->issue($sale, $cashier);
    }

    public function test_public_verification_returns_minimal_summary_for_authentic_receipt(): void
    {
        $receipt = $this->issueReceipt();

        $response = $this->getJson('/api/receipts/verify/' . $receipt->verification_token);

        $response->assertOk()
            ->assertJsonPath('data.verification_status', 'verified')
            ->assertJsonPath('data.receipt_number', $receipt->receipt_number)
            ->assertJsonPath('data.business_name', 'Verification Test Store')
            ->assertJsonMissingPath('data.customer')
            ->assertJsonMissingPath('data.items');
    }

    public function test_public_verification_detects_snapshot_tampering(): void
    {
        $receipt = $this->issueReceipt();
        $snapshot = $receipt->snapshot;
        $snapshot['sale']['total'] = '0.01';
        $receipt->forceFill(['snapshot' => $snapshot])->save();

        $this->getJson('/api/receipts/verify/' . $receipt->verification_token)
            ->assertOk()
            ->assertJsonPath('data.verification_status', 'integrity_check_failed')
            ->assertJsonPath('data.verified', false);
    }

    public function test_unknown_verification_token_does_not_disclose_receipt_data(): void
    {
        $this->getJson('/api/receipts/verify/' . str_repeat('a', 64))
            ->assertNotFound();
    }


    public function test_qr_service_generates_svg_for_the_receipt_verification_url(): void
    {
        $receipt = $this->issueReceipt();

        $qrService = app(ReceiptQrCodeService::class);

        $url = $qrService->verificationUrl($receipt);
        $svg = $qrService->svg($receipt);

        $this->assertSame(
            rtrim((string) config('app.url'), '/')
                . '/api/receipts/verify/'
                . $receipt->verification_token,
            $url,
        );

        $this->assertStringContainsString('<svg', $svg);
        $this->assertStringContainsString('</svg>', $svg);
        $this->assertNotEmpty($svg);
    }

    public function test_qr_verification_url_reaches_the_live_backend_verification_endpoint(): void
    {
        $receipt = $this->issueReceipt();

        $url = app(ReceiptQrCodeService::class)
            ->verificationUrl($receipt);

        $path = parse_url($url, PHP_URL_PATH);

        $this->assertSame(
            '/api/receipts/verify/' . $receipt->verification_token,
            $path,
        );

        $this->getJson($path)
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.verification_status', 'verified')
            ->assertJsonPath('data.verified', true)
            ->assertJsonPath('data.receipt_number', $receipt->receipt_number);
    }

    public function test_successful_qr_verification_is_recorded_in_the_audit_chain(): void
    {
        $receipt = $this->issueReceipt();

        $path = '/api/receipts/verify/' . $receipt->verification_token;

        $before = \DB::table('receipt_audit_events')
            ->where('receipt_id', $receipt->id)
            ->count();

        $this->getJson($path)
            ->assertOk()
            ->assertJsonPath('data.verification_status', 'verified');

        $after = \DB::table('receipt_audit_events')
            ->where('receipt_id', $receipt->id)
            ->count();

        $this->assertSame($before + 1, $after);

        $this->assertTrue(
            app(ReceiptAuditService::class)->verifyChain($receipt->business_id),
        );
    }


}
