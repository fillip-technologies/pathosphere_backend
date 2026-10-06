<?php

namespace App\Modules\Ledger\Infrastructure;

use App\Modules\Ledger\Contracts\JournalBook;
use App\Modules\Ledger\Contracts\JournalFileWriter;
use App\Modules\Ledger\Domain\BookAccount;
use App\Modules\Ledger\Domain\JournalLine;
use App\Modules\Ledger\Domain\JournalVoucher;
use XMLWriter;

/**
 * Tally's XML import (Gateway of Tally → Import → Vouchers, or a POST to
 * Tally's HTTP port). Every ledger the vouchers use is sent first with its
 * group, so new branches and clients need no setup in Tally; ledgers that
 * already exist are left as they are.
 *
 * Tally's sign convention: a debit is ISDEEMEDPOSITIVE Yes with a negative
 * AMOUNT, a credit is No with a positive AMOUNT. Dates are YYYYMMDD.
 */
final class TallyXmlJournal implements JournalFileWriter
{
    public function extension(): string
    {
        return 'xml';
    }

    public function mimeType(): string
    {
        return 'application/xml';
    }

    public function write(JournalBook $book): string
    {
        $xml = new XMLWriter;
        $xml->openMemory();
        $xml->setIndent(true);
        $xml->startDocument('1.0', 'UTF-8');

        $xml->startElement('ENVELOPE');
        $xml->startElement('HEADER');
        $xml->writeElement('TALLYREQUEST', 'Import Data');
        $xml->endElement();

        $xml->startElement('BODY');
        $xml->startElement('IMPORTDATA');
        $xml->startElement('REQUESTDESC');
        $xml->writeElement('REPORTNAME', 'Vouchers');
        $xml->startElement('STATICVARIABLES');
        $xml->writeElement('SVCURRENTCOMPANY', $book->companyName);
        $xml->endElement();
        $xml->endElement();

        $xml->startElement('REQUESTDATA');
        foreach ($this->accountsUsed($book) as $account) {
            $this->writeLedger($xml, $account);
        }
        foreach ($book->vouchers as $voucher) {
            $this->writeVoucher($xml, $voucher);
        }
        $xml->endElement();

        $xml->endElement();
        $xml->endElement();
        $xml->endElement();
        $xml->endDocument();

        return $xml->outputMemory();
    }

    private function writeLedger(XMLWriter $xml, BookAccount $account): void
    {
        $xml->startElement('TALLYMESSAGE');
        $xml->writeAttribute('xmlns:UDF', 'TallyUDF');
        $xml->startElement('LEDGER');
        $xml->writeAttribute('NAME', $account->name);
        $xml->writeAttribute('ACTION', 'Create');
        $xml->startElement('NAME.LIST');
        $xml->writeElement('NAME', $account->name);
        $xml->endElement();
        $xml->writeElement('PARENT', $account->group);
        $xml->writeElement('ISBILLWISEON', 'No');
        $xml->endElement();
        $xml->endElement();
    }

    private function writeVoucher(XMLWriter $xml, JournalVoucher $voucher): void
    {
        $xml->startElement('TALLYMESSAGE');
        $xml->writeAttribute('xmlns:UDF', 'TallyUDF');
        $xml->startElement('VOUCHER');
        $xml->writeAttribute('VCHTYPE', $voucher->type->value);
        $xml->writeAttribute('ACTION', 'Create');
        $xml->writeAttribute('OBJVIEW', 'Accounting Voucher View');
        $xml->writeElement('DATE', $voucher->date->format('Ymd'));
        $xml->writeElement('EFFECTIVEDATE', $voucher->date->format('Ymd'));
        $xml->writeElement('VOUCHERTYPENAME', $voucher->type->value);
        $xml->writeElement('VOUCHERNUMBER', $voucher->reference);
        $xml->writeElement('REFERENCE', $voucher->reference);
        $xml->writeElement('NARRATION', $voucher->narration);
        $party = $voucher->party();
        if ($party !== null) {
            $xml->writeElement('PARTYLEDGERNAME', $party->name);
        }
        $xml->writeElement('PERSISTEDVIEW', 'Accounting Voucher View');

        foreach ($voucher->lines as $line) {
            $this->writeLine($xml, $line);
        }

        $xml->endElement();
        $xml->endElement();
    }

    private function writeLine(XMLWriter $xml, JournalLine $line): void
    {
        $amount = $line->amount()->toDecimalString();

        $xml->startElement('ALLLEDGERENTRIES.LIST');
        $xml->writeElement('LEDGERNAME', $line->account->name);
        $xml->writeElement('ISDEEMEDPOSITIVE', $line->isDebit() ? 'Yes' : 'No');
        $xml->writeElement('ISPARTYLEDGER', $line->account->isParty ? 'Yes' : 'No');
        $xml->writeElement('AMOUNT', $line->isDebit() ? '-'.$amount : $amount);
        $xml->endElement();
    }

    /** @return list<BookAccount> each ledger once, in order of first use */
    private function accountsUsed(JournalBook $book): array
    {
        $accounts = [];
        foreach ($book->vouchers as $voucher) {
            foreach ($voucher->lines as $line) {
                $accounts[$line->account->name] ??= $line->account;
            }
        }

        return array_values($accounts);
    }
}
