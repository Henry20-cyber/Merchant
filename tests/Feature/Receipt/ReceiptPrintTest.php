<?php

namespace Tests\Feature\Receipt;

use App\Domains\Organization\Models\Business;
use App\Domains\Organization\Models\BusinessUser;
use App\Domains\Receipt\Models\Receipt;
use App\Domains\Sales\Models\Sale;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class ReceiptPrintTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(
            PermissionSeeder::class
        );
    }

    /**
     * Create a business and an owner with receipt
     * viewing permission.
     */
    private function createBusinessWithOwner(): array
    {
        $business = Business::factory()->create();

        $user = User::factory()->create();

        BusinessUser::create([
            'business_id' => $business->id,
            'user_id' => $user->id,
            'status' => 'active',
            'joined_at' => now(),
        ]);

        app(
            \App\Domains\Identity\Services\RoleService::class
        )->assignOwner(
            $user,
            $business->id
        );

        app(
            PermissionRegistrar::class
        )->setPermissionsTeamId(
            $business->id
        );

        return [
            $business,
            $user,
        ];
    }

    /**
     * Create a receipt for a business.
     */
    private function createReceipt(
        Business $business,
        User $user
    ): Receipt {
        $sale = Sale::factory()->create([
            'business_id' => $business->id,
            'cashier_id' => $user->id,
            'status' => 'completed',
            'payment_status' => 'paid',
            'payment_method' => 'cash',
            'subtotal' => 10000,
            'discount' => 0,
            'tax' => 0,
            'total' => 10000,
        ]);

        return Receipt::factory()->create([
            'business_id' => $business->id,
            'sale_id' => $sale->id,
            'issued_by' => $user->id,
            'receipt_number' => 'RCPT-000001',
            'snapshot' => [
                'version' => 1,

                'receipt' => [
                    'number' => 'RCPT-000001',
                    'status' => 'issued',
                    'issued_at' => now()->toISOString(),
                ],

                'business' => [
                    'name' => $business->name,
                    'currency' => 'NGN',
                    'timezone' => 'Africa/Lagos',
                ],

                'customer' => null,

                'cashier' => [
                    'id' => $user->id,
                    'name' => $user->name,
                ],

                'items' => [
                    [
                        'id' => 'item-1',
                        'type' => 'product',
                        'name' => 'Test Product',
                        'quantity' => 2,
                        'unit_price' => 5000,
                        'discount' => 0,
                        'total' => 10000,
                    ],
                ],

                'sale' => [
                    'id' => $sale->id,
                    'subtotal' => '10000.00',
                    'discount' => '0.00',
                    'tax' => '0.00',
                    'total' => '10000.00',
                    'payment_method' => 'cash',
                    'payment_status' => 'paid',
                    'status' => 'completed',
                ],

                'payments' => [],
            ],
        ]);
    }

    public function test_authenticated_user_can_print_receipt(): void
    {
        [$business, $user] =
            $this->createBusinessWithOwner();

        $receipt = $this->createReceipt(
            $business,
            $user
        );

        $response = $this
            ->actingAs($user)
            ->withHeaders([
                'X-Business-ID' => $business->id,
            ])
            ->get(
                "/api/businesses/current/receipts/{$receipt->id}/print"
            );

        $response
            ->assertOk()
            ->assertHeader(
                'Content-Type',
                'text/html; charset=UTF-8'
            )
            ->assertHeader(
                'X-Receipt-Number',
                'RCPT-000001'
            );

        $response->assertSee(
            $business->name
        );

        $response->assertSee(
            'RCPT-000001'
        );

        $response->assertSee(
            'Test Product'
        );

        $response->assertSee(
            '10,000.00'
        );
    }

    public function test_58mm_format_is_supported(): void
    {
        [$business, $user] =
            $this->createBusinessWithOwner();

        $receipt = $this->createReceipt(
            $business,
            $user
        );

        $response = $this
            ->actingAs($user)
            ->withHeaders([
                'X-Business-ID' => $business->id,
            ])
            ->get(
                "/api/businesses/current/receipts/{$receipt->id}/print?format=58mm"
            );

        $response
            ->assertOk()
            ->assertSee(
                'receipt-58mm'
            );
    }

    public function test_80mm_format_is_supported(): void
    {
        [$business, $user] =
            $this->createBusinessWithOwner();

        $receipt = $this->createReceipt(
            $business,
            $user
        );

        $response = $this
            ->actingAs($user)
            ->withHeaders([
                'X-Business-ID' => $business->id,
            ])
            ->get(
                "/api/businesses/current/receipts/{$receipt->id}/print?format=80mm"
            );

        $response
            ->assertOk()
            ->assertSee(
                'receipt-80mm'
            );
    }

    public function test_a4_format_is_supported(): void
    {
        [$business, $user] =
            $this->createBusinessWithOwner();

        $receipt = $this->createReceipt(
            $business,
            $user
        );

        $response = $this
            ->actingAs($user)
            ->withHeaders([
                'X-Business-ID' => $business->id,
            ])
            ->get(
                "/api/businesses/current/receipts/{$receipt->id}/print?format=a4"
            );

        $response
            ->assertOk()
            ->assertSee(
                'receipt-a4'
            );
    }

    public function test_unsupported_format_is_rejected(): void
    {
        [$business, $user] =
            $this->createBusinessWithOwner();

        $receipt = $this->createReceipt(
            $business,
            $user
        );

        $response = $this
            ->actingAs($user)
            ->withHeaders([
                'X-Business-ID' => $business->id,
            ])
            ->get(
                "/api/businesses/current/receipts/{$receipt->id}/print?format=letter"
            );

        $response->assertStatus(422);
    }

    public function test_receipt_from_another_business_cannot_be_printed(): void
    {
        [$businessA, $userA] =
            $this->createBusinessWithOwner();

        [$businessB, $userB] =
            $this->createBusinessWithOwner();

        $receipt = $this->createReceipt(
            $businessB,
            $userB
        );

        $response = $this
            ->actingAs($userA)
            ->withHeaders([
                'X-Business-ID' => $businessA->id,
            ])
            ->get(
                "/api/businesses/current/receipts/{$receipt->id}/print"
            );

        $response->assertNotFound();
    }

    public function test_user_without_receipt_permission_cannot_print(): void
    {
        $business = Business::factory()->create();

        $user = User::factory()->create();

        BusinessUser::create([
            'business_id' => $business->id,
            'user_id' => $user->id,
            'status' => 'active',
            'joined_at' => now(),
        ]);

        app(
            PermissionRegistrar::class
        )->setPermissionsTeamId(
            $business->id
        );

        $receipt = Receipt::factory()->create([
            'business_id' => $business->id,
            'issued_by' => $user->id,
        ]);

        $response = $this
            ->actingAs($user)
            ->withHeaders([
                'X-Business-ID' => $business->id,
            ])
            ->get(
                "/api/businesses/current/receipts/{$receipt->id}/print"
            );

        $response->assertForbidden();
    }
}
