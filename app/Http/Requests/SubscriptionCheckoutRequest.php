<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SubscriptionCheckoutRequest extends FormRequest
{
    /**
     * Determine whether the user is authorized
     * to make this request.
     */
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * Validation rules for subscription checkout.
     */
    public function rules(): array
    {
        return [
            'plan_id' => [
                'required',
                'uuid',
                'exists:subscription_plans,id',
            ],

            'email' => [
                'required',
                'email',
                'max:255',
            ],
        ];
    }
}