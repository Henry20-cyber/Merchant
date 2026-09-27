<?php

use App\Domains\Inventory\Services\InventoryQuantityConverter;
use App\Domains\Product\Models\ProductUnit;
use Illuminate\Validation\ValidationException;

it('converts a base unit correctly', function () {
    $unit = ProductUnit::factory()->make([
        'quantity' => 1,
        'is_base_unit' => true,
    ]);

    $converter = app(InventoryQuantityConverter::class);

    expect(
        $converter->toBaseUnits(5, $unit)
    )->toBe(5.0);
});

it('converts a pack to base units', function () {
    $unit = ProductUnit::factory()->make([
        'quantity' => 12,
        'is_base_unit' => false,
    ]);

    $converter = app(InventoryQuantityConverter::class);

    expect(
        $converter->toBaseUnits(3, $unit)
    )->toBe(36.0);
});

it('converts a carton correctly', function () {
    $unit = ProductUnit::factory()->make([
        'quantity' => 24,
        'is_base_unit' => false,
    ]);

    $converter = app(InventoryQuantityConverter::class);

    expect(
        $converter->toBaseUnits(2, $unit)
    )->toBe(48.0);
});

it('rejects zero quantity', function () {
    $unit = ProductUnit::factory()->make([
        'quantity' => 1,
    ]);

    $converter = app(InventoryQuantityConverter::class);

    expect(fn () =>
        $converter->toBaseUnits(0, $unit)
    )->toThrow(ValidationException::class);
});

it('supports fractional quantities', function () {
    $unit = ProductUnit::factory()->make([
        'quantity' => 12,
        'is_base_unit' => false,
    ]);

    $converter = app(InventoryQuantityConverter::class);

    expect(
        $converter->toBaseUnits(1.5, $unit)
    )->toBe(18.0);
});

it('rejects a unit with zero conversion quantity', function () {
    $unit = ProductUnit::factory()->make([
        'quantity' => 0,
        'is_base_unit' => false,
    ]);

    $converter = app(InventoryQuantityConverter::class);

    expect(fn () =>
        $converter->toBaseUnits(1, $unit)
    )->toThrow(ValidationException::class);
});