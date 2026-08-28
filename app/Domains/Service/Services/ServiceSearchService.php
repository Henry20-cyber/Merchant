<?php

namespace App\Domains\Service\Services;

use App\Domains\Organization\Models\Business;
use App\Domains\Search\Results\SearchResult;
use App\Domains\Service\Models\Service;

class ServiceSearchService
{
    /**
     * Search services belonging to a business.
     */
    public function search(
        Business $business,
        string $search
    ): array {
        $search = trim($search);

        if ($search === '') {
            return [];
        }

        return Service::query()
            ->where('business_id', $business->id)
            ->where(function ($query) use ($search) {
                $query
                    ->where('name', 'ILIKE', '%' . $search . '%')
                    ->orWhere(
                        'description',
                        'ILIKE',
                        '%' . $search . '%'
                    );
            })
            ->orderBy('name')
            ->limit(20)
            ->get()
            ->map(function (Service $service) {
                return new SearchResult(
                    type: 'service',
                    id: $service->id,
                    title: $service->name,
                    subtitle: '₦' . number_format(
                        (float) $service->price,
                        2
                    ),
                    metadata: [
                        'price' => (float) $service->price,
                        'is_active' => $service->is_active,
                    ],
                );
            })
            ->map(
                fn (SearchResult $result) => $result->toArray()
            )
            ->all();
    }
}
