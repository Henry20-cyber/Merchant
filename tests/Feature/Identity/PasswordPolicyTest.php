<?php

namespace Tests\Feature\Identity;

use App\Domains\Identity\Requests\EmployeeRegistrationRequest;
use App\Domains\Identity\Requests\RegisterRequest;
use App\Domains\Identity\Requests\ResetPasswordRequest;
use Illuminate\Contracts\Validation\UncompromisedVerifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;
use Tests\TestCase;

class PasswordPolicyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Activate the production-only password policy for these tests.
        $this->app->detectEnvironment(fn () => 'production');

        // Re-register the policy after changing the test environment.
        (new \App\Providers\AppServiceProvider($this->app))->boot();

        // Keep breach-check tests deterministic and offline.
        $this->app->instance(
            UncompromisedVerifier::class,
            new class implements UncompromisedVerifier {
                public bool $compromised = false;

                public array $checks = [];

                public function verify($data): bool
                {
                    $this->checks[] = $data;

                    return ! $this->compromised;
                }
            }
        );
    }

    private function passwordValidator(string $password): \Illuminate\Validation\Validator
    {
        return Validator::make(
            ['password' => $password],
            ['password' => ['required', 'string', Password::default()]]
        );
    }

    public function test_eight_character_password_meeting_all_requirements_is_accepted(): void
    {
        $validator = $this->passwordValidator('Abcdef1!');

        $this->assertTrue($validator->passes());
    }

    public function test_seven_character_password_is_rejected(): void
    {
        $validator = $this->passwordValidator('Abcde1!');

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('password', $validator->errors()->toArray());
    }

    public function test_password_without_uppercase_is_rejected(): void
    {
        $this->assertTrue(
            $this->passwordValidator('abcdef1!')->fails()
        );
    }

    public function test_password_without_lowercase_is_rejected(): void
    {
        $this->assertTrue(
            $this->passwordValidator('ABCDEFG1!')->fails()
        );
    }

    public function test_password_without_number_is_rejected(): void
    {
        $this->assertTrue(
            $this->passwordValidator('Abcdefgh!')->fails()
        );
    }

    public function test_password_without_symbol_is_rejected(): void
    {
        $this->assertTrue(
            $this->passwordValidator('Abcdefg1')->fails()
        );
    }

    public function test_compromised_password_is_rejected(): void
    {
        $verifier = new class implements UncompromisedVerifier {
            public function verify($data): bool
            {
                return false;
            }
        };

        $this->app->instance(UncompromisedVerifier::class, $verifier);

        $validator = $this->passwordValidator('Abcdef1!');

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('password', $validator->errors()->toArray());
    }

    public function test_default_password_rule_is_used_by_all_identity_requests(): void
    {
        $requestClasses = [
            RegisterRequest::class => 'owner.password',
            EmployeeRegistrationRequest::class => 'password',
            ResetPasswordRequest::class => 'password',
        ];

        foreach ($requestClasses as $requestClass => $passwordField) {
            $request = new $requestClass();
            $rules = $request->rules();

            $hasPasswordRule = collect($rules[$passwordField])
                ->contains(fn ($rule) => $rule instanceof Password);

            $this->assertTrue(
                $hasPasswordRule,
                "{$requestClass} must use the shared default password policy."
            );
        }
    }
}
