<?php

namespace App\Modules\Lab\Infrastructure;

use App\Modules\Lab\Contracts\QrCodeRenderer;
use chillerlan\QRCode\Output\QROutputInterface;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;

/** QR codes as inline SVG, drawn locally (no network call). */
final class SvgQrCodeRenderer implements QrCodeRenderer
{
    public function svg(string $text): string
    {
        $options = new QROptions([
            'outputType' => QROutputInterface::MARKUP_SVG,
            'outputBase64' => false,
            'addQuietzone' => true,
        ]);

        $svg = (new QRCode($options))->render($text);

        // Inline in HTML: drop the XML declaration.
        return trim(preg_replace('/^<\?xml[^>]*\?>/', '', $svg) ?? $svg);
    }
}
