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
            'verificationQrSvg' => $receipt->verification_token
                ? app(\App\Domains\Receipt\Services\ReceiptQrCodeService::class)->svg($receipt)
                : null,
            'receipt' => $receipt,
            'snapshot' => $receipt->snapshot ?? [],
            'format' => $format,
        ])->render();
    }
}
