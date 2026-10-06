<?php

namespace App\Modules\Ledger\Domain;

use App\Modules\Ledger\Enums\VoucherType;
use App\Modules\Shared\Money\Money;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * A balanced voucher: debits equal credits, always. The reference is
 * derived from what it summarises, so exporting the same month again gives
 * the same references and an accounting package can spot a repeat import.
 */
final class JournalVoucher
{
    /** @param  list<JournalLine>  $lines */
    public function __construct(
        public readonly VoucherType $type,
        public readonly CarbonImmutable $date,
        public readonly string $reference,
        public readonly string $narration,
        public readonly array $lines,
    ) {
        if (count($lines) < 2) {
            throw new InvalidArgumentException("Voucher {$reference} needs a debit and a credit.");
        }

        if (! $this->totalDebit()->equals($this->totalCredit())) {
            throw new InvalidArgumentException("Voucher {$reference} does not balance: debit {$this->totalDebit()}, credit {$this->totalCredit()}.");
        }
    }

    public function totalDebit(): Money
    {
        return array_reduce($this->lines, fn (Money $sum, JournalLine $line) => $sum->add($line->debit), Money::zero());
    }

    public function totalCredit(): Money
    {
        return array_reduce($this->lines, fn (Money $sum, JournalLine $line) => $sum->add($line->credit), Money::zero());
    }

    /** The party the voucher is about (a customer or partner), if any. */
    public function party(): ?BookAccount
    {
        foreach ($this->lines as $line) {
            if ($line->account->isParty) {
                return $line->account;
            }
        }

        return null;
    }
}
