<?php

namespace App\Console\Commands;

use App\Domains\Organization\Models\BusinessUser;
use App\Domains\Payment\Services\SubscriptionPaymentRecoveryService;
use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Throwable;

use function setPermissionsTeamId;

#[Signature('subscription:recover-paid-payment {reference : Exact local Paystack reference} {--payment-id=} {--business-id=} {--subscription-id=} {--plan-id=} {--plan-slug=} {--paystack-plan-code=} {--amount-kobo=} {--currency=} {--billing-interval=} {--actor-id=} {--execute : Apply an eligible entitlement recovery; omitted means dry-run}')]
#[Description('Preflight or recover a historical paid subscription entitlement without charging again')]
class RecoverSubscriptionPaymentEntitlement extends Command
{
    public function handle(): int
    {
        $required = ['payment-id', 'business-id', 'subscription-id', 'plan-id', 'plan-slug', 'paystack-plan-code', 'amount-kobo', 'currency', 'billing-interval', 'actor-id'];
        $values = [];
        foreach ($required as $key) {
            $value = trim((string) $this->option($key));
            if ($value === '') {
                $this->error("Missing required --{$key} recovery evidence.");

                return self::FAILURE;
            }
            $values[$key] = $value;
        }
        foreach (['payment-id', 'business-id', 'subscription-id', 'plan-id'] as $key) {
            if (! Str::isUuid($values[$key])) {
                $this->error("--{$key} must be a UUID.");

                return self::FAILURE;
            }
        }
        if (! ctype_digit($values['amount-kobo']) || (int) $values['amount-kobo'] < 1) {
            $this->error('--amount-kobo must be a positive integer.');

            return self::FAILURE;
        }
        if (! preg_match('/^[A-Za-z0-9_-]+$/', (string) $this->argument('reference'))) {
            $this->error('The exact payment reference has an invalid format.');

            return self::FAILURE;
        }

        $actor = User::query()->find($values['actor-id']);
        if (! $actor) {
            $this->error('The specified recovery actor does not exist.');

            return self::FAILURE;
        }
        if (! BusinessUser::query()->where('business_id', $values['business-id'])->where('user_id', $actor->id)->where('status', 'active')->exists()) {
            $this->error('The specified actor is not an active member of the target business.');

            return self::FAILURE;
        }
        setPermissionsTeamId($values['business-id']);
        if (! $actor->can('subscription.manage')) {
            $this->error('The specified actor lacks subscription.manage in the target business.');

            return self::FAILURE;
        }

        $terms = [
            'payment_id' => $values['payment-id'],
            'business_id' => $values['business-id'],
            'subscription_id' => $values['subscription-id'],
            'plan_id' => $values['plan-id'],
            'plan_slug' => $values['plan-slug'],
            'paystack_plan_code' => $values['paystack-plan-code'],
            'amount_kobo' => (int) $values['amount-kobo'],
            'currency' => strtoupper($values['currency']),
            'billing_interval' => $values['billing-interval'],
        ];

        try {
            $result = app(SubscriptionPaymentRecoveryService::class)->recover(
                (string) $this->argument('reference'),
                $terms,
                $actor,
                (bool) $this->option('execute'),
            );
        } catch (Throwable $exception) {
            $this->error('Recovery preflight failed safely: '.$exception->getMessage());

            return self::FAILURE;
        }

        if ($result['already_recovered'] ?? false) {
            $this->info('Payment entitlement was already recovered; no changes were made.');

            return self::SUCCESS;
        }
        if (! $this->option('execute')) {
            $this->info('DRY RUN: all preflight checks passed. No database changes were made.');
        } else {
            $this->info('Recovery executed and recorded in the append-only audit trail.');
        }
        $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
