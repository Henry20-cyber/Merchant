<?php

namespace App\Domains\Receipt\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ReceiptResource extends JsonResource
{
    /**
     * Transform the receipt into an API response.
     */
    public function toArray(Request $request): array
    {
        $snapshot = $this->snapshot ?? [];

        return [
            'id' => $this->id,

            'receipt_number' => $this->receipt_number,

            'status' => $this->status,

            'issued_at' => $this->issued_at?->toISOString(),

            /*
             * The snapshot is the authoritative historical
             * representation of the receipt.
             */
            'receipt' => $snapshot,

            /*
             * Useful relational identifiers.
             */
            'business_id' => $this->business_id,

            'sale_id' => $this->sale_id,

            'issued_by' => $this->issued_by,

            'created_at' => $this->created_at?->toISOString(),

            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}