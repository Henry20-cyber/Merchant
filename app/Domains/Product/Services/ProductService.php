<?php

namespace App\Domains\Product\Services;

use App\Domains\Organization\Models\Business;
use App\Domains\Product\Models\Product;
use App\Domains\Product\Models\ProductUnit;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ProductService
{
    /**
     * Create a product with its mandatory base unit.
     */
    public function createProduct(
        Business $business,
        array $productData,
        array $baseUnitData
    ): Product {
        return DB::transaction(function () use (
            $business,
            $productData,
            $baseUnitData
        ) {
            $product = Product::create([
                'business_id' => $business->id,
                'name' => $productData['name'],
                'sku' => $productData['sku'] ?? null,
                'description' => $productData['description'] ?? null,
                'status' => $productData['status'] ?? 'active',
            ]);

            $this->createBaseUnit(
                $product,
                $business,
                $baseUnitData
            );

            return $product->load('units');
        });
    }

    /**
     * Create the base unit for a product.
     */
    public function createBaseUnit(
        Product $product,
        Business $business,
        array $data
    ): ProductUnit {
        $this->assertProductBelongsToBusiness(
            $product,
            $business
        );

        if (
            $product->units()
                ->where('is_base_unit', true)
                ->exists()
        ) {
            throw ValidationException::withMessages([
                'unit' => 'This product already has a base unit.',
            ]);
        }

        $quantity = (float) ($data['quantity'] ?? 1);

        if ($quantity !== 1.0) {
            throw ValidationException::withMessages([
                'quantity' => 'The base unit quantity must be exactly 1.',
            ]);
        }

        return ProductUnit::create([
            'business_id' => $business->id,
            'product_id' => $product->id,
            'name' => $data['name'],
            'quantity' => 1,
            'cost_price' => $data['cost_price'],
            'selling_price' => $data['selling_price'],
            'currency' => $data['currency']
                ?? $business->currency,
            'is_base_unit' => true,
            'is_sellable' => $data['is_sellable'] ?? true,
            'is_purchasable' => $data['is_purchasable'] ?? true,
        ]);
    }

    /**
     * Add a non-base/bulk unit.
     */
    public function addUnit(
        Product $product,
        Business $business,
        array $data
    ): ProductUnit {
        $this->assertProductBelongsToBusiness(
            $product,
            $business
        );

        $quantity = (float) ($data['quantity'] ?? 0);

        if ($quantity <= 1) {
            throw ValidationException::withMessages([
                'quantity' => 'A non-base unit must contain more than 1 base unit.',
            ]);
        }

        $exists = $product->units()
            ->where('name', $data['name'])
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'name' => 'This unit already exists for the product.',
            ]);
        }

        return ProductUnit::create([
            'business_id' => $business->id,
            'product_id' => $product->id,
            'name' => $data['name'],
            'quantity' => $quantity,
            'cost_price' => $data['cost_price'],
            'selling_price' => $data['selling_price'],
            'currency' => $data['currency']
                ?? $business->currency,
            'is_base_unit' => false,
            'is_sellable' => $data['is_sellable'] ?? true,
            'is_purchasable' => $data['is_purchasable'] ?? true,
        ]);
    }

    /**
     * Update a product unit.
     */
    public function updateUnit(
        ProductUnit $unit,
        Business $business,
        array $data
    ): ProductUnit {
        $this->assertUnitBelongsToBusiness(
            $unit,
            $business
        );

        /*
         * A base unit must always remain quantity = 1.
         */
        if ($unit->is_base_unit) {
            if (
                array_key_exists('quantity', $data)
                && (float) $data['quantity'] !== 1.0
            ) {
                throw ValidationException::withMessages([
                    'quantity' => 'The base unit quantity must remain exactly 1.',
                ]);
            }

            $data['quantity'] = 1;
        } else {
            /*
             * Non-base units must contain more than one base unit.
             */
            if (
                array_key_exists('quantity', $data)
                && (float) $data['quantity'] <= 1
            ) {
                throw ValidationException::withMessages([
                    'quantity' => 'A non-base unit must contain more than 1 base unit.',
                ]);
            }
        }

        /*
         * Prevent duplicate unit names within the same product.
         */
        if (
            array_key_exists('name', $data)
            && $unit->product
                ->units()
                ->where('name', $data['name'])
                ->whereKeyNot($unit->id)
                ->exists()
        ) {
            throw ValidationException::withMessages([
                'name' => 'This unit already exists for the product.',
            ]);
        }

        $allowed = [
            'name',
            'quantity',
            'cost_price',
            'selling_price',
            'currency',
            'is_sellable',
            'is_purchasable',
        ];

        $unit->update(
            array_intersect_key(
                $data,
                array_flip($allowed)
            )
        );

        return $unit->refresh();
    }

    /**
     * Promote a unit to become the product's base unit.
     */
    public function setBaseUnit(
        ProductUnit $unit,
        Business $business
    ): ProductUnit {
        $this->assertUnitBelongsToBusiness(
            $unit,
            $business
        );

        return DB::transaction(function () use (
            $unit
        ) {
            $product = $unit->product;

            /*
             * The selected unit must represent exactly
             * one base unit when promoted.
             */
            if ((float) $unit->quantity !== 1.0) {
                throw ValidationException::withMessages([
                    'quantity' => 'Only a unit with quantity 1 can become the base unit.',
                ]);
            }

            /*
             * Demote the current base unit.
             */
            $product->units()
                ->where('is_base_unit', true)
                ->whereKeyNot($unit->id)
                ->update([
                    'is_base_unit' => false,
                ]);

            /*
             * Promote the selected unit.
             */
            $unit->update([
                'is_base_unit' => true,
                'quantity' => 1,
            ]);

            return $unit->refresh();
        });
    }

    /**
     * Remove a product unit.
     */
    public function removeUnit(
        ProductUnit $unit,
        Business $business
    ): void {
        $this->assertUnitBelongsToBusiness(
            $unit,
            $business
        );

        /*
         * The base unit cannot be deleted because
         * every product requires exactly one base unit.
         */
        if ($unit->is_base_unit) {
            throw ValidationException::withMessages([
                'unit' => 'The base unit cannot be deleted.',
            ]);
        }

        $unit->delete();
    }

    /**
     * Ensure the product belongs to the business.
     */
    private function assertProductBelongsToBusiness(
        Product $product,
        Business $business
    ): void {
        if ($product->business_id !== $business->id) {
            throw ValidationException::withMessages([
                'business' => 'This product does not belong to this business.',
            ]);
        }
    }

    /**
     * Ensure the unit belongs to the business.
     */
    private function assertUnitBelongsToBusiness(
        ProductUnit $unit,
        Business $business
    ): void {
        if ($unit->business_id !== $business->id) {
            throw ValidationException::withMessages([
                'business' => 'This product unit does not belong to this business.',
            ]);
        }

        if ($unit->product->business_id !== $business->id) {
            throw ValidationException::withMessages([
                'business' => 'This product unit is attached to a different business.',
            ]);
        }
    }
}