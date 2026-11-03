<?php

namespace Tests\Feature\Payment;

use App\Domains\Payment\Contracts\PaymentGateway;
use App\Domains\Payment\Services\PaystackSubscriptionProvisioningService;
use App\Domains\Organization\Models\Business;
use App\Domains\Organization\Models\BusinessType;
use App\Domains\Subscription\Models\Subscription;
use App\Domains\Subscription\Models\SubscriptionPlan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class PaystackSubscriptionProvisioningServiceTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Disable the outer test transaction.
     * The provisioning tests must verify real transaction commits.
     */
    protected $connectionsToTransact = [];

    private PaymentGateway $gateway;

    private string $planCode;

    /** @var list<string> */
    private array $createdBusinessTypeIds = [];

    /** @var list<string> */
    private array $createdBusinessIds = [];

    /** @var list<string> */
    private array $createdPlanIds = [];

    /** @var list<string> */
    private array $createdSubscriptionIds = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->planCode = 'PLN_RECONCILE_' . strtoupper(\Illuminate\Support\Str::random(12));

        $this->gateway = Mockery::mock(PaymentGateway::class);
        $this->app->instance(PaymentGateway::class, $this->gateway);
    }

    protected function tearDown(): void
    {
        try {
            $this->cleanupCreatedFixtures();
        } finally {
            try {
                Mockery::close();
            } finally {
                parent::tearDown();
            }
        }
    }

    private function makeSubscription(string $provisioningStatus = 'pending'): Subscription
    {
        $business = Business::factory()->create();
        $this->createdBusinessTypeIds[] = (string) $business->business_type_id;
        $this->createdBusinessIds[] = (string) $business->getKey();

        $plan = SubscriptionPlan::factory()->create([
            'price' => '10000.00',
            'currency' => 'NGN',
            'billing_interval' => 'monthly',
            'paystack_plan_code' => $this->planCode,
            'is_active' => true,
        ]);
        $this->createdPlanIds[] = (string) $plan->getKey();

        $subscription = Subscription::factory()->create([
            'business_id' => $business->id,
            'plan_id' => $plan->id,
            'status' => 'active',
            'provider' => 'paystack',
            'provider_customer_code' => 'CUS_RECONCILE_TEST',
            'provider_customer_id' => 12345,
            'provider_authorization_code' => 'AUTH_RECONCILE_TEST',
            'provider_subscription_code' => null,
            'provider_email_token' => null,
            'provisioning_status' => $provisioningStatus,
            'provisioning_attempted_at' => null,
            'provisioning_error' => null,
        ]);

        $this->createdSubscriptionIds[] = (string) $subscription->getKey();

        return $subscription;
    }

    private function cleanupCreatedFixtures(): void
    {
        if ($this->createdSubscriptionIds !== []) {
            Subscription::query()
                ->whereKey($this->createdSubscriptionIds)
                ->delete();
        }

        if ($this->createdBusinessIds !== []) {
            Business::withTrashed()
                ->whereKey($this->createdBusinessIds)
                ->forceDelete();
        }

        if ($this->createdBusinessTypeIds !== []) {
            BusinessType::query()
                ->whereKey($this->createdBusinessTypeIds)
                ->delete();
        }

        if ($this->createdPlanIds !== []) {
            SubscriptionPlan::query()
                ->whereKey($this->createdPlanIds)
                ->delete();
        }
    }

    private function expectPlanLookup(): void
    {
        $this->gateway->shouldReceive('fetchPlan')
            ->once()
            ->with($this->planCode)
            ->andReturn([
                'success' => true,
                'data' => [
                    'id' => 987,
                    'plan_code' => $this->planCode,
                    'amount' => 1000000,
                    'currency' => 'NGN',
                    'interval' => 'monthly',
                ],
                'raw' => [],
            ]);
    }

    private function emptyList(): array
    {
        return ['success' => true, 'data' => [], 'meta' => ['pageCount' => 0], 'raw' => []];
    }

    private function providerDetail(string $code = 'SUB_RECONCILED_TEST'): array
    {
        return [
            'success' => true,
            'data' => [
                'subscription_code' => $code,
                'customer' => ['id' => 12345, 'customer_code' => 'CUS_RECONCILE_TEST'],
                'authorization' => ['authorization_code' => 'AUTH_RECONCILE_TEST'],
                'plan' => ['plan_code' => $this->planCode],
                'email_token' => 'EMAIL_RECONCILE_TEST',
            ],
            'raw' => [],
        ];
    }

    public function test_new_pending_subscription_is_created_once_and_marked_provisioned(): void
    {
        $subscription = $this->makeSubscription();
        $this->expectPlanLookup();
        $this->gateway->shouldReceive('listSubscriptions')->once()->andReturn($this->emptyList());
        $this->gateway->shouldReceive('createSubscription')->once()->with([
    'customer_code' => 'CUS_RECONCILE_TEST',
    'plan_code' => $this->planCode,
    'authorization_code' => 'AUTH_RECONCILE_TEST',
])->andReturnUsing(function () use ($subscription): array {
    $attempt = $subscription->fresh();

    expect($attempt->provisioning_status)->toBe('creating')
        ->and($attempt->provisioning_attempted_at)->not->toBeNull()
        ->and(DB::transactionLevel())->toBe(0);

    return [
        'success' => true,
        'subscription_code' => 'SUB_CREATED_TEST',
        'customer_code' => 'CUS_RECONCILE_TEST',
        'email_token' => 'EMAIL_CREATED_TEST',
        'raw' => [],
    ];
});

      $result = app(PaystackSubscriptionProvisioningService::class)->provision($subscription);

        expect($result->provisioning_status)->toBe('provisioned')
            ->and($result->provider_subscription_code)->toBe('SUB_CREATED_TEST')
            ->and($result->provider_email_token)->toBe('EMAIL_CREATED_TEST')
            ->and($result->provisioning_attempted_at)->not->toBeNull();
    }

    public function test_existing_exact_provider_subscription_is_reconciled_without_creation(): void
    {
        $subscription = $this->makeSubscription('reconciliation_required');
        $this->expectPlanLookup();
        $this->gateway->shouldReceive('listSubscriptions')->once()->andReturn([
            'success' => true,
            'data' => [[
                'subscription_code' => 'SUB_RECONCILED_TEST',
                'customer' => ['id' => 12345, 'customer_code' => 'CUS_RECONCILE_TEST'],
                'authorization' => ['authorization_code' => 'AUTH_RECONCILE_TEST'],
                'plan' => ['plan_code' => $this->planCode],
            ]],
            'meta' => ['pageCount' => 1],
            'raw' => [],
        ]);
        $this->gateway->shouldReceive('fetchSubscription')->once()->with('SUB_RECONCILED_TEST')->andReturn($this->providerDetail());
        $this->gateway->shouldNotReceive('createSubscription');

        $result = app(PaystackSubscriptionProvisioningService::class)->provision($subscription);

        expect($result->provisioning_status)->toBe('provisioned')
            ->and($result->provider_subscription_code)->toBe('SUB_RECONCILED_TEST');
    }

    public function test_multiple_exact_provider_matches_are_sent_to_manual_review(): void
    {
        $subscription = $this->makeSubscription();
        $this->expectPlanLookup();
        $candidate = [
            'customer' => ['id' => 12345, 'customer_code' => 'CUS_RECONCILE_TEST'],
            'authorization' => ['authorization_code' => 'AUTH_RECONCILE_TEST'],
            'plan' => ['plan_code' => $this->planCode],
        ];
        $this->gateway->shouldReceive('listSubscriptions')->once()->andReturn([
            'success' => true,
            'data' => [
                $candidate + ['subscription_code' => 'SUB_DUPLICATE_A'],
                $candidate + ['subscription_code' => 'SUB_DUPLICATE_B'],
            ],
            'meta' => ['pageCount' => 1],
            'raw' => [],
        ]);
        $this->gateway->shouldReceive('fetchSubscription')->twice()->andReturn(
            $this->providerDetail('SUB_DUPLICATE_A'),
            $this->providerDetail('SUB_DUPLICATE_B')
        );
        $this->gateway->shouldNotReceive('createSubscription');

        $result = app(PaystackSubscriptionProvisioningService::class)->provision($subscription);

        expect($result->provisioning_status)->toBe('manual_review')
            ->and($result->provider_subscription_code)->toBeNull();
    }

    public function test_previous_ambiguous_attempt_is_never_recreated_when_no_match_is_found(): void
    {
        $subscription = $this->makeSubscription('creating');
        $subscription->forceFill(['provisioning_attempted_at' => now()])->save();
        $this->expectPlanLookup();
        $this->gateway->shouldReceive('listSubscriptions')->once()->andReturn($this->emptyList());
        $this->gateway->shouldNotReceive('createSubscription');

        $result = app(PaystackSubscriptionProvisioningService::class)->provision($subscription);

        expect($result->provisioning_status)->toBe('reconciliation_required')
            ->and($result->provisioning_attempted_at)->not->toBeNull();
    }

    public function test_creation_exception_persists_ambiguous_state_and_retry_does_not_post_again(): void
    {
        $subscription = $this->makeSubscription();
        $this->expectPlanLookup();
        $this->gateway->shouldReceive('listSubscriptions')->once()->andReturn($this->emptyList());
        $this->gateway->shouldReceive('createSubscription')->once()->andThrow(new RuntimeException('network timeout'));

        try {
            app(PaystackSubscriptionProvisioningService::class)->provision($subscription);
            $this->fail('Expected uncertain creation outcome exception.');
        } catch (RuntimeException $exception) {
            expect($exception->getMessage())->toContain('outcome is uncertain');
        }

        expect($subscription->fresh()->provisioning_status)->toBe('reconciliation_required');
        expect($subscription->fresh()->provisioning_attempted_at)->not->toBeNull();

        $this->expectPlanLookup();
        $this->gateway->shouldReceive('listSubscriptions')->once()->andReturn($this->emptyList());
        $this->gateway->shouldNotReceive('createSubscription');

        $retry = app(PaystackSubscriptionProvisioningService::class)->provision($subscription->fresh());
        expect($retry->provisioning_status)->toBe('reconciliation_required');
    }

    public function test_incomplete_pagination_is_fail_closed_and_never_creates(): void
    {
        $subscription = $this->makeSubscription();
        $this->expectPlanLookup();
        $this->gateway->shouldReceive('listSubscriptions')->once()->andReturn([
            'success' => true,
            'data' => [],
            'meta' => [],
            'raw' => [],
        ]);
        $this->gateway->shouldNotReceive('createSubscription');

        expect(fn() => app(PaystackSubscriptionProvisioningService::class)->provision($subscription))
            ->toThrow(RuntimeException::class);
        expect($subscription->fresh()->provisioning_status)->toBe('manual_review');
    }

    public function test_search_over_safe_page_limit_is_fail_closed(): void
    {
        $subscription = $this->makeSubscription();
        $this->expectPlanLookup();
        $this->gateway->shouldReceive('listSubscriptions')->once()->andReturn([
            'success' => true,
            'data' => [],
            'meta' => ['pageCount' => 11],
            'raw' => [],
        ]);
        $this->gateway->shouldNotReceive('createSubscription');

        expect(fn() => app(PaystackSubscriptionProvisioningService::class)->provision($subscription))
            ->toThrow(RuntimeException::class);
        expect($subscription->fresh()->provisioning_status)->toBe('manual_review');
    }

    public function test_legacy_not_required_subscription_is_never_provisioned(): void
    {
        $subscription = $this->makeSubscription('not_required');
        $this->gateway->shouldNotReceive('fetchPlan');
        $this->gateway->shouldNotReceive('listSubscriptions');
        $this->gateway->shouldNotReceive('createSubscription');

        expect(fn() => app(PaystackSubscriptionProvisioningService::class)->provision($subscription))
            ->toThrow(RuntimeException::class);
        expect($subscription->fresh()->provisioning_status)->toBe('not_required');
    }
}
