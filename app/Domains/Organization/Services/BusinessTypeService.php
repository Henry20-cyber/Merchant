<?php

namespace App\Domains\Organization\Services;

use App\Domains\Organization\Models\BusinessType;

class BusinessTypeService
{
    public function index()
    {
        return BusinessType::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get();
    }
}