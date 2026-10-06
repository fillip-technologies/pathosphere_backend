<?php

namespace App\Modules\Ledger\Errors;

use App\Modules\Shared\Errors\DomainError;
use App\Modules\Shared\Money\Money;

/** Partner ledger and settlement failures with stable codes. */
final class LedgerError
{
    public static function walletInsufficient(Money $available, Money $required): DomainError
    {
        return new DomainError(
            'WALLET_INSUFFICIENT',
            "The franchise wallet has Rs {$available} available (balance plus credit limit) but this needs Rs {$required}. Top up the wallet first.",
            422,
            [['available' => $available->toDecimalString(), 'required' => $required->toDecimalString()]],
        );
    }

    public static function agreementNotInForce(): DomainError
    {
        return new DomainError(
            'FRANCHISE_AGREEMENT_INACTIVE',
            'This franchise has no agreement in force, so its branches cannot take orders.',
            422,
        );
    }

    public static function partnerRequired(): DomainError
    {
        return new DomainError('VALIDATION_ERROR', 'Choose exactly one of franchise_id or b2b_client_id.', 422, [
            ['field' => 'franchise_id'], ['field' => 'b2b_client_id'],
        ]);
    }

    public static function paymentReferenceRequired(): DomainError
    {
        return new DomainError('PAYMENT_REFERENCE_REQUIRED', 'Record the bank transfer reference (UTR) for a settlement that moves money.', 422, [['field' => 'payment_reference']]);
    }

    public static function franchiseCannotTopUp(): DomainError
    {
        return new DomainError('WALLET_TOPUP_NOT_ALLOWED', 'Only a franchise with an agreement, not terminated, can top up its wallet.', 422);
    }

    public static function accountingPeriodOpen(string $month): DomainError
    {
        return new DomainError('ACCOUNTING_PERIOD_OPEN', "{$month} has not ended yet. Export a month once it is over.", 422, [['field' => 'month']]);
    }

    public static function accountingExportFailed(): DomainError
    {
        return new DomainError('ACCOUNTING_EXPORT_FAILED', 'This export could not be built. Ask for a new one.', 409);
    }
}
