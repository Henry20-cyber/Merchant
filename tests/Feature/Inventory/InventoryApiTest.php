<?php

namespace Tests\Feature\Inventory;

use App\Domains\Identity\Services\RoleService;
use App\Domains\Inventory\Models\Stock;
use App\Domains\Organization\Models\Business;
use App\Domains\Organization\Models\BusinessUser;
use App\Domains\Product\Models\Product;
use App\Domains\Product\Models\ProductUnit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class InventoryApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        /*
         * MerchantOS uses Spatie team-scoped permissions.
         *
         * PermissionSeeder creates:
         * inventory.view
         * inventory.receive
         * inventory.adjust
         * inventory.transfer
         */
        $this->seed(\Database\Seeders\PermissionSeeder::class);
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers
    |--------------------------------------------------------------------------
    */

    private function setPermissionTeam(Business $business): void
    {
        setPermissionsTeamId($business->id);
    }

    /**
     * Headers required by SetCurrentBusiness middleware.
     */
    private function businessHeaders(Business $business): array
    {
        return [
            'X-Business-ID' => $business->id,
            'Accept' => 'application/json',
        ];
    }

    /**
     * Create an active business membership.
     */
    private function createMembership(
        User $user,
        Business $business
    ): BusinessUser {
        return BusinessUser::create([
            'business_id' => $business->id,
            'user_id' => $user->id,
            'status' => 'active',
            'joined_at' => now(),
        ]);
    }

    /**
     * Create a business owner.
     */
    private function createOwner(Business $business): User
    {
        $owner = User::factory()->create();

        $this->createMembership($owner, $business);

        /*
         * Spatie teams must be set BEFORE assigning the role.
         */
        $this->setPermissionTeam($business);

        app(RoleService::class)->assignOwner(
            $owner,
            $business->id
        );

        /*
         * Clear cached relationships so subsequent permission
         * checks use the current team context.
         */
        $owner->unsetRelation('roles');
        $owner->unsetRelation('permissions');

        return $owner;
    }

    /**
     * Create a business-scoped user with NO permissions.
     *
     * We insert the model_has_roles pivot explicitly so that
     * team_id can never accidentally be NULL.
     */
    private function createRestrictedUser(Business $business): User
    {
        $user = User::factory()->create();

        $this->createMembership($user, $business);

        $this->setPermissionTeam($business);

        $role = Role::create([
            'name' => 'Restricted-' . Str::uuid(),
            'guard_name' => 'web',
            'team_id' => $business->id,
            'is_system' => false,
        ]);

        /*
         * Do NOT use assignRole() here.
         *
         * With teams enabled, the project was occasionally inserting
         * NULL into model_has_roles.team_id.
         *
         * Insert the scoped pivot explicitly.
         */
        DB::table('model_has_roles')->insert([
            'role_id' => $role->id,
            'model_type' => User::class,
            'model_id' => $user->id,
            'team_id' => $business->id,
        ]);

        $user->unsetRelation('roles');
        $user->unsetRelation('permissions');

        return $user;
    }

    /**
     * Create a product with a base unit.
     *
     * IMPORTANT:
     * The database column is is_base_unit, not is_base.
     */
    private function productWithBaseUnit(
        Business $business
    ): array {
        $product = Product::factory()->create([
            'business_id' => $business->id,
            'sku' => 'INV-' . strtoupper(Str::random(12)),
        ]);

        $unit = ProductUnit::factory()->create([
            'product_id' => $product->id,
            'business_id' => $business->id,
            'name' => 'Piece',
            'quantity' => 1,
            'is_base_unit' => true,
        ]);

        return [$product, $unit];
    }

    /**
     * Create a product with a custom SKU.
     */
    private function createProduct(
        Business $business,
        ?string $sku = null
    ): Product {
        return Product::factory()->create([
            'business_id' => $business->id,
            'sku' => $sku ?? 'INV-' . strtoupper(Str::random(12)),
        ]);
    }

    /**
     * Create a base unit for a product.
     */
    private function createBaseUnit(
        Business $business,
        Product $product
    ): ProductUnit {
        return ProductUnit::factory()->create([
            'product_id' => $product->id,
            'business_id' => $business->id,
            'name' => 'Piece',
            'quantity' => 1,
            'is_base_unit' => true,
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Permission Tests
    |--------------------------------------------------------------------------
    */

    public function test_owner_has_inventory_permissions(): void
    {
        $business = Business::factory()->create();

        $owner = $this->createOwner($business);

        /*
         * Explicitly establish the business/team context.
         */
        $this->setPermissionTeam($business);

        /*
         * Clear cached permission/role relationships.
         */
        $owner->unsetRelation('roles');
        $owner->unsetRelation('permissions');

        /*
         * Verify the Owner role exists for this business.
         */
        $role = Role::query()
            ->where('name', 'Owner')
            ->where('team_id', $business->id)
            ->first();

        $this->assertNotNull($role);

        /*
         * Verify the Owner role contains the inventory permissions.
         */
        $permissions = $role
            ->permissions()
            ->pluck('name')
            ->all();

        /*
         * If the Owner role is configured to inherit inventory
         * permissions, these must be present.
         */
        $this->assertContains(
            'inventory.view',
            $permissions
        );

        $this->assertContains(
            'inventory.receive',
            $permissions
        );

        $this->assertContains(
            'inventory.adjust',
            $permissions
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Inventory Listing
    |--------------------------------------------------------------------------
    */

    public function test_owner_can_view_inventory(): void
    {
        $business = Business::factory()->create();

        $owner = $this->createOwner($business);

        [$product, $unit] = $this->productWithBaseUnit($business);

        Stock::create([
            'business_id' => $business->id,
            'product_id' => $product->id,
            'product_unit_id' => $unit->id,
            'quantity' => 50,
            'reorder_level' => 10,
        ]);

        $this->setPermissionTeam($business);

        $response = $this
            ->actingAs($owner)
            ->withHeaders($this->businessHeaders($business))
            ->getJson(
                '/api/businesses/current/inventory'
            );

        $response
            ->assertOk()
            ->assertJsonPath('success', true);

        $response->assertJsonFragment([
            'product_id' => $product->id,
            'product_unit_id' => $unit->id,
        ]);
    }

    public function test_user_without_inventory_view_permission_cannot_view_inventory(): void
    {
        $business = Business::factory()->create();

        $user = $this->createRestrictedUser($business);

        $this->setPermissionTeam($business);

        $response = $this
            ->actingAs($user)
            ->withHeaders($this->businessHeaders($business))
            ->getJson(
                '/api/businesses/current/inventory'
            );

        $response->assertForbidden();
    }

    /*
    |--------------------------------------------------------------------------
    | Receive Stock
    |--------------------------------------------------------------------------
    */

    public function test_owner_can_receive_stock(): void
    {
        $business = Business::factory()->create();

        $owner = $this->createOwner($business);

        [$product, $unit] = $this->productWithBaseUnit($business);

        $this->setPermissionTeam($business);

        $response = $this
            ->actingAs($owner)
            ->withHeaders($this->businessHeaders($business))
            ->postJson(
                '/api/businesses/current/inventory/receive',
                [
                    'product_id' => $product->id,
                    'product_unit_id' => $unit->id,
                    'quantity' => 50,
                    'note' => 'Initial stock',
                ]
            );

        $response
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertDatabaseHas('stocks', [
            'business_id' => $business->id,
            'product_id' => $product->id,
            'product_unit_id' => $unit->id,
            'quantity' => 50,
        ]);

        $this->assertDatabaseHas('stock_movements', [
            'business_id' => $business->id,
            'product_id' => $product->id,
            'product_unit_id' => $unit->id,
            'type' => 'receive',
            'quantity' => 50,
            'quantity_before' => 0,
            'quantity_after' => 50,
        ]);
    }

    public function test_user_without_inventory_receive_permission_cannot_receive_stock(): void
    {
        $business = Business::factory()->create();

        $user = $this->createRestrictedUser($business);

        [$product, $unit] = $this->productWithBaseUnit($business);

        $this->setPermissionTeam($business);

        $response = $this
            ->actingAs($user)
            ->withHeaders($this->businessHeaders($business))
            ->postJson(
                '/api/businesses/current/inventory/receive',
                [
                    'product_id' => $product->id,
                    'product_unit_id' => $unit->id,
                    'quantity' => 50,
                ]
            );

        $response->assertForbidden();
    }

    /*
    |--------------------------------------------------------------------------
    | Adjust Stock
    |--------------------------------------------------------------------------
    */

    public function test_owner_can_adjust_stock(): void
    {
        $business = Business::factory()->create();

        $owner = $this->createOwner($business);

        [$product, $unit] = $this->productWithBaseUnit($business);

        $this->setPermissionTeam($business);

        /*
         * First receive 50 units.
         */
        $this
            ->actingAs($owner)
            ->withHeaders($this->businessHeaders($business))
            ->postJson(
                '/api/businesses/current/inventory/receive',
                [
                    'product_id' => $product->id,
                    'product_unit_id' => $unit->id,
                    'quantity' => 50,
                    'note' => 'Initial stock',
                ]
            )
            ->assertOk();

        /*
         * Then adjust by -5.
         */
        $response = $this
            ->actingAs($owner)
            ->withHeaders($this->businessHeaders($business))
            ->postJson(
                '/api/businesses/current/inventory/adjust',
                [
                    'product_id' => $product->id,
                    'product_unit_id' => $unit->id,
                    'quantity' => -5,
                    'note' => 'Damaged goods',
                ]
            );

        $response
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertDatabaseHas('stocks', [
            'business_id' => $business->id,
            'product_id' => $product->id,
            'product_unit_id' => $unit->id,
            'quantity' => 45,
        ]);

        /*
         * StockService records the movement type as "adjustment".
         */
        $this->assertDatabaseHas('stock_movements', [
            'business_id' => $business->id,
            'product_id' => $product->id,
            'product_unit_id' => $unit->id,
            'type' => 'adjustment',
            'quantity' => -5,
            'quantity_before' => 50,
            'quantity_after' => 45,
        ]);
    }

    public function test_user_without_inventory_adjust_permission_cannot_adjust_stock(): void
    {
        $business = Business::factory()->create();

        $user = $this->createRestrictedUser($business);

        [$product, $unit] = $this->productWithBaseUnit($business);

        $this->setPermissionTeam($business);

        /*
         * Do not create stock first.
         *
         * The request must be rejected by authorization before
         * StockService can complain about negative inventory.
         */
        $response = $this
            ->actingAs($user)
            ->withHeaders($this->businessHeaders($business))
            ->postJson(
                '/api/businesses/current/inventory/adjust',
                [
                    'product_id' => $product->id,
                    'product_unit_id' => $unit->id,
                    'quantity' => -5,
                ]
            );

        $response->assertForbidden();
    }

    /*
    |--------------------------------------------------------------------------
    | Tenant Isolation
    |--------------------------------------------------------------------------
    */

    public function test_inventory_cannot_access_product_from_another_business(): void
    {
        $business = Business::factory()->create();

        $otherBusiness = Business::factory()->create();

        $owner = $this->createOwner($business);

        [$otherProduct, $otherUnit] =
            $this->productWithBaseUnit($otherBusiness);

        $this->setPermissionTeam($business);

        $response = $this
            ->actingAs($owner)
            ->withHeaders($this->businessHeaders($business))
            ->postJson(
                '/api/businesses/current/inventory/receive',
                [
                    'product_id' => $otherProduct->id,
                    'product_unit_id' => $otherUnit->id,
                    'quantity' => 50,
                ]
            );

        $response->assertForbidden();

        $this->assertDatabaseMissing('stocks', [
            'business_id' => $business->id,
            'product_id' => $otherProduct->id,
            'product_unit_id' => $otherUnit->id,
        ]);
    }

    public function test_inventory_rejects_unit_from_another_product(): void
    {
        $business = Business::factory()->create();

        $owner = $this->createOwner($business);

        [$product, $unit] = $this->productWithBaseUnit($business);

        [$otherProduct, $otherUnit] =
            $this->productWithBaseUnit($business);

        $this->setPermissionTeam($business);

        $response = $this
            ->actingAs($owner)
            ->withHeaders($this->businessHeaders($business))
            ->postJson(
                '/api/businesses/current/inventory/receive',
                [
                    'product_id' => $product->id,
                    'product_unit_id' => $otherUnit->id,
                    'quantity' => 50,
                ]
            );

        $response->assertForbidden();

        $this->assertDatabaseMissing('stocks', [
            'business_id' => $business->id,
            'product_id' => $product->id,
            'product_unit_id' => $otherUnit->id,
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Validation
    |--------------------------------------------------------------------------
    */

    public function test_inventory_rejects_zero_quantity(): void
    {
        $business = Business::factory()->create();

        $owner = $this->createOwner($business);

        [$product, $unit] = $this->productWithBaseUnit($business);

        $this->setPermissionTeam($business);

        $response = $this
            ->actingAs($owner)
            ->withHeaders($this->businessHeaders($business))
            ->postJson(
                '/api/businesses/current/inventory/receive',
                [
                    'product_id' => $product->id,
                    'product_unit_id' => $unit->id,
                    'quantity' => 0,
                ]
            );

        $response->assertStatus(422);
    }

    public function test_inventory_rejects_negative_receive_quantity(): void
    {
        $business = Business::factory()->create();

        $owner = $this->createOwner($business);

        [$product, $unit] = $this->productWithBaseUnit($business);

        $this->setPermissionTeam($business);

        $response = $this
            ->actingAs($owner)
            ->withHeaders($this->businessHeaders($business))
            ->postJson(
                '/api/businesses/current/inventory/receive',
                [
                    'product_id' => $product->id,
                    'product_unit_id' => $unit->id,
                    'quantity' => -10,
                ]
            );

        $response->assertStatus(422);
    }

    /*
    |--------------------------------------------------------------------------
    | Stock Detail
    |--------------------------------------------------------------------------
    */

    public function test_inventory_stock_detail_can_be_viewed(): void
    {
        $business = Business::factory()->create();

        $owner = $this->createOwner($business);

        [$product, $unit] = $this->productWithBaseUnit($business);

        $this->setPermissionTeam($business);

        $stock = Stock::create([
            'business_id' => $business->id,
            'product_id' => $product->id,
            'product_unit_id' => $unit->id,
            'quantity' => 50,
            'reorder_level' => 10,
        ]);

        $response = $this
            ->actingAs($owner)
            ->withHeaders($this->businessHeaders($business))
            ->getJson(
                "/api/businesses/current/inventory/{$stock->id}"
            );

        $response
            ->assertOk()
            ->assertJsonPath('success', true);

        $response->assertJsonFragment([
            'id' => $stock->id,
            'product_id' => $product->id,
            'product_unit_id' => $unit->id,
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Movement History
    |--------------------------------------------------------------------------
    */

    public function test_inventory_movement_history_can_be_viewed(): void
    {
        $business = Business::factory()->create();

        $owner = $this->createOwner($business);

        [$product, $unit] = $this->productWithBaseUnit($business);

        $this->setPermissionTeam($business);

        $this
            ->actingAs($owner)
            ->withHeaders($this->businessHeaders($business))
            ->postJson(
                '/api/businesses/current/inventory/receive',
                [
                    'product_id' => $product->id,
                    'product_unit_id' => $unit->id,
                    'quantity' => 50,
                    'note' => 'Initial stock',
                ]
            )
            ->assertOk();

        $stock = Stock::query()
            ->where('business_id', $business->id)
            ->where('product_id', $product->id)
            ->where('product_unit_id', $unit->id)
            ->firstOrFail();

        $response = $this
            ->actingAs($owner)
            ->withHeaders($this->businessHeaders($business))
            ->getJson(
                "/api/businesses/current/inventory/{$stock->id}/movements"
            );

        $response
            ->assertOk()
            ->assertJsonPath('success', true);

        $response->assertJsonFragment([
            'type' => 'receive',
            'quantity' => '50.0000',
        ]);
    }
}