<?php

namespace Tests\Feature\Subscription;

use App\Domains\Organization\Models\Business;
use App\Domains\Organization\Models\BusinessUser;
use App\Domains\Payment\Models\Payment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class SubscriptionPaymentVerificationControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_verification_requires_authentication_and_valid_reference(): void
    {
        $this->postJson('/api/businesses/current/subscription/payment-verification', ['reference' => 'valid-ref'])
            ->assertUnauthorized();

        [$actor, $business] = $this->merchant();
        $this->actingAs($actor)->withHeader('X-Business-ID', $business->id)
            ->postJson('/api/businesses/current/subscription/payment-verification', ['reference' => 'bad ref'])
            ->assertUnprocessable();
    }

    public function test_unknown_and_other_business_references_are_not_disclosed(): void
    {
        [$actor, $business] = $this->merchant();
        $other = Business::factory()->create();
        Payment::factory()->create(['business_id' => $other->id, 'reference' => 'OTHER-BUSINESS-REF']);
        $this->actingAs($actor)->withHeader('X-Business-ID', $business->id)
            ->postJson('/api/businesses/current/subscription/payment-verification', ['reference' => 'OTHER-BUSINESS-REF'])
            ->assertNotFound()->assertJsonMissing(['reference' => 'OTHER-BUSINESS-REF']);
    }

    public function test_non_subscription_payment_cannot_be_verified_through_this_endpoint(): void
    {
        [$actor, $business] = $this->merchant();
        Payment::factory()->create(['business_id' => $business->id, 'reference' => 'SALE-REF', 'metadata' => ['type' => 'sale']]);
        $this->actingAs($actor)->withHeader('X-Business-ID', $business->id)
            ->postJson('/api/businesses/current/subscription/payment-verification', ['reference' => 'SALE-REF'])
            ->assertNotFound();
    }

    private function merchant(): array
    {
        $business = Business::factory()->create();
        $actor = User::factory()->create();
        BusinessUser::create(['business_id' => $business->id, 'user_id' => $actor->id, 'status' => 'active', 'joined_at' => now()]);
        $permission = Permission::firstOrCreate(['name' => 'subscription.manage', 'guard_name' => 'web']);
        setPermissionsTeamId($business->id);
        $role = Role::firstOrCreate(['name' => 'Payment Verification Owner', 'guard_name' => 'web', 'team_id' => $business->id], ['is_system' => true]);
        $role->givePermissionTo($permission);
        $actor->assignRole($role);
        return [$actor, $business];
    }
}
