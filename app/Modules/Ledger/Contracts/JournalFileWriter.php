<?php

namespace App\Modules\Ledger\Contracts;

/**
 * An accounting package's import format (spec §3: Tally XML, Zoho Books).
 * Its field names and conventions stay in the writer.
 */
interface JournalFileWriter
{
    public function extension(): string;

    public function mimeType(): string;

    public function write(JournalBook $book): string;
}
