<?php

namespace Tests\Feature\Receipt;

use App\Domains\Organization\Models\Business;
use App\Domains\Receipt\Services\ReceiptNumberGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReceiptNumberGeneratorTest extends TestCase
{
    use RefreshDatabase;

    public function test_first_receipt_number_is_generated(): void
    {
        $business = Business::factory()->create();

        $generator = app(
            ReceiptNumberGenerator::class
        );

        $number = $generator->next($business);

        $this->assertSame(
            'RCPT-000001',
            $number
        );
    }

    public function test_receipt_numbers_increment_per_business(): void
    {
        $business = Business::factory()->create();

        $generator = app(
            ReceiptNumberGenerator::class
        );

        $this->assertSame(
            'RCPT-000001',
            $generator->next($business)
        );

        $this->assertSame(
            'RCPT-000002',
            $generator->next($business)
        );

        $this->assertSame(
            'RCPT-000003',
            $generator->next($business)
        );
    }

    public function test_receipt_numbers_are_scoped_to_business(): void
    {
        $business1 = Business::factory()->create();
        $business2 = Business::factory()->create();

        $generator = app(
            ReceiptNumberGenerator::class
        );

        $this->assertSame(
            'RCPT-000001',
            $generator->next($business1)
        );

        $this->assertSame(
            'RCPT-000001',
            $generator->next($business2)
        );

        $this->assertSame(
            'RCPT-000002',
            $generator->next($business1)
        );
    }

    public function test_sequence_row_is_created_for_business(): void
    {
        $business = Business::factory()->create();

        $generator = app(
            ReceiptNumberGenerator::class
        );

        $generator->next($business);

        $this->assertDatabaseHas(
            'receipt_sequences',
            [
                'business_id' => $business->id,
                'next_number' => 2,
            ]
        );
    }

    public function test_sequence_does_not_reset_when_generator_is_recreated(): void
    {
        $business = Business::factory()->create();

        $generator = app(
            ReceiptNumberGenerator::class
        );

        $this->assertSame(
            'RCPT-000001',
            $generator->next($business)
        );

        $generator = app(
            ReceiptNumberGenerator::class
        );

        $this->assertSame(
            'RCPT-000002',
            $generator->next($business)
        );
    }
}
