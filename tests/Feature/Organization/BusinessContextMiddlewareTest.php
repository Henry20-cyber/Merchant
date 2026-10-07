<?php

namespace Tests\Feature;

use App\Domains\Organization\Models\Business;
use App\Domains\Organization\Models\BusinessUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;
use function getPermissionsTeamId;

class BusinessContextMiddlewareTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        /*
         * Isolated endpoint used only to inspect the resolved
         * business context and Spatie permission team.
         */
        Route::middleware([
            'auth:sanctum',
            'business.context',
        ])->get('/api/test/business-context', function (Request $request) {
            $context = $request->attributes->get('current_business');

            return response()->json([
                'business_id' => $context?->id,
                'team_id' => getPermissionsTeamId(),
            ]);
        });

        Route::middleware([
            'auth:sanctum',
            'business.context',
            'branch.context',
        ])->get('/api/test/branch-context', function (Request $request) {
            $branch = $request->attributes->get('current_branch');

            return response()->json([
                'branch_id' => $branch?->id,
                'business_id' => $branch?->business_id,
            ]);
        });
    }

    private function createBusiness(): Business
    {
        return Business::factory()->create();
    }

    private function addMember(
        User $user,
        Business $business,
        string $status = 'active'
    ): BusinessUser {
        return BusinessUser::create([
            'business_id' => $business->id,
            'user_id' => $user->id,
            'status' => $status,
            'joined_at' => now(),
        ]);
    }

    public function test_it_resolves_business_from_header(): void
    {
        $user = User::factory()->create();
        $business = $this->createBusiness();

        $this->addMember($user, $business);

        $response = $this
            ->actingAs($user)
            ->withHeader('X-Business-ID', $business->id)
            ->getJson('/api/test/business-context');

        $response
            ->assertSuccessful()
            ->assertJsonPath('business_id', $business->id)
            ->assertJsonPath('team_id', $business->id);
    }

    public function test_it_rejects_business_from_header_when_user_is_not_a_member(): void
    {
        $user = User::factory()->create();
        $business = $this->createBusiness();

        $response = $this
            ->actingAs($user)
            ->withHeader('X-Business-ID', $business->id)
            ->getJson('/api/test/business-context');

        $response
            ->assertForbidden()
            ->assertJsonPath('success', false);
    }

    public function test_it_resolves_business_from_session_when_header_is_absent(): void
    {
        $user = User::factory()->create();
        $business = $this->createBusiness();

        $this->addMember($user, $business);

        session([
            'current_business_id' => $business->id,
        ]);

        $response = $this
            ->actingAs($user)
            ->getJson('/api/test/business-context');

        $response
            ->assertSuccessful()
            ->assertJsonPath('business_id', $business->id)
            ->assertJsonPath('team_id', $business->id);
    }

    public function test_header_takes_precedence_over_session_context(): void
    {
        $user = User::factory()->create();

        $businessA = $this->createBusiness();
        $businessB = $this->createBusiness();

        $this->addMember($user, $businessA);
        $this->addMember($user, $businessB);

        session([
            'current_business_id' => $businessA->id,
        ]);

        $response = $this
            ->actingAs($user)
            ->withHeader('X-Business-ID', $businessB->id)
            ->getJson('/api/test/business-context');

        $response
            ->assertSuccessful()
            ->assertJsonPath('business_id', $businessB->id)
            ->assertJsonPath('team_id', $businessB->id);
    }

    public function test_header_cannot_override_session_with_an_unowned_business(): void
    {
        $user = User::factory()->create();

        $businessA = $this->createBusiness();
        $businessB = $this->createBusiness();

        $this->addMember($user, $businessA);

        session([
            'current_business_id' => $businessA->id,
        ]);

        $response = $this
            ->actingAs($user)
            ->withHeader('X-Business-ID', $businessB->id)
            ->getJson('/api/test/business-context');

        $response
            ->assertForbidden()
            ->assertJsonPath('success', false);
    }

    public function test_it_has_no_business_context_when_neither_header_nor_session_exists(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->getJson('/api/test/business-context');

        $response
            ->assertSuccessful()
            ->assertJsonPath('business_id', null)
            ->assertJsonPath('team_id', null);
    }

    public function test_inactive_membership_cannot_establish_business_context(): void
    {
        $user = User::factory()->create();
        $business = $this->createBusiness();

        $this->addMember($user, $business, 'inactive');

        $response = $this
            ->actingAs($user)
            ->withHeader('X-Business-ID', $business->id)
            ->getJson('/api/test/business-context');

        $response
            ->assertForbidden()
            ->assertJsonPath('success', false);
    }

    public function test_business_context_service_uses_the_same_business_resolved_by_middleware(): void
    {
        $user = User::factory()->create();

        $businessA = $this->createBusiness();
        $businessB = $this->createBusiness();

        $this->addMember($user, $businessA);
        $this->addMember($user, $businessB);

        session([
            'current_business_id' => $businessA->id,
        ]);

        Route::middleware([
            'auth:sanctum',
            'business.context',
        ])->get('/api/test/business-context-service', function (
            Request $request
        ) {
            $business = app(
                \App\Domains\Organization\Services\BusinessContextService::class
            )->current($request->user());

            return response()->json([
                'business_id' => $business?->id,
                'team_id' => getPermissionsTeamId(),
            ]);
        });

        $response = $this
            ->actingAs($user)
            ->withHeader('X-Business-ID', $businessB->id)
            ->getJson('/api/test/business-context-service');

        $response
            ->assertSuccessful()
            ->assertJsonPath('business_id', $businessB->id)
            ->assertJsonPath('team_id', $businessB->id);
    }

    public function test_it_resolves_current_branch_from_session(): void
{
    $user = User::factory()->create();

    $business = $this->createBusiness();

    $this->addMember($user, $business);

    $branch = \App\Domains\Organization\Models\Branch::create([
        'business_id' => $business->id,
        'name' => 'Owerri Branch',
        'code' => 'OW-CONTEXT-001',
        'city' => 'Owerri',
        'state' => 'Imo',
        'country' => 'Nigeria',
        'is_head_office' => false,
    ]);

    session([
        'current_business_id' => $business->id,
        'current_branch_id' => $branch->id,
    ]);

    $response = $this
        ->actingAs($user)
        ->getJson('/api/test/branch-context');

    $response
        ->assertSuccessful()
        ->assertJsonPath('branch_id', $branch->id)
        ->assertJsonPath('business_id', $business->id);
}

public function test_it_does_not_resolve_branch_from_another_business(): void
{
    $user = User::factory()->create();

    $businessA = $this->createBusiness();
    $businessB = $this->createBusiness();

    $this->addMember($user, $businessA);
    $this->addMember($user, $businessB);

    $branchA = \App\Domains\Organization\Models\Branch::create([
        'business_id' => $businessA->id,
        'name' => 'Business A Branch',
        'code' => 'A-BRANCH-001',
        'city' => 'Owerri',
        'state' => 'Imo',
        'country' => 'Nigeria',
        'is_head_office' => false,
    ]);

    session([
        'current_business_id' => $businessB->id,
        'current_branch_id' => $branchA->id,
    ]);

    $response = $this
        ->actingAs($user)
        ->getJson('/api/test/branch-context');

    $response
        ->assertSuccessful()
        ->assertJsonPath('branch_id', null)
        ->assertJsonPath('business_id', null);

    $this->assertFalse(
        session()->has('current_branch_id')
    );
}
}
