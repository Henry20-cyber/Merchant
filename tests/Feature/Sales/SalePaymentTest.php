<?php

namespace Tests\Feature\Sales;

use App\Domains\Identity\Services\RoleService;
use App\Domains\Inventory\Models\Stock;
use App\Domains\Organization\Models\Business;
use App\Domains\Organization\Models\BusinessUser;
use App\Domains\Payment\Models\Payment;
use App\Domains\Product\Models\Product;
use App\Domains\Product\Models\ProductUnit;
use App\Domains\Sales\Models\Sale;
use App\Domains\Subscription\Models\Subscription;
use App\Domains\Subscription\Models\SubscriptionPlan;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class SalePaymentTest extends TestCase
{
  use RefreshDatabase;

  protected function setUp(): void
  {
    parent::setUp();

    $this->seed(PermissionSeeder::class);
  }

  private function createBusinessWithOwner(): array
  {
    $business = Business::factory()->create();

     $this->createSubscriptionFor($business);

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

    return [$business, $owner];
  }

  private function createProductWithStock(
    Business $business
): array {
    $product = Product::factory()->create([
        'business_id' => $business->id,
    ]);

    $unit = ProductUnit::factory()->create([
        'business_id' => $business->id,
        'product_id' => $product->id,
        'selling_price' => 1000,
        'cost_price' => 600,
        'is_sellable' => true,
    ]);

    $stock = Stock::create([
        'business_id' => $business->id,
        'product_id' => $product->id,
        'product_unit_id' => $unit->id,
        'quantity' => 10,
        'reorder_level' => 0,
    ]);

    return [$product, $unit, $stock];
}

private function createSubscriptionFor(
    Business $business
): Subscription {
    $plan = SubscriptionPlan::factory()->create([
        'transaction_daily_limit' => 1000,
        'transaction_monthly_limit' => 10000,
        'is_active' => true,
    ]);

    return Subscription::factory()->create([
        'business_id' => $business->id,
        'plan_id' => $plan->id,
        'status' => 'active',
        'starts_at' => now()->subDay(),
        'current_period_start' => now()->subDay(),
        'current_period_end' => now()->addMonth(),
        'grace_period_ends_at' => null,
        'cancelled_at' => null,
        'ended_at' => null,
    ]);
}

  public function test_paid_sale_creates_payment(): void
  {
    [$business, $owner] =
      $this->createBusinessWithOwner();

    [$product, $unit] =
      $this->createProductWithStock($business);

    $response = $this
      ->actingAs($owner)
      ->withHeaders([
        'X-Business-ID' => $business->id,
      ])
      ->postJson('/api/businesses/current/sales', [
        'items' => [
          [
            'product_id' => $product->id,
            'product_unit_id' => $unit->id,
            'quantity' => 2,
          ],
        ],
        'payment_method' => 'cash',
        'payment_status' => 'paid',
        'status' => 'completed',
      ]);

    $response->assertCreated();

    $sale = Sale::query()->latest()->first();

    $this->assertNotNull($sale);

    $payment = Payment::query()
      ->where('sale_id', $sale->id)
      ->first();

    $this->assertNotNull($payment);

    $this->assertSame(
      $business->id,
      $payment->business_id
    );

    $this->assertEquals(
      $sale->total,
      $payment->amount
    );

    $this->assertSame(
      'cash',
      $payment->method
    );

    $this->assertSame(
      'paid',
      $payment->status
    );

    $this->assertNotNull(
      $payment->paid_at
    );
  }

  public function test_payment_uses_sale_payment_method(): void
  {
    [$business, $owner] =
      $this->createBusinessWithOwner();

    [$product, $unit] =
      $this->createProductWithStock($business);

    $response = $this
      ->actingAs($owner)
      ->withHeaders([
        'X-Business-ID' => $business->id,
      ])
      ->postJson('/api/businesses/current/sales', [
        'items' => [
          [
            'product_id' => $product->id,
            'product_unit_id' => $unit->id,
            'quantity' => 1,
          ],
        ],
        'payment_method' => 'bank_transfer',
        'payment_status' => 'paid',
        'status' => 'completed',
      ]);

    $response->assertCreated();

    $sale = Sale::query()->latest()->first();

    $payment = Payment::query()
      ->where('sale_id', $sale->id)
      ->firstOrFail();

    $this->assertSame(
      'bank_transfer',
      $payment->method
    );
  }

  public function test_unpaid_sale_does_not_create_paid_payment(): void
  {
    [$business, $owner] =
      $this->createBusinessWithOwner();

    [$product, $unit] =
      $this->createProductWithStock($business);

    $response = $this
      ->actingAs($owner)
      ->withHeaders([
        'X-Business-ID' => $business->id,
      ])
      ->postJson('/api/businesses/current/sales', [
        'items' => [
          [
            'product_id' => $product->id,
            'product_unit_id' => $unit->id,
            'quantity' => 1,
          ],
        ],
        'payment_method' => 'cash',
        'payment_status' => 'pending',
        'status' => 'completed',
      ]);

    $response->assertCreated();

    $sale = Sale::query()->latest()->first();

    $this->assertDatabaseMissing(
      'payments',
      [
        'sale_id' => $sale->id,
        'status' => 'paid',
      ]
    );
  }

  public function test_payment_belongs_to_same_business_as_sale(): void
  {
    [$business, $owner] =
      $this->createBusinessWithOwner();

    [$product, $unit] =
      $this->createProductWithStock($business);

    $this
      ->actingAs($owner)
      ->withHeaders([
        'X-Business-ID' => $business->id,
      ])
      ->postJson('/api/businesses/current/sales', [
        'items' => [
          [
            'product_id' => $product->id,
            'product_unit_id' => $unit->id,
            'quantity' => 1,
          ],
        ],
        'payment_method' => 'card',
        'payment_status' => 'paid',
        'status' => 'completed',
      ])
      ->assertCreated();

    $sale = Sale::query()->latest()->first();

    $payment = Payment::query()
      ->where('sale_id', $sale->id)
      ->firstOrFail();

    $this->assertSame(
      $sale->business_id,
      $payment->business_id
    );
  }
}
