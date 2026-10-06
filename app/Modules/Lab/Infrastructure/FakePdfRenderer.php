<?php

namespace App\Modules\Lab\Infrastructure;

use App\Modules\Lab\Contracts\PdfRenderer;

/**
 * Local and test stand-in for Chromium: writes a small, valid PDF holding the
 * report's text, so storage, hashing and delivery can run without a browser.
 */
final class FakePdfRenderer implements PdfRenderer
{
    private const LINES_PER_PAGE = 60;

    public function render(string $html): string
    {
        $pages = array_chunk($this->textLines($html), self::LINES_PER_PAGE) ?: [[]];

        // Objects: 1 catalog, 2 page tree, 3 font, then a page and its content per page.
        $objects = [1 => '', 2 => '', 3 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>'];
        $pageIds = [];

        foreach ($pages as $lines) {
            $pageId = count($objects) + 1;
            $contentId = $pageId + 1;
            $pageIds[] = "{$pageId} 0 R";
            $stream = $this->contentStream($lines);
            $objects[$pageId] = "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 3 0 R >> >> /Contents {$contentId} 0 R >>";
            $objects[$contentId] = '<< /Length '.strlen($stream)." >>\nstream\n{$stream}\nendstream";
        }

        $objects[1] = '<< /Type /Catalog /Pages 2 0 R >>';
        $objects[2] = '<< /Type /Pages /Kids ['.implode(' ', $pageIds).'] /Count '.count($pageIds).' >>';

        return $this->assemble($objects);
    }

    /** @return list<string> */
    private function textLines(string $html): array
    {
        $html = preg_replace('/<(style|script|svg)\b.*?<\/\1>/is', '', $html) ?? '';
        $text = html_entity_decode(strip_tags(preg_replace('/<(br|\/p|\/tr|\/h\d|\/div|\/li)\b[^>]*>/i', "\n", $html) ?? ''), ENT_QUOTES | ENT_HTML5);
        $lines = array_map(fn (string $line) => trim(preg_replace('/\s+/', ' ', $line) ?? ''), explode("\n", $text));

        return array_values(array_filter($lines, fn (string $line) => $line !== ''));
    }

    /** @param  list<string>  $lines */
    private function contentStream(array $lines): string
    {
        $commands = ['BT', '/F1 9 Tf', '40 800 Td', '12 TL'];

        foreach ($lines as $line) {
            $ascii = preg_replace('/[^\x20-\x7E]/', '?', mb_substr($line, 0, 110)) ?? '';
            $commands[] = '('.str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $ascii).') Tj T*';
        }

        $commands[] = 'ET';

        return implode("\n", $commands);
    }

    /** @param  array<int, string>  $objects */
    private function assemble(array $objects): string
    {
        ksort($objects);
        $pdf = "%PDF-1.4\n";
        $offsets = [];

        foreach ($objects as $id => $body) {
            $offsets[$id] = strlen($pdf);
            $pdf .= "{$id} 0 obj\n{$body}\nendobj\n";
        }

        $xref = strlen($pdf);
        $pdf .= 'xref'."\n0 ".(count($objects) + 1)."\n0000000000 65535 f \n";

        foreach ($offsets as $offset) {
            $pdf .= sprintf("%010d 00000 n \n", $offset);
        }

        return $pdf.'trailer'."\n<< /Size ".(count($objects) + 1)." /Root 1 0 R >>\nstartxref\n{$xref}\n%%EOF\n";
    }
}
