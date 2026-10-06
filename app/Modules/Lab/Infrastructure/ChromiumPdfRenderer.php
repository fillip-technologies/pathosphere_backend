<?php

namespace App\Modules\Lab\Infrastructure;

use App\Modules\Lab\Contracts\PdfRenderer;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * Prints HTML to PDF with headless Chromium (spec §3, VPS hosting). Each
 * render uses its own temporary folder, removed afterwards.
 */
final class ChromiumPdfRenderer implements PdfRenderer
{
    public function __construct(
        private readonly string $chromiumBinary,
        private readonly int $timeoutSeconds = 60,
    ) {}

    public function render(string $html): string
    {
        $folder = sys_get_temp_dir().'/report-'.Str::uuid();

        if (! mkdir($folder, 0700) && ! is_dir($folder)) {
            throw new RuntimeException('Could not create a folder for PDF rendering.');
        }

        try {
            file_put_contents("{$folder}/report.html", $html);

            $process = new Process([
                $this->chromiumBinary,
                '--headless=new',
                '--disable-gpu',
                '--no-sandbox',
                '--no-pdf-header-footer',
                "--user-data-dir={$folder}/profile",
                "--print-to-pdf={$folder}/report.pdf",
                "file://{$folder}/report.html",
            ]);
            $process->setTimeout($this->timeoutSeconds);
            $process->run();

            $pdf = is_file("{$folder}/report.pdf") ? (string) file_get_contents("{$folder}/report.pdf") : '';

            if (! $process->isSuccessful() || ! str_starts_with($pdf, '%PDF')) {
                throw new RuntimeException('Chromium could not render the report PDF (exit code '.$process->getExitCode().').');
            }

            return $pdf;
        } finally {
            (new Filesystem)->deleteDirectory($folder);
        }
    }
}
