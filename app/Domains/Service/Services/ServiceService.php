<?php

namespace App\Domains\Service\Services;

use App\Domains\Catalog\Models\Category;
use App\Domains\Organization\Models\Business;
use App\Domains\Service\Models\Service;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ServiceService
{
    /**
     * Create a service for the specified business.
     */
    public function create(
        Business $business,
        array $data
    ): Service {
        return DB::transaction(function () use (
            $business,
            $data
        ) {
            if (
                array_key_exists('category_id', $data)
                && $data['category_id'] !== null
            ) {
                $this->assertCategoryBelongsToBusiness(
                    $data['category_id'],
                    $business
                );
            }

            $service = Service::create([
                'business_id' => $business->id,
                'category_id' => $data['category_id'] ?? null,
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'price' => $data['price'] ?? 0,
                'is_active' => $data['is_active'] ?? true,
            ]);

            return $service->load('category');
        });
    }

    /**
     * Update a service.
     */
    public function update(
        Service $service,
        Business $business,
        array $data
    ): Service {
        $this->assertServiceBelongsToBusiness(
            $service,
            $business
        );

        return DB::transaction(function () use (
            $service,
            $business,
            $data
        ) {
            if (
                array_key_exists('category_id', $data)
                && $data['category_id'] !== null
            ) {
                $this->assertCategoryBelongsToBusiness(
                    $data['category_id'],
                    $business
                );
            }

            $allowed = [
                'name',
                'description',
                'price',
                'is_active',
                'category_id',
            ];

            $service->update(
                array_intersect_key(
                    $data,
                    array_flip($allowed)
                )
            );

            return $service
                ->refresh()
                ->load('category');
        });
    }

    /**
     * Delete a service.
     */
    public function delete(
        Service $service,
        Business $business
    ): void {
        $this->assertServiceBelongsToBusiness(
            $service,
            $business
        );

        $service->delete();
    }

    /**
     * Ensure the category belongs to the current business.
     */
    private function assertCategoryBelongsToBusiness(
        string $categoryId,
        Business $business
    ): void {
        $exists = Category::query()
            ->whereKey($categoryId)
            ->where('business_id', $business->id)
            ->exists();

        if (! $exists) {
            throw ValidationException::withMessages([
                'category_id' =>
                    'The selected category does not belong to this business.',
            ]);
        }
    }

    /**
     * Ensure the service belongs to the current business.
     */
    private function assertServiceBelongsToBusiness(
        Service $service,
        Business $business
    ): void {
        if ($service->business_id !== $business->id) {
            throw ValidationException::withMessages([
                'business' =>
                    'This service does not belong to this business.',
            ]);
        }
    }
}