<?php

namespace App\Domains\Receipt\Renderers;

use App\Domains\Receipt\Models\Receipt;

class ReceiptHtmlRenderer
{
    public function render(
        Receipt $receipt,
        string $format = '80mm'
    ): string {
        return view('receipts.show', [
            'receipt' => $receipt,
            'snapshot' => $receipt->snapshot ?? [],
            'format' => $format,
        ])->render();
    }
}
