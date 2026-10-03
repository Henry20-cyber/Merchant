<?php

namespace App\Domains\Organization\Resources;

use App\Domains\Identity\Services\RoleService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BusinessMemberResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     */
    public function toArray(Request $request): array
    {
        $roleService = app(RoleService::class);

        $role = $roleService->getBusinessRole(
            $this->user,
            $this->business_id
        );

        return [
            'membership_id' => $this->id,

            'user' => [
                'id' => $this->user->id,
                'name' => $this->user->name,
                'email' => $this->user->email,
            ],

            'role' => $role?->name,

            'status' => $this->status,

            'joined_at' => $this->joined_at,
        ];
    }
}