<?php

namespace App\Domains\Customer\Services;

use App\Domains\Customer\Models\Customer;
use App\Domains\Organization\Models\Business;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CustomerService
{
    /**
     * Create a customer for a business.
     *
     * Customer name and phone are intentionally optional.
     */
    public function create(
        Business $business,
        array $data
    ): Customer {
        return DB::transaction(function () use (
            $business,
            $data
        ): Customer {
            $customerNumber = $this->generateCustomerNumber(
                $business
            );

            return Customer::create([
                'business_id' => $business->id,

                'customer_number' => $customerNumber,

                'name' => $data['name'] ?? null,

                'phone' => $data['phone'] ?? null,

                'status' => $data['status'] ?? 'active',
            ]);
        });
    }

    /**
     * Update a customer belonging to the business.
     */
    public function update(
        Business $business,
        Customer $customer,
        array $data
    ): Customer {
        $this->ensureBelongsToBusiness(
            $business,
            $customer
        );

        /*
         * Customer number is immutable.
         *
         * It is the customer's permanent
         * business-facing identifier.
         */
        unset($data['customer_number']);

        $customer->update([
            'name' => array_key_exists('name', $data)
                ? $data['name']
                : $customer->name,

            'phone' => array_key_exists('phone', $data)
                ? $data['phone']
                : $customer->phone,

            'status' => array_key_exists('status', $data)
                ? $data['status']
                : $customer->status,
        ]);

        return $customer->refresh();
    }

    /**
     * Deactivate a customer without deleting the record.
     */
    public function deactivate(
        Business $business,
        Customer $customer
    ): Customer {
        $this->ensureBelongsToBusiness(
            $business,
            $customer
        );

        $customer->update([
            'status' => 'inactive',
        ]);

        return $customer->refresh();
    }

    /**
     * Find a customer belonging to a business.
     */
    public function getForBusiness(
        Business $business,
        string $customerId
    ): Customer {
        return Customer::query()
            ->where('business_id', $business->id)
            ->where('id', $customerId)
            ->firstOrFail();
    }

    /**
     * Generate the next customer number for a business.
     *
     * Examples:
     *
     * CUS-000001
     * CUS-000002
     * CUS-000003
     */
    private function generateCustomerNumber(
        Business $business
    ): string {
        $lastNumber = Customer::withTrashed()
            ->where('business_id', $business->id)
            ->lockForUpdate()
            ->orderByDesc('customer_number')
            ->value('customer_number');

        if (! $lastNumber) {
            return 'CUS-000001';
        }

        /*
         * Extract the numeric portion.
         *
         * CUS-000001
         *      ↓
         * 000001
         */
        $number = (int) str_replace(
            'CUS-',
            '',
            $lastNumber
        );

        $nextNumber = $number + 1;

        return 'CUS-' . str_pad(
            (string) $nextNumber,
            6,
            '0',
            STR_PAD_LEFT
        );
    }

    /**
     * Ensure the customer belongs to the current business.
     */
    private function ensureBelongsToBusiness(
        Business $business,
        Customer $customer
    ): void {
        if (
            $customer->business_id !== $business->id
        ) {
            throw ValidationException::withMessages([
                'customer' =>
                    'Customer does not belong to this business.',
            ]);
        }
    }
}
