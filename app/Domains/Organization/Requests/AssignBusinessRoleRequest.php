<?php

namespace App\Domains\Organization\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AssignBusinessRoleRequest extends FormRequest
{
    public function authorize(): bool
    {
        /*
         * Authorization is handled by the route permission:
         *
         * permission:roles.assign
         *
         * and by RoleService's domain-level invariants.
         */
        return true;
    }

    public function rules(): array
    {
        return [
            'role' => [
                'required',
                'string',
                'max:100',

                /*
                 * Owner cannot be assigned through the
                 * normal employee role-management endpoint.
                 */
                'not_in:Owner',
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'role.not_in' => [
                'The Owner role cannot be assigned through employee role management.',
            ],
        ];
    }
}