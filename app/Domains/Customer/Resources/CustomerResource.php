<?php

namespace App\Domains\Customer\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CustomerResource extends JsonResource
{
    /**
     * Transform the customer into an API response.
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,

            'customer_number' => $this->customer_number,

            'name' => $this->name,

            'phone' => $this->phone,

            'status' => $this->status,

            'created_at' => $this->created_at?->toISOString(),

            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
