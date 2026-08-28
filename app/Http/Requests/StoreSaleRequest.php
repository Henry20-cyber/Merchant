<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreSaleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            /*
            |--------------------------------------------------------------------------
            | Sale Items
            |--------------------------------------------------------------------------
            */

            'items' => [
                'required',
                'array',
                'min:1',
            ],

            /*
            |--------------------------------------------------------------------------
            | Product
            |--------------------------------------------------------------------------
            */

            'items.*.product_id' => [
                'nullable',
                'uuid',
            ],

            'items.*.product_unit_id' => [
                'nullable',
                'uuid',
            ],

            /*
            |--------------------------------------------------------------------------
            | Service
            |--------------------------------------------------------------------------
            */

            'items.*.service_id' => [
                'nullable',
                'uuid',
            ],

            /*
            |--------------------------------------------------------------------------
            | Quantity
            |--------------------------------------------------------------------------
            */

            'items.*.quantity' => [
                'required',
                'numeric',
                'gt:0',
            ],

            /*
            |--------------------------------------------------------------------------
            | Item Pricing
            |--------------------------------------------------------------------------
            */

            'items.*.unit_price' => [
                'nullable',
                'numeric',
                'min:0',
            ],

            'items.*.unit_cost' => [
                'nullable',
                'numeric',
                'min:0',
            ],

            'items.*.discount' => [
                'nullable',
                'numeric',
                'min:0',
            ],

            /*
            |--------------------------------------------------------------------------
            | Sale Totals
            |--------------------------------------------------------------------------
            */

            'discount' => [
                'nullable',
                'numeric',
                'min:0',
            ],

            'tax' => [
                'nullable',
                'numeric',
                'min:0',
            ],

            /*
            |--------------------------------------------------------------------------
            | Customer
            |--------------------------------------------------------------------------
            |
            | Customer is optional because MerchantOS supports
            | walk-in sales.
            |
            | We intentionally do NOT use exists:customers,id here.
            | SaleService must verify the customer belongs to
            | the current business.
            |
            */

            'customer_id' => [
                'nullable',
                'uuid',
            ],

            /*
            |--------------------------------------------------------------------------
            | Payment
            |--------------------------------------------------------------------------
            */

            'payment_method' => [
                'nullable',
                'string',
                'max:50',
            ],

            'payment_status' => [
                'nullable',
                'string',
                'max:50',
            ],

            'status' => [
                'nullable',
                'string',
                'max:50',
            ],

            /*
            |--------------------------------------------------------------------------
            | Note
            |--------------------------------------------------------------------------
            */

            'note' => [
                'nullable',
                'string',
                'max:1000',
            ],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'discount' => $this->input('discount', 0),
            'tax' => $this->input('tax', 0),
        ]);
    }
}