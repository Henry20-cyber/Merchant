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
            $email = Str::lower(
                Str::ascii(
                    (string) $request->input('email')
                )
            );

            return [
                Limit::perMinute(5)
                    ->by(
                        'login-email:' .
                        $email .
                        '|ip:' .
                        $request->ip()
                    ),

                Limit::perMinute(20)
                    ->by(
                        'login-ip:' .
                        $request->ip()
                    ),
            ];
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