<?php

namespace App\Domains\Catalog\Services;

use App\Domains\Catalog\Models\Category;
use App\Domains\Organization\Models\Business;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CategoryService
{
    public function create(
        Business $business,
        array $data
    ): Category {
        return DB::transaction(function () use ($business, $data) {
            $parent = null;

            if (! empty($data['parent_id'])) {
                $parent = Category::query()
                    ->whereKey($data['parent_id'])
                    ->where('business_id', $business->id)
                    ->first();

                if (! $parent) {
                    throw ValidationException::withMessages([
                        'parent_id' =>
                            'The selected parent category does not belong to this business.',
                    ]);
                }
            }

            return Category::create([
                'business_id' => $business->id,
                'parent_id' => $parent?->id,
                'name' => $data['name'],
                'slug' => $this->generateUniqueSlug(
                    $business,
                    $data['name']
                ),
                'description' => $data['description'] ?? null,
                'sort_order' => $data['sort_order'] ?? 0,
                'status' => $data['status'] ?? 'active',
            ]);
        });
    }

    public function update(
        Category $category,
        Business $business,
        array $data
    ): Category {
        $this->assertBelongsToBusiness(
            $category,
            $business
        );

        return DB::transaction(function () use (
            $category,
            $business,
            $data
        ) {
            if (array_key_exists('parent_id', $data)) {
                $parent = null;

                if (! empty($data['parent_id'])) {
                    if ($data['parent_id'] === $category->id) {
                        throw ValidationException::withMessages([
                            'parent_id' =>
                                'A category cannot be its own parent.',
                        ]);
                    }

                    $parent = Category::query()
                        ->whereKey($data['parent_id'])
                        ->where('business_id', $business->id)
                        ->first();

                    if (! $parent) {
                        throw ValidationException::withMessages([
                            'parent_id' =>
                                'The selected parent category does not belong to this business.',
                        ]);
                    }

                    $this->assertNotDescendant(
                        $category,
                        $parent
                    );
                }

                $data['parent_id'] = $parent?->id;
            }

            if (array_key_exists('name', $data)) {
                $data['slug'] = $this->generateUniqueSlug(
                    $business,
                    $data['name'],
                    $category->id
                );
            }

            $allowed = [
                'parent_id',
                'name',
                'slug',
                'description',
                'sort_order',
                'status',
            ];

            $category->update(
                array_intersect_key(
                    $data,
                    array_flip($allowed)
                )
            );

            return $category->refresh();
        });
    }

    public function delete(
        Category $category,
        Business $business
    ): void {
        $this->assertBelongsToBusiness(
            $category,
            $business
        );

        /*
         * Do not allow deletion while child categories
         * still depend on this category.
         */
        if ($category->children()->exists()) {
            throw ValidationException::withMessages([
                'category' =>
                    'This category cannot be deleted because it has child categories.',
            ]);
        }

        $category->delete();
    }

    private function assertBelongsToBusiness(
        Category $category,
        Business $business
    ): void {
        if ($category->business_id !== $business->id) {
            abort(404);
        }
    }

    private function generateUniqueSlug(
        Business $business,
        string $name,
        ?string $ignoreId = null
    ): string {
        $baseSlug = Str::slug($name);

        if ($baseSlug === '') {
            throw ValidationException::withMessages([
                'name' => 'The category name must contain valid characters.',
            ]);
        }

        $slug = $baseSlug;
        $counter = 2;

        while (
            Category::query()
                ->where('business_id', $business->id)
                ->where('slug', $slug)
                ->when(
                    $ignoreId,
                    fn ($query) =>
                        $query->whereKeyNot($ignoreId)
                )
                ->exists()
        ) {
            $slug = "{$baseSlug}-{$counter}";
            $counter++;
        }

        return $slug;
    }

    /**
     * Prevent:
     *
     * A → B → C
     *
     * becoming:
     *
     * A → B → C → A
     */
    private function assertNotDescendant(
        Category $category,
        Category $parent
    ): void {
        $current = $parent;

        while ($current->parent_id !== null) {
            if ($current->parent_id === $category->id) {
                throw ValidationException::withMessages([
                    'parent_id' =>
                        'A category cannot be moved below one of its descendants.',
                ]);
            }

            $current = $current->parent;
        }
    }
}