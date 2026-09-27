<?php

namespace App\Domains\Inventory\Services;

use App\Domains\Product\Models\ProductUnit;
use Illuminate\Validation\ValidationException;

class InventoryQuantityConverter
{
    public function toBaseUnits(float $quantity, ProductUnit $unit): float
{
    if ($quantity <= 0) {
        throw ValidationException::withMessages([
            'quantity' => 'Quantity must be greater than zero.',
        ]);
    }

    $conversion = (float) $unit->quantity;

    if ($conversion <= 0) {
        throw ValidationException::withMessages([
            'unit' => 'Product unit has an invalid conversion quantity.',
        ]);
    }

    return $quantity * $conversion;
}
}