<?php

namespace App\Providers;

use App\Domains\Payment\Contracts\PaymentGateway;
use App\Domains\Payment\Gateways\PaystackGateway;
use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        /*
         * Payment gateway abstraction.
         *
         * Anywhere in MerchantOS that requires a
         * PaymentGateway will receive PaystackGateway.
         *
         * This keeps the rest of the application
         * independent of Paystack.
         */
        $this->app->bind(
            PaymentGateway::class,
            PaystackGateway::class
        );
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();

        RateLimiter::for('login', function (Request $request) {
            // Account-level failed-attempt lockout is enforced by
            // LoginLockoutService. Keep this middleware limit IP-based so it
            // does not pre-empt the fifth failed credential attempt.
            return Limit::perMinute(20)->by('login-ip:' . $request->ip());
        });

        RateLimiter::for('register', function (Request $request) {
            $email = Str::lower(trim((string) $request->input('owner.email', '')));

            $limits = [
                Limit::perHour(5)->by('register-ip:' . $request->ip()),
            ];

            if ($email !== '') {
                $limits[] = Limit::perHour(5)->by('register-email:' . $email);
            }

            return $limits;
        });

        RateLimiter::for('employee-registration', function (Request $request) {
            $email = Str::lower(trim((string) $request->input('email', '')));
            $merchantId = Str::upper(trim((string) $request->input('merchant_id', '')));

            $limits = [
                Limit::perHour(5)->by('employee-register-ip:' . $request->ip()),
            ];

            if ($email !== '') {
                $limits[] = Limit::perHour(5)->by('employee-register-email:' . $email);
            }

            if ($merchantId !== '') {
                $limits[] = Limit::perHour(10)->by(
                    'employee-register-merchant:' . $merchantId . '|ip:' . $request->ip()
                );
            }

            return $limits;
        });

        // Authenticated application traffic: generous enough for normal POS use,
        // but bounded per account and source IP.
        RateLimiter::for('api', function (Request $request) {
            $userId = $request->user()?->getAuthIdentifier();

            return $userId
                ? [
                    Limit::perMinute(180)->by('api-user:' . $userId),
                    Limit::perMinute(1200)->by('api-ip:' . $request->ip()),
                ]
                : Limit::perMinute(60)->by('api-ip:' . $request->ip());
        });

        RateLimiter::for('public-api', function (Request $request) {
            return Limit::perMinute(60)->by('public-api-ip:' . $request->ip());
        });

        // Paystack retries are expected; allow bursts without leaving the
        // webhook endpoint unlimited. Signature verification remains mandatory.
        RateLimiter::for('paystack-webhook', function (Request $request) {
            return Limit::perMinute(300)->by('paystack-webhook-ip:' . $request->ip());
        });
    }

    /**
     * Configure default behaviors for
     * production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(
            fn (): ?Password => app()->isProduction()
                ? Password::min(12)
                    ->mixedCase()
                    ->letters()
                    ->numbers()
                    ->symbols()
                    ->uncompromised()
                : null,
        );
    }
}