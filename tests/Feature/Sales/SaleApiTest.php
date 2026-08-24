<?php

namespace Tests\Feature\Sales;

use App\Domains\Inventory\Models\Stock;
use App\Domains\Inventory\Models\StockMovement;
use App\Domains\Organization\Models\Business;
use App\Domains\Organization\Models\BusinessUser;
use App\Domains\Product\Models\Product;
use App\Domains\Product\Models\ProductUnit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;
use Database\Seeders\PermissionSeeder;

class SaleApiTest extends TestCase
{
    use RefreshDatabase;

protected function setUp(): void
{
    parent::setUp();

    $this->seed(PermissionSeeder::class);
}

    private function setPermissionTeam(Business $business): void
    {
        app(PermissionRegistrar::class)
            ->setPermissionsTeamId($business->id);
    }

    private function createBusinessWithOwner(): array
    {
        $business = Business::factory()->create();

        $owner = User::factory()->create();

        BusinessUser::create([
            'business_id' => $business->id,
            'user_id' => $owner->id,
            'status' => 'active',
            'joined_at' => now(),
        ]);

        app(\App\Domains\Identity\Services\RoleService::class)
            ->assignOwner($owner, $business->id);

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
        [$business, $owner] = $this->createBusinessWithOwner();

        [$product, $unit] = $this->createProductWithStock($business);

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
            ->assertJsonPath('success', true);
    }

    public function test_manager_can_create_sale(): void
    {
        [$business] = $this->createBusinessWithOwner();

        $manager = $this->createMember(
            $business,
            'Manager'
        );

        [$product, $unit] = $this->createProductWithStock($business);

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
            ->assertJsonPath('success', true);
    }

    public function test_cashier_can_create_sale(): void
    {
        [$business] = $this->createBusinessWithOwner();

        $cashier = $this->createMember(
            $business,
            'Cashier'
        );

        [$product, $unit] = $this->createProductWithStock($business);

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
            ->assertJsonPath('success', true);
    }

    public function test_inventory_staff_cannot_create_sale(): void
    {
        [$business] = $this->createBusinessWithOwner();

        $staff = $this->createMember(
            $business,
            'Inventory Staff'
        );

        [$product, $unit] = $this->createProductWithStock($business);

        $response = $this
            ->actingAs($staff)
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
        [$business, $owner] = $this->createBusinessWithOwner();

        $user = $this->createMember(
            $business,
            'Inventory Staff'
        );

        $this->setPermissionTeam($business);

        $this->assertFalse(
            $user->hasPermissionTo('sales.create')
        );

        [$product, $unit] = $this->createProductWithStock($business);

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
        [$business, $owner] = $this->createBusinessWithOwner();

        [$product, $unit] = $this->createProductWithStock(
            $business,
            50
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
                    5
                )
            );

        $response
            ->assertSuccessful()
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'success',
                'data',
            ]);
    }

    public function test_sale_deducts_stock(): void
    {
        [$business, $owner] = $this->createBusinessWithOwner();

        [$product, $unit, $stock] = $this->createProductWithStock(
            $business,
            50
        );

        $this
            ->actingAs($owner)
            ->withHeaders([
                'X-Business-ID' => $business->id,
            ])
            ->postJson(
                '/api/businesses/current/sales',
                $this->salePayload(
                    $product,
                    $unit,
                    5
                )
            )
            ->assertSuccessful();

        $this->assertDatabaseHas('stocks', [
            'id' => $stock->id,
            'quantity' => 45,
        ]);
    }

    public function test_sale_creates_stock_movement(): void
    {
        [$business, $owner] = $this->createBusinessWithOwner();

        [$product, $unit] = $this->createProductWithStock(
            $business,
            50
        );

        $this
            ->actingAs($owner)
            ->withHeaders([
                'X-Business-ID' => $business->id,
            ])
            ->postJson(
                '/api/businesses/current/sales',
                $this->salePayload(
                    $product,
                    $unit,
                    5
                )
            )
            ->assertSuccessful();

        $this->assertDatabaseHas('stock_movements', [
            'business_id' => $business->id,
            'product_id' => $product->id,
            'product_unit_id' => $unit->id,
            'type' => 'sale',
            'quantity' => -5,
            'quantity_before' => 50,
            'quantity_after' => 45,
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Validation / Business Rules
    |--------------------------------------------------------------------------
    */

    public function test_sale_rejects_insufficient_stock(): void
    {
        [$business, $owner] = $this->createBusinessWithOwner();

        [$product, $unit, $stock] = $this->createProductWithStock(
            $business,
            5
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
                    10
                )
            );

        $response->assertStatus(422);

        $this->assertDatabaseHas('stocks', [
            'id' => $stock->id,
            'quantity' => 5,
        ]);
    }

    public function test_sale_rejects_zero_quantity(): void
    {
        [$business, $owner] = $this->createBusinessWithOwner();

        [$product, $unit] = $this->createProductWithStock($business);

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
                    0
                )
            );

        $response->assertStatus(422);
    }

    public function test_sale_rejects_negative_quantity(): void
    {
        [$business, $owner] = $this->createBusinessWithOwner();

        [$product, $unit] = $this->createProductWithStock($business);

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
                    -5
                )
            );

        $response->assertStatus(422);
    }

    public function test_sale_rejects_non_sellable_unit(): void
    {
        [$business, $owner] = $this->createBusinessWithOwner();

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

        $this->createProductWithStockForUnit(
            $business,
            $product,
            $unit,
            50
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

        $response->assertStatus(422);
    }

    public function test_sale_rejects_product_from_another_business(): void
    {
        [$business, $owner] = $this->createBusinessWithOwner();

        $otherBusiness = Business::factory()->create();

        [$product, $unit] = $this->createProductWithStock(
            $otherBusiness,
            50
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

        $response->assertStatus(422);
    }

    /*
    |--------------------------------------------------------------------------
    | Tenant Boundary
    |--------------------------------------------------------------------------
    */

    public function test_sale_cannot_be_created_in_another_business_context(): void
    {
        [$businessA, $owner] = $this->createBusinessWithOwner();

        $businessB = Business::factory()->create();

        [$product, $unit] = $this->createProductWithStock(
            $businessA,
            50
        );

        $response = $this
            ->actingAs($owner)
            ->withHeaders([
                'X-Business-ID' => $businessB->id,
            ])
            ->postJson(
                '/api/businesses/current/sales',
                $this->salePayload(
                    $product,
                    $unit,
                    2
                )
            );

        $response->assertForbidden();
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers
    |--------------------------------------------------------------------------
    */

    private function createProductWithStockForUnit(
        Business $business,
        Product $product,
        ProductUnit $unit,
        float $quantity
    ): Stock {
        return Stock::create([
            'business_id' => $business->id,
            'product_id' => $product->id,
            'product_unit_id' => $unit->id,
            'quantity' => $quantity,
            'reorder_level' => 10,
        ]);
    }
}
