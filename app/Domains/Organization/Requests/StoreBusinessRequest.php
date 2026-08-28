<?php

namespace App\Domains\Organization\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreBusinessRequest extends FormRequest
{
    /**
     * Determine if the user is authorized.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Validation rules.
     */
    public function rules(): array
    {
        return [
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

            'website' => [
                'nullable',
                'url',
                'max:255',
            ],

            'registration_number' => [
                'nullable',
                'string',
                'max:100',
            ],

            'tax_number' => [
                'nullable',
                'string',
                'max:100',
            ],

            'default_country' => [
                'nullable',
                'string',
                'max:100',
            ],

            'currency' => [
                'nullable',
                'string',
                'size:3',
            ],

            'timezone' => [
                'nullable',
                'string',
            ],

            'address' => [
                'nullable',
                'string',
            ],

            'city' => [
                'required',
                'string',
                'max:100',
            ],

            'state' => [
                'required',
                'string',
                'max:100',
            ],

            /*
             * Business capabilities.
             *
             * The onboarding flow must explicitly tell
             * MerchantOS whether the business sells products,
             * services, or both.
             */
            'products_enabled' => [
                'required',
                'boolean',
            ],

            'services_enabled' => [
                'required',
                'boolean',
            ],
        ];
    }

    /**
     * Add cross-field validation.
     *
     * At least one business capability must be enabled.
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $productsEnabled = $this->boolean(
                    'products_enabled'
                );

                $servicesEnabled = $this->boolean(
                    'services_enabled'
                );

                if (! $productsEnabled && ! $servicesEnabled) {
                    $validator->errors()->add(
                        'capabilities',
                        'At least one of products or services must be enabled.'
                    );
                }
            },
        ];
    }
}
