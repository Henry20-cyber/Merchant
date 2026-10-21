<?php

namespace Tests\Feature\Product;

use App\Domains\Organization\Models\Branch;
use App\Domains\Organization\Models\Business;
use App\Domains\Organization\Models\BusinessUser;
use App\Domains\Organization\Services\BranchContextService;
use App\Domains\Product\Models\Product;
use App\Domains\Product\Models\ProductBarcode;
use App\Domains\Product\Models\ProductBarcodeBranchMapping;
use App\Domains\Product\Models\ProductUnit;
use App\Domains\Subscription\Models\Subscription;
use App\Domains\Subscription\Models\SubscriptionPlan;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class ProductBarcodeRouteTest extends TestCase
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

    private function createBusinessWithOwner(
        bool $barcodeEnabled = true
    ): array {
        $business = Business::factory()->create([
            'barcode_enabled' => $barcodeEnabled,
        ]);

        $plan = SubscriptionPlan::factory()->create([
            'transaction_daily_limit' => 1000,
            'transaction_monthly_limit' => 10000,
            'is_active' => true,
        ]);

        Subscription::factory()->create([
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

        $branch = Branch::create([
            'business_id' => $business->id,
            'name' => 'Main Branch',
            'code' => 'MAIN-' . $business->id,
            'city' => 'Owerri',
            'state' => 'Imo',
            'country' => 'Nigeria',
            'is_head_office' => true,
        ]);

        app(BranchContextService::class)->set(
            $owner,
            $business,
            $branch
        );

        return [$business, $owner, $branch];
    }

    private function createBarcodeMapping(
        Business $business,
        Branch $branch
    ): array {
        $product = Product::factory()->create([
            'business_id' => $business->id,
            'name' => 'Route Test Product',
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

        $barcode = ProductBarcode::create([
            'business_id' => $business->id,
            'product_id' => $product->id,
            'product_unit_id' => $unit->id,
            'barcode' => '1234567890123',
            'type' => 'manufacturer',
            'is_primary' => true,
        ]);

        ProductBarcodeBranchMapping::create([
            'business_id' => $business->id,
            'branch_id' => $branch->id,
            'product_barcode_id' => $barcode->id,
        ]);

        return [$product, $unit];
    }

    private function lookupAs(
        User $user,
        Business $business,
        string $barcode = '1234567890123'
    ) {
        return $this
            ->actingAs($user)
            ->withHeaders([
                'X-Business-ID' => $business->id,
            ])
            ->postJson(
                '/api/businesses/current/products/barcodes/lookup',
                ['barcode' => $barcode]
            );
    }

    public function test_owner_can_lookup_barcode_through_api_route(): void
    {
        [$business, $owner, $branch] =
            $this->createBusinessWithOwner();

        [$product, $unit] =
            $this->createBarcodeMapping($business, $branch);

        $this->lookupAs($owner, $business)
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('product.id', $product->id)
            ->assertJsonPath('unit.id', $unit->id)
            ->assertJsonPath('barcode.barcode', '1234567890123');
    }

    public function test_lookup_route_requires_authentication(): void
    {
        $this->postJson(
            '/api/businesses/current/products/barcodes/lookup',
            ['barcode' => '1234567890123']
        )->assertUnauthorized();
    }

    public function test_lookup_route_rejects_disabled_barcode_feature(): void
    {
        [$business, $owner] =
            $this->createBusinessWithOwner(false);

        $this->lookupAs($owner, $business)
            ->assertForbidden()
            ->assertJsonFragment([
                'message' =>
                    'Barcode functionality is not enabled for this business.',
            ]);
    }

    public function test_lookup_route_rejects_missing_barcode(): void
    {
        [$business, $owner] =
            $this->createBusinessWithOwner();

        $this
            ->actingAs($owner)
            ->withHeaders([
                'X-Business-ID' => $business->id,
            ])
            ->postJson(
                '/api/businesses/current/products/barcodes/lookup',
                []
            )
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['barcode']);
    }
}
