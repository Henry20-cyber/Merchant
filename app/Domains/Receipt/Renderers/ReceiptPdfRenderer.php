<?php

namespace App\Domains\Receipt\Renderers;

use App\Domains\Receipt\Models\Receipt;
use App\Domains\Receipt\Services\ReceiptQrCodeService;
use Barryvdh\DomPDF\Facade\Pdf;

class ReceiptPdfRenderer
{
    /**
     * Render a receipt as a PDF.
     *
     * Supported formats: 58mm, 80mm, and a4.
     */
    public function render(
        Receipt $receipt,
        string $format = '80mm'
    ): \Barryvdh\DomPDF\PDF {
        // DomPDF paper dimensions are expressed in points.
        // Use a generous height for thermal receipts so longer
        // receipts are less likely to be clipped.
        // A4 remains a standard paginated page size.
        $paper = match ($format) {
            '58mm' => [0, 0, 164, 600],
            '80mm' => [0, 0, 227, 600],
            'a4' => 'a4',
            default => throw new \InvalidArgumentException(
                'Unsupported receipt format.'
            ),
        };

        $verificationQrSvg = null;

        if ($receipt->verification_token) {
            $verificationQrSvg = app(
                ReceiptQrCodeService::class
            )->svg($receipt);
        }

        return Pdf::loadView(
            'receipts.show',
            [
                'verificationQrSvg' => $verificationQrSvg,
                'receipt' => $receipt,
                'snapshot' => $receipt->snapshot ?? [],
                'format' => $format,
            ]
        )
            ->setPaper($paper)
            ->setOption([
                'isHtml5ParserEnabled' => true,
                'isRemoteEnabled' => false,
                'defaultFont' => 'Arial',
            ]);
    }
}
