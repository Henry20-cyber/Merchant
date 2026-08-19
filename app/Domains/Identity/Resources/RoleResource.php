<?php

namespace App\Domains\Identity\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RoleResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,

            'name' => $this->name,

            'business_id' => $this->team_id,

            'is_system' => (bool) $this->is_system,

            'permissions' => $this->whenLoaded(
                'permissions',
                fn () => $this->permissions
                    ->pluck('name')
                    ->values()
            ),

            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}