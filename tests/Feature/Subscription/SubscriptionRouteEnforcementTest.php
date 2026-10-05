<?php

namespace Tests\Feature\Subscription;

use App\Domains\Identity\Services\RoleService;
use App\Domains\Organization\Models\BusinessUser;
use App\Domains\Organization\Models\Business;
use App\Domains\Subscription\Models\Subscription;
use App\Domains\Subscription\Models\SubscriptionPlan;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreatesSubscriptionForBusiness;
use Tests\TestCase;

class SubscriptionRouteEnforcementTest extends TestCase
{
    use CreatesSubscriptionForBusiness;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PermissionSeeder::class);
    }

    private function ownerWithSubscription(array $features = []): array
    {
        $business = $this->createBusinessWithSubscription([
            'features' => array_merge([
                'receipts' => true,
                'advanced_reports' => true,
                'advanced_rbac' => true,
            ], $features),
        ]);

        $owner = User::factory()->create();

        BusinessUser::create([
            'business_id' => $business->id,
            'user_id' => $owner->id,
            'status' => 'active',
            'joined_at' => now(),
        ]);

        app(RoleService::class)->assignOwner(
            $owner,
            $business->id
        );

        return [$business, $owner];
    }

    public function test_restricted_subscription_blocks_business_operations(): void
    {
        [$business, $owner] = $this->ownerWithSubscription();

        $business->subscription()->update([
            'status' => 'restricted',
        ]);

        $response = $this
            ->actingAs($owner)
            ->withHeader('X-Business-ID', $business->id)
            ->getJson('/api/businesses/current/branches');

        $response
            ->assertStatus(402)
            ->assertJsonPath('code', 'SUBSCRIPTION_INACTIVE')
            ->assertJsonPath('subscription_status', 'restricted');
    }

    public function test_active_subscription_allows_business_operations(): void
    {
        [$business, $owner] = $this->ownerWithSubscription();

        $response = $this
            ->actingAs($owner)
            ->withHeader('X-Business-ID', $business->id)
            ->getJson('/api/businesses/current/branches');

        $response->assertOk();
    }

    public function test_receipts_require_the_receipts_capability(): void
    {
        [$business, $owner] = $this->ownerWithSubscription([
            'receipts' => false,
        ]);

        $response = $this
            ->actingAs($owner)
            ->withHeader('X-Business-ID', $business->id)
            ->getJson('/api/businesses/current/receipts');

        $response
            ->assertStatus(403)
            ->assertJsonPath('code', 'SUBSCRIPTION_CAPABILITY_REQUIRED')
            ->assertJsonPath('capability', 'receipts');
    }

    public function test_receipts_are_available_when_capability_is_enabled(): void
    {
        [$business, $owner] = $this->ownerWithSubscription([
            'receipts' => true,
        ]);

        $response = $this
            ->actingAs($owner)
            ->withHeader('X-Business-ID', $business->id)
            ->getJson('/api/businesses/current/receipts');

        $response->assertOk();
    }
}
