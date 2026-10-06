<?php

namespace App\Modules\Lab\Contracts;

/**
 * Turns a report's HTML into a PDF (spec §3: headless Chromium on the VPS).
 * Implementations throw on failure; the rendering job retries.
 */
interface PdfRenderer
{
    /** @return string the PDF bytes */
    public function render(string $html): string;
}
