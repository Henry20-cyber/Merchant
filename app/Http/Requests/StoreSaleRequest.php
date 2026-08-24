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
            'items' => [
                'required',
                'array',
                'min:1',
            ],

            'items.*.product_id' => [
                'required',
                'uuid',
            ],

            'items.*.product_unit_id' => [
                'required',
                'uuid',
            ],

            'items.*.quantity' => [
                'required',
                'numeric',
                'gt:0',
            ],

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

            'customer_id' => [
                'nullable',
                'integer',
            ],

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