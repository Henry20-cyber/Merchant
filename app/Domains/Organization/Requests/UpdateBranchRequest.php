<?php

namespace App\Domains\Organization\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateBranchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $branchId = $this->route('branch');

        return [
            'name' => [
                'sometimes',
                'required',
                'string',
                'max:255',
            ],

            'code' => [
                'sometimes',
                'required',
                'string',
                'max:100',
                Rule::unique('branches', 'code')
                    ->ignore($branchId),
            ],

            'phone' => [
                'nullable',
                'string',
                'max:50',
            ],

            'email' => [
                'nullable',
                'email',
                'max:255',
            ],

            'address' => [
                'nullable',
                'string',
                'max:2000',
            ],

            'city' => [
                'sometimes',
                'required',
                'string',
                'max:100',
            ],

            'state' => [
                'sometimes',
                'required',
                'string',
                'max:100',
            ],

            'country' => [
                'nullable',
                'string',
                'max:100',
            ],

            'is_head_office' => [
                'sometimes',
                'boolean',
            ],
        ];
    }
}