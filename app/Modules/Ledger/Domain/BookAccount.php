<?php

namespace App\Modules\Ledger\Domain;

/**
 * An account in the company's books (a Tally ledger, a Zoho account), named
 * from configuration. Party accounts are customers and partners: Tally keeps
 * one ledger each, Zoho books them to receivables against a contact.
 */
final class BookAccount
{
    public function __construct(
        public readonly string $name,
        /** The Tally group it is created under, e.g. Sundry Debtors. */
        public readonly string $group,
        public readonly bool $isParty = false,
    ) {}
}
