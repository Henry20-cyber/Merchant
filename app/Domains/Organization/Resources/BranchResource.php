<?php

namespace App\Domains\Organization\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BranchResource extends JsonResource
{
    /**
     * Transform the branch into an API response.
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,

            'business_id' => $this->business_id,

            'name' => $this->name,

            'code' => $this->code,

            'phone' => $this->phone,

            'email' => $this->email,

            'address' => $this->address,

            'city' => $this->city,

            'state' => $this->state,

            'country' => $this->country,

            'is_head_office' => $this->is_head_office,

            'created_at' => $this->created_at,

            'updated_at' => $this->updated_at,
        ];
    }
}
