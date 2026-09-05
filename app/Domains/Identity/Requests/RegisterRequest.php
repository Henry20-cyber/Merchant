<?php

namespace App\Domains\Identity\Requests;

use App\Domains\Organization\Models\BusinessType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class RegisterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'owner' => [
                'required',
                'array',
            ],

            'owner.name' => [
                'required',
                'string',
                'max:255',
            ],

            'owner.email' => [
                'required',
                'email',
                'max:255',
                'unique:users,email',
            ],

            'owner.password' => [
                'required',
                'string',
                'confirmed',
                Password::min(8),
            ],

            'owner.password_confirmation' => [
                'required',
                'string',
            ],

            'business' => [
                'required',
                'array',
            ],

            'business.business_type_id' => [
                'required',
                'uuid',
                'exists:business_types,id',
            ],

            'business.custom_business_type' => [
                'nullable',
                'string',
                'max:100',
            ],

            'business.name' => [
                'required',
                'string',
                'max:255',
            ],

            'business.phone' => [
                'required',
                'string',
                'max:20',
            ],

            'business.email' => [
                'nullable',
                'email',
                'max:255',
            ],

            'business.website' => [
                'nullable',
                'url',
                'max:255',
            ],

            'business.registration_number' => [
                'nullable',
                'string',
                'max:100',
            ],

            'business.tax_number' => [
                'nullable',
                'string',
                'max:100',
            ],

            'business.default_country' => [
                'nullable',
                'string',
                'max:100',
            ],

            'business.currency' => [
                'nullable',
                'string',
                'size:3',
            ],

            'business.timezone' => [
                'nullable',
                'timezone',
            ],

            'business.address' => [
                'nullable',
                'string',
            ],

            'business.city' => [
                'required',
                'string',
                'max:100',
            ],

            'business.state' => [
                'required',
                'string',
                'max:100',
            ],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $businessTypeId = $this->input(
                'business.business_type_id'
            );

            $customBusinessType = $this->input(
                'business.custom_business_type'
            );

            if (! $businessTypeId) {
                return;
            }

            $businessType = BusinessType::query()
                ->where('id', $businessTypeId)
                ->where('name', 'Other')
                ->first();

            if ($businessType) {
                if (blank($customBusinessType)) {
                    $validator->errors()->add(
                        'business.custom_business_type',
                        'Please specify your business type.'
                    );
                }

                return;
            }

            if (filled($customBusinessType)) {
                $validator->errors()->add(
                    'business.custom_business_type',
                    'Custom business type can only be provided when "Other" is selected.'
                );
            }
        });
    }
}