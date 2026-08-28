<?php

namespace Tests\Feature\Sales;

use App\Domains\Organization\Models\Business;
use App\Domains\Organization\Models\BusinessUser;
use App\Domains\Product\Models\Product;
use App\Domains\Product\Models\ProductUnit;
use App\Domains\Service\Models\Service;
use App\Domains\Sales\Models\Sale;
use App\Domains\Sales\Models\SaleItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class SalesDashboardApiTest extends TestCase
{
    use RefreshDatabase;

    private function createBusinessMember(
        Business $business,
        ?User $user = null
    ): User {
        $user ??= User::factory()->create();

        BusinessUser::create([
            'business_id' => $business->id,
            'user_id' => $user->id,
            'status' => 'active',
            'joined_at' => now(),
        ]);

        return $user;
    }

    private function createPermissions(): void
    {
        Permission::findOrCreate(
            'sales.view',
            'web'
        );
    }

    private function giveSalesViewPermission(
        User $user,
        Business $business
    ): void {
        setPermissionsTeamId($business->id);

        $role = Role::firstOrCreate([
            'name' => 'Analytics Viewer',
            'guard_name' => 'web',
            'team_id' => $business->id,
        ]);

        $permission = Permission::findOrCreate(
            'sales.view',
            'web'
        );

        $role->givePermissionTo($permission);

        $user->assignRole($role);
    }

    private function createProduct(
        Business $business,
        string $name,
        float $price
    ): array {
        $product = Product::factory()->create([
            'business_id' => $business->id,
            'name' => $name,
        ]);

        $unit = ProductUnit::factory()->create([
            'business_id' => $business->id,
            'product_id' => $product->id,
            'quantity' => 1,
            'cost_price' => $price * 0.6,
            'selling_price' => $price,
            'is_base_unit' => true,
            'is_sellable' => true,
            'is_purchasable' => true,
        ]);

        return [$product, $unit];
    }

    private function createProductSale(
        Business $business,
        User $cashier,
        Product $product,
        ProductUnit $unit,
        float $quantity,
        float $unitPrice
    ): Sale {
        $total = $quantity * $unitPrice;

        $sale = Sale::create([
            'business_id' => $business->id,
            'cashier_id' => $cashier->id,
            'subtotal' => $total,
            'discount' => 0,
            'tax' => 0,
            'total' => $total,
            'payment_method' => 'cash',
            'payment_status' => 'paid',
            'status' => 'completed',
        ]);

        SaleItem::create([
            'sale_id' => $sale->id,
            'product_id' => $product->id,
            'product_unit_id' => $unit->id,
            'service_id' => null,
            'quantity' => $quantity,
            'unit_price' => $unitPrice,
            'unit_cost' => $unitPrice * 0.6,
            'discount' => 0,
            'total' => $total,
        ]);

        return $sale;
    }

    private function createServiceSale(
        Business $business,
        User $cashier,
        Service $service,
        float $quantity
    ): Sale {
        $unitPrice = (float) $service->price;
        $total = $quantity * $unitPrice;

        $sale = Sale::create([
            'business_id' => $business->id,
            'cashier_id' => $cashier->id,
            'subtotal' => $total,
            'discount' => 0,
            'tax' => 0,
            'total' => $total,
            'payment_method' => 'cash',
            'payment_status' => 'paid',
            'status' => 'completed',
        ]);

        SaleItem::create([
            'sale_id' => $sale->id,
            'product_id' => null,
            'product_unit_id' => null,
            'service_id' => $service->id,
            'quantity' => $quantity,
            'unit_price' => $unitPrice,
            'unit_cost' => 0,
            'discount' => 0,
            'total' => $total,
        ]);

        return $sale;
    }

    public function test_authorized_user_can_view_sales_dashboard(): void
    {
        $business = Business::factory()->create();

        $user = $this->createBusinessMember($business);

        $this->createPermissions();

        $this->actingAs($user);

        setPermissionsTeamId($business->id);

        $this->giveSalesViewPermission(
            $user,
            $business
        );

        [$product, $unit] = $this->createProduct(
            $business,
            'Coca-Cola',
            1000
        );

        $this->createProductSale(
            $business,
            $user,
            $product,
            $unit,
            5,
            1000
        );

        $response = $this->getJson(
            '/api/businesses/current/sales/dashboard'
        );

        $response
            ->assertOk()
            ->assertJsonPath(
                'success',
                true
            )
            ->assertJsonPath(
                'data.daily.revenue',
                5000
            );
    }

    public function test_user_without_sales_view_permission_cannot_view_dashboard(): void
    {
        $business = Business::factory()->create();

        $user = $this->createBusinessMember($business);

        $this->createPermissions();

        $this->actingAs($user);

        setPermissionsTeamId($business->id);

        $response = $this->getJson(
            '/api/businesses/current/sales/dashboard'
        );

        $response->assertForbidden();
    }

    public function test_dashboard_is_scoped_to_current_business(): void
    {
        $businessA = Business::factory()->create();
        $businessB = Business::factory()->create();

        $user = $this->createBusinessMember($businessA);

        $this->createBusinessMember(
            $businessB
        );

        $this->createPermissions();

        $this->actingAs($user);

        setPermissionsTeamId($businessA->id);

        $this->giveSalesViewPermission(
            $user,
            $businessA
        );

        [$productA, $unitA] = $this->createProduct(
            $businessA,
            'Coca-Cola',
            1000
        );

        [$productB, $unitB] = $this->createProduct(
            $businessB,
            'Coca-Cola',
            1000
        );

        $this->createProductSale(
            $businessA,
            $user,
            $productA,
            $unitA,
            5,
            1000
        );

        $businessBOwner = $businessB->users()->first();

        $this->createProductSale(
            $businessB,
            $businessBOwner,
            $productB,
            $unitB,
            100,
            1000
        );

        $response = $this->getJson(
            '/api/businesses/current/sales/dashboard'
        );

        $response
            ->assertOk()
            ->assertJsonPath(
                'data.daily.revenue',
                5000
            );
    }

    public function test_dashboard_includes_services(): void
    {
        $business = Business::factory()->create();

        $user = $this->createBusinessMember($business);

        $this->createPermissions();

        $this->actingAs($user);

        setPermissionsTeamId($business->id);

        $this->giveSalesViewPermission(
            $user,
            $business
        );

        $service = Service::factory()->create([
            'business_id' => $business->id,
            'name' => 'Hair Braiding',
            'price' => 15000,
            'is_active' => true,
        ]);

        $this->createServiceSale(
            $business,
            $user,
            $service,
            2
        );

        $response = $this->getJson(
            '/api/businesses/current/sales/dashboard'
        );

        $response
            ->assertOk()
            ->assertJsonPath(
                'data.daily.revenue',
                30000
            );

        $response->assertJsonPath(
            'data.top_items.0.name',
            'Hair Braiding'
        );

        $response->assertJsonPath(
            'data.top_items.0.item_type',
            'service'
        );
    }
}
