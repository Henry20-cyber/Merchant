<?php

namespace Tests\Feature\Payment;

use App\Domains\Subscription\Models\SubscriptionPlan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use Tests\TestCase;

class SubscriptionCheckoutRecoveryMigrationTest extends TestCase
{
    use RefreshDatabase;

    private object $migration;

    protected function setUp(): void
    {
        parent::setUp();

        $this->migration = require database_path('migrations/2026_11_04_120000_create_subscription_checkout_recoveries_table.php');
    }

    public function test_complete_historical_paid_terms_are_succeeded(): void
    {
        $plan = SubscriptionPlan::factory()->create();
        $planId = $this->invokeMigrationMethod('existingPlanId', $plan->id);

        self::assertSame($plan->id, $planId);
        self::assertTrue($this->invokeMigrationMethod('hasCompletePurchaseTerms', $planId, $this->completeTerms()));
        self::assertSame('succeeded', $this->invokeMigrationMethod('legacyRecoveryStatus', 'paid', true));
    }

    #[DataProvider('incompleteTermCases')]
    public function test_incomplete_historical_paid_terms_require_manual_review(
        string $field,
        bool $remove,
        mixed $value,
    ): void {
        $plan = SubscriptionPlan::factory()->create();
        $planId = $this->invokeMigrationMethod('existingPlanId', $plan->id);
        $terms = $this->completeTerms();
        if ($remove) {
            unset($terms[$field]);
        } else {
            $terms[$field] = $value;
        }

        self::assertFalse($this->invokeMigrationMethod('hasCompletePurchaseTerms', $planId, $terms));
        self::assertSame('manual_review', $this->invokeMigrationMethod('legacyRecoveryStatus', 'paid', false));
    }

    public static function incompleteTermCases(): array
    {
        return [
            'missing currency' => ['currency', true, null],
            'null currency' => ['currency', false, null],
            'empty currency' => ['currency', false, '  '],
            'missing Paystack plan code' => ['paystack_plan_code', true, null],
            'null Paystack plan code' => ['paystack_plan_code', false, null],
            'empty Paystack plan code' => ['paystack_plan_code', false, ''],
            'missing billing interval' => ['billing_interval', true, null],
            'null billing interval' => ['billing_interval', false, null],
        ];
    }

    public function test_missing_malformed_and_nonexistent_plan_ids_are_invalid(): void
    {
        self::assertNull($this->invokeMigrationMethod('existingPlanId', null));
        self::assertNull($this->invokeMigrationMethod('existingPlanId', 'not-a-uuid'));
        self::assertNull($this->invokeMigrationMethod('existingPlanId', (string) Str::uuid()));
        self::assertFalse($this->invokeMigrationMethod('hasCompletePurchaseTerms', null, $this->completeTerms()));
    }

    public function test_failed_and_pending_historical_payments_keep_their_states_when_terms_are_incomplete(): void
    {
        self::assertSame('retryable', $this->invokeMigrationMethod('legacyRecoveryStatus', 'failed', false));
        self::assertSame('pending', $this->invokeMigrationMethod('legacyRecoveryStatus', 'pending', false));
    }

    /** @return array{billing_interval: string, currency: string, paystack_plan_code: string} */
    private function completeTerms(): array
    {
        return [
            'billing_interval' => 'monthly',
            'currency' => 'NGN',
            'paystack_plan_code' => 'PLN_migration_test',
        ];
    }

    private function invokeMigrationMethod(string $name, mixed ...$arguments): mixed
    {
        $method = new ReflectionMethod($this->migration, $name);

        return $method->invoke($this->migration, ...$arguments);
    }
}
