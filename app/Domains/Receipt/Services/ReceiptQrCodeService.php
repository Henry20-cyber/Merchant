<?php

namespace App\Domains\Receipt\Services;

use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use App\Domains\Receipt\Models\Receipt;
use RuntimeException;

class ReceiptQrCodeService
{
    public function verificationUrl(Receipt $receipt): string
    {
        if (! $receipt->verification_token) {
            throw new RuntimeException('Receipt verification token is missing.');
        }

        return rtrim((string) config('app.url'), '/')
            . '/api/receipts/verify/' . $receipt->verification_token;
    }

    public function svg(Receipt $receipt): string
    {
        $renderer = new ImageRenderer(
            new RendererStyle(140, 2),
            new SvgImageBackEnd(),
        );

        return (new Writer($renderer))->writeString($this->verificationUrl($receipt));
    }
}
