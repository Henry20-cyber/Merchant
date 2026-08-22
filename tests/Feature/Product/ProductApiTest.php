<?php

namespace Tests\Feature\Product;

use App\Domains\Identity\Services\RoleService;
use App\Domains\Organization\Models\Business;
use App\Domains\Organization\Models\BusinessUser;
use App\Domains\Product\Services\ProductService;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PermissionSeeder::class);
    }

    /**
     * Create an authenticated owner and business.
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

        app(RoleService::class)->assignOwner(
            $owner,
            $business->id
        );

        return [$owner, $business];
    }

    /**
     * Add the business context required by API requests.
     */
    private function withBusiness(Business $business)
    {
        return $this->withHeader(
            'X-Business-ID',
            $business->id
        );
    }

    public function test_owner_can_create_product(): void
    {
        [$owner, $business] = $this->ownerWithBusiness();

        $response = $this->withBusiness($business)
            ->postJson(
                '/api/businesses/current/products',
                [
                    'name' => 'Gala',

                    'sku' => 'GALA-001',

                    'description' => 'Gala sausage roll',

                    'base_unit' => [
                        'name' => 'Piece',
                        'cost_price' => 100,
                        'selling_price' => 150,
                        'currency' => 'NGN',
                    ],
                ]
            );

        $response
            ->assertCreated()
            ->assertJsonPath('success', true);

        $this->assertDatabaseHas('products', [
            'business_id' => $business->id,
            'name' => 'Gala',
            'sku' => 'GALA-001',
        ]);
    }

   public function test_user_without_product_create_permission_cannot_create_product(): void
{
    [$owner, $business] = $this->ownerWithBusiness();

    $cashier = User::factory()->create();

    BusinessUser::create([
        'business_id' => $business->id,
        'user_id' => $cashier->id,
        'status' => 'active',
        'joined_at' => now(),
    ]);

    /*
     * Establish the business context for the new user.
     */
    $this->actingAs($cashier);

    app(\App\Domains\Organization\Services\MerchantOSTeamResolver::class)
        ->setPermissionsTeamId($business->id);

    /*
     * Make sure the standard business roles exist.
     */
    app(RoleService::class)->provisionBusinessRoles(
        $business->id
    );

    /*
     * Cashier does not have products.create.
     */
    app(RoleService::class)->assignRole(
        $cashier,
        'Cashier',
        $business->id
    );

    $response = $this->withBusiness($business)
        ->postJson(
            '/api/businesses/current/products',
            [
                'name' => 'Gala',
                'sku' => 'GALA-002',
                'base_unit' => [
                    'name' => 'Piece',
                    'cost_price' => 100,
                    'selling_price' => 150,
                    'currency' => 'NGN',
                ],
            ]
        );

    $response->assertForbidden();

    $this->assertDatabaseMissing('products', [
        'business_id' => $business->id,
        'sku' => 'GALA-002',
    ]);
}

    public function test_product_cannot_be_created_for_another_business(): void
    {
        [$owner, $business] = $this->ownerWithBusiness();

        $otherBusiness = Business::factory()->create();

        /*
         * The request is intentionally operating inside
         * the authenticated owner's business.
         *
         * There is no mechanism for the request body to
         * override the business_id.
         */
        $response = $this->withBusiness($business)
            ->postJson(
                '/api/businesses/current/products',
                [
                    'name' => 'Gala',

                    'sku' => 'GALA-003',

                    'base_unit' => [
                        'name' => 'Piece',
                        'cost_price' => 100,
                        'selling_price' => 150,
                        'currency' => 'NGN',
                    ],
                ]
            );

        $response->assertCreated();

        /*
         * Product belongs to the current business.
         */
        $this->assertDatabaseHas('products', [
            'business_id' => $business->id,
            'sku' => 'GALA-003',
        ]);

        /*
         * Product must NOT appear under another business.
         */
        $this->assertDatabaseMissing('products', [
            'business_id' => $otherBusiness->id,
            'sku' => 'GALA-003',
        ]);
    }

    public function test_owner_can_list_business_products(): void
    {
        [$owner, $business] = $this->ownerWithBusiness();

        $productService = app(ProductService::class);

        $productService->createProduct(
            $business,
            [
                'name' => 'Gala',
                'sku' => 'GALA-004',
                'description' => null,
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

        $response = $this->withBusiness($business)
            ->getJson(
                '/api/businesses/current/products'
            );

        $response
            ->assertOk()
            ->assertJsonPath('success', true);

        $response->assertJsonFragment([
            'name' => 'Gala',
            'sku' => 'GALA-004',
        ]);
    }
}