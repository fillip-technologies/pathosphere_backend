<?php

namespace App\Modules\Lab\Contracts;

/** Draws the QR code printed on reports, linking to the public verify page. */
interface QrCodeRenderer
{
    /** @return string an inline SVG document */
    public function svg(string $text): string;
}
