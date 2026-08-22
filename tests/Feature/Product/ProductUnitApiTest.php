<?php

namespace Tests\Feature\Product;

use App\Domains\Identity\Services\RoleService;
use App\Domains\Organization\Models\Business;
use App\Domains\Organization\Models\BusinessUser;
use App\Domains\Organization\Services\MerchantOSTeamResolver;
use App\Domains\Product\Models\Product;
use App\Domains\Product\Models\ProductUnit;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductUnitApiTest extends TestCase
{
  use RefreshDatabase;

  protected function setUp(): void
  {
    parent::setUp();

    $this->seed(PermissionSeeder::class);
  }

  /**
   * Create an authenticated owner with an active business context.
   */
  private function ownerWithBusiness(): array
  {
    $business = Business::factory()->create();

    $owner = User::factory()->create();

    BusinessUser::create([
      'business_id' => $business->id,
      'user_id' => $owner->id,
      'status' => 'active',
      'joined_at' => now(),
    ]);

    $this->actingAs($owner);

    /*
     * Establish the current business.
     *
     * This is required because the API middleware uses the
     * current business to set Spatie's permission team ID.
     */
    app(MerchantOSTeamResolver::class)
      ->setPermissionsTeamId($business->id);

    /*
     * Provision and assign the business Owner role.
     */
    app(RoleService::class)->assignOwner(
      $owner,
      $business->id
    );

    return [$owner, $business];
  }

  /**
   * Create a product with its mandatory base unit.
   */
  private function createProduct(Business $business): Product
  {
    return app(
      \App\Domains\Product\Services\ProductService::class
    )->createProduct(
      $business,
      [
        'name' => 'Gala',
        'sku' => 'GALA-' . fake()->unique()->numerify('####'),
        'description' => 'Gala sausage roll',
        'status' => 'active',
      ],
      [
        'name' => 'Piece',
        'quantity' => 1,
        'cost_price' => 100,
        'selling_price' => 150,
        'currency' => 'NGN',
        'is_sellable' => true,
        'is_purchasable' => true,
      ]
    );
  }

  public function test_owner_can_add_bulk_unit(): void
  {
    [$owner, $business] = $this->ownerWithBusiness();

    $product = $this->createProduct($business);

    $response = $this->postJson(
      "/api/businesses/current/products/{$product->id}/units",
      [
        'name' => 'Carton',
        'quantity' => 12,
        'cost_price' => 1200,
        'selling_price' => 1500,
        'currency' => 'NGN',
        'is_sellable' => true,
        'is_purchasable' => true,
      ]
    );

    $response->assertCreated()
      ->assertJsonPath('success', true);

    $this->assertDatabaseHas('product_units', [
      'business_id' => $business->id,
      'product_id' => $product->id,
      'name' => 'Carton',
      'quantity' => 12,
      'is_base_unit' => false,
    ]);
  }

  public function test_manager_can_add_bulk_unit(): void
  {
    $business = Business::factory()->create();

    $manager = User::factory()->create();

    BusinessUser::create([
      'business_id' => $business->id,
      'user_id' => $manager->id,
      'status' => 'active',
      'joined_at' => now(),
    ]);

    $this->actingAs($manager);

    app(MerchantOSTeamResolver::class)
      ->setPermissionsTeamId($business->id);

    app(RoleService::class)->provisionBusinessRoles(
      $business->id
    );

    app(RoleService::class)->assignRole(
      $manager,
      'Manager',
      $business->id
    );

    $product = $this->createProduct($business);

    $response = $this->postJson(
      "/api/businesses/current/products/{$product->id}/units",
      [
        'name' => 'Carton',
        'quantity' => 12,
        'cost_price' => 1200,
        'selling_price' => 1500,
        'currency' => 'NGN',
        'is_sellable' => true,
        'is_purchasable' => true,
      ]
    );

    $response->assertCreated();

    $this->assertDatabaseHas('product_units', [
      'business_id' => $business->id,
      'product_id' => $product->id,
      'name' => 'Carton',
      'quantity' => 12,
    ]);
  }

  public function test_cashier_cannot_add_bulk_unit(): void
  {
    $business = Business::factory()->create();

    $cashier = User::factory()->create();

    BusinessUser::create([
      'business_id' => $business->id,
      'user_id' => $cashier->id,
      'status' => 'active',
      'joined_at' => now(),
    ]);

    $this->actingAs($cashier);

app(MerchantOSTeamResolver::class)
    ->setPermissionsTeamId($business->id);

app(RoleService::class)->provisionBusinessRoles(
    $business->id
);

app(RoleService::class)->assignRole(
    $cashier,
    'Cashier',
    $business->id
);

    $product = $this->createProduct($business);

    $response = $this->postJson(
      "/api/businesses/current/products/{$product->id}/units",
      [
        'name' => 'Carton',
        'quantity' => 12,
        'cost_price' => 1200,
        'selling_price' => 1500,
        'currency' => 'NGN',
      ]
    );

    $response->assertForbidden();

    $this->assertDatabaseMissing('product_units', [
      'product_id' => $product->id,
      'name' => 'Carton',
    ]);
  }

  public function test_owner_can_update_bulk_unit(): void
  {
    [$owner, $business] = $this->ownerWithBusiness();

    $product = $this->createProduct($business);

    $unit = ProductUnit::factory()
      ->bulk('Carton', 12)
      ->create([
        'business_id' => $business->id,
        'product_id' => $product->id,
      ]);

    $response = $this->putJson(
      "/api/businesses/current/products/{$product->id}/units/{$unit->id}",
      [
        'name' => 'Carton',
        'quantity' => 24,
        'cost_price' => 2400,
        'selling_price' => 3000,
        'currency' => 'NGN',
        'is_sellable' => true,
        'is_purchasable' => true,
      ]
    );

    $response->assertOk()
      ->assertJsonPath('success', true);

    $this->assertDatabaseHas('product_units', [
      'id' => $unit->id,
      'quantity' => 24,
      'selling_price' => 3000,
    ]);
  }

  public function test_owner_can_delete_bulk_unit(): void
  {
    [$owner, $business] = $this->ownerWithBusiness();

    $product = $this->createProduct($business);

    $unit = ProductUnit::factory()
      ->bulk('Carton', 12)
      ->create([
        'business_id' => $business->id,
        'product_id' => $product->id,
      ]);

    $response = $this->deleteJson(
      "/api/businesses/current/products/{$product->id}/units/{$unit->id}"
    );

    $response->assertOk()
      ->assertJsonPath('success', true);

    $this->assertSoftDeleted('product_units', [
      'id' => $unit->id,
    ]);
  }

  public function test_base_unit_cannot_be_deleted(): void
  {
    [$owner, $business] = $this->ownerWithBusiness();

    $product = $this->createProduct($business);

    $baseUnit = $product->units()
      ->where('is_base_unit', true)
      ->firstOrFail();

    $response = $this->deleteJson(
      "/api/businesses/current/products/{$product->id}/units/{$baseUnit->id}"
    );

    $response->assertStatus(422);

    $this->assertDatabaseHas('product_units', [
      'id' => $baseUnit->id,
      'deleted_at' => null,
    ]);
  }

  public function test_bulk_unit_cannot_become_base_unit(): void
{
    [$owner, $business] = $this->ownerWithBusiness();

    $product = $this->createProduct($business);

    $bulkUnit = ProductUnit::factory()
        ->bulk('Carton', 12)
        ->create([
            'business_id' => $business->id,
            'product_id' => $product->id,
        ]);

    $response = $this->postJson(
        "/api/businesses/current/products/{$product->id}/units/{$bulkUnit->id}/base"
    );

    $response
        ->assertStatus(422)
        ->assertJsonValidationErrors(['quantity']);

    $this->assertDatabaseHas('product_units', [
        'id' => $bulkUnit->id,
        'is_base_unit' => false,
        'quantity' => 12,
    ]);
}

  public function test_user_cannot_modify_unit_from_another_business(): void
  {
    [$owner, $business] = $this->ownerWithBusiness();

    $otherBusiness = Business::factory()->create();

    $otherOwner = User::factory()->create();

    BusinessUser::create([
      'business_id' => $otherBusiness->id,
      'user_id' => $otherOwner->id,
      'status' => 'active',
      'joined_at' => now(),
    ]);

    app(RoleService::class)->provisionBusinessRoles(
      $otherBusiness->id
    );

    app(RoleService::class)->assignOwner(
      $otherOwner,
      $otherBusiness->id
    );

    $otherProduct = $this->createProduct($otherBusiness);

    $otherUnit = ProductUnit::factory()
      ->bulk('Carton', 12)
      ->create([
        'business_id' => $otherBusiness->id,
        'product_id' => $otherProduct->id,
      ]);

    $this->actingAs($owner);

    app(
      \App\Domains\Organization\Services\MerchantOSTeamResolver::class
    )->setPermissionsTeamId($business->id);

    $response = $this->putJson(
      "/api/businesses/current/products/{$otherProduct->id}/units/{$otherUnit->id}",
      [
        'name' => 'Hacked Carton',
        'quantity' => 99,
        'cost_price' => 1,
        'selling_price' => 1,
        'currency' => 'NGN',
      ]
    );

    $response->assertNotFound();
  }
}
