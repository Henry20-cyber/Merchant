<?php

namespace Tests\Feature\Sales;

use App\Domains\Customer\Models\Customer;
use App\Domains\Inventory\Models\Stock;
use App\Domains\Inventory\Models\StockMovement;
use App\Domains\Organization\Models\Business;
use App\Domains\Organization\Models\BusinessUser;
use App\Domains\Product\Models\Product;
use App\Domains\Product\Models\ProductUnit;
use App\Models\User;
use App\Domains\Subscription\Models\Subscription;
use App\Domains\Subscription\Models\SubscriptionPlan;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class SaleApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PermissionSeeder::class);
    }

    /*
    |--------------------------------------------------------------------------
    | Test Helpers
    |--------------------------------------------------------------------------
    */

    private function setPermissionTeam(Business $business): void
    {
        app(PermissionRegistrar::class)
            ->setPermissionsTeamId($business->id);
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

        app(\App\Domains\Identity\Services\RoleService::class)
            ->assignOwner(
                $owner,
                $business->id
            );

        $this->setPermissionTeam($business);

        return [$business, $owner];
    }

    private function createMember(
        Business $business,
        string $roleName
    ): User {
        $user = User::factory()->create();

        BusinessUser::create([
            'business_id' => $business->id,
            'user_id' => $user->id,
            'status' => 'active',
            'joined_at' => now(),
        ]);

        $this->setPermissionTeam($business);

        app(\App\Domains\Identity\Services\RoleService::class)
            ->assignRole(
                $user,
                $roleName,
                $business->id
            );

        return $user;
    }

    private function createProductWithStock(
        Business $business,
        float $stockQuantity = 100
    ): array {
        $product = Product::factory()->create([
            'business_id' => $business->id,
        ]);

        $unit = ProductUnit::factory()->create([
            'business_id' => $business->id,
            'product_id' => $product->id,
            'quantity' => 1,
            'cost_price' => 100,
            'selling_price' => 150,
            'is_base_unit' => true,
            'is_sellable' => true,
            'is_purchasable' => true,
        ]);

        $stock = Stock::create([
            'business_id' => $business->id,
            'product_id' => $product->id,
            'product_unit_id' => $unit->id,
            'quantity' => $stockQuantity,
            'reorder_level' => 10,
        ]);

        return [$product, $unit, $stock];
    }

    private function salePayload(
        Product $product,
        ProductUnit $unit,
        float $quantity = 2
    ): array {
        return [
            'items' => [
                [
                    'product_id' => $product->id,
                    'product_unit_id' => $unit->id,
                    'quantity' => $quantity,
                ],
            ],
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Authorization
    |--------------------------------------------------------------------------
    */

    public function test_owner_can_create_sale(): void
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
            ->postJson(
                '/api/businesses/current/sales',
                $this->salePayload($product, $unit)
            );

        $response
            ->assertSuccessful()
            ->assertJsonPath(
                'success',
                true
            );
    }

    public function test_manager_can_create_sale(): void
    {
        [$business] =
            $this->createBusinessWithOwner();

        $manager = $this->createMember(
            $business,
            'Manager'
        );

        [$product, $unit] =
            $this->createProductWithStock($business);

        $response = $this
            ->actingAs($manager)
            ->withHeaders([
                'X-Business-ID' => $business->id,
            ])
            ->postJson(
                '/api/businesses/current/sales',
                $this->salePayload($product, $unit)
            );

        $response
            ->assertSuccessful()
            ->assertJsonPath(
                'success',
                true
            );
    }

    public function test_cashier_can_create_sale(): void
    {
        [$business] =
            $this->createBusinessWithOwner();

        $cashier = $this->createMember(
            $business,
            'Cashier'
        );

        [$product, $unit] =
            $this->createProductWithStock($business);

        $response = $this
            ->actingAs($cashier)
            ->withHeaders([
                'X-Business-ID' => $business->id,
            ])
            ->postJson(
                '/api/businesses/current/sales',
                $this->salePayload($product, $unit)
            );

        $response
            ->assertSuccessful()
            ->assertJsonPath(
                'success',
                true
            );
    }

    public function test_inventory_staff_cannot_create_sale(): void
    {
        [$business] =
            $this->createBusinessWithOwner();

        $inventoryStaff = $this->createMember(
            $business,
            'Inventory Staff'
        );

        [$product, $unit] =
            $this->createProductWithStock($business);

        $response = $this
            ->actingAs($inventoryStaff)
            ->withHeaders([
                'X-Business-ID' => $business->id,
            ])
            ->postJson(
                '/api/businesses/current/sales',
                $this->salePayload($product, $unit)
            );

        $response->assertForbidden();
    }

    public function test_user_without_sales_permission_cannot_create_sale(): void
    {
        [$business] =
            $this->createBusinessWithOwner();

        $user = User::factory()->create();

        BusinessUser::create([
            'business_id' => $business->id,
            'user_id' => $user->id,
            'status' => 'active',
            'joined_at' => now(),
        ]);

        [$product, $unit] =
            $this->createProductWithStock($business);

        $response = $this
            ->actingAs($user)
            ->withHeaders([
                'X-Business-ID' => $business->id,
            ])
            ->postJson(
                '/api/businesses/current/sales',
                $this->salePayload($product, $unit)
            );

        $response->assertForbidden();
    }

    /*
    |--------------------------------------------------------------------------
    | Sale Creation
    |--------------------------------------------------------------------------
    */

    public function test_sale_creation_returns_sale_data(): void
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
            ->postJson(
                '/api/businesses/current/sales',
                $this->salePayload($product, $unit)
            );

        $response
            ->assertSuccessful()
            ->assertJsonStructure([
                'success',
                'message',
                'data' => [
                    'id',
                    'business_id',
                    'cashier_id',
                    'customer_id',
                    'subtotal',
                    'discount',
                    'tax',
                    'total',
                    'payment_method',
                    'payment_status',
                    'status',
                    'items',
                ],
            ]);
    }

    public function test_sale_deducts_stock(): void
    {
        [$business, $owner] =
            $this->createBusinessWithOwner();

        [$product, $unit, $stock] =
            $this->createProductWithStock(
                $business,
                100
            );

        $response = $this
            ->actingAs($owner)
            ->withHeaders([
                'X-Business-ID' => $business->id,
            ])
            ->postJson(
                '/api/businesses/current/sales',
                $this->salePayload(
                    $product,
                    $unit,
                    2
                )
            );

        $response->assertSuccessful();

        $this->assertDatabaseHas('stocks', [
            'id' => $stock->id,
            'quantity' => 98,
        ]);
    }

    public function test_sale_creates_stock_movement(): void
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
            ->postJson(
                '/api/businesses/current/sales',
                $this->salePayload(
                    $product,
                    $unit,
                    2
                )
            );

        $response->assertSuccessful();

        $this->assertDatabaseHas(
            'stock_movements',
            [
                'business_id' => $business->id,
                'product_id' => $product->id,
                'product_unit_id' => $unit->id,
                'quantity' => -2,
            ]
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Validation
    |--------------------------------------------------------------------------
    */

    public function test_sale_rejects_insufficient_stock(): void
    {
        [$business, $owner] =
            $this->createBusinessWithOwner();

        [$product, $unit] =
            $this->createProductWithStock(
                $business,
                1
            );

        $response = $this
            ->actingAs($owner)
            ->withHeaders([
                'X-Business-ID' => $business->id,
            ])
            ->postJson(
                '/api/businesses/current/sales',
                $this->salePayload(
                    $product,
                    $unit,
                    2
                )
            );

        $response->assertUnprocessable();
    }

    public function test_sale_rejects_zero_quantity(): void
    {
        [$business, $owner] =
            $this->createBusinessWithOwner();

        [$product, $unit] =
            $this->createProductWithStock($business);

        $payload = $this->salePayload(
            $product,
            $unit,
            0
        );

        $response = $this
            ->actingAs($owner)
            ->withHeaders([
                'X-Business-ID' => $business->id,
            ])
            ->postJson(
                '/api/businesses/current/sales',
                $payload
            );

        $response->assertUnprocessable();
    }

    public function test_sale_rejects_negative_quantity(): void
    {
        [$business, $owner] =
            $this->createBusinessWithOwner();

        [$product, $unit] =
            $this->createProductWithStock($business);

        $payload = $this->salePayload(
            $product,
            $unit,
            -1
        );

        $response = $this
            ->actingAs($owner)
            ->withHeaders([
                'X-Business-ID' => $business->id,
            ])
            ->postJson(
                '/api/businesses/current/sales',
                $payload
            );

        $response->assertUnprocessable();
    }

    public function test_sale_rejects_non_sellable_unit(): void
    {
        [$business, $owner] =
            $this->createBusinessWithOwner();

        $product = Product::factory()->create([
            'business_id' => $business->id,
        ]);

        $unit = ProductUnit::factory()->create([
            'business_id' => $business->id,
            'product_id' => $product->id,
            'quantity' => 1,
            'cost_price' => 100,
            'selling_price' => 150,
            'is_base_unit' => true,
            'is_sellable' => false,
            'is_purchasable' => true,
        ]);

        Stock::create([
            'business_id' => $business->id,
            'product_id' => $product->id,
            'product_unit_id' => $unit->id,
            'quantity' => 100,
            'reorder_level' => 10,
        ]);

        $response = $this
            ->actingAs($owner)
            ->withHeaders([
                'X-Business-ID' => $business->id,
            ])
            ->postJson(
                '/api/businesses/current/sales',
                $this->salePayload(
                    $product,
                    $unit
                )
            );

        $response->assertUnprocessable();
    }

    public function test_sale_rejects_product_from_another_business(): void
    {
        [$businessA, $owner] =
            $this->createBusinessWithOwner();

        $businessB =
            Business::factory()->create();

        [$productB, $unitB] =
            $this->createProductWithStock(
                $businessB
            );

        $response = $this
            ->actingAs($owner)
            ->withHeaders([
                'X-Business-ID' => $businessA->id,
            ])
            ->postJson(
                '/api/businesses/current/sales',
                $this->salePayload(
                    $productB,
                    $unitB
                )
            );

        $response->assertUnprocessable();
    }

    public function test_sale_cannot_be_created_in_another_business_context(): void
    {
        [$businessA, $owner] =
            $this->createBusinessWithOwner();

        $businessB =
            Business::factory()->create();

        [$productB, $unitB] =
            $this->createProductWithStock(
                $businessB
            );

        $response = $this
            ->actingAs($owner)
            ->withHeaders([
                'X-Business-ID' => $businessA->id,
            ])
            ->postJson(
                '/api/businesses/current/sales',
                $this->salePayload(
                    $productB,
                    $unitB
                )
            );

        $response->assertUnprocessable();
    }

    /*
    |--------------------------------------------------------------------------
    | Customer Integration
    |--------------------------------------------------------------------------
    */

    public function test_cashier_can_create_sale_for_customer(): void
    {
        [$business] =
            $this->createBusinessWithOwner();

        $cashier = $this->createMember(
            $business,
            'Cashier'
        );

        [$product, $unit] =
            $this->createProductWithStock(
                $business
            );

        $customer = Customer::factory()->create([
            'business_id' => $business->id,
            'status' => 'active',
        ]);

        $payload = $this->salePayload(
            $product,
            $unit
        );

        $payload['customer_id'] =
            $customer->id;

        $response = $this
            ->actingAs($cashier)
            ->withHeaders([
                'X-Business-ID' => $business->id,
            ])
            ->postJson(
                '/api/businesses/current/sales',
                $payload
            );

        $response
            ->assertSuccessful()
            ->assertJsonPath(
                'success',
                true
            )
            ->assertJsonPath(
                'data.customer_id',
                $customer->id
            );

        $this->assertDatabaseHas('sales', [
            'business_id' => $business->id,
            'customer_id' => $customer->id,
        ]);
    }

    public function test_sale_can_be_created_without_customer(): void
    {
        [$business, $owner] =
            $this->createBusinessWithOwner();

        [$product, $unit] =
            $this->createProductWithStock(
                $business
            );

        $response = $this
            ->actingAs($owner)
            ->withHeaders([
                'X-Business-ID' => $business->id,
            ])
            ->postJson(
                '/api/businesses/current/sales',
                $this->salePayload(
                    $product,
                    $unit
                )
            );

        $response
            ->assertSuccessful()
            ->assertJsonPath(
                'success',
                true
            )
            ->assertJsonPath(
                'data.customer_id',
                null
            );
    }

    public function test_sale_cannot_use_customer_from_another_business(): void
    {
        [$businessA, $owner] =
            $this->createBusinessWithOwner();

        $businessB =
            Business::factory()->create();

        $customerB = Customer::factory()->create([
            'business_id' => $businessB->id,
            'status' => 'active',
        ]);

        [$product, $unit] =
            $this->createProductWithStock(
                $businessA
            );

        $payload = $this->salePayload(
            $product,
            $unit
        );

        $payload['customer_id'] =
            $customerB->id;

        $response = $this
            ->actingAs($owner)
            ->withHeaders([
                'X-Business-ID' => $businessA->id,
            ])
            ->postJson(
                '/api/businesses/current/sales',
                $payload
            );

        $response->assertUnprocessable();

        $this->assertDatabaseMissing('sales', [
            'business_id' => $businessA->id,
            'customer_id' => $customerB->id,
        ]);
    }

    public function test_sale_cannot_use_inactive_customer(): void
    {
        [$business, $owner] =
            $this->createBusinessWithOwner();

        $customer = Customer::factory()->create([
            'business_id' => $business->id,
            'status' => 'inactive',
        ]);

        [$product, $unit] =
            $this->createProductWithStock(
                $business
            );

        $payload = $this->salePayload(
            $product,
            $unit
        );

        $payload['customer_id'] =
            $customer->id;

        $response = $this
            ->actingAs($owner)
            ->withHeaders([
                'X-Business-ID' => $business->id,
            ])
            ->postJson(
                '/api/businesses/current/sales',
                $payload
            );

        $response->assertUnprocessable();

        $this->assertDatabaseMissing('sales', [
            'business_id' => $business->id,
            'customer_id' => $customer->id,
        ]);
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
}
