<?php

namespace Tests\Feature\Authorization;

use App\Domains\Identity\Services\RoleService;
use App\Domains\Organization\Models\Business;
use App\Domains\Organization\Models\BusinessUser;
use App\Domains\Subscription\Models\Subscription;
use App\Domains\Subscription\Models\SubscriptionPlan;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BusinessRoleSubscriptionCapabilityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PermissionSeeder::class);
    }

    public function test_large_subscription_can_view_business_roles(): void
    {
        [$owner, $business] = $this->ownerWithBusiness();

        $this->createSubscription(
            $business,
            true
        );

        $response = $this
            ->withBusiness($business)
            ->getJson(
                '/api/businesses/current/roles'
            );

        $response->assertOk()
            ->assertJsonPath(
                'success',
                true
            );
    }

    public function test_non_large_subscription_cannot_view_business_roles(): void
    {
        [$owner, $business] = $this->ownerWithBusiness();

        $this->createSubscription(
            $business,
            false
        );

        $response = $this
            ->withBusiness($business)
            ->getJson(
                '/api/businesses/current/roles'
            );

        $response->assertForbidden()
            ->assertJsonPath(
                'success',
                false
            )
            ->assertJsonPath(
                'code',
                'SUBSCRIPTION_CAPABILITY_REQUIRED'
            );
    }

    public function test_large_subscription_can_create_custom_role(): void
    {
        [$owner, $business] = $this->ownerWithBusiness();

        $this->createSubscription(
            $business,
            true
        );

        $response = $this
            ->withBusiness($business)
            ->postJson(
                '/api/businesses/current/roles',
                [
                    'name' => 'Sales Supervisor',
                    'permissions' => [
                        'sales.view',
                        'sales.create',
                    ],
                ]
            );

        $response->assertCreated()
            ->assertJsonPath(
                'success',
                true
            )
            ->assertJsonPath(
                'data.name',
                'Sales Supervisor'
            );
    }

    public function test_non_large_subscription_cannot_create_custom_role(): void
    {
        [$owner, $business] = $this->ownerWithBusiness();

        $this->createSubscription(
            $business,
            false
        );

        $response = $this
            ->withBusiness($business)
            ->postJson(
                '/api/businesses/current/roles',
                [
                    'name' => 'Sales Supervisor',
                    'permissions' => [
                        'sales.view',
                        'sales.create',
                    ],
                ]
            );

        $response->assertForbidden()
            ->assertJsonPath(
                'success',
                false
            )
            ->assertJsonPath(
                'code',
                'SUBSCRIPTION_CAPABILITY_REQUIRED'
            );
    }

    public function test_large_subscription_still_requires_roles_view_permission(): void
    {
        $business = Business::factory()->create();

        $user = User::factory()->create();

        BusinessUser::create([
            'business_id' => $business->id,
            'user_id' => $user->id,
            'status' => 'active',
            'joined_at' => now(),
        ]);

        $this->actingAs($user);

        app(RoleService::class)->provisionBusinessRoles(
            $business->id
        );

        $this->createSubscription(
            $business,
            true
        );

        $response = $this
            ->withBusiness($business)
            ->getJson(
                '/api/businesses/current/roles'
            );

        $response->assertForbidden();
    }

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

    private function createSubscription(
        Business $business,
        bool $advancedRbac
    ): Subscription {
        $plan = SubscriptionPlan::factory()->create([
            'features' => [
                'advanced_rbac' => $advancedRbac,
            ],
            'is_active' => true,
        ]);

        return Subscription::factory()->create([
            'business_id' => $business->id,
            'plan_id' => $plan->id,
            'status' => 'active',
            'starts_at' => now()->subDay(),
            'current_period_start' => now()->subDay(),
            'current_period_end' => now()->addMonth(),
        ]);
    }

    private function withBusiness(Business $business)
    {
        return $this->withHeader(
            'X-Business-ID',
            $business->id
        );
    }
}
