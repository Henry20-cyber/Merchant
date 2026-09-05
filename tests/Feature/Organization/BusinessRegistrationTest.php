<?php

namespace Tests\Feature\Organization;

use App\Domains\Organization\Models\Business;
use App\Domains\Organization\Models\BusinessType;
use App\Domains\Organization\Services\BusinessService;
use Database\Seeders\SubscriptionPlanSeeder;
use App\Domains\Organization\Requests\StoreBusinessRequest;
use App\Domains\Subscription\Models\Subscription;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BusinessRegistrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
{
    parent::setUp();

    $this->seed(SubscriptionPlanSeeder::class);
}

    private function validBusinessData(
        bool $productsEnabled,
        bool $servicesEnabled
    ): array {
        $businessType = BusinessType::factory()->create();

        return [
            'business_type_id' => $businessType->id,

            'name' => 'Henry Beauty Store',

            'phone' => '08012345678',

            'email' => 'business@example.com',

            'products_enabled' => $productsEnabled,

            'services_enabled' => $servicesEnabled,

            'address' => 'Main Street',

            'city' => 'Owerri',

            'state' => 'Imo',
        ];
    }

    public function test_products_only_business_can_be_registered(): void
    {
        $business = app(BusinessService::class)
            ->registerBusiness(
                $this->validBusinessData(true, false)
            );

        $capabilities = $business->fresh()->capabilities;

        $this->assertTrue(
            $capabilities->products_enabled
        );

        $this->assertFalse(
            $capabilities->services_enabled
        );
    }

    public function test_services_only_business_can_be_registered(): void
    {
        $business = app(BusinessService::class)
            ->registerBusiness(
                $this->validBusinessData(false, true)
            );

        $capabilities = $business->fresh()->capabilities;

        $this->assertFalse(
            $capabilities->products_enabled
        );

        $this->assertTrue(
            $capabilities->services_enabled
        );
    }

    public function test_hybrid_business_can_be_registered(): void
    {
        $business = app(BusinessService::class)
            ->registerBusiness(
                $this->validBusinessData(true, true)
            );

        $capabilities = $business->fresh()->capabilities;

        $this->assertTrue(
            $capabilities->products_enabled
        );

        $this->assertTrue(
            $capabilities->services_enabled
        );
    }

    public function test_business_with_neither_products_nor_services_is_invalid(): void
{
    $data = $this->validBusinessData(false, false);

    $validator = validator(
        $data,
        [
            'business_type_id' => [
                'required',
                'uuid',
                'exists:business_types,id',
            ],

            'name' => [
                'required',
                'string',
                'max:255',
            ],

            'phone' => [
                'required',
                'string',
                'max:20',
            ],

            'email' => [
                'nullable',
                'email',
                'max:255',
            ],

            'products_enabled' => [
                'required',
                'boolean',
            ],

            'services_enabled' => [
                'required',
                'boolean',
            ],
        ]
    );

    $validator->after(function ($validator) use ($data): void {
        if (
            ! $data['products_enabled'] &&
            ! $data['services_enabled']
        ) {
            $validator->errors()->add(
                'capabilities',
                'At least one of products or services must be enabled.'
            );
        }
    });

    $this->assertTrue($validator->fails());

    $this->assertArrayHasKey(
        'capabilities',
        $validator->errors()->toArray()
    );
}

public function test_business_registration_generates_unique_merchant_id(): void
{
    $businessA = app(BusinessService::class)
        ->registerBusiness(
            $this->validBusinessData(true, false)
        );

    $businessB = app(BusinessService::class)
        ->registerBusiness(
            $this->validBusinessData(false, true)
        );

    $this->assertNotNull($businessA->merchant_id);
    $this->assertNotNull($businessB->merchant_id);

    $this->assertMatchesRegularExpression(
        '/^MCH-[A-Z0-9]{6}$/',
        $businessA->merchant_id
    );

    $this->assertMatchesRegularExpression(
        '/^MCH-[A-Z0-9]{6}$/',
        $businessB->merchant_id
    );

    $this->assertNotSame(
        $businessA->merchant_id,
        $businessB->merchant_id
    );
}

public function test_business_registration_creates_free_subscription(): void
{
    $business = app(BusinessService::class)
        ->registerBusiness(
            $this->validBusinessData(true, true)
        );

    $subscription = Subscription::query()
        ->where('business_id', $business->id)
        ->with('plan')
        ->first();

    expect($subscription)->not->toBeNull();
    expect($subscription->status)->toBe('active');
    expect($subscription->plan)->not->toBeNull();
    expect($subscription->plan->slug)->toBe('free');
    expect($subscription->plan->transaction_daily_limit)->toBe(10);
    expect($subscription->plan->transaction_monthly_limit)->toBe(30);
}

public function test_business_registration_stores_custom_business_type(): void
{
    $otherType = BusinessType::factory()->create([
        'name' => 'Other',
    ]);

    $data = $this->validBusinessData(true, false);

    $data['business_type_id'] = $otherType->id;
    $data['custom_business_type'] = 'Auto Parts';

    $business = app(BusinessService::class)
        ->registerBusiness($data);

    $this->assertDatabaseHas('businesses', [
        'id' => $business->id,
        'business_type_id' => $otherType->id,
        'custom_business_type' => 'Auto Parts',
    ]);
}

public function test_standard_business_registration_does_not_store_custom_business_type(): void
{
    $standardType = BusinessType::factory()->create([
        'name' => 'Supermarket',
    ]);

    $data = $this->validBusinessData(true, false);

    $data['business_type_id'] = $standardType->id;

    $business = app(BusinessService::class)
        ->registerBusiness($data);

    $this->assertDatabaseHas('businesses', [
        'id' => $business->id,
        'business_type_id' => $standardType->id,
        'custom_business_type' => null,
    ]);
}
}
