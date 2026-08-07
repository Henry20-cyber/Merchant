<?php

namespace App\Domains\Organization\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BusinessResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     */
    public function toArray(Request $request): array
    {
        return [

            'id' => $this->id,

            'business_type_id' => $this->business_type_id,

            'name' => $this->name,

            'slug' => $this->slug,

            'phone' => $this->phone,

            'email' => $this->email,

            'website' => $this->website,

            'registration_number' => $this->registration_number,

            'tax_number' => $this->tax_number,

            'default_country' => $this->default_country,

            'currency' => $this->currency,

            'timezone' => $this->timezone,

            'status' => $this->status,

            'created_at' => $this->created_at,
        ];
    }
}