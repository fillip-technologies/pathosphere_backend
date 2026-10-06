<?php

namespace App\Modules\Ledger\Infrastructure;

use App\Modules\Ledger\Contracts\JournalBook;
use App\Modules\Ledger\Contracts\JournalFileWriter;
use App\Modules\Ledger\Domain\JournalLine;
use App\Modules\Ledger\Domain\JournalVoucher;
use RuntimeException;

/**
 * Zoho Books manual journal import (Accountant → Manual Journals → Import).
 * One row per line; rows with the same Reference Number form one journal.
 * Customers and partners are booked to the receivables account against a
 * contact, as Zoho requires. Text that a spreadsheet could read as a
 * formula is prefixed with an apostrophe.
 */
final class ZohoBooksJournalCsv implements JournalFileWriter
{
    private const COLUMNS = ['Journal Date', 'Reference Number', 'Notes', 'Currency Code', 'Account', 'Description', 'Contact Name', 'Debit', 'Credit'];

    public function __construct(private readonly string $receivablesAccount) {}

    public function extension(): string
    {
        return 'csv';
    }

    public function mimeType(): string
    {
        return 'text/csv';
    }

    public function write(JournalBook $book): string
    {
        $stream = fopen('php://temp', 'r+') ?: throw new RuntimeException('Cannot open a temporary stream for the CSV.');
        fputcsv($stream, self::COLUMNS);

        foreach ($book->vouchers as $voucher) {
            foreach ($voucher->lines as $line) {
                fputcsv($stream, $this->row($book, $voucher, $line));
            }
        }

        rewind($stream);
        $csv = (string) stream_get_contents($stream);
        fclose($stream);

        return $csv;
    }

    /** @return list<string> */
    private function row(JournalBook $book, JournalVoucher $voucher, JournalLine $line): array
    {
        return [
            $voucher->date->format('Y-m-d'),
            $this->text($voucher->reference),
            $this->text($voucher->narration),
            $book->currency,
            $this->text($line->account->isParty ? $this->receivablesAccount : $line->account->name),
            $this->text($voucher->type->value),
            $line->account->isParty ? $this->text($line->account->name) : '',
            $line->isDebit() ? $line->debit->toDecimalString() : '',
            $line->isDebit() ? '' : $line->credit->toDecimalString(),
        ];
    }

    private function text(string $value): string
    {
        return preg_match('/^[=+\-@\t\r]/', $value) === 1 ? "'".$value : $value;
    }
}
