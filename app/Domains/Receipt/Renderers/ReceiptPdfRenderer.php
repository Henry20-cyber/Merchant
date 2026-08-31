<?php

namespace App\Domains\Receipt\Renderers;

use App\Domains\Receipt\Models\Receipt;
use Barryvdh\DomPDF\Facade\Pdf;

class ReceiptPdfRenderer
{
    /**
     * Render a receipt as a PDF.
     *
     * Supported formats:
     *
     * - 58mm
     * - 80mm
     * - a4
     */
    public function render(
        Receipt $receipt,
        string $format = '80mm'
    ): \Barryvdh\DomPDF\PDF {
        $paper = match ($format) {
            '58mm' => [0, 0, 164, 600],
            '80mm' => [0, 0, 227, 600],
            'a4' => 'a4',
            default => throw new \InvalidArgumentException(
                'Unsupported receipt format.'
            ),
        };

        return Pdf::loadView(
            'receipts.show',
            [
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
