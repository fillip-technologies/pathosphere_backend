<?php

namespace App\Modules\Ledger\Domain;

use App\Modules\Ledger\Enums\LedgerEntryType;
use App\Modules\Shared\Money\Money;
use InvalidArgumentException;

/**
 * One ledger row to be written: exactly one of debit (partner owes HQ) or
 * credit (HQ owes partner), never both, never zero (spec §7.8).
 */
final class Posting
{
    private function __construct(
        public readonly LedgerEntryType $entryType,
        public readonly Money $debit,
        public readonly Money $credit,
        public readonly string $narration,
        public readonly string $referenceType,
        public readonly ?string $referenceId,
        /** Same key, same money: a retried job or webhook never posts twice. Null for manual entries. */
        public readonly ?string $idempotencyKey,
    ) {}

    public static function debit(LedgerEntryType $type, Money $amount, string $narration, string $referenceType, ?string $referenceId, ?string $idempotencyKey): self
    {
        self::assertPositive($amount);

        return new self($type, $amount, Money::zero(), $narration, $referenceType, $referenceId, $idempotencyKey);
    }

    public static function credit(LedgerEntryType $type, Money $amount, string $narration, string $referenceType, ?string $referenceId, ?string $idempotencyKey): self
    {
        self::assertPositive($amount);

        return new self($type, Money::zero(), $amount, $narration, $referenceType, $referenceId, $idempotencyKey);
    }

    /** The posting's effect on the balance (credit − debit). */
    public function effect(): Money
    {
        return $this->credit->subtract($this->debit);
    }

    private static function assertPositive(Money $amount): void
    {
        if ($amount->isNegative() || $amount->isZero()) {
            throw new InvalidArgumentException('A ledger posting must move a positive amount.');
        }
    }
}
