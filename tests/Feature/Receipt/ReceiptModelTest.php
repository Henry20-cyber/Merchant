<?php

namespace Tests\Feature\Receipt;

use App\Domains\Organization\Models\Business;
use App\Domains\Receipt\Models\Receipt;
use App\Domains\Sales\Models\Sale;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReceiptModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_receipt_belongs_to_business(): void
    {
        $business = Business::factory()->create();

        $cashier = User::factory()->create();

        $sale = Sale::factory()->create([
            'business_id' => $business->id,
            'cashier_id' => $cashier->id,
            'status' => 'completed',
        ]);

        $receipt = Receipt::create([
            'business_id' => $business->id,
            'sale_id' => $sale->id,
            'receipt_number' => 'RCPT-000001',
            'status' => 'issued',
            'issued_by' => $cashier->id,
            'issued_at' => now(),
        ]);

        $this->assertTrue(
            $receipt->business->is($business)
        );
    }

    public function test_receipt_belongs_to_sale(): void
    {
        $business = Business::factory()->create();

        $cashier = User::factory()->create();

        $sale = Sale::factory()->create([
            'business_id' => $business->id,
            'cashier_id' => $cashier->id,
            'status' => 'completed',
        ]);

        $receipt = Receipt::create([
            'business_id' => $business->id,
            'sale_id' => $sale->id,
            'receipt_number' => 'RCPT-000001',
            'status' => 'issued',
            'issued_by' => $cashier->id,
            'issued_at' => now(),
        ]);

        $this->assertTrue(
            $receipt->sale->is($sale)
        );
    }

    public function test_sale_has_one_receipt(): void
    {
        $business = Business::factory()->create();

        $cashier = User::factory()->create();

        $sale = Sale::factory()->create([
            'business_id' => $business->id,
            'cashier_id' => $cashier->id,
            'status' => 'completed',
        ]);

        $receipt = Receipt::create([
            'business_id' => $business->id,
            'sale_id' => $sale->id,
            'receipt_number' => 'RCPT-000001',
            'status' => 'issued',
            'issued_by' => $cashier->id,
            'issued_at' => now(),
        ]);

        $this->assertTrue(
            $sale->receipt->is($receipt)
        );
    }

    public function test_sale_cannot_have_two_receipts(): void
    {
        $business = Business::factory()->create();

        $cashier = User::factory()->create();

        $sale = Sale::factory()->create([
            'business_id' => $business->id,
            'cashier_id' => $cashier->id,
            'status' => 'completed',
        ]);

        Receipt::create([
            'business_id' => $business->id,
            'sale_id' => $sale->id,
            'receipt_number' => 'RCPT-000001',
            'status' => 'issued',
            'issued_by' => $cashier->id,
            'issued_at' => now(),
        ]);

        $this->expectException(
            \Illuminate\Database\QueryException::class
        );

        Receipt::create([
            'business_id' => $business->id,
            'sale_id' => $sale->id,
            'receipt_number' => 'RCPT-000002',
            'status' => 'issued',
            'issued_by' => $cashier->id,
            'issued_at' => now(),
        ]);
    }
}
