<?php

namespace App\Domains\Organization\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateBusinessRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => [
                'sometimes',
                'string',
                'max:255',
            ],

            'phone' => [
                'sometimes',
                'string',
                'max:50',
            ],

            'email' => [
                'sometimes',
                'nullable',
                'email',
                'max:255',
            ],

            'website' => [
                'sometimes',
                'nullable',
                'url',
                'max:255',
            ],

            'registration_number' => [
                'sometimes',
                'nullable',
                'string',
                'max:255',
            ],

            'tax_number' => [
                'sometimes',
                'nullable',
                'string',
                'max:255',
            ],

            'default_country' => [
                'sometimes',
                'string',
                'max:100',
            ],

            'currency' => [
                'sometimes',
                'string',
                'max:10',
            ],

            'timezone' => [
                'sometimes',
                'string',
                'max:100',
            ],
        ];
    }
}