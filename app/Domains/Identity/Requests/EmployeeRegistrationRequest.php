<?php

namespace App\Domains\Identity\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class EmployeeRegistrationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => [
                'required',
                'string',
                'max:255',
            ],

            'email' => [
                'required',
                'email',
                'max:255',
                'unique:users,email',
            ],

            'password' => [
                'required',
                'string',
                'confirmed',
                Password::min(8),
            ],

            'password_confirmation' => [
                'required',
                'string',
            ],

            'merchant_id' => [
                'required',
                'string',
                'max:10',
            ],

            'requested_role_id' => [
                'nullable',
                'integer',
            ],
        ];
    }
}
