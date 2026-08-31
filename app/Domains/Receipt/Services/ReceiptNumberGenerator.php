<?php

namespace App\Domains\Receipt\Services;

use App\Domains\Organization\Models\Business;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class ReceiptNumberGenerator
{
    /**
     * Generate the next receipt number for a business.
     *
     * Receipt numbers are sequential per business.
     *
     * Example:
     *
     * RCPT-000001
     * RCPT-000002
     * RCPT-000003
     *
     * The sequence is protected with a database row lock
     * so concurrent cashiers cannot receive the same number.
     */
    public function next(Business $business): string
    {
        return DB::transaction(function () use ($business) {
            $sequence = DB::table('receipt_sequences')
                ->where('business_id', $business->id)
                ->lockForUpdate()
                ->first();

            if (! $sequence) {
                DB::table('receipt_sequences')->insert([
                    'business_id' => $business->id,
                    'next_number' => 2,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                $number = 1;
            } else {
                $number = (int) $sequence->next_number;

                DB::table('receipt_sequences')
                    ->where('business_id', $business->id)
                    ->update([
                        'next_number' => $number + 1,
                        'updated_at' => now(),
                    ]);
            }

            return sprintf(
                'RCPT-%06d',
                $number
            );
        });
    }
}
